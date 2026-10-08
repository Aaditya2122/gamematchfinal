<?php
declare(strict_types=1);

// Works with Railway (MYSQL* / DB_* vars), TiDB, and local XAMPP.
$host = getenv('DB_HOST')     ?: getenv('MYSQLHOST')     ?: 'localhost';
$port = getenv('DB_PORT')     ?: getenv('MYSQLPORT')     ?: '3306';
$db   = getenv('DB_DATABASE') ?: getenv('MYSQLDATABASE') ?: 'gamematch';
$user = getenv('DB_USERNAME') ?: getenv('MYSQLUSER')     ?: 'root';
$pass = getenv('DB_PASSWORD') ?: getenv('MYSQLPASSWORD') ?: '';
$charset = 'utf8mb4';

$dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// TLS only when DB_SSL_CA is set explicitly (needed for TiDB Cloud).
// Railway MySQL does not use the bundled ca.pem, so it is not auto-loaded.
$ca = getenv('DB_SSL_CA');
if ($ca && is_file($ca)) {
    $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
}

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die('Database connection failed. Check your database configuration. Error: ' . htmlspecialchars($e->getMessage()));
}
?>
