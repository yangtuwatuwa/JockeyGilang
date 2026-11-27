<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
require_once __DIR__ . '/../config/database.php';

$pdo = database_connection();
$errorMessage = '';

$today = date('Y-m-d');
$dateFrom = transaction_history_date(is_string($_GET['from'] ?? null) ? $_GET['from'] : null);
$dateTo = transaction_history_date(is_string($_GET['to'] ?? null) ? $_GET['to'] : null);
$hasDateInput = (is_string($_GET['from'] ?? null) && $_GET['from'] !== '')
    || (is_string($_GET['to'] ?? null) && $_GET['to'] !== '');

if ($hasDateInput && ($dateFrom === null || $dateTo === null)) {
    $errorMessage = 'Format tanggal tidak valid. Gunakan format tanggal yang benar.';
    $dateFrom = null;
    $dateTo = null;
} elseif ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
    $errorMessage = 'Tanggal awal tidak boleh setelah tanggal akhir.';
    $dateFrom = null;
    $dateTo = null;
}

// Default range is the current month, from the first day through today.
$dateFrom ??= date('Y-m-01');
$dateTo ??= $today;
$dateToExclusive = (new DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d');

$rangeParameters = [
    'date_from' => $dateFrom . ' 00:00:00',
    'date_to' => $dateToExclusive . ' 00:00:00',
];

$summaryStatement = $pdo->prepare(
    'SELECT COUNT(*) AS transaction_count,
            COALESCE(SUM(total_amount), 0) AS revenue,
            COALESCE(SUM(total_discount), 0) AS discounts,
            COALESCE(ROUND(AVG(total_amount), 2), 0) AS average_transaction
     FROM transactions
     WHERE transaction_date >= :date_from AND transaction_date < :date_to'
);
$summaryStatement->execute($rangeParameters);
$summary = $summaryStatement->fetch();

$unitStatement = $pdo->prepare(
    'SELECT td.unit_snapshot, SUM(td.quantity) AS quantity_sold
     FROM transaction_details AS td
     INNER JOIN transactions AS t ON t.id = td.transaction_id
     WHERE t.transaction_date >= :date_from AND t.transaction_date < :date_to
     GROUP BY td.unit_snapshot
     ORDER BY td.unit_snapshot ASC'
);
$unitStatement->execute($rangeParameters);
$quantityByUnit = $unitStatement->fetchAll();

$dailyStatement = $pdo->prepare(
    'SELECT DATE(transaction_date) AS sale_date,
            COUNT(*) AS transaction_count,
            SUM(total_amount) AS revenue,
            SUM(total_discount) AS discounts
     FROM transactions
     WHERE transaction_date >= :date_from AND transaction_date < :date_to
     GROUP BY DATE(transaction_date)
     ORDER BY sale_date DESC'
);
$dailyStatement->execute($rangeParameters);
$dailySales = $dailyStatement->fetchAll();

$topProductStatement = $pdo->prepare(
    'SELECT td.product_id,
            MAX(td.product_name_snapshot) AS product_name,
            td.unit_snapshot,
            SUM(td.quantity) AS quantity_sold,
            SUM(td.subtotal) AS revenue
     FROM transaction_details AS td
     INNER JOIN transactions AS t ON t.id = td.transaction_id
     WHERE t.transaction_date >= :date_from AND t.transaction_date < :date_to
     GROUP BY td.product_id, td.unit_snapshot
     ORDER BY quantity_sold DESC, revenue DESC
     LIMIT 10'
);
$topProductStatement->execute($rangeParameters);
$topProducts = $topProductStatement->fetchAll();

$pageTitle = 'Laporan Penjualan';
require __DIR__ . '/../includes/header.php';
?>
<p class="page-intro">Ringkasan penjualan untuk rentang tanggal yang dipilih. Jumlah item dihitung per satuan.</p>

<?php if ($errorMessage !== ''): ?>
    <div class="alert alert-error" role="alert"><?= escape_html($errorMessage) ?></div>
<?php endif; ?>

<section class="panel">
    <div class="panel-heading product-list-heading">
        <div>
            <h2>Rentang tanggal</h2>
            <p><?= escape_html($dateFrom) ?> s.d. <?= escape_html($dateTo) ?></p>
        </div>
        <form method="get" action="<?= escape_html(app_url('admin/reports.php')) ?>" class="search-form">
            <label class="visually-hidden" for="report-from">Tanggal awal</label>
            <input id="report-from" name="from" type="date" value="<?= escape_html($dateFrom) ?>" required>
            <label class="visually-hidden" for="report-to">Tanggal akhir</label>
            <input id="report-to" name="to" type="date" value="<?= escape_html($dateTo) ?>" required>
            <button class="secondary-button" type="submit">Terapkan</button>
            <a class="cancel-link" href="<?= escape_html(app_url('admin/reports.php')) ?>">Bulan ini</a>
        </form>
    </div>
    <div class="table-wrap">
        <table>
            <tbody>
            <tr><th>Total penjualan</th><td><?= escape_html(transaction_history_money((string) $summary['revenue'])) ?></td></tr>
            <tr><th>Jumlah transaksi</th><td><?= number_format((int) $summary['transaction_count']) ?></td></tr>
            <tr><th>Rata-rata nilai transaksi</th><td><?= escape_html(transaction_history_money((string) $summary['average_transaction'])) ?></td></tr>
            <tr><th>Total diskon</th><td><?= escape_html(transaction_history_money((string) $summary['discounts'])) ?></td></tr>
            <tr>
                <th>Total item terjual</th>
                <td>
                    <?php if ($quantityByUnit === []): ?>
                        -
                    <?php else: ?>
                        <?php foreach ($quantityByUnit as $unitRow): ?>
                            <?= escape_html((string) $unitRow['quantity_sold']) ?> <?= escape_html($unitRow['unit_snapshot']) ?><br>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </td>
            </tr>
            </tbody>
        </table>
    </div>
</section>

<section class="panel">
    <div class="panel-heading">
        <div><h2>Penjualan harian</h2><p><?= number_format(count($dailySales)) ?> hari dengan transaksi</p></div>
    </div>
    <?php if ($dailySales === []): ?>
        <div class="empty-state"><strong>Tidak ada transaksi</strong><p>Tidak ada penjualan pada rentang tanggal ini.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Tanggal</th><th>Transaksi</th><th>Total penjualan</th><th>Diskon</th></tr></thead>
                <tbody>
                <?php foreach ($dailySales as $day): ?>
                    <tr>
                        <td><?= escape_html(date('d-m-Y', strtotime($day['sale_date']))) ?></td>
                        <td><?= number_format((int) $day['transaction_count']) ?></td>
                        <td><?= escape_html(transaction_history_money((string) $day['revenue'])) ?></td>
                        <td><?= escape_html(transaction_history_money((string) $day['discounts'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="panel">
    <div class="panel-heading">
        <div><h2>Produk terlaris</h2><p>10 produk teratas berdasarkan jumlah terjual</p></div>
    </div>
    <?php if ($topProducts === []): ?>
        <div class="empty-state"><strong>Belum ada data</strong><p>Produk terjual pada rentang tanggal ini akan muncul di sini.</p></div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>No</th><th>Produk</th><th>Jumlah terjual</th><th>Pendapatan</th></tr></thead>
                <tbody>
                <?php foreach ($topProducts as $index => $product): ?>
                    <tr>
                        <td><?= $index + 1 ?></td>
                        <td><?= escape_html($product['product_name']) ?></td>
                        <td><?= escape_html((string) $product['quantity_sold']) ?> <?= escape_html($product['unit_snapshot']) ?></td>
                        <td><?= escape_html(transaction_history_money((string) $product['revenue'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
