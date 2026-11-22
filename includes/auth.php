<?php
declare(strict_types=1);

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    $isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

start_app_session();

function current_user(): ?array
{
    $user = $_SESSION['user'] ?? null;
    $validRoles = ['admin', 'cashier', 'supplier'];

    if (!is_array($user)
        || !isset($user['id'], $user['full_name'], $user['username'], $user['role'])
        || (!is_int($user['id']) && !is_string($user['id']))
        || !is_string($user['full_name'])
        || !is_string($user['username'])
        || !is_string($user['role'])
        || !in_array($user['role'], $validRoles, true)
    ) {
        unset($_SESSION['user']);
        return null;
    }

    return $user;
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function session_account_is_active(PDO $pdo, array $user): bool
{
    $statement = $pdo->prepare(
        'SELECT role, status FROM users WHERE id = :id LIMIT 1'
    );
    $statement->execute(['id' => $user['id']]);
    $account = $statement->fetch();

    return $account !== false
        && $account['status'] === 'active'
        && $account['role'] === $user['role'];
}

function require_login(): void
{
    $user = current_user();

    if ($user === null) {
        header('Location: ' . app_url('login.php'));
        exit;
    }

    require_once __DIR__ . '/../config/database.php';

    try {
        $accountIsActive = session_account_is_active(database_connection(), $user);
    } catch (PDOException $exception) {
        error_log('Session account verification error: ' . $exception->getMessage());
        http_response_code(503);
        exit('Session tidak dapat diverifikasi saat ini. Silakan coba lagi nanti.');
    }

    if (!$accountIsActive) {
        end_app_session();
        header('Location: ' . app_url('login.php'));
        exit;
    }
}

function require_role(string ...$allowedRoles): void
{
    require_login();
    $user = current_user();

    if ($user === null || !in_array($user['role'] ?? '', $allowedRoles, true)) {
        http_response_code(403);
        exit('Akses ditolak. Anda tidak memiliki izin membuka halaman ini.');
    }
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token(mixed $submittedToken): bool
{
    return is_string($submittedToken)
        && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $submittedToken);
}

function authenticate_user(PDO $pdo, string $username, string $password): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, full_name, username, password_hash, role, status
         FROM users
         WHERE username = :username
         LIMIT 1'
    );
    $statement->execute(['username' => $username]);
    $user = $statement->fetch();

    if (!$user || $user['status'] !== 'active' || !password_verify($password, $user['password_hash'])) {
        return null;
    }

    session_regenerate_id(true);
    unset($user['password_hash'], $user['status']);
    $_SESSION['user'] = $user;
    unset($_SESSION['csrf_token']);
    return $user;
}

function end_app_session(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $cookieParameters = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $cookieParameters['path'],
            'domain' => $cookieParameters['domain'],
            'secure' => $cookieParameters['secure'],
            'httponly' => $cookieParameters['httponly'],
            'samesite' => $cookieParameters['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}
