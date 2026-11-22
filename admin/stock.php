<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
require_once __DIR__ . '/../config/database.php';

function stock_quantity_to_milli(string $value): int|false
{
    if (preg_match('/^([0-9]+)(?:\.([0-9]{1,3}))?$/D', $value, $matches) !== 1) {
        return false;
    }

    $whole = ltrim($matches[1], '0');
    $whole = $whole === '' ? '0' : $whole;
    if (strlen($whole) > 9 || (strlen($whole) === 9 && strcmp($whole, '999999999') > 0)) {
        return false;
    }

    $fraction = str_pad($matches[2] ?? '', 3, '0');
    return ((int) $whole * 1000) + (int) $fraction;
}

function stock_milli_to_decimal(int $milli): string
{
    $sign = $milli < 0 ? '-' : '';
    $absoluteMilli = abs($milli);

    return $sign . intdiv($absoluteMilli, 1000) . '.'
        . str_pad((string) ($absoluteMilli % 1000), 3, '0', STR_PAD_LEFT);
}

function stock_unit_requires_whole_quantity(string $unit): bool
{
    return in_array($unit, ['pcs', 'pack', 'box', 'botol', 'ikat'], true);
}

function stock_reason_is_valid(string $reason): bool
{
    return trim($reason) !== ''
        && preg_match('//u', $reason) === 1
        && preg_match('/[\x00-\x1F\x7F]/u', $reason) !== 1
        && preg_match_all('/./us', $reason, $matches) <= 240;
}

$pdo = database_connection();
$movementTypes = ['admin_adjustment', 'supplier_in', 'sale_out'];
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash_message('error', 'Form tidak valid atau sudah kedaluwarsa. Silakan coba lagi.');
        header('Location: ' . app_url('admin/stock.php'));
        exit;
    }

    $action = $_POST['action'] ?? null;
    $rawProductId = $_POST['product_id'] ?? null;
    $productId = is_string($rawProductId)
        ? filter_var($rawProductId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;
    $quantityInput = $_POST['quantity'] ?? null;
    $reasonInput = $_POST['reason'] ?? null;

    if (!is_string($action) || !in_array($action, ['stock_in', 'adjustment'], true)) {
        $errorMessage = 'Jenis perubahan stok tidak valid.';
    } elseif ($productId === false) {
        $errorMessage = 'Pilih produk yang valid.';
    } elseif (!is_string($quantityInput)) {
        $errorMessage = 'Jumlah stok harus berupa angka yang valid.';
    } elseif (!is_string($reasonInput) || !stock_reason_is_valid($reasonInput)) {
        $errorMessage = 'Alasan wajib diisi, tidak boleh berisi karakter kontrol, dan maksimal 240 karakter.';
    } elseif ($action === 'adjustment' && !isset($_POST['direction'])) {
        $errorMessage = 'Pilih arah penyesuaian stok.';
    } elseif ($action === 'adjustment' && !in_array($_POST['direction'], ['increase', 'decrease'], true)) {
        $errorMessage = 'Arah penyesuaian stok tidak valid.';
    }

    $quantityMilli = is_string($quantityInput) ? stock_quantity_to_milli(trim($quantityInput)) : false;
    if ($errorMessage === '' && ($quantityMilli === false || $quantityMilli <= 0)) {
        $errorMessage = 'Jumlah harus lebih dari nol dan maksimal memiliki 3 angka desimal.';
    }

    if ($errorMessage === '' && $action === 'adjustment' && $_POST['direction'] === 'decrease') {
        $quantityMilli = -$quantityMilli;
    }

    if ($errorMessage === '') {
        try {
            $pdo->beginTransaction();

            $productStatement = $pdo->prepare(
                'SELECT id, name, stock, unit FROM products WHERE id = :id FOR UPDATE'
            );
            $productStatement->execute(['id' => $productId]);
            $product = $productStatement->fetch();

            if ($product === false) {
                throw new DomainException('Produk tidak ditemukan.');
            }

            $stockMilli = stock_quantity_to_milli((string) $product['stock']);
            if ($stockMilli === false) {
                throw new RuntimeException('Nilai stok produk tidak valid.');
            }

            if (stock_unit_requires_whole_quantity((string) $product['unit'])
                && ($quantityMilli % 1000 !== 0 || $stockMilli % 1000 !== 0)
            ) {
                throw new DomainException('Satuan ' . $product['unit'] . ' harus menggunakan jumlah bilangan bulat.');
            }

            $newStockMilli = $stockMilli + $quantityMilli;
            if ($newStockMilli < 0) {
                throw new DomainException('Stok tidak mencukupi. Stok tidak boleh menjadi negatif.');
            }
            if ($newStockMilli > 999999999999) {
                throw new DomainException('Jumlah stok melebihi kapasitas penyimpanan.');
            }

            $movementReason = ($action === 'stock_in' ? 'Stock-in: ' : 'Adjustment: ') . trim($reasonInput);
            if (preg_match_all('/./us', $movementReason, $reasonCharacters) > 255) {
                throw new DomainException('Alasan terlalu panjang setelah ditambahkan jenis operasinya.');
            }

            $updateStatement = $pdo->prepare('UPDATE products SET stock = :stock WHERE id = :id');
            $updateStatement->execute([
                'stock' => stock_milli_to_decimal($newStockMilli),
                'id' => $productId,
            ]);

            $movementStatement = $pdo->prepare(
                'INSERT INTO stock_movements
                 (product_id, actor_user_id, movement_type, quantity_change, reason, transaction_id)
                 VALUES (:product_id, :actor_user_id, :movement_type, :quantity_change, :reason, NULL)'
            );
            $movementStatement->execute([
                'product_id' => $productId,
                'actor_user_id' => current_user()['id'],
                'movement_type' => 'admin_adjustment',
                'quantity_change' => stock_milli_to_decimal($quantityMilli),
                'reason' => $movementReason,
            ]);

            $pdo->commit();
            set_flash_message(
                'success',
                ($action === 'stock_in' ? 'Stock-in' : 'Adjustment') . ' berhasil dicatat untuk ' . $product['name'] . '.'
            );
            header('Location: ' . app_url('admin/stock.php'));
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
            error_log('Admin stock update error: ' . $exception->getMessage());
            $errorMessage = 'Perubahan stok gagal disimpan. Tidak ada perubahan yang diterapkan.';
        }
    }
}

