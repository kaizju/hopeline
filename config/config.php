<?php
date_default_timezone_set('Asia/Manila');
ini_set('display_errors', '0');
ini_set('log_errors', '1');

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 1800);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0');
    session_start();
}

// Local XAMPP defaults. Override these in config/secrets.php or environment variables.
$host = 'localhost';
$dbname = 'hopeline';
$username = 'root';
$password = '';
$baseUrl = 'https://localhost/hopeline';
$secrets = __DIR__ . '/secrets.php';
if (is_file($secrets)) { require $secrets; }
$host = getenv('HOPELINE_DB_HOST') !== false ? getenv('HOPELINE_DB_HOST') : $host;
$dbname = getenv('HOPELINE_DB_NAME') !== false ? getenv('HOPELINE_DB_NAME') : $dbname;
$username = getenv('HOPELINE_DB_USER') !== false ? getenv('HOPELINE_DB_USER') : $username;
$password = getenv('HOPELINE_DB_PASSWORD') !== false ? getenv('HOPELINE_DB_PASSWORD') : $password;
$baseUrl = getenv('HOPELINE_BASE_URL') !== false ? getenv('HOPELINE_BASE_URL') : $baseUrl;
define('ARRIVE_GEOFENCE_M', 300);
define('BASE_URL', rtrim($baseUrl, '/'));

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $pdo->exec("SET time_zone = '+08:00'");
} catch (PDOException $e) {
    error_log('HopeLine database connection failed: ' . $e->getMessage());
    http_response_code(500);
    die('Database connection failed. Check the server configuration and logs.');
}
