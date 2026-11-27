<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_role('cashier');
require_once __DIR__ . '/../config/database.php';

const SALES_MAX_TOTAL_CENTS = 99999999999999;

function sales_money_to_cents(string $value): int|false
{
    if (preg_match('/^([0-9]{1,10})(?:\.([0-9]{1,2}))?$/D', $value, $matches) !== 1) {
        return false;
    }

    return ((int) $matches[1] * 100) + (int) str_pad($matches[2] ?? '', 2, '0');
}

function sales_cents_to_decimal(int $cents): string
{
    return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
}

function sales_cents_to_rupiah(int $cents): string
{
    return 'Rp ' . number_format($cents / 100, 2, ',', '.');
}

// Returns quantity * unit price in cents, rounded half-up to the nearest cent.
// Quantity is in milli-units (1.500 kg = 1500).
function sales_quantity_cents(int $unitCents, int $quantityMilli): int|false
{
    if ($unitCents > 0 && $quantityMilli > intdiv(PHP_INT_MAX - 500, $unitCents)) {
        return false;
    }

    return intdiv(($unitCents * $quantityMilli) + 500, 1000);
}

function sales_fetch_product(PDO $pdo, int $productId, bool $lock): array|false
{
    $sql = 'SELECT id, name, selling_price, discount_percent, stock, unit, status FROM products WHERE id = :id';
    if ($lock) {
        $sql .= ' FOR UPDATE';
    }

    $statement = $pdo->prepare($sql);
    $statement->execute(['id' => $productId]);
    return $statement->fetch();
}

// Throws DomainException when the quantity is not allowed. Returns the current stock in milli-units.
function sales_check_quantity(array $product, int $quantityMilli): int
{
    if (stock_unit_requires_whole_quantity((string) $product['unit']) && $quantityMilli % 1000 !== 0) {
        throw new DomainException('Satuan ' . $product['unit'] . ' harus menggunakan jumlah bilangan bulat.');
    }

    $stockMilli = stock_quantity_to_milli((string) $product['stock']);
    if ($stockMilli === false) {
        throw new RuntimeException('Nilai stok produk tidak valid.');
    }

    if ($quantityMilli > $stockMilli) {
        throw new DomainException(
            'Stok ' . $product['name'] . ' tidak mencukupi. Sisa stok: ' . $product['stock'] . ' ' . $product['unit'] . '.'
        );
    }

    return $stockMilli;
}

// Builds one cart line from the current product data. Prices and discounts always come from the database.
function sales_build_line(array $product, int $quantityMilli): array|false
{
    $sellingCents = sales_money_to_cents((string) $product['selling_price']);
    $unitCents = sales_money_to_cents(
        product_discounted_price((string) $product['selling_price'], (string) $product['discount_percent'])
    );
    if ($sellingCents === false || $unitCents === false) {
        return false;
    }

    $grossCents = sales_quantity_cents($sellingCents, $quantityMilli);
    $subtotalCents = sales_quantity_cents($unitCents, $quantityMilli);
    if ($grossCents === false || $subtotalCents === false) {
        return false;
    }

    return [
        'product_id' => (int) $product['id'],
        'name' => (string) $product['name'],
        'unit' => (string) $product['unit'],
        'discount_percent' => (string) $product['discount_percent'],
        'quantity_milli' => $quantityMilli,
        'selling_cents' => $sellingCents,
        'unit_cents' => $unitCents,
        'gross_cents' => $grossCents,
        'subtotal_cents' => $subtotalCents,
    ];
}

