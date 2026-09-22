<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('BASE_URL', 'https://localhost/hopeline/');
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
} catch (PDOException $e) {
    die('Database connection failed: ' . $e->getMessage());
}