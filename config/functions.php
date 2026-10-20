<?php

function redirect($path) {
    header("Location: " . BASE_URL . $path);
    exit;
}
if (!defined('PTV_BASE_LAT')) {            // shared base coordinates (#7, #9)
    define('PTV_BASE_LAT', 8.371714652741774);
    define('PTV_BASE_LNG', 124.85717564826615);
}

function csrfToken(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function verifyCsrf(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
    $t = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');   // form field OR header (JSON APIs)
    if (!is_string($t) || !hash_equals(csrfToken(), $t)) {
        http_response_code(403);
        if (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') !== false) {
            header('Content-Type: application/json');
            exit(json_encode(['ok' => false, 'error' => 'csrf']));
        }
        exit('Invalid or expired form token. Go back, refresh the page, and try again.');
    }
}

// safe string input: arrays/missing keys can't crash trim()
function postStr(string $k, int $max = 255): string {
    $v = $_POST[$k] ?? '';
    return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
}
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function hasRole($role) {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

function currentUserId() {
    return $_SESSION['user_id'] ?? null;
}

function currentUserEmail() {
    return $_SESSION['email'] ?? null;
}

function requireLogin() {
    if (!isLoggedIn()) {
        redirect('/index.php');
    }
}

function requireRole($role) {
    requireLogin();
    if (!hasRole($role)) {
        die("Access denied. Required role: $role");
    }
}
function purokList() {
    return ['Kihare','Lower Kalanawan','Lower Pol-ton','Lower Sosohon','Mangima',
            'Saint Joseph','Upper Kalanawan','Upper Pol-ton','Upper Sosohon'];
}
function renderHeader($title) {
    $currentRole = $_SESSION['role'] ?? 'guest';
    $currentEmail = $_SESSION['email'] ?? '';
    $isLoggedIn = isset($_SESSION['user_id']);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo htmlspecialchars($title); ?></title>
        <!-- Material Icons -->
        <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
        <!-- Google Fonts -->
        <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="http://localhost/php-pdo/assets/css/style.css">


    </head>
    <body>
        <?php if ($isLoggedIn): ?>
            <div class="main-content">
                <div class="container">
        <?php else: ?>
            <div class="container" style="margin: 0 auto; max-width: 500px; padding-top: 100px;">
        <?php endif; ?>
    <?php
}

function renderFooter() {
    $isLoggedIn = isset($_SESSION['user_id']);
    ?>
        <?php if ($isLoggedIn): ?>
                </div>
            </div>
        <?php else: ?>
            </div>
        <?php endif; ?>
    </body>
    </html>
    <?php
}
require_once __DIR__ . '/../includes/activity-logger.php';

// ---- CSRF, enforced once for every page/API that includes this file ----
csrfToken();
verifyCsrf();                                   // any POST without a valid token stops here

$csrfFieldHtml = csrfField();
$csrfSkip = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') !== false
         || basename($_SERVER['SCRIPT_NAME'] ?? '') === 'export-cases.php';
if (!$csrfSkip) {
    // auto-adds the hidden token to every <form method="POST"> in the page output
    ob_start(function ($html) use ($csrfFieldHtml) {
        if (stripos($html, '<form') === false) return $html;
        return preg_replace_callback('/<form\b[^>]*\bmethod\s*=\s*["\']?post["\']?[^>]*>/i',
            function ($m) use ($csrfFieldHtml) { return $m[0] . $csrfFieldHtml; }, $html);
    });
}