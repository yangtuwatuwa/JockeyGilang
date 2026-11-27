<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
require_once __DIR__ . '/../config/database.php';

$pdo = database_connection();
$errorMessage = '';

$search = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
if (mb_strlen($search) > 40) {
    $search = mb_substr($search, 0, 40);
}

$dateFrom = transaction_history_date(is_string($_GET['from'] ?? null) ? $_GET['from'] : null);
$dateTo = transaction_history_date(is_string($_GET['to'] ?? null) ? $_GET['to'] : null);
if ((is_string($_GET['from'] ?? null) && $_GET['from'] !== '' && $dateFrom === null)
    || (is_string($_GET['to'] ?? null) && $_GET['to'] !== '' && $dateTo === null)
) {
    $errorMessage = 'Format tanggal tidak valid. Gunakan format tanggal yang benar.';
    $dateFrom = null;
    $dateTo = null;
}
if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
    $errorMessage = 'Tanggal awal tidak boleh setelah tanggal akhir.';
    $dateFrom = null;
    $dateTo = null;
}

$selectedId = null;
if (isset($_GET['id']) && is_string($_GET['id'])) {
    $filteredId = filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($filteredId !== false) {
        $selectedId = $filteredId;
    }
}

$conditions = [];
$parameters = [];
if ($search !== '') {
    $conditions[] = 't.transaction_number LIKE :search';
    $parameters['search'] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
}
if ($dateFrom !== null) {
    $conditions[] = 't.transaction_date >= :date_from';
    $parameters['date_from'] = $dateFrom . ' 00:00:00';
}
if ($dateTo !== null) {
    $dateToExclusive = (new DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d');
    $conditions[] = 't.transaction_date < :date_to';
    $parameters['date_to'] = $dateToExclusive . ' 00:00:00';
}

$listSql =
    'SELECT t.id, t.transaction_number, t.transaction_date, t.total_amount, t.total_discount,
            t.amount_paid, t.change_amount, u.full_name AS cashier_name
     FROM transactions AS t
     INNER JOIN users AS u ON u.id = t.cashier_user_id';
if ($conditions !== []) {
    $listSql .= ' WHERE ' . implode(' AND ', $conditions);
}
$listSql .= ' ORDER BY t.transaction_date DESC, t.id DESC LIMIT 100';
$listStatement = $pdo->prepare($listSql);
$listStatement->execute($parameters);
$transactions = $listStatement->fetchAll();

$selectedTransaction = false;
$selectedDetails = [];
if ($selectedId !== null) {
    $transactionStatement = $pdo->prepare(
        'SELECT t.id, t.transaction_number, t.transaction_date, t.total_amount, t.total_discount,
                t.amount_paid, t.change_amount, u.full_name AS cashier_name
         FROM transactions AS t
         INNER JOIN users AS u ON u.id = t.cashier_user_id
         WHERE t.id = :id'
    );
    $transactionStatement->execute(['id' => $selectedId]);
    $selectedTransaction = $transactionStatement->fetch();

    if ($selectedTransaction !== false) {
        $detailStatement = $pdo->prepare(
            'SELECT product_name_snapshot, selling_price_snapshot, discount_percent_snapshot,
                    unit_price_after_discount, unit_snapshot, quantity, subtotal
             FROM transaction_details
             WHERE transaction_id = :transaction_id
             ORDER BY id ASC'
        );
        $detailStatement->execute(['transaction_id' => $selectedId]);
        $selectedDetails = $detailStatement->fetchAll();
    } else {
        $errorMessage = 'Transaksi yang dipilih tidak ditemukan.';
    }
}

$pageTitle = 'Riwayat Transaksi';
require __DIR__ . '/../includes/header.php';
?>
<p class="page-intro">Lihat seluruh transaksi penjualan, termasuk harga, diskon, dan kembalian.</p>

<?php if ($errorMessage !== ''): ?>
    <div class="alert alert-error" role="alert"><?= escape_html($errorMessage) ?></div>
<?php endif; ?>

<?php if ($selectedTransaction !== false): ?>
    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2>Detail transaksi</h2>
                <p><?= escape_html($selectedTransaction['transaction_number']) ?></p>
            </div>
            <a class="cancel-link" href="<?= escape_html(app_url('admin/transactions.php')) ?>">Kembali ke daftar</a>
        </div>
        <p>Kasir: <?= escape_html($selectedTransaction['cashier_name']) ?></p>
        <p>Waktu: <?= escape_html(date('d-m-Y H:i:s', strtotime($selectedTransaction['transaction_date']))) ?></p>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>Produk</th><th>Harga jual</th><th>Diskon</th><th>Harga setelah diskon</th>
                    <th>Jumlah</th><th>Subtotal</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($selectedDetails as $detail): ?>
                    <tr>
                        <td><?= escape_html($detail['product_name_snapshot']) ?></td>
                        <td><?= escape_html(transaction_history_money($detail['selling_price_snapshot'])) ?></td>
                        <td><?= escape_html((string) $detail['discount_percent_snapshot']) ?>%</td>
                        <td><?= escape_html(transaction_history_money($detail['unit_price_after_discount'])) ?></td>
                        <td><?= escape_html((string) $detail['quantity']) ?> <?= escape_html($detail['unit_snapshot']) ?></td>
                        <td><?= escape_html(transaction_history_money($detail['subtotal'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p>Total: <strong><?= escape_html(transaction_history_money($selectedTransaction['total_amount'])) ?></strong></p>
        <p>Total diskon: <?= escape_html(transaction_history_money($selectedTransaction['total_discount'])) ?></p>
        <p>Dibayar: <?= escape_html(transaction_history_money($selectedTransaction['amount_paid'])) ?></p>
        <p>Kembalian: <?= escape_html(transaction_history_money($selectedTransaction['change_amount'])) ?></p>
    </section>
<?php endif; ?>

<section class="panel">
    <div class="panel-heading product-list-heading">
        <div>
            <h2>Daftar transaksi</h2>
            <p><?= number_format(count($transactions)) ?> transaksi (maksimal 100 terbaru)</p>
        </div>
        <form method="get" action="<?= escape_html(app_url('admin/transactions.php')) ?>" class="search-form">
            <label class="visually-hidden" for="transaction-search">Cari nomor transaksi</label>
            <input id="transaction-search" name="q" type="search" maxlength="40" placeholder="Nomor transaksi" value="<?= escape_html($search) ?>">
            <label class="visually-hidden" for="date-from">Tanggal awal</label>
            <input id="date-from" name="from" type="date" value="<?= escape_html((string) $dateFrom) ?>">
            <label class="visually-hidden" for="date-to">Tanggal akhir</label>
            <input id="date-to" name="to" type="date" value="<?= escape_html((string) $dateTo) ?>">
            <button class="secondary-button" type="submit">Terapkan</button>
            <?php if ($search !== '' || $dateFrom !== null || $dateTo !== null): ?>
                <a class="cancel-link" href="<?= escape_html(app_url('admin/transactions.php')) ?>">Reset</a>
            <?php endif; ?>
        </form>
    </div>
    <?php if ($transactions === []): ?>
        <div class="empty-state">
            <strong>Belum ada transaksi</strong>
            <p>Transaksi yang sesuai filter akan muncul di sini.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                <tr>
                    <th>No. transaksi</th><th>Kasir</th><th>Waktu</th><th>Total</th>
                    <th>Diskon</th><th>Dibayar</th><th>Kembalian</th><th class="actions-cell">Aksi</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($transactions as $transaction): ?>
                    <tr>
                        <td><?= escape_html($transaction['transaction_number']) ?></td>
                        <td><?= escape_html($transaction['cashier_name']) ?></td>
                        <td><?= escape_html(date('d-m-Y H:i', strtotime($transaction['transaction_date']))) ?></td>
                        <td><?= escape_html(transaction_history_money($transaction['total_amount'])) ?></td>
                        <td><?= escape_html(transaction_history_money($transaction['total_discount'])) ?></td>
                        <td><?= escape_html(transaction_history_money($transaction['amount_paid'])) ?></td>
                        <td><?= escape_html(transaction_history_money($transaction['change_amount'])) ?></td>
                        <td class="actions-cell">
                            <a class="text-button" href="<?= escape_html(app_url('admin/transactions.php?id=' . (int) $transaction['id'])) ?>">Detail</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
