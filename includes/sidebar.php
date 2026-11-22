<?php
declare(strict_types=1);

$sidebarUser = current_user();
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
?>
<aside class="sidebar">
    <a class="brand" href="<?= escape_html(app_url('index.php')) ?>">
        <span class="brand-mark">P</span>
        <span>POS Kelontong</span>
    </a>
    <?php if ($sidebarUser !== null): ?>
        <div class="nav-caption">MENU</div>
        <nav aria-label="Navigasi utama">
            <a class="nav-link <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>" href="<?= escape_html(app_url(role_home_path($sidebarUser['role']))) ?>">Dashboard</a>
            <?php if ($sidebarUser['role'] === 'admin'): ?>
                <a class="nav-link <?= $currentPage === 'categories.php' ? 'active' : '' ?>" href="<?= escape_html(app_url('admin/categories.php')) ?>">Kategori</a>
                <a class="nav-link <?= $currentPage === 'stock.php' ? 'active' : '' ?>" href="<?= escape_html(app_url('admin/stock.php')) ?>">Stok</a>
            <?php endif; ?>
        </nav>
        <div class="sidebar-bottom">
            <form action="<?= escape_html(app_url('logout.php')) ?>" method="post">
                <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
                <button class="logout-button" type="submit">Keluar</button>
            </form>
        </div>
    <?php endif; ?>
</aside>
