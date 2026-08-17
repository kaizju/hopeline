<?php
session_start();

// Log the logout before destroying the session (optional but useful for your audit trail)
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/functions.php';
require_once __DIR__ . '/includes/activity-logger.php';

if (isset($_SESSION['user_id']) && function_exists('logActivity')) {
    logActivity($pdo, $_SESSION['user_id'], $_SESSION['email'], 'logout', 'success');
}

// Clear all session data
$_SESSION = [];

// Destroy the session cookie itself
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params['path'], $params['domain'], $params['secure'], $params['httponly']
    );
}

session_destroy();

redirect('/index.php');
exit;