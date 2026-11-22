<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_role('supplier');

$pageTitle = 'Dashboard Supplier';
require __DIR__ . '/../includes/header.php';
?>
<div class="welcome-card">
    <span class="welcome-icon">S</span>
    <div>
        <h2>Selamat datang, <?= escape_html(current_user()['full_name']) ?></h2>
        <p>Login Supplier berhasil. Modul produk dan stok akan tersedia pada tahap berikutnya.</p>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
