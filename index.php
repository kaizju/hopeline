<?php
require_once 'config/config.php';   // session_start() happens here
require_once 'config/functions.php';
require_once 'includes/activity-logger.php';

if (isLoggedIn()) {
    switch ($_SESSION['role']) {
        case 'admin':   redirect('/app/admin/dashboard.php'); break;
        case 'manager': redirect('/app/manager/dashboard.php'); break;
        case 'user':    redirect('/app/responder/dashboard.php'); break;
    }
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // Rate limit: 5 failed attempts per email/IP within 15 minutes
    $n = $pdo->prepare("SELECT COUNT(*) FROM activity_log
                        WHERE action = 'login' AND status = 'failed'
                          AND (email = ? OR ip_address = ?)
                          AND created_at > NOW() - INTERVAL 15 MINUTE");
    $n->execute([$email, $_SERVER['REMOTE_ADDR']]);

    if ($n->fetchColumn() >= 5) {
        $error = 'Too many attempts. Try again in 15 minutes.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users
                               WHERE email = ? AND is_verified = 1 AND archived_at IS NULL");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['email']   = $user['email'];
            $_SESSION['role']    = $user['role'];

            logActivity($pdo, $user['id'], $user['email'], 'login', 'success');

            switch ($user['role']) {
                case 'admin':   redirect('/app/admin/dashboard.php'); break;
                case 'manager': redirect('/app/manager/dashboard.php'); break;
                case 'user':    redirect('/app/responder/dashboard.php'); break;
            }
        } else {
            $error = 'Invalid credentials or email not verified';
            logActivity($pdo, null, $email, 'login', 'failed');
        }
    }
}

renderHeader('Login');
?>

<!-- Same stylesheet as the rest of the app: theme vars, .card, .field, buttons, .alert-error -->
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/app.css">
<style>
    .login-wrapper { flex: 1; display: flex; align-items: center; justify-content: center; padding: 20px; overflow-y: auto; }
    .login-card { width: 100%; max-width: 380px; padding: 28px; margin: auto; background: var(--cool-blue); }
    .login-card .brand { justify-content: center; margin-bottom: 6px; }
    .login-card .brand-mark { width: 34px; height: 34px; border-radius: 8px; }
    .login-card .brand-mark svg { width: 19px; height: 19px; }
    .login-card .brand-name { font-size: 20px; }
    .login-card .tagline { text-align: center; color: var(--light-grayish); font-size: 12.5px; margin-bottom: 22px; }
    .login-card input { width: 100%; }
    .login-card button[type="submit"] { width: 100%; justify-content: center; padding: 12px; margin-top: 6px; font-size: 14px; }
</style>

<div class="login-wrapper">
    <div class="card login-card">
        <div class="brand">
            <div class="brand-mark">
                <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1.1L12 21l7.8-7.5 1-1.1a5.5 5.5 0 0 0 0-7.8z"/></svg>
            </div>
            <span class="brand-name">HopeLine</span>
        </div>
        <p class="tagline">Sign in to your account</p>

        <?php if ($error): ?>
            <div class="alert-banner alert-error" role="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="field">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" autocomplete="username" required autofocus>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" autocomplete="current-password" required>
            </div>

            <button type="submit">Log in</button>
        </form>
    </div>
</div>

<?php renderFooter(); ?>