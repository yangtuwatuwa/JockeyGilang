<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
require_once __DIR__ . '/../config/database.php';

$dashboardData = null;
$recentTransactions = [];
$dashboardError = '';

try {
    $pdo = database_connection();

    $productStatement = $pdo->query(
        "SELECT
            COUNT(*) AS total_products,
            COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) AS active_products,
            COALESCE(SUM(CASE WHEN status = 'active' AND stock = 0 THEN 1 ELSE 0 END), 0) AS out_of_stock,
            COALESCE(SUM(CASE WHEN status = 'active' AND stock > 0 AND stock <= minimum_stock THEN 1 ELSE 0 END), 0) AS low_stock
         FROM products"
    );
    $dashboardData = $productStatement->fetch();

    $categoryCount = $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
    $salesStatement = $pdo->query(
        'SELECT COUNT(*) AS transaction_count,
                COALESCE(SUM(total_amount), 0) AS sales_total
         FROM transactions
         WHERE transaction_date >= CURRENT_DATE()
           AND transaction_date < CURRENT_DATE() + INTERVAL 1 DAY'
    );
    $salesToday = $salesStatement->fetch();

    $recentStatement = $pdo->query(
        'SELECT t.transaction_number, t.transaction_date, t.total_amount, u.full_name AS cashier_name
         FROM transactions AS t
         INNER JOIN users AS u ON u.id = t.cashier_user_id
         ORDER BY t.transaction_date DESC, t.id DESC
         LIMIT 5'
    );
    $recentTransactions = $recentStatement->fetchAll();
} catch (PDOException $exception) {
    error_log('Admin dashboard query error: ' . $exception->getMessage());
    $dashboardError = 'Data dashboard tidak dapat dimuat saat ini. Silakan coba lagi.';
}

$pageTitle = 'Dashboard Admin';
require __DIR__ . '/../includes/header.php';
?>
<?php if ($dashboardError !== ''): ?>
    <div class="alert alert-error" role="alert"><?= escape_html($dashboardError) ?></div>
<?php else: ?>
    <p class="page-intro">Ringkasan kondisi toko berdasarkan data yang tersimpan.</p>

    <div class="stat-grid">
        <article class="stat-card">
            <span class="stat-label">Produk aktif</span>
            <strong><?= number_format((int) $dashboardData['active_products']) ?></strong>
            <span class="stat-note">dari <?= number_format((int) $dashboardData['total_products']) ?> produk</span>
        </article>
        <article class="stat-card">
            <span class="stat-label">Kategori</span>
            <strong><?= number_format((int) $categoryCount) ?></strong>
            <a class="stat-link" href="<?= escape_html(app_url('admin/categories.php')) ?>">Kelola kategori</a>
        </article>
        <article class="stat-card stat-card-warning">
            <span class="stat-label">Stok perlu perhatian</span>
            <strong><?= number_format((int) $dashboardData['low_stock'] + (int) $dashboardData['out_of_stock']) ?></strong>
            <span class="stat-note"><?= number_format((int) $dashboardData['out_of_stock']) ?> produk habis</span>
        </article>
        <article class="stat-card">
            <span class="stat-label">Penjualan hari ini</span>
            <strong class="stat-money">Rp <?= number_format((float) $salesToday['sales_total'], 0, ',', '.') ?></strong>
            <span class="stat-note"><?= number_format((int) $salesToday['transaction_count']) ?> transaksi</span>
        </article>
    </div>

    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Transaksi terbaru</h2>
                <p>Transaksi terakhir yang tersimpan di sistem.</p>
            </div>
        </div>
        <?php if ($recentTransactions === []): ?>
            <div class="empty-state">
                <strong>Belum ada transaksi</strong>
                <p>Transaksi akan muncul di sini setelah penjualan pertama disimpan.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>No. transaksi</th>
                        <th>Tanggal</th>
                        <th>Kasir</th>
                        <th class="number-cell">Total</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recentTransactions as $transaction): ?>
                        <tr>
                            <td><?= escape_html($transaction['transaction_number']) ?></td>
                            <td><?= escape_html(date('d/m/Y H:i', strtotime($transaction['transaction_date']))) ?></td>
                            <td><?= escape_html($transaction['cashier_name']) ?></td>
                            <td class="number-cell">Rp <?= number_format((float) $transaction['total_amount'], 0, ',', '.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
