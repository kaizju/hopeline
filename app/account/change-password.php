<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';
requireLogin();

$flash = consumeFlash();
$flashType = consumeFlashType();
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    verifyCsrf();
    $current = is_string($_POST['current_password'] ?? null) ? $_POST['current_password'] : '';
    $new = is_string($_POST['new_password'] ?? null) ? $_POST['new_password'] : '';
    $confirm = is_string($_POST['confirm_password'] ?? null) ? $_POST['confirm_password'] : '';
    try {
        $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ? AND archived_at IS NULL');
        $stmt->execute([(int)$_SESSION['user_id']]);
        $hash = $stmt->fetchColumn();
        if (!$hash || !password_verify($current, $hash)) {
            redirectAfterPost('change-password.php', 'Current password is incorrect.', 'error');
        }
        if (($policyError = passwordPolicyError($new)) !== null) {
            redirectAfterPost('change-password.php', $policyError, 'error');
        }
        if ($new !== $confirm) {
            redirectAfterPost('change-password.php', 'New password and confirmation do not match.', 'error');
        }
        if (password_verify($new, $hash)) {
            redirectAfterPost('change-password.php', 'Choose a password different from your current password.', 'error');
        }
        $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
        $stmt->execute([password_hash($new, PASSWORD_DEFAULT), (int)$_SESSION['user_id']]);
        session_regenerate_id(true);
        redirectAfterPost('change-password.php', 'Password changed successfully.');
    } catch (PDOException $e) {
        error_log('HopeLine password change failed: ' . $e->getMessage());
        redirectAfterPost('change-password.php', 'Password could not be changed. Please try again.', 'error');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Change Password — HopeLine</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/app.css">
</head>
<body>
<?php
$sidebar = [
    'admin' => __DIR__ . '/../../assets/layouts/admin/admin_sidebar.php',
    'manager' => __DIR__ . '/../../assets/layouts/manager/manager_sidebar.php',
    'user' => __DIR__ . '/../../assets/layouts/responder/responder_sidebar.php',
][$_SESSION['role'] ?? ''] ?? null;
if ($sidebar) require $sidebar;
?>
<main class="main main-1100">
    <div class="page-head"><h1>Change password</h1><p>Update your account password.</p></div>
    <?php if ($flash !== ''): ?><div class="alert-banner <?php echo $flashType === 'error' ? 'alert-error' : ''; ?>"><?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <section class="card" style="max-width:620px;padding:24px">
        <form method="POST" action="" autocomplete="on">
            <?php echo csrfField(); ?>
            <div class="field"><label for="current_password">Current password</label><input id="current_password" type="password" name="current_password" autocomplete="current-password" required></div>
            <div class="field"><label for="new_password">New password</label><input id="new_password" type="password" name="new_password" minlength="12" autocomplete="new-password" required><small>At least 12 characters, including uppercase, lowercase, number, and symbol.</small></div>
            <div class="field"><label for="confirm_password">Confirm new password</label><input id="confirm_password" type="password" name="confirm_password" minlength="12" autocomplete="new-password" required></div>
            <button class="btn-primary" type="submit">Change password</button>
        </form>
    </section>
</main>
</body>
</html>
