<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
require_once __DIR__ . '/../config/database.php';

function product_decimal_is_valid(string $value, int $decimalPlaces): bool
{
    if (preg_match('/^([0-9]+)(?:\.([0-9]{1,' . $decimalPlaces . '}))?$/D', $value, $matches) !== 1) {
        return false;
    }

    $wholePart = ltrim($matches[1], '0');
    $wholePart = $wholePart === '' ? '0' : $wholePart;
    $maximumWholePart = $decimalPlaces === 2 ? '9999999999' : '999999999';
    if (strlen($wholePart) > strlen($maximumWholePart)
        || (strlen($wholePart) === strlen($maximumWholePart) && strcmp($wholePart, $maximumWholePart) > 0)
    ) {
        return false;
    }

    return true;
}

function product_quantity_must_be_whole(string $unit): bool
{
    return in_array($unit, ['pcs', 'pack', 'box', 'botol', 'ikat'], true);
}

function product_quantity_has_fraction(string $quantity): bool
{
    if (!str_contains($quantity, '.')) {
        return false;
    }

    [, $fraction] = explode('.', $quantity, 2);
    return trim($fraction, '0') !== '';
}

function product_discounted_price(string $sellingPrice, string $discountPercent): string
{
    [$wholePrice, $fractionPrice] = array_pad(explode('.', $sellingPrice, 2), 2, '');
    [$wholeDiscount, $fractionDiscount] = array_pad(explode('.', $discountPercent, 2), 2, '');
    $priceCents = ((int) $wholePrice * 100) + (int) str_pad($fractionPrice, 2, '0');
    $discountBasisPoints = ((int) $wholeDiscount * 100) + (int) str_pad($fractionDiscount, 2, '0');
    $discountedCents = intdiv(($priceCents * (10000 - $discountBasisPoints)) + 5000, 10000);

    return intdiv($discountedCents, 100) . '.' . str_pad((string) ($discountedCents % 100), 2, '0', STR_PAD_LEFT);
}

function product_form_values(array $source): array
{
    $fieldNames = [
        'name', 'category_id', 'purchase_price', 'selling_price', 'discount_percent',
        'stock', 'minimum_stock', 'unit', 'status',
    ];
    $values = [];

    foreach ($fieldNames as $fieldName) {
        $value = $source[$fieldName] ?? '';
        $values[$fieldName] = is_string($value) || (is_int($value) && $fieldName === 'category_id')
            ? trim((string) $value)
            : '';
    }

    return $values;
}

function product_limit_search_chars(string $value, int $maximumCharacters): string
{
    if (preg_match('//u', $value) !== 1) {
        return '';
    }

    if (preg_match_all('/./us', $value, $characters) === false) {
        return '';
    }

    return implode('', array_slice($characters[0], 0, $maximumCharacters));
}

$pdo = database_connection();
$allowedUnits = ['pcs', 'pack', 'box', 'botol', 'kg', 'gram', 'liter', 'ml', 'ikat'];
$allowedStatuses = ['active', 'inactive'];
$formError = '';
$editingProduct = null;
$search = '';
$formMode = 'create';
$formValues = [
    'name' => '',
    'category_id' => '',
    'purchase_price' => '',
    'selling_price' => '',
    'discount_percent' => '0',
    'stock' => '',
    'minimum_stock' => '0',
    'unit' => 'pcs',
    'status' => 'active',
];

