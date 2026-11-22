<?php
declare(strict_types=1);

function database_connection(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = getenv('POS_DB_HOST') ?: '127.0.0.1';
    $database = getenv('POS_DB_NAME') ?: 'pos_kelontong';
    $username = getenv('POS_DB_USER') ?: 'dbadmin';
    $password = getenv('POS_DB_PASSWORD') ?: 'PasswordKuat123!';
    $dsn = "mysql:host={$host};dbname={$database};charset=utf8mb4";

    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}
