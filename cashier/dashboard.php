<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_role('cashier');

$pageTitle = 'Dashboard Kasir';
require __DIR__ . '/../includes/header.php';
?>
<div class="welcome-card">
    <span class="welcome-icon">K</span>
    <div>
        <h2>Selamat datang, <?= escape_html(current_user()['full_name']) ?></h2>
        <p>Login Kasir berhasil. Modul penjualan akan tersedia pada tahap berikutnya.</p>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