if (isset($_GET['q'])) {
    $rawSearch = $_GET['q'];
    if (is_string($rawSearch)) {
        $search = trim(product_limit_search_chars($rawSearch, 120));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash_message('error', 'Form tidak valid atau sudah kedaluwarsa. Silakan coba lagi.');
        header('Location: ' . app_url('admin/products.php'));
        exit;
    }

    $action = $_POST['action'] ?? null;
    if (!is_string($action) || !in_array($action, ['create', 'update'], true)) {
        set_flash_message('error', 'Permintaan produk tidak valid.');
        header('Location: ' . app_url('admin/products.php'));
        exit;
    }

    $formMode = $action;
    $formValues = product_form_values($_POST);
    $productId = null;

    if ($action === 'update') {
        $rawId = $_POST['id'] ?? null;
        $productId = is_string($rawId)
            ? filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        if ($productId === false) {
            set_flash_message('error', 'Produk yang akan diedit tidak valid.');
            header('Location: ' . app_url('admin/products.php'));
            exit;
        }
    }

    $productName = $formValues['name'];
    if ($formError === '' && ($productName === '' || preg_match('//u', $productName) !== 1 || preg_match('/[\x00-\x1F\x7F]/u', $productName) === 1)) {
        $formError = 'Nama produk wajib diisi dengan teks yang valid.';
    } elseif ($formError === '' && preg_match_all('/./us', $productName, $matches) > 120) {
        $formError = 'Nama produk maksimal 120 karakter.';
    }

    $categoryId = filter_var($formValues['category_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($formError === '' && $categoryId === false) {
        $formError = 'Pilih kategori yang valid.';
    }

    foreach (['purchase_price', 'selling_price'] as $priceField) {
        if ($formError === '' && !product_decimal_is_valid($formValues[$priceField], 2)) {
            $formError = 'Harga beli dan harga jual harus berupa angka tidak negatif dengan maksimal 2 angka desimal.';
        }
    }

    if ($formError === '' && !product_decimal_is_valid($formValues['discount_percent'], 2)) {
        $formError = 'Diskon harus berupa angka antara 0 dan 100 persen.';
    } elseif ($formError === '' && (float) $formValues['discount_percent'] > 100) {
        $formError = 'Diskon harus berada di antara 0 dan 100 persen.';
    }

    if (!in_array($formValues['unit'], $allowedUnits, true) && $formError === '') {
        $formError = 'Pilih satuan produk yang tersedia.';
    }

    if (!in_array($formValues['status'], $allowedStatuses, true) && $formError === '') {
        $formError = 'Status produk tidak valid.';
    }

    if ($action === 'create') {
        foreach (['stock', 'minimum_stock'] as $stockField) {
            if ($formError === '' && !product_decimal_is_valid($formValues[$stockField], 3)) {
                $formError = 'Stok awal dan stok minimum harus berupa angka tidak negatif dengan maksimal 3 angka desimal.';
            }
        }

        if ($formError === '' && product_quantity_must_be_whole($formValues['unit'])
            && (product_quantity_has_fraction($formValues['stock']) || product_quantity_has_fraction($formValues['minimum_stock']))
        ) {
            $formError = 'Satuan ' . $formValues['unit'] . ' harus menggunakan jumlah bilangan bulat.';
        }
    }

    if ($action === 'update' && $formError === '' && product_quantity_must_be_whole($formValues['unit'])
        && product_quantity_has_fraction($formValues['minimum_stock'])
    ) {
        $formError = 'Stok minimum untuk satuan ' . $formValues['unit'] . ' harus berupa bilangan bulat.';
    }

    if ($formError === '') {
        try {
            $categoryCheck = $pdo->prepare('SELECT id FROM categories WHERE id = :id');
            $categoryCheck->execute(['id' => $categoryId]);
            if ($categoryCheck->fetchColumn() === false) {
                $formError = 'Kategori yang dipilih tidak ditemukan.';
            }

            if ($formError === '' && $action === 'update' && product_quantity_must_be_whole($formValues['unit'])) {
                $stockCheck = $pdo->prepare('SELECT stock FROM products WHERE id = :id');
                $stockCheck->execute(['id' => $productId]);
                $currentStock = $stockCheck->fetchColumn();
                if ($currentStock === false) {
                    $formError = 'Produk yang akan diedit tidak ditemukan.';
                } elseif (product_quantity_has_fraction((string) $currentStock)) {
                    $formError = 'Satuan tidak dapat diubah menjadi ' . $formValues['unit'] . ' karena stok saat ini masih pecahan.';
                }
            }
        } catch (PDOException $exception) {
            error_log('Product category validation error: ' . $exception->getMessage());
            $formError = 'Kategori tidak dapat diverifikasi saat ini.';
        }
    }

    if ($formError === '') {
        try {
            if ($action === 'create') {
                $statement = $pdo->prepare(
                    'INSERT INTO products
                     (category_id, name, purchase_price, selling_price, discount_percent, stock, minimum_stock, unit, status)
                     VALUES (:category_id, :name, :purchase_price, :selling_price, :discount_percent, :stock, :minimum_stock, :unit, :status)'
                );
                $statement->execute([
                    'category_id' => $categoryId,
                    'name' => $productName,
                    'purchase_price' => $formValues['purchase_price'],
                    'selling_price' => $formValues['selling_price'],
                    'discount_percent' => $formValues['discount_percent'],
                    'stock' => $formValues['stock'],
                    'minimum_stock' => $formValues['minimum_stock'],
                    'unit' => $formValues['unit'],
                    'status' => $formValues['status'],
                ]);
                set_flash_message('success', 'Produk berhasil ditambahkan.');
            } else {
                $existsStatement = $pdo->prepare('SELECT id FROM products WHERE id = :id');
                $existsStatement->execute(['id' => $productId]);
                if ($existsStatement->fetchColumn() === false) {
                    set_flash_message('error', 'Produk yang akan diedit tidak ditemukan.');
                    header('Location: ' . app_url('admin/products.php'));
                    exit;
                }

                $statement = $pdo->prepare(
                    'UPDATE products
                     SET category_id = :category_id,
                         name = :name,
                         purchase_price = :purchase_price,
                         selling_price = :selling_price,
                         discount_percent = :discount_percent,
                         minimum_stock = :minimum_stock,
                         unit = :unit,
                         status = :status
                     WHERE id = :id'
                );
                $statement->execute([
                    'category_id' => $categoryId,
                    'name' => $productName,
                    'purchase_price' => $formValues['purchase_price'],
                    'selling_price' => $formValues['selling_price'],
                    'discount_percent' => $formValues['discount_percent'],
                    'minimum_stock' => $formValues['minimum_stock'],
                    'unit' => $formValues['unit'],
                    'status' => $formValues['status'],
                    'id' => $productId,
                ]);
                set_flash_message('success', 'Produk berhasil diperbarui. Stok tidak diubah.');
            }

            header('Location: ' . app_url('admin/products.php'));
            exit;
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000'
                && isset($exception->errorInfo[1])
                && (int) $exception->errorInfo[1] === 1452
            ) {
                $formError = 'Kategori yang dipilih tidak lagi tersedia. Pilih kategori lain.';
            } else {
                error_log('Product save error: ' . $exception->getMessage());
                $formError = 'Produk tidak dapat disimpan saat ini. Periksa kembali data lalu coba lagi.';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit'])) {
    $formMode = 'update';
    $rawId = $_GET['edit'];
    $productId = is_string($rawId)
        ? filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
        : false;
    if ($productId === false) {
        set_flash_message('error', 'Produk yang akan diedit tidak valid.');
        header('Location: ' . app_url('admin/products.php'));
        exit;
    }

    $statement = $pdo->prepare('SELECT * FROM products WHERE id = :id');
    $statement->execute(['id' => $productId]);
    $editingProduct = $statement->fetch() ?: null;
    if ($editingProduct === null) {
        set_flash_message('error', 'Produk yang dipilih tidak ditemukan.');
        header('Location: ' . app_url('admin/products.php'));
        exit;
    }
    $formValues = product_form_values($editingProduct);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $formMode === 'update' && $formError !== '' && is_int($productId) && $productId > 0) {
    $statement = $pdo->prepare('SELECT * FROM products WHERE id = :id');
    $statement->execute(['id' => $productId]);
    $editingProduct = $statement->fetch() ?: null;
    if ($editingProduct === null) {
        set_flash_message('error', 'Produk yang akan diedit tidak ditemukan.');
        header('Location: ' . app_url('admin/products.php'));
        exit;
    }
}

$flashMessage = take_flash_message();
$categoryError = '';
try {
    $categories = $pdo->query('SELECT id, name FROM categories ORDER BY name ASC')->fetchAll();
} catch (PDOException $exception) {
    error_log('Product category list error: ' . $exception->getMessage());
    $categories = [];
    $categoryError = 'Daftar kategori tidak dapat dimuat saat ini. Form produk tidak dapat memilih kategori.';
}

$listError = '';
try {
    if ($search === '') {
        $products = $pdo->query(
            'SELECT p.id, p.name, p.category_id, c.name AS category_name,
                    p.purchase_price, p.selling_price, p.discount_percent, p.stock,
                    p.minimum_stock, p.unit, p.status
             FROM products AS p
             INNER JOIN categories AS c ON c.id = p.category_id
             ORDER BY p.name ASC'
        )->fetchAll();
    } else {
        $statement = $pdo->prepare(
            'SELECT p.id, p.name, p.category_id, c.name AS category_name,
                    p.purchase_price, p.selling_price, p.discount_percent, p.stock,
                    p.minimum_stock, p.unit, p.status
             FROM products AS p
             INNER JOIN categories AS c ON c.id = p.category_id
             WHERE p.name LIKE :search
             ORDER BY p.name ASC'
        );
        $statement->execute(['search' => '%' . $search . '%']);
        $products = $statement->fetchAll();
    }
} catch (PDOException $exception) {
    error_log('Product list error: ' . $exception->getMessage());
    $products = [];
    $listError = 'Daftar produk tidak dapat dimuat saat ini.';
}

$pageTitle = 'Produk';
require __DIR__ . '/../includes/header.php';
?>
<div class="content-heading">
    <div><p class="page-intro">Kelola informasi produk dan diskon. Stok hanya dapat diisi saat produk dibuat.</p></div>
    <a class="button-link" href="<?= escape_html(app_url('admin/products.php')) ?>">Produk baru</a>
</div>

<?php if (is_array($flashMessage)): ?>
    <div class="alert <?= ($flashMessage['type'] ?? '') === 'success' ? 'alert-success' : 'alert-error' ?>" role="status">
        <?= escape_html(is_string($flashMessage['message'] ?? null) ? $flashMessage['message'] : '') ?>
    </div>
<?php endif; ?>
<?php if ($formError !== ''): ?>
    <div class="alert alert-error" role="alert"><?= escape_html($formError) ?></div>
<?php endif; ?>
<?php if ($categoryError !== ''): ?>
    <div class="alert alert-error" role="alert"><?= escape_html($categoryError) ?></div>
<?php endif; ?>

<section class="panel product-form-panel">
    <div class="panel-heading">
        <div>
            <h2><?= $formMode === 'update' && $editingProduct !== null ? 'Edit produk' : 'Tambah produk' ?></h2>
            <p>Harga menggunakan Rupiah. Diskon 0% berarti harga normal.</p>
        </div>
    </div>
    <form method="post" action="<?= escape_html(app_url('admin/products.php')) ?>" class="product-form">
        <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
        <input type="hidden" name="action" value="<?= $formMode === 'update' && $editingProduct !== null ? 'update' : 'create' ?>">
        <?php if ($formMode === 'update' && $editingProduct !== null): ?>
            <input type="hidden" name="id" value="<?= (int) $editingProduct['id'] ?>">
        <?php endif; ?>

        <div class="product-fields">
            <div class="form-field form-field-wide">
                <label for="product-name">Nama produk</label>
                <input id="product-name" name="name" type="text" maxlength="120" required value="<?= escape_html($formValues['name']) ?>">
            </div>
            <div class="form-field">
                <label for="category-id">Kategori</label>
                <select id="category-id" name="category_id" required>
                    <option value="">Pilih kategori</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= (int) $category['id'] ?>" <?= $formValues['category_id'] === (string) $category['id'] ? 'selected' : '' ?>><?= escape_html($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field">
                <label for="product-unit">Satuan</label>
                <select id="product-unit" name="unit" required>
                    <?php foreach ($allowedUnits as $unit): ?>
                        <option value="<?= escape_html($unit) ?>" <?= $formValues['unit'] === $unit ? 'selected' : '' ?>><?= escape_html($unit) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field">
                <label for="purchase-price">Harga beli (Rp)</label>
                <input id="purchase-price" name="purchase_price" type="number" min="0" step="0.01" required value="<?= escape_html($formValues['purchase_price']) ?>">
            </div>
            <div class="form-field">
                <label for="selling-price">Harga jual (Rp)</label>
                <input id="selling-price" name="selling_price" type="number" min="0" step="0.01" required value="<?= escape_html($formValues['selling_price']) ?>">
            </div>
            <div class="form-field">
                <label for="discount-percent">Diskon (%)</label>
                <input id="discount-percent" name="discount_percent" type="number" min="0" max="100" step="0.01" required value="<?= escape_html($formValues['discount_percent']) ?>">
            </div>
            <div class="form-field">
                <label for="minimum-stock">Stok minimum</label>
                <input id="minimum-stock" name="minimum_stock" type="number" min="0" step="0.001" required value="<?= escape_html($formValues['minimum_stock']) ?>">
            </div>
            <?php if ($formMode === 'create'): ?>
                <div class="form-field">
                    <label for="initial-stock">Stok awal</label>
                    <input id="initial-stock" name="stock" type="number" min="0" step="0.001" required value="<?= escape_html($formValues['stock']) ?>">
                </div>
            <?php elseif ($editingProduct !== null): ?>
                <div class="form-field">
                    <label>Stok saat ini</label>
                    <div class="readonly-value"><?= escape_html((string) $editingProduct['stock']) ?> <?= escape_html($editingProduct['unit']) ?> <span>(tidak diubah dari form edit)</span></div>
                </div>
            <?php endif; ?>
            <div class="form-field">
                <label for="product-status">Status produk</label>
                <select id="product-status" name="status" required>
                    <option value="active" <?= $formValues['status'] === 'active' ? 'selected' : '' ?>>Aktif</option>
                    <option value="inactive" <?= $formValues['status'] === 'inactive' ? 'selected' : '' ?>>Nonaktif</option>
                </select>
            </div>
        </div>
        <div class="form-actions">
            <button class="primary-button" type="submit"><?= $formMode === 'update' && $editingProduct !== null ? 'Simpan perubahan' : 'Tambah produk' ?></button>
            <?php if ($formMode === 'update' && $editingProduct !== null): ?>
                <a class="cancel-link" href="<?= escape_html(app_url('admin/products.php')) ?>">Batal edit</a>
            <?php endif; ?>
        </div>
    </form>
</section>

<section class="panel product-list-panel">
    <div class="panel-heading product-list-heading">
        <div>
            <h2>Daftar produk</h2>
            <p><?= number_format(count($products)) ?> produk<?= $search !== '' ? ' ditemukan' : '' ?></p>
        </div>
        <form method="get" action="<?= escape_html(app_url('admin/products.php')) ?>" class="search-form">
            <label class="visually-hidden" for="product-search">Cari nama produk</label>
            <input id="product-search" name="q" type="search" maxlength="120" placeholder="Cari nama produk" value="<?= escape_html($search) ?>">
            <button class="secondary-button" type="submit">Cari</button>
            <?php if ($search !== ''): ?><a class="cancel-link" href="<?= escape_html(app_url('admin/products.php')) ?>">Reset</a><?php endif; ?>
        </form>
    </div>
    <?php if ($listError !== ''): ?>
        <div class="empty-state"><p><?= escape_html($listError) ?></p></div>
    <?php elseif ($products === []): ?>
        <div class="empty-state">
            <strong><?= $search !== '' ? 'Produk tidak ditemukan' : 'Belum ada produk' ?></strong>
            <p><?= $search !== '' ? 'Coba kata pencarian lain.' : 'Tambahkan produk pertama melalui formulir di atas.' ?></p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Produk</th><th>Kategori</th><th>Harga beli</th><th>Harga jual</th>
                    <th>Diskon</th><th>Harga diskon</th><th>Stok</th><th>Minimum</th><th>Status</th><th class="actions-cell">Aksi</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($products as $product): ?>
                    <tr>
                        <td><?= escape_html($product['name']) ?><span class="unit-note"><?= escape_html($product['unit']) ?></span></td>
                        <td><?= escape_html($product['category_name']) ?></td>
                        <td>Rp <?= number_format((float) $product['purchase_price'], 2, ',', '.') ?></td>
                        <td>Rp <?= number_format((float) $product['selling_price'], 2, ',', '.') ?></td>
                        <td><?= escape_html((string) $product['discount_percent']) ?>%</td>
                        <td>Rp <?= number_format((float) product_discounted_price((string) $product['selling_price'], (string) $product['discount_percent']), 2, ',', '.') ?></td>
                        <td><?= escape_html((string) $product['stock']) ?> <?= escape_html($product['unit']) ?></td>
                        <td><?= escape_html((string) $product['minimum_stock']) ?> <?= escape_html($product['unit']) ?></td>
                        <td><span class="status-badge <?= $product['status'] === 'active' ? 'status-active' : 'status-inactive' ?>"><?= $product['status'] === 'active' ? 'Aktif' : 'Nonaktif' ?></span></td>
                        <td class="actions-cell"><a class="text-button" href="<?= escape_html(app_url('admin/products.php?edit=' . (int) $product['id'])) ?>">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
