<?php
// The calling page sets $slRole = 'admin' | 'manager' | 'user'
require_once __DIR__ . '/security_logs.php';
requireRole($slRole);
$unreadAlerts = 0;
$slFolder = $slRole === 'user' ? 'responder' : $slRole;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Security Logs — HopeLine</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/app.css">
</head>
<body>
<?php require_once __DIR__ . '/../assets/layouts/' . $slFolder . '/' . $slFolder . '_sidebar.php'; ?>
<main class="main main-1320">
    <?php renderSecurityLogs($pdo, $slRole, (int)$_SESSION['user_id']); ?>
</main>
</body>
</html>