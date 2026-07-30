<?php
require_once 'config/config.php';
require_once 'config/functions.php';
require_once 'includes/activity-logger.php';
// uncomment on deployment
/*
require_once $_SERVER['DOCUMENT_ROOT'] . '/test/config/config.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/test/config/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/test/includes/activity-logger.php';
*/
if (isLoggedIn()) {
    switch ($_SESSION['role']) {
        case 'admin':
            redirect('/app/admin/dashboard.php');
            break;
        case 'manager':
            redirect('/app/manager/dashboard.php');
            break;
        case 'user':
            redirect('/app/responder/dashboard.php');
            break;
    }
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND is_verified = 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password'])) {
        // Successful login
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['role'] = $user['role'];

        // Log successful login
        logActivity($pdo, $user['id'], $user['email'], 'login', 'success');

        switch ($user['role']) {
            case 'admin':
                redirect('/app/admin/dashboard.php');
                break;
            case 'manager':
                redirect('/app/manager/dashboard.php');
                break;
            case 'user':
                redirect('/app/responder/dashboard.php');
                break;
        }
    } else {
        // Failed login
        $error = "Invalid credentials or email not verified";

        // Log failed login attempt
        logActivity($pdo, null, $email, 'login', 'failed');
    }
}

renderHeader('Login');
?>

<style>
    :root {
        --burnt-umber: #6d120b;
        --redwood: #b02029;
        --macadamia: #fbf0d8;
        --cool-blue: #113047;
        --light-grayish: #739ab9;
    }

    body {
        background: var(--cool-blue);
        font-family: 'Segoe UI', Arial, sans-serif;
    }

    .login-wrapper {
        display: flex;
        justify-content: center;
        align-items: center;
        min-height: 90vh;
        padding: 20px;
    }

    .login-card {
        background: var(--macadamia);
        color: var(--cool-blue);
        width: 100%;
        max-width: 380px;
        padding: 30px 28px;
        border-radius: 6px;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35);
    }

    .login-card h1 {
        color: var(--burnt-umber);
        font-size: 22px;
        margin: 0 0 20px;
        text-align: center;
    }

    .login-card .form-group {
        margin-bottom: 16px;
    }

    .login-card label {
        display: block;
        margin-bottom: 6px;
        font-weight: 600;
        color: var(--cool-blue);
    }

    .login-card input[type="email"],
    .login-card input[type="password"] {
        width: 100%;
        box-sizing: border-box;
        padding: 10px 12px;
        border: 1px solid var(--light-grayish);
        border-radius: 4px;
        font-size: 14px;
    }

    .login-card input:focus {
        outline: none;
        border-color: var(--redwood);
        box-shadow: 0 0 0 3px rgba(176, 32, 41, 0.2);
    }

    .login-card button {
        width: 100%;
        padding: 11px;
        margin-top: 8px;
        border: 0;
        border-radius: 50px;
        background: var(--burnt-umber);
        color: var(--macadamia);
        font-weight: 600;
        font-size: 15px;
        cursor: pointer;
        transition: background 0.2s ease-in-out;
    }

    .login-card button:hover {
        background: var(--redwood);
    }

    .login-card .error {
        background: var(--redwood);
        color: var(--macadamia);
        padding: 10px 12px;
        border-radius: 4px;
        margin-bottom: 16px;
        font-size: 14px;
        text-align: center;
    }

    .login-card .info-box {
        margin-top: 20px;
        padding: 12px;
        background: var(--light-grayish);
        color: var(--cool-blue);
        border-radius: 4px;
        font-size: 12px;
        text-align: center;
        line-height: 1.5;
    }
</style>

<div class="login-wrapper">
    <div class="login-card">
        <h1>HopeLine Login</h1>

        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label for="email">Email:</label>
                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" required>
            </div>

            <div class="form-group">
                <label for="password">Password:</label>
                <input type="password" id="password" name="password" required>
            </div>

            <button type="submit">Login</button>
        </form>

        <div class="info-box">
            <strong>Test Accounts (password: password123):</strong><br>
            admin@example.com | manager@example.com | user@example.com
        </div>
    </div>
</div>

<?php renderFooter(); ?>