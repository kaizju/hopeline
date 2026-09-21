<?php

// Start the session here, once, for every page that includes this file.
// Guarded so it's safe even if a page still has its own session_start().
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('BASE_URL', 'https://hopeline.ics-dev.io/');
$host = 'u442411629_hopeline_db';
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