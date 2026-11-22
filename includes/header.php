<?php
declare(strict_types=1);

$pageTitle = $pageTitle ?? 'POS Toko Kelontong';
$user = current_user();
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= escape_html($pageTitle) ?> | POS Kelontong</title>
    <link rel="stylesheet" href="<?= escape_html(app_url('assets/css/style.css')) ?>">
</head>
<body>
<div class="app-shell">
    <?php require __DIR__ . '/sidebar.php'; ?>
    <main class="main-content">
        <header class="topbar">
            <div>
                <span class="eyebrow">POS TOKO KELONTONG</span>
                <h1><?= escape_html($pageTitle) ?></h1>
            </div>
            <?php if ($user !== null): ?>
                <div class="user-summary">
                    <span><?= escape_html($user['full_name']) ?></span>
                    <span class="role-label"><?= escape_html(ucfirst($user['role'])) ?></span>
                </div>
            <?php endif; ?>
        </header>
        <section class="page-content">
