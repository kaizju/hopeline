<?php
date_default_timezone_set('Asia/Manila');

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 1800);
    session_start();
}
$secrets = __DIR__ . '/secrets.php';
if (file_exists($secrets)) { require $secrets; }
define('ARRIVE_GEOFENCE_M', 300);
define('BASE_URL', 'https://localhost/hopeline');
$host = 'localhost';
$dbname = 'hopeline';
$username = 'root';
$password = '';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $pdo->exec("SET time_zone = '+08:00'");
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}
