<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
require_once __DIR__ . '/../config/database.php';

function category_name_validation_error(string $name): ?string
{
    $name = trim($name);

    if ($name === '') {
        return 'Nama kategori wajib diisi.';
    }
    if (preg_match('//u', $name) !== 1) {
        return 'Nama kategori memiliki format teks yang tidak valid.';
    }
    if (preg_match('/[\x00-\x1F\x7F]/u', $name) === 1) {
        return 'Nama kategori tidak boleh berisi karakter kontrol.';
    }
    if (preg_match_all('/./us', $name, $matches) > 80) {
        return 'Nama kategori maksimal 80 karakter.';
    }

    return null;
}

function category_is_duplicate(PDOException $exception): bool
{
    return (string) $exception->getCode() === '23000'
        && isset($exception->errorInfo[1])
        && (int) $exception->errorInfo[1] === 1062;
}

$pdo = database_connection();
$formError = '';
$editingCategory = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        set_flash_message('error', 'Form tidak valid atau sudah kedaluwarsa. Silakan coba lagi.');
        header('Location: ' . app_url('admin/categories.php'));
        exit;
    }

    $action = $_POST['action'] ?? null;
    if (!is_string($action)) {
        set_flash_message('error', 'Permintaan kategori tidak valid.');
        header('Location: ' . app_url('admin/categories.php'));
        exit;
    }

    if ($action === 'create' || $action === 'update') {
        $postedName = $_POST['name'] ?? null;
        if (!is_string($postedName)) {
            $formError = 'Nama kategori harus berupa teks.';
        } else {
            $name = trim($postedName);
            $formError = category_name_validation_error($name) ?? '';
        }

        $categoryId = null;
        if ($action === 'update') {
            $rawId = $_POST['id'] ?? null;
            $categoryId = is_string($rawId) ? filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            if ($categoryId === false) {
                $formError = 'Kategori yang akan diedit tidak valid.';
            }
        }

        if ($formError === '') {
            try {
                if ($action === 'create') {
                    $statement = $pdo->prepare('INSERT INTO categories (name) VALUES (:name)');
                    $statement->execute(['name' => $name]);
                    set_flash_message('success', 'Kategori berhasil ditambahkan.');
                } else {
                    $existsStatement = $pdo->prepare('SELECT id FROM categories WHERE id = :id');
                    $existsStatement->execute(['id' => $categoryId]);
                    if ($existsStatement->fetchColumn() === false) {
                        set_flash_message('error', 'Kategori yang akan diedit tidak ditemukan.');
                        header('Location: ' . app_url('admin/categories.php'));
                        exit;
                    }

                    $statement = $pdo->prepare('UPDATE categories SET name = :name WHERE id = :id');
                    $statement->execute(['name' => $name, 'id' => $categoryId]);
                    set_flash_message('success', 'Kategori berhasil diperbarui.');
                }

                header('Location: ' . app_url('admin/categories.php'));
                exit;
            } catch (PDOException $exception) {
                if (category_is_duplicate($exception)) {
                    $formError = 'Nama kategori tersebut sudah digunakan.';
                } else {
                    error_log('Category save error: ' . $exception->getMessage());
                    $formError = 'Kategori tidak dapat disimpan saat ini. Periksa kembali data lalu coba lagi.';
                }
            }
        }
    } elseif ($action === 'delete') {
        $rawId = $_POST['id'] ?? null;
        $categoryId = is_string($rawId) ? filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;

        if ($categoryId === false) {
            set_flash_message('error', 'Kategori yang akan dihapus tidak valid.');
        } else {
            try {
                $statement = $pdo->prepare('DELETE FROM categories WHERE id = :id');
                $statement->execute(['id' => $categoryId]);
                set_flash_message(
                    $statement->rowCount() === 1 ? 'success' : 'error',
                    $statement->rowCount() === 1
                        ? 'Kategori berhasil dihapus.'
                        : 'Kategori tidak ditemukan.'
                );
            } catch (PDOException $exception) {
                if ((string) $exception->getCode() === '23000' && isset($exception->errorInfo[1]) && (int) $exception->errorInfo[1] === 1451) {
                    set_flash_message('error', 'Kategori tidak dapat dihapus karena masih digunakan oleh produk.');
                } else {
                    error_log('Category delete error: ' . $exception->getMessage());
                    set_flash_message('error', 'Kategori tidak dapat dihapus saat ini.');
                }
            }
        }

        header('Location: ' . app_url('admin/categories.php'));
        exit;
    } else {
        set_flash_message('error', 'Aksi kategori tidak dikenal.');
        header('Location: ' . app_url('admin/categories.php'));
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['edit'])) {
    $rawId = $_GET['edit'];
    $categoryId = is_string($rawId) ? filter_var($rawId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
    if ($categoryId === false) {
        $formError = 'Kategori yang akan diedit tidak valid.';
    } else {
        $statement = $pdo->prepare('SELECT id, name FROM categories WHERE id = :id');
        $statement->execute(['id' => $categoryId]);
        $editingCategory = $statement->fetch() ?: null;
        if ($editingCategory === null) {
            $formError = 'Kategori yang dipilih tidak ditemukan.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? null) === 'update'
    && is_int($categoryId)
    && $categoryId > 0
    && $editingCategory === null
) {
    $statement = $pdo->prepare('SELECT id, name FROM categories WHERE id = :id');
    $statement->execute(['id' => $categoryId]);
    $editingCategory = $statement->fetch() ?: null;
}

$flashMessage = take_flash_message();
try {
    $categories = $pdo->query(
        'SELECT c.id, c.name, c.created_at, COUNT(p.id) AS product_count
         FROM categories AS c
         LEFT JOIN products AS p ON p.category_id = c.id
         GROUP BY c.id, c.name, c.created_at
         ORDER BY c.name ASC'
    )->fetchAll();
    $listError = '';
} catch (PDOException $exception) {
    error_log('Category list error: ' . $exception->getMessage());
    $categories = [];
    $listError = 'Daftar kategori tidak dapat dimuat saat ini.';
}

$pageTitle = 'Kategori';
require __DIR__ . '/../includes/header.php';
?>
<div class="content-heading">
    <div>
        <p class="page-intro">Atur kategori yang digunakan untuk mengelompokkan produk toko.</p>
    </div>
    <a class="button-link" href="<?= escape_html(app_url('admin/categories.php')) ?>">Kategori baru</a>
</div>

<?php if (is_array($flashMessage)): ?>
    <div class="alert <?= ($flashMessage['type'] ?? '') === 'success' ? 'alert-success' : 'alert-error' ?>" role="status">
        <?= escape_html(is_string($flashMessage['message'] ?? null) ? $flashMessage['message'] : '') ?>
    </div>
<?php endif; ?>
<?php if ($formError !== ''): ?>
    <div class="alert alert-error" role="alert"><?= escape_html($formError) ?></div>
<?php endif; ?>

<div class="category-layout">
    <section class="panel category-form-panel">
        <div class="panel-heading">
            <div>
                <h2><?= $editingCategory !== null ? 'Edit kategori' : 'Tambah kategori' ?></h2>
                <p>Nama kategori maksimal 80 karakter.</p>
            </div>
        </div>
        <form method="post" action="<?= escape_html(app_url('admin/categories.php')) ?>" class="stacked-form">
            <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
            <input type="hidden" name="action" value="<?= $editingCategory !== null ? 'update' : 'create' ?>">
            <?php if ($editingCategory !== null): ?>
                <input type="hidden" name="id" value="<?= (int) $editingCategory['id'] ?>">
            <?php endif; ?>
            <label for="category-name">Nama kategori</label>
            <input id="category-name" name="name" type="text" maxlength="80" required
                   value="<?= escape_html(is_string($_POST['name'] ?? null) ? $_POST['name'] : ($editingCategory['name'] ?? '')) ?>">
            <button class="primary-button" type="submit"><?= $editingCategory !== null ? 'Simpan perubahan' : 'Tambah kategori' ?></button>
            <?php if ($editingCategory !== null): ?>
                <a class="cancel-link" href="<?= escape_html(app_url('admin/categories.php')) ?>">Batal edit</a>
            <?php endif; ?>
        </form>
    </section>

    <section class="panel category-list-panel">
        <div class="panel-heading">
            <div>
                <h2>Daftar kategori</h2>
                <p><?= number_format(count($categories)) ?> kategori</p>
            </div>
        </div>
        <?php if ($listError !== ''): ?>
            <div class="empty-state"><p><?= escape_html($listError) ?></p></div>
        <?php elseif ($categories === []): ?>
            <div class="empty-state">
                <strong>Belum ada kategori</strong>
                <p>Tambahkan kategori pertama melalui formulir ini.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr><th>Nama kategori</th><th>Produk</th><th class="actions-cell">Aksi</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($categories as $category): ?>
                        <tr>
                            <td><?= escape_html($category['name']) ?></td>
                            <td><?= number_format((int) $category['product_count']) ?></td>
                            <td class="actions-cell">
                                <div class="row-actions">
                                    <a class="text-button" href="<?= escape_html(app_url('admin/categories.php?edit=' . (int) $category['id'])) ?>">Edit</a>
                                    <form method="post" action="<?= escape_html(app_url('admin/categories.php')) ?>">
                                        <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $category['id'] ?>">
                                        <button class="text-button text-button-danger" type="submit">Hapus</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
