<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_role('supplier');
require_once __DIR__ . '/../config/database.php';

function supplier_note_is_valid(string $note): bool
{
    return preg_match('//u', $note) === 1
        && preg_match('/[\x00-\x1F\x7F]/u', $note) !== 1
        && preg_match_all('/./us', $note) <= 255;
}

function supplier_stock_condition(string $stock, string $minimumStock): string
{
    $stockMilli = stock_quantity_to_milli($stock);
    $minimumMilli = stock_quantity_to_milli($minimumStock);
    if ($stockMilli === false || $minimumMilli === false) {
        return 'Tidak diketahui';
    }
    if ($stockMilli === 0) {
        return 'Habis';
    }

    return $stockMilli <= $minimumMilli ? 'Menipis' : 'Aman';
}

$pdo = database_connection();
$supplierId = (int) current_user()['id'];
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash_message('error', 'Form tidak valid atau sudah kedaluwarsa. Silakan coba lagi.');
        header('Location: ' . app_url('supplier/dashboard.php'));
        exit;
    }

    $rawProductId = $_POST['product_id'] ?? null;
    $productId = is_string($rawProductId)
        ? filter_var($rawProductId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;
    $quantityInput = $_POST['quantity'] ?? null;
    $quantityMilli = is_string($quantityInput) ? stock_quantity_to_milli(trim($quantityInput)) : false;
    $note = is_string($_POST['note'] ?? null) ? trim($_POST['note']) : '';

    if ($productId === false) {
        $errorMessage = 'Pilih produk yang valid.';
    } elseif ($quantityMilli === false || $quantityMilli <= 0) {
        $errorMessage = 'Jumlah harus lebih dari nol dan maksimal memiliki 3 angka desimal.';
    } elseif (!supplier_note_is_valid($note)) {
        $errorMessage = 'Catatan tidak boleh berisi karakter kontrol dan maksimal 255 karakter.';
    }

    if ($errorMessage === '') {
        try {
            $pdo->beginTransaction();

            // The join limits the row to products assigned to this supplier. FOR UPDATE locks it for the stock change.
            $productStatement = $pdo->prepare(
                'SELECT p.id, p.name, p.stock, p.unit
                 FROM products AS p
                 INNER JOIN supplier_products AS sp ON sp.product_id = p.id
                 WHERE p.id = :product_id AND sp.supplier_user_id = :supplier_id
                 FOR UPDATE'
            );
            $productStatement->execute(['product_id' => $productId, 'supplier_id' => $supplierId]);
            $product = $productStatement->fetch();
            if ($product === false) {
                throw new DomainException('Produk tidak termasuk dalam daftar produk Anda.');
            }

            if (stock_unit_requires_whole_quantity((string) $product['unit']) && $quantityMilli % 1000 !== 0) {
                throw new DomainException('Satuan ' . $product['unit'] . ' harus menggunakan jumlah bilangan bulat.');
            }

            $stockMilli = stock_quantity_to_milli((string) $product['stock']);
            if ($stockMilli === false) {
                throw new RuntimeException('Nilai stok produk tidak valid.');
            }
            $newStockMilli = $stockMilli + $quantityMilli;
            if ($newStockMilli > 999999999999) {
                throw new DomainException('Jumlah stok melebihi batas yang dapat disimpan.');
            }

            $updateStatement = $pdo->prepare('UPDATE products SET stock = :stock WHERE id = :id');
            $updateStatement->execute([
                'stock' => stock_milli_to_decimal($newStockMilli),
                'id' => $productId,
            ]);

            $movementStatement = $pdo->prepare(
                "INSERT INTO stock_movements
                 (product_id, actor_user_id, movement_type, quantity_change, reason, transaction_id)
                 VALUES (:product_id, :actor_user_id, 'supplier_in', :quantity_change, :reason, NULL)"
            );
            $movementStatement->execute([
                'product_id' => $productId,
                'actor_user_id' => $supplierId,
                'quantity_change' => stock_milli_to_decimal($quantityMilli),
                'reason' => $note === '' ? null : $note,
            ]);

            $pdo->commit();
            set_flash_message('success', 'Stok masuk berhasil dicatat untuk ' . $product['name'] . '.');
            header('Location: ' . app_url('supplier/dashboard.php'));
            exit;
        } catch (DomainException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errorMessage = $exception->getMessage();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Supplier stock-in error: ' . $exception->getMessage());
            $errorMessage = 'Stok masuk gagal disimpan. Tidak ada perubahan yang diterapkan.';
        }
    }
}

$productStatement = $pdo->prepare(
    'SELECT p.id, p.name, p.stock, p.minimum_stock, p.unit, p.selling_price, p.status
     FROM products AS p
     INNER JOIN supplier_products AS sp ON sp.product_id = p.id
     WHERE sp.supplier_user_id = :supplier_id
     ORDER BY p.name ASC'
);
$productStatement->execute(['supplier_id' => $supplierId]);
$assignedProducts = $productStatement->fetchAll();

