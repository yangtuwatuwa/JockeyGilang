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

function stock_quantity_to_milli(string $value): int|false
{
    if (preg_match('/^([0-9]+)(?:\.([0-9]{1,3}))?$/D', $value, $matches) !== 1) {
        return false;
    }

    $whole = ltrim($matches[1], '0');
    $whole = $whole === '' ? '0' : $whole;
    if (strlen($whole) > 9 || (strlen($whole) === 9 && strcmp($whole, '999999999') > 0)) {
        return false;
    }

    $fraction = str_pad($matches[2] ?? '', 3, '0');
    return ((int) $whole * 1000) + (int) $fraction;
}

function stock_milli_to_decimal(int $milli): string
{
    $sign = $milli < 0 ? '-' : '';
    $absoluteMilli = abs($milli);

    return $sign . intdiv($absoluteMilli, 1000) . '.'
        . str_pad((string) ($absoluteMilli % 1000), 3, '0', STR_PAD_LEFT);
}

function stock_unit_requires_whole_quantity(string $unit): bool
{
    return in_array($unit, ['pcs', 'pack', 'box', 'botol', 'ikat'], true);
}

function product_discounted_price(string $sellingPrice, string $discountPercent): string
{
    [$wholePrice, $fractionPrice] = array_pad(explode('.', $sellingPrice, 2), 2, '');
    [$wholeDiscount, $fractionDiscount] = array_pad(explode('.', $discountPercent, 2), 2, '');
    $priceCents = ((int) $wholePrice * 100) + (int) str_pad($fractionPrice, 2, '0');
    $discountBasisPoints = ((int) $wholeDiscount * 100) + (int) str_pad($fractionDiscount, 2, '0');
    $discountedCents = intdiv(($priceCents * (10000 - $discountBasisPoints)) + 5000, 10000);

    return intdiv($discountedCents, 100) . '.' . str_pad((string) ($discountedCents % 100), 2, '0', STR_PAD_LEFT);
}

function transaction_history_money(string $amount): string
{
    return 'Rp ' . number_format((float) $amount, 2, ',', '.');
}

function transaction_history_date(?string $value): ?string
{
    if (!is_string($value) || $value === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
}
