<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    header('Location: ' . app_url(role_home_path(current_user()['role'])));
    exit;
}

$errorMessage = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $errorMessage = 'Form sudah kedaluwarsa. Silakan muat ulang halaman dan coba lagi.';
    } else {
        $postedUsername = $_POST['username'] ?? null;
        $password = $_POST['password'] ?? null;

        if (!is_string($postedUsername) || !is_string($password)) {
            $errorMessage = 'Username dan password harus berupa teks.';
        } else {
            $username = trim($postedUsername);
        }

        if ($errorMessage === '' && ($username === '' || $password === '')) {
            $errorMessage = 'Username dan password wajib diisi.';
        } elseif ($errorMessage === '' && strlen($username) > 50) {
            $errorMessage = 'Username maksimal 50 karakter.';
        } elseif ($errorMessage === '') {
            require_once __DIR__ . '/config/database.php';
            try {
                $user = authenticate_user(database_connection(), $username, $password);
                if ($user === null) {
                    $errorMessage = 'Username atau password salah, atau akun tidak aktif.';
                } else {
                    header('Location: ' . app_url(role_home_path($user['role'])));
                    exit;
                }
            } catch (PDOException $exception) {
                error_log('Database login error: ' . $exception->getMessage());
                $errorMessage = 'Koneksi database gagal. Periksa pengaturan database lalu coba lagi.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | POS Kelontong</title>
    <link rel="stylesheet" href="<?= escape_html(app_url('assets/css/style.css')) ?>">
</head>
<body class="login-page">
    <main class="login-card">
        <div class="login-brand">
            <span class="brand-mark">P</span>
            <div>
                <span class="eyebrow">TOKO KELONTONG</span>
                <h1>Masuk ke POS</h1>
            </div>
        </div>
        <p class="muted">Gunakan akun yang telah dibuat oleh Admin.</p>
        <?php if ($errorMessage !== ''): ?>
            <div class="alert alert-error" role="alert"><?= escape_html($errorMessage) ?></div>
        <?php endif; ?>
        <form method="post" action="<?= escape_html(app_url('login.php')) ?>">
            <input type="hidden" name="csrf_token" value="<?= escape_html(csrf_token()) ?>">
            <label for="username">Username</label>
            <input id="username" name="username" type="text" maxlength="50" autocomplete="username" required value="<?= escape_html($username) ?>">
            <label for="password">Password</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
            <button class="primary-button full-width" type="submit">Login</button>
        </form>
    </main>
</body>
</html>