$selectedProductId = '';
if (isset($_GET['product_id']) && is_string($_GET['product_id'])) {
    $filteredProductId = filter_var($_GET['product_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($filteredProductId !== false) {
        $selectedProductId = (string) $filteredProductId;
    }
}
$selectedMovementType = 'all';
if (isset($_GET['movement_type']) && is_string($_GET['movement_type'])
    && in_array($_GET['movement_type'], $movementTypes, true)
) {
    $selectedMovementType = $_GET['movement_type'];
}

$products = $pdo->query(
    'SELECT id, name, stock, minimum_stock, unit, status FROM products ORDER BY name ASC'
)->fetchAll();

$movementSql =
    'SELECT sm.id, sm.movement_type, sm.quantity_change, sm.reason, sm.created_at,
            p.name AS product_name, p.unit, u.full_name AS actor_name
     FROM stock_movements AS sm
     INNER JOIN products AS p ON p.id = sm.product_id
     INNER JOIN users AS u ON u.id = sm.actor_user_id';
$conditions = [];
$parameters = [];
if ($selectedProductId !== '') {
    $conditions[] = 'sm.product_id = :product_id';
    $parameters['product_id'] = $selectedProductId;
}
if ($selectedMovementType !== 'all') {
    $conditions[] = 'sm.movement_type = :movement_type';
    $parameters['movement_type'] = $selectedMovementType;
}
if ($conditions !== []) {
    $movementSql .= ' WHERE ' . implode(' AND ', $conditions);
}
$movementSql .= ' ORDER BY sm.created_at DESC, sm.id DESC LIMIT 100';
$movementStatement = $pdo->prepare($movementSql);
$movementStatement->execute($parameters);
$movements = $movementStatement->fetchAll();

$pageTitle = 'Manajemen Stok';
require __DIR__ . '/../includes/header.php';
?>
<p class="page-intro">Pantau kondisi stok dan catat penambahan atau penyesuaian stok oleh Admin.</p>

<?php if (is_array($flashMessage = take_flash_message())): ?>
    <div class="alert <?= ($flashMessage['type'] ?? '') === 'success' ? 'alert-success' : 'alert-error' ?>" role="status">
        <?= escape_html(is_string($flashMessage['message'] ?? null) ? $flashMessage['message'] : '') ?>
    </div>
<?php endif; ?>
<?php if ($errorMessage !== ''): ?>
    <div class="alert alert-error" role="alert"><?= escape_html($errorMessage) ?></div>
<?php endif; ?>

<div class="stock-panels">
    <section class="panel">
        <div class="panel-heading">
            <div><h2>Manual stock-in</h2><p>Menambah jumlah stok dan mencatat alasan penerimaan.</p></div>
        </div>
        <form method="post" action="<?= escape_html(app_url('admin/stock.php')) ?>" class="stock-form">
            <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
            <input type="hidden" name="action" value="stock_in">
            <label for="stock-in-product">Produk</label>
            <select id="stock-in-product" name="product_id" required>
                <option value="">Pilih produk</option>
                <?php foreach ($products as $product): ?>
                    <option value="<?= (int) $product['id'] ?>"><?= escape_html($product['name'] . ' (' . $product['stock'] . ' ' . $product['unit'] . ')') ?></option>
                <?php endforeach; ?>
            </select>
            <label for="stock-in-quantity">Jumlah masuk</label>
            <input id="stock-in-quantity" name="quantity" type="number" min="0.001" step="0.001" required>
            <label for="stock-in-reason">Alasan</label>
            <textarea id="stock-in-reason" name="reason" maxlength="240" required></textarea>
            <button class="primary-button" type="submit">Catat stock-in</button>
        </form>
    </section>

    <section class="panel">
        <div class="panel-heading">
            <div><h2>Stock adjustment</h2><p>Tambah atau kurangi stok dengan alasan yang jelas.</p></div>
        </div>
        <form method="post" action="<?= escape_html(app_url('admin/stock.php')) ?>" class="stock-form">
            <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
            <input type="hidden" name="action" value="adjustment">
            <label for="adjust-product">Produk</label>
            <select id="adjust-product" name="product_id" required>
                <option value="">Pilih produk</option>
                <?php foreach ($products as $product): ?>
                    <option value="<?= (int) $product['id'] ?>"><?= escape_html($product['name'] . ' (' . $product['stock'] . ' ' . $product['unit'] . ')') ?></option>
                <?php endforeach; ?>
            </select>
            <label for="adjust-direction">Arah penyesuaian</label>
            <select id="adjust-direction" name="direction" required>
                <option value="increase">Tambah</option>
                <option value="decrease">Kurangi</option>
            </select>
            <label for="adjust-quantity">Jumlah penyesuaian</label>
            <input id="adjust-quantity" name="quantity" type="number" min="0.001" step="0.001" required>
            <label for="adjust-reason">Alasan</label>
            <textarea id="adjust-reason" name="reason" maxlength="240" required></textarea>
            <button class="primary-button" type="submit">Simpan adjustment</button>
        </form>
    </section>
</div>

<section class="panel stock-overview-panel">
    <div class="panel-heading"><div><h2>Overview stok</h2><p><?= number_format(count($products)) ?> produk</p></div></div>
    <?php if ($products === []): ?>
        <div class="empty-state"><strong>Belum ada produk</strong><p>Produk akan muncul di sini setelah dibuat oleh Admin.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Produk</th><th>Stok</th><th>Minimum</th><th>Kondisi</th><th>Status produk</th></tr></thead>
                <tbody>
                <?php foreach ($products as $product): ?>
                    <?php
                    $stockMilli = stock_quantity_to_milli((string) $product['stock']);
                    $minimumMilli = stock_quantity_to_milli((string) $product['minimum_stock']);
                    $stockCondition = $stockMilli === 0 ? 'Habis' : ($stockMilli <= $minimumMilli ? 'Menipis' : 'Aman');
                    $conditionClass = $stockCondition === 'Aman' ? 'stock-safe' : ($stockCondition === 'Menipis' ? 'stock-low' : 'stock-empty');
                    ?>
                    <tr>
                        <td><?= escape_html($product['name']) ?></td>
                        <td><?= escape_html((string) $product['stock']) ?> <?= escape_html($product['unit']) ?></td>
                        <td><?= escape_html((string) $product['minimum_stock']) ?> <?= escape_html($product['unit']) ?></td>
                        <td><span class="stock-condition <?= $conditionClass ?>"><?= escape_html($stockCondition) ?></span></td>
                        <td><?= $product['status'] === 'active' ? 'Aktif' : 'Nonaktif' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="panel stock-history-panel">
    <div class="panel-heading stock-history-heading">
        <div><h2>Riwayat pergerakan stok</h2><p>Menampilkan maksimal 100 catatan terbaru.</p></div>
        <form method="get" action="<?= escape_html(app_url('admin/stock.php')) ?>" class="stock-filter-form">
            <label for="filter-product">Produk</label>
            <select id="filter-product" name="product_id">
                <option value="">Semua produk</option>
                <?php foreach ($products as $product): ?>
                    <option value="<?= (int) $product['id'] ?>" <?= $selectedProductId === (string) $product['id'] ? 'selected' : '' ?>><?= escape_html($product['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <label for="filter-type">Jenis</label>
            <select id="filter-type" name="movement_type">
                <option value="all" <?= $selectedMovementType === 'all' ? 'selected' : '' ?>>Semua jenis</option>
                <option value="admin_adjustment" <?= $selectedMovementType === 'admin_adjustment' ? 'selected' : '' ?>>Admin: stock-in / adjustment</option>
                <option value="supplier_in" <?= $selectedMovementType === 'supplier_in' ? 'selected' : '' ?>>Supplier stock-in</option>
                <option value="sale_out" <?= $selectedMovementType === 'sale_out' ? 'selected' : '' ?>>Penjualan</option>
            </select>
            <button class="secondary-button" type="submit">Filter</button>
            <?php if ($selectedProductId !== '' || $selectedMovementType !== 'all'): ?>
                <a class="cancel-link" href="<?= escape_html(app_url('admin/stock.php')) ?>">Reset</a>
            <?php endif; ?>
        </form>
    </div>
    <?php if ($movements === []): ?>
        <div class="empty-state"><strong>Belum ada pergerakan stok</strong><p>Perubahan stok yang tercatat akan muncul di sini.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tanggal</th><th>Produk</th><th>Jenis</th><th>Perubahan</th><th>Alasan</th><th>Oleh</th></tr></thead>
                <tbody>
                <?php foreach ($movements as $movement): ?>
                    <?php
                    $movementLabel = match ($movement['movement_type']) {
                        'admin_adjustment' => str_starts_with((string) $movement['reason'], 'Stock-in: ') ? 'Manual stock-in' : 'Stock adjustment',
                        'supplier_in' => 'Supplier stock-in',
                        'sale_out' => 'Penjualan',
                        default => 'Lainnya',
                    };
                    ?>
                    <tr>
                        <td><?= escape_html(date('d/m/Y H:i', strtotime($movement['created_at']))) ?></td>
                        <td><?= escape_html($movement['product_name']) ?></td>
                        <td><?= escape_html($movementLabel) ?></td>
                        <td><?= escape_html((string) $movement['quantity_change']) ?> <?= escape_html($movement['unit']) ?></td>
                        <td><?= escape_html($movement['reason'] ?? '') ?></td>
                        <td><?= escape_html($movement['actor_name']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
