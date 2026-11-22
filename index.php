<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if (!is_logged_in()) {
    header('Location: ' . app_url('login.php'));
    exit;
}

header('Location: ' . app_url(role_home_path(current_user()['role'])));
exit;
