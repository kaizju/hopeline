<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Asia/Manila');
define('ARRIVE_GEOFENCE_M', 300);
define('BASE_URL', 'https://hopeline.ics-dev.io/');
$host = 'localhost';
$dbname = 'u442411629_hopeline_db';
$username = 'u442411629_hoeline_dev';
$password = 'w[9_£J98gFz}';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}