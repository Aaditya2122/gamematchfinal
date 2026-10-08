<?php
declare(strict_types=1);

$host = getenv('DB_HOST') ?: 'localhost';
$port = getenv('DB_PORT') ?: '3306';
$db   = getenv('DB_DATABASE') ?: 'gamematch';
$user = getenv('DB_USERNAME') ?: 'root';
$pass = getenv('DB_PASSWORD') ?: '';
$charset = 'utf8mb4';

$dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// TiDB Cloud Serverless requires TLS. Keep localhost/XAMPP simple, but
// automatically enable TLS when DB_HOST is configured for a remote DB.
if (getenv('DB_HOST')) {
    $ca = getenv('DB_SSL_CA');
    if (!$ca) {
        $candidate = __DIR__ . '/../ca.pem';
        if (is_file($candidate)) {
            $ca = $candidate;
        }
    }
    if ($ca && is_file($ca)) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }
}

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    die('Database connection failed. Check your database configuration. Error: ' . htmlspecialchars($e->getMessage()));
}
?>