$historyStatement = $pdo->prepare(
    "SELECT sm.quantity_change, sm.reason, sm.created_at, p.name AS product_name, p.unit
     FROM stock_movements AS sm
     INNER JOIN products AS p ON p.id = sm.product_id
     WHERE sm.actor_user_id = :supplier_id AND sm.movement_type = 'supplier_in'
     ORDER BY sm.created_at DESC, sm.id DESC
     LIMIT 100"
);
$historyStatement->execute(['supplier_id' => $supplierId]);
$history = $historyStatement->fetchAll();

$flashMessage = take_flash_message();
$pageTitle = 'Dashboard Supplier';
require __DIR__ . '/../includes/header.php';
?>
<p class="page-intro">Pantau stok produk yang ditugaskan kepada Anda dan catat stok masuk.</p>

<?php if (is_array($flashMessage)): ?>
    <div class="alert <?= ($flashMessage['type'] ?? '') === 'success' ? 'alert-success' : 'alert-error' ?>" role="status">
        <?= escape_html(is_string($flashMessage['message'] ?? null) ? $flashMessage['message'] : '') ?>
    </div>
<?php endif; ?>
<?php if ($errorMessage !== ''): ?>
    <div class="alert alert-error" role="alert"><?= escape_html($errorMessage) ?></div>
<?php endif; ?>

<section class="panel">
    <div class="panel-heading">
        <div><h2>Produk saya</h2><p><?= number_format(count($assignedProducts)) ?> produk ditugaskan</p></div>
    </div>
    <?php if ($assignedProducts === []): ?>
        <div class="empty-state">
            <strong>Belum ada produk</strong>
            <p>Hubungi Admin untuk menugaskan produk kepada Anda.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr><th>Produk</th><th>Stok</th><th>Minimum</th><th>Harga jual</th><th>Kondisi stok</th></tr>
                </thead>
                <tbody>
                <?php foreach ($assignedProducts as $product): ?>
                    <?php $condition = supplier_stock_condition((string) $product['stock'], (string) $product['minimum_stock']); ?>
                    <tr>
                        <td><?= escape_html($product['name']) ?><span class="unit-note"><?= escape_html($product['unit']) ?></span></td>
                        <td><?= escape_html((string) $product['stock']) ?> <?= escape_html($product['unit']) ?></td>
                        <td><?= escape_html((string) $product['minimum_stock']) ?> <?= escape_html($product['unit']) ?></td>
                        <td>Rp <?= number_format((float) $product['selling_price'], 2, ',', '.') ?></td>
                        <td><span class="status-badge <?= $condition === 'Aman' ? 'status-active' : 'status-inactive' ?>"><?= escape_html($condition) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if ($assignedProducts !== []): ?>
    <section class="panel">
        <div class="panel-heading">
            <div><h2>Catat stok masuk</h2><p>Hanya untuk produk yang ditugaskan kepada Anda.</p></div>
        </div>
        <form method="post" action="<?= escape_html(app_url('supplier/dashboard.php')) ?>" class="stock-form">
            <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
            <label for="supplier-product">Produk</label>
            <select id="supplier-product" name="product_id" required>
                <option value="">Pilih produk</option>
                <?php foreach ($assignedProducts as $product): ?>
                    <option value="<?= (int) $product['id'] ?>"><?= escape_html($product['name'] . ' (' . $product['stock'] . ' ' . $product['unit'] . ')') ?></option>
                <?php endforeach; ?>
            </select>
            <label for="supplier-quantity">Jumlah masuk</label>
            <input id="supplier-quantity" name="quantity" type="number" min="0.001" step="0.001" required>
            <label for="supplier-note">Catatan (opsional)</label>
            <textarea id="supplier-note" name="note" maxlength="255"></textarea>
            <button class="primary-button" type="submit">Catat stok masuk</button>
        </form>
    </section>
<?php endif; ?>

<section class="panel">
    <div class="panel-heading">
        <div><h2>Riwayat stok masuk</h2><p>100 pencatatan terakhir oleh Anda.</p></div>
    </div>
    <?php if ($history === []): ?>
        <div class="empty-state"><strong>Belum ada riwayat</strong><p>Stok masuk yang Anda catat akan muncul di sini.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Waktu</th><th>Produk</th><th>Jumlah masuk</th><th>Catatan</th></tr></thead>
                <tbody>
                <?php foreach ($history as $row): ?>
                    <tr>
                        <td><?= escape_html($row['created_at']) ?></td>
                        <td><?= escape_html($row['product_name']) ?></td>
                        <td>+<?= escape_html((string) $row['quantity_change']) ?> <?= escape_html($row['unit']) ?></td>
                        <td><?= escape_html($row['reason'] ?? '-') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