// Saves the sale in one transaction. Locks each product row, re-checks stock, then writes
// the transaction, its details, the sale_out stock movements, and the new stock values.
function sales_checkout(PDO $pdo, array $cart, int $cashierId, int $amountPaidCents): array
{
    if ($cart === []) {
        throw new DomainException('Keranjang masih kosong.');
    }

    ksort($cart);
    $pdo->beginTransaction();

    try {
        $lines = [];
        $totalCents = 0;
        $totalDiscountCents = 0;

        foreach ($cart as $productId => $quantityMilli) {
            $product = sales_fetch_product($pdo, (int) $productId, true);
            if ($product === false || $product['status'] !== 'active') {
                throw new DomainException('Ada produk di keranjang yang tidak tersedia. Hapus produk tersebut lalu coba lagi.');
            }

            $stockMilli = sales_check_quantity($product, $quantityMilli);
            $line = sales_build_line($product, $quantityMilli);
            if ($line === false) {
                throw new DomainException('Jumlah transaksi terlalu besar.');
            }

            $line['stock_after_milli'] = $stockMilli - $quantityMilli;
            $lines[] = $line;
            $totalCents += $line['subtotal_cents'];
            $totalDiscountCents += $line['gross_cents'] - $line['subtotal_cents'];
        }

        if ($totalCents > SALES_MAX_TOTAL_CENTS) {
            throw new DomainException('Total transaksi terlalu besar.');
        }
        if ($amountPaidCents < $totalCents) {
            throw new DomainException('Uang yang dibayarkan kurang dari total belanja.');
        }

        $changeCents = $amountPaidCents - $totalCents;
        $transactionNumber = 'TRX-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));

        $transactionStatement = $pdo->prepare(
            'INSERT INTO transactions
             (transaction_number, cashier_user_id, total_amount, total_discount, amount_paid, change_amount, payment_method)
             VALUES (:transaction_number, :cashier_user_id, :total_amount, :total_discount, :amount_paid, :change_amount, :payment_method)'
        );
        $transactionStatement->execute([
            'transaction_number' => $transactionNumber,
            'cashier_user_id' => $cashierId,
            'total_amount' => sales_cents_to_decimal($totalCents),
            'total_discount' => sales_cents_to_decimal($totalDiscountCents),
            'amount_paid' => sales_cents_to_decimal($amountPaidCents),
            'change_amount' => sales_cents_to_decimal($changeCents),
            'payment_method' => 'cash',
        ]);
        $transactionId = (int) $pdo->lastInsertId();

        $detailStatement = $pdo->prepare(
            'INSERT INTO transaction_details
             (transaction_id, product_id, product_name_snapshot, selling_price_snapshot, discount_percent_snapshot,
              unit_price_after_discount, unit_snapshot, quantity, subtotal)
             VALUES (:transaction_id, :product_id, :product_name, :selling_price, :discount_percent,
                     :unit_price, :unit, :quantity, :subtotal)'
        );
        $stockStatement = $pdo->prepare('UPDATE products SET stock = :stock WHERE id = :id');
        $movementStatement = $pdo->prepare(
            "INSERT INTO stock_movements
             (product_id, actor_user_id, movement_type, quantity_change, reason, transaction_id)
             VALUES (:product_id, :actor_user_id, 'sale_out', :quantity_change, NULL, :transaction_id)"
        );

        foreach ($lines as $line) {
            $detailStatement->execute([
                'transaction_id' => $transactionId,
                'product_id' => $line['product_id'],
                'product_name' => $line['name'],
                'selling_price' => sales_cents_to_decimal($line['selling_cents']),
                'discount_percent' => $line['discount_percent'],
                'unit_price' => sales_cents_to_decimal($line['unit_cents']),
                'unit' => $line['unit'],
                'quantity' => stock_milli_to_decimal($line['quantity_milli']),
                'subtotal' => sales_cents_to_decimal($line['subtotal_cents']),
            ]);

            $stockStatement->execute([
                'stock' => stock_milli_to_decimal($line['stock_after_milli']),
                'id' => $line['product_id'],
            ]);

            $movementStatement->execute([
                'product_id' => $line['product_id'],
                'actor_user_id' => $cashierId,
                'quantity_change' => '-' . stock_milli_to_decimal($line['quantity_milli']),
                'transaction_id' => $transactionId,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return [
        'transaction_number' => $transactionNumber,
        'total_cents' => $totalCents,
        'discount_cents' => $totalDiscountCents,
        'paid_cents' => $amountPaidCents,
        'change_cents' => $changeCents,
        'lines' => array_map(
            static fn (array $line): array => [
                'name' => $line['name'],
                'quantity' => stock_milli_to_decimal($line['quantity_milli']) . ' ' . $line['unit'],
                'unit_cents' => $line['unit_cents'],
                'subtotal_cents' => $line['subtotal_cents'],
            ],
            $lines
        ),
    ];
}

$pdo = database_connection();
$cashierId = (int) current_user()['id'];

$cart = $_SESSION['sales_cart'] ?? [];
if (!is_array($cart)) {
    $cart = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash_message('error', 'Form tidak valid atau sudah kedaluwarsa. Silakan coba lagi.');
        header('Location: ' . app_url('cashier/sales.php'));
        exit;
    }

    $action = $_POST['action'] ?? null;
    $rawProductId = $_POST['product_id'] ?? null;
    $productId = is_string($rawProductId)
        ? filter_var($rawProductId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;
    $quantityInput = $_POST['quantity'] ?? null;
    $quantityMilli = is_string($quantityInput) ? stock_quantity_to_milli(trim($quantityInput)) : false;
    $amountInput = $_POST['amount_paid'] ?? null;
    $amountPaidCents = is_string($amountInput) ? sales_money_to_cents(trim($amountInput)) : false;

    try {
        if ($action === 'add' || $action === 'update') {
            if ($productId === false) {
                throw new DomainException('Pilih produk yang valid.');
            }
            if ($quantityMilli === false || $quantityMilli <= 0) {
                throw new DomainException('Jumlah harus lebih dari nol dan maksimal memiliki 3 angka desimal.');
            }

            $product = sales_fetch_product($pdo, $productId, false);
            if ($product === false || $product['status'] !== 'active') {
                throw new DomainException('Produk tidak tersedia.');
            }

            if ($action === 'add') {
                $newQuantityMilli = ($cart[$productId] ?? 0) + $quantityMilli;
            } else {
                if (!isset($cart[$productId])) {
                    throw new DomainException('Produk tidak ada di keranjang.');
                }
                $newQuantityMilli = $quantityMilli;
            }

            sales_check_quantity($product, $newQuantityMilli);
            $cart[$productId] = $newQuantityMilli;
            set_flash_message('success', 'Keranjang diperbarui.');
        } elseif ($action === 'remove') {
            if ($productId === false) {
                throw new DomainException('Produk yang akan dihapus tidak valid.');
            }
            unset($cart[$productId]);
            set_flash_message('success', 'Produk dihapus dari keranjang.');
        } elseif ($action === 'clear') {
            $cart = [];
            set_flash_message('success', 'Keranjang dikosongkan.');
        } elseif ($action === 'checkout') {
            if ($amountPaidCents === false) {
                throw new DomainException('Uang bayar harus berupa angka dengan maksimal 2 angka desimal.');
            }

            $_SESSION['sales_receipt'] = sales_checkout($pdo, $cart, $cashierId, $amountPaidCents);
            $cart = [];
        } else {
            throw new DomainException('Aksi penjualan tidak valid.');
        }
    } catch (DomainException $exception) {
        set_flash_message('error', $exception->getMessage());
    } catch (Throwable $exception) {
        error_log('Cashier sales error: ' . $exception->getMessage());
        set_flash_message('error', 'Transaksi gagal disimpan. Tidak ada perubahan yang diterapkan.');
    }

    $_SESSION['sales_cart'] = $cart;
    header('Location: ' . app_url('cashier/sales.php'));
    exit;
}

$receipt = $_SESSION['sales_receipt'] ?? null;
unset($_SESSION['sales_receipt']);
$flashMessage = take_flash_message();

$cartRows = [];
$cartTotalCents = 0;
$cartDiscountCents = 0;
$cartHasIssue = false;
foreach ($cart as $productId => $quantityMilli) {
    $product = sales_fetch_product($pdo, (int) $productId, false);
    $row = [
        'product_id' => (int) $productId,
        'quantity_milli' => $quantityMilli,
        'name' => 'Produk tidak tersedia',
        'unit' => '',
        'line' => false,
        'issue' => '',
    ];

    if ($product === false || $product['status'] !== 'active') {
        $row['issue'] = 'Produk ini tidak aktif atau sudah tidak ada. Hapus dari keranjang.';
    } else {
        $row['name'] = (string) $product['name'];
        $row['unit'] = (string) $product['unit'];
        try {
            sales_check_quantity($product, $quantityMilli);
        } catch (DomainException $exception) {
            $row['issue'] = $exception->getMessage();
        }
        $row['line'] = sales_build_line($product, $quantityMilli);
        if ($row['line'] === false) {
            $row['issue'] = 'Jumlah transaksi terlalu besar.';
        }
    }

    if ($row['issue'] !== '') {
        $cartHasIssue = true;
    } else {
        $cartTotalCents += $row['line']['subtotal_cents'];
        $cartDiscountCents += $row['line']['gross_cents'] - $row['line']['subtotal_cents'];
    }
    $cartRows[] = $row;
}

$products = $pdo->query(
    "SELECT id, name, selling_price, discount_percent, stock, unit
     FROM products
     WHERE status = 'active' AND stock > 0
     ORDER BY name ASC"
)->fetchAll();

$pageTitle = 'Penjualan';
require __DIR__ . '/../includes/header.php';
?>
<p class="page-intro">Catat penjualan tunai. Harga dan diskon diambil dari data produk saat ini.</p>

<?php if (is_array($flashMessage)): ?>
    <div class="alert <?= ($flashMessage['type'] ?? '') === 'success' ? 'alert-success' : 'alert-error' ?>" role="status">
        <?= escape_html(is_string($flashMessage['message'] ?? null) ? $flashMessage['message'] : '') ?>
    </div>
<?php endif; ?>

<?php if (is_array($receipt)): ?>
    <section class="panel sales-receipt-panel">
        <div class="panel-heading">
            <div>
                <h2>Transaksi berhasil</h2>
                <p>No. transaksi: <?= escape_html($receipt['transaction_number']) ?></p>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Produk</th><th>Jumlah</th><th class="number-cell">Harga satuan</th><th class="number-cell">Subtotal</th></tr></thead>
                <tbody>
                <?php foreach ($receipt['lines'] as $receiptLine): ?>
                    <tr>
                        <td><?= escape_html($receiptLine['name']) ?></td>
                        <td><?= escape_html($receiptLine['quantity']) ?></td>
                        <td class="number-cell"><?= escape_html(sales_cents_to_rupiah($receiptLine['unit_cents'])) ?></td>
                        <td class="number-cell"><?= escape_html(sales_cents_to_rupiah($receiptLine['subtotal_cents'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p>Total: <strong><?= escape_html(sales_cents_to_rupiah($receipt['total_cents'])) ?></strong></p>
        <p>Diskon: <?= escape_html(sales_cents_to_rupiah($receipt['discount_cents'])) ?></p>
        <p>Dibayar: <?= escape_html(sales_cents_to_rupiah($receipt['paid_cents'])) ?></p>
        <p>Kembalian: <strong><?= escape_html(sales_cents_to_rupiah($receipt['change_cents'])) ?></strong></p>
    </section>
<?php endif; ?>

<section class="panel">
    <div class="panel-heading">
        <div><h2>Tambah produk</h2><p>Pilih produk dan jumlah yang dibeli.</p></div>
    </div>
    <?php if ($products === []): ?>
        <div class="empty-state"><strong>Tidak ada produk yang dijual</strong><p>Produk aktif dengan stok akan muncul di sini.</p></div>
    <?php else: ?>
        <form method="post" action="<?= escape_html(app_url('cashier/sales.php')) ?>" class="stacked-form">
            <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
            <input type="hidden" name="action" value="add">
            <label for="sale-product">Produk</label>
            <select id="sale-product" name="product_id" required>
                <option value="">Pilih produk</option>
                <?php foreach ($products as $product): ?>
                    <?php $unitCents = sales_money_to_cents(product_discounted_price((string) $product['selling_price'], (string) $product['discount_percent'])); ?>
                    <option value="<?= (int) $product['id'] ?>"><?= escape_html($product['name'] . ' (' . sales_cents_to_rupiah((int) $unitCents) . ', stok ' . $product['stock'] . ' ' . $product['unit'] . ')') ?></option>
                <?php endforeach; ?>
            </select>
            <label for="sale-quantity">Jumlah</label>
            <input id="sale-quantity" name="quantity" type="number" min="0.001" step="0.001" required>
            <button class="primary-button" type="submit">Tambah ke keranjang</button>
        </form>
    <?php endif; ?>
</section>

<section class="panel">
    <div class="panel-heading">
        <div><h2>Keranjang</h2><p><?= number_format(count($cartRows)) ?> produk</p></div>
        <?php if ($cartRows !== []): ?>
            <form method="post" action="<?= escape_html(app_url('cashier/sales.php')) ?>">
                <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                <input type="hidden" name="action" value="clear">
                <button class="text-button text-button-danger" type="submit">Kosongkan</button>
            </form>
        <?php endif; ?>
    </div>
    <?php if ($cartRows === []): ?>
        <div class="empty-state"><strong>Keranjang kosong</strong><p>Tambahkan produk untuk memulai penjualan.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Produk</th><th>Jumlah</th><th class="number-cell">Harga satuan</th><th class="number-cell">Subtotal</th><th class="actions-cell">Aksi</th></tr></thead>
                <tbody>
                <?php foreach ($cartRows as $row): ?>
                    <tr>
                        <td>
                            <?= escape_html($row['name']) ?>
                            <?php if ($row['issue'] !== ''): ?>
                                <span class="unit-note"><?= escape_html($row['issue']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post" action="<?= escape_html(app_url('cashier/sales.php')) ?>">
                                <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="product_id" value="<?= (int) $row['product_id'] ?>">
                                <input name="quantity" type="number" min="0.001" step="0.001" required value="<?= escape_html(stock_milli_to_decimal($row['quantity_milli'])) ?>">
                                <?= escape_html($row['unit']) ?>
                                <button class="text-button" type="submit">Ubah</button>
                            </form>
                        </td>
                        <td class="number-cell"><?= $row['line'] !== false ? escape_html(sales_cents_to_rupiah($row['line']['unit_cents'])) : '-' ?></td>
                        <td class="number-cell"><?= $row['line'] !== false && $row['issue'] === '' ? escape_html(sales_cents_to_rupiah($row['line']['subtotal_cents'])) : '-' ?></td>
                        <td class="actions-cell">
                            <form method="post" action="<?= escape_html(app_url('cashier/sales.php')) ?>">
                                <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="product_id" value="<?= (int) $row['product_id'] ?>">
                                <button class="text-button text-button-danger" type="submit">Hapus</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <p>Total: <strong><?= escape_html(sales_cents_to_rupiah($cartTotalCents)) ?></strong></p>
        <p>Diskon: <?= escape_html(sales_cents_to_rupiah($cartDiscountCents)) ?></p>
        <?php if ($cartHasIssue): ?>
            <div class="alert alert-error" role="alert">Hapus atau perbaiki produk yang bermasalah sebelum checkout.</div>
        <?php endif; ?>

        <form method="post" action="<?= escape_html(app_url('cashier/sales.php')) ?>" class="stacked-form">
            <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
            <input type="hidden" name="action" value="checkout">
            <label for="amount-paid">Uang bayar tunai (Rp)</label>
            <input id="amount-paid" name="amount_paid" type="number" min="0" step="0.01" required>
            <button class="primary-button" type="submit" <?= $cartHasIssue ? 'disabled' : '' ?>>Bayar dan simpan transaksi</button>
        </form>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
