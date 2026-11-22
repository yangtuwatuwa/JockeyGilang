<?php
declare(strict_types=1);

function escape_html(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_base_path(): string
{
    $scriptDirectory = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $scriptDirectory = rtrim($scriptDirectory, '/');

    if (preg_match('~/(admin|cashier|supplier)$~', $scriptDirectory) === 1) {
        $scriptDirectory = rtrim(dirname($scriptDirectory), '/');
    }

    return $scriptDirectory === '' ? '' : $scriptDirectory;
}

function app_url(string $path = ''): string
{
    $basePath = app_base_path();
    $path = ltrim($path, '/');

    if ($path === '') {
        return $basePath === '' ? '/' : $basePath . '/';
    }

    return ($basePath === '' ? '' : $basePath) . '/' . $path;
}

function role_home_path(string $role): string
{
    $rolePaths = [
        'admin' => 'admin/dashboard.php',
        'cashier' => 'cashier/dashboard.php',
        'supplier' => 'supplier/dashboard.php',
    ];

    return $rolePaths[$role] ?? 'login.php';
}

function set_flash_message(string $type, string $message): void
{
    $_SESSION['flash_message'] = ['type' => $type, 'message' => $message];
}

function take_flash_message(): ?array
{
    $message = $_SESSION['flash_message'] ?? null;
    unset($_SESSION['flash_message']);
    return is_array($message) ? $message : null;
}
