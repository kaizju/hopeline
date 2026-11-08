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

/* Small helper: inline SVG icons (same stroke style as the rest of the app) */
function cp_icon($name) {
    static $p = [
        'lock'   => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'key'    => '<circle cx="8" cy="15" r="4"/><path d="m10.85 12.15 8.65-8.65M17 6l3 3M14 9l2 2"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
        'eye'    => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'eyeoff' => '<path d="M17.94 17.94A10.9 10.9 0 0 1 12 19c-6.5 0-10-7-10-7a18.7 18.7 0 0 1 5.06-5.94M9.9 4.24A9.8 9.8 0 0 1 12 4c6.5 0 10 7 10 7a18.5 18.5 0 0 1-2.16 3.19M14.12 14.12a3 3 0 1 1-4.24-4.24M1 1l22 22"/>',
        'check'  => '<path d="M20 6 9 17l-5-5"/>',
        'back'   => '<path d="M19 12H5M12 19l-7-7 7-7"/>',
        'info'   => '<circle cx="12" cy="12" r="9"/><path d="M12 8h.01M11 12h1v4h1"/>',
        'alert'  => '<circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}

$role = $_SESSION['role'] ?? '';
$dashboardUrl = BASE_URL . '/app/' . ($role === 'admin' ? 'admin' : ($role === 'manager' ? 'manager' : 'responder')) . '/dashboard.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Change Password — HopeLine</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/app.css">
    <style>
        .cp-wrap { display: grid; grid-template-columns: minmax(0, 1.5fr) minmax(0, 1fr); gap: 18px; align-items: start; }
        .cp-wrap > * { min-width: 0; }

        .cp-head { display: flex; align-items: center; gap: 14px; margin-bottom: 22px; }
        .cp-head-ico { width: 46px; height: 46px; border-radius: 12px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; background: rgba(254,119,67,.16); color: var(--burnt-umber); }
        .cp-head-ico svg { width: 22px; height: 22px; }
        .cp-head h1 { font-size: 21px; margin-bottom: 2px; }
        .cp-head p { font-size: 13px; color: var(--light-grayish); }

        .cp-back { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 600; color: var(--light-grayish); text-decoration: none; margin-bottom: 14px; }
        .cp-back svg { width: 14px; height: 14px; }
        .cp-back:hover { color: var(--macadamia); }

        .cp-card { margin-bottom: 0; padding: 22px; }
        .cp-card h2 { font-size: 14.5px; margin-bottom: 4px; }
        .cp-sub { font-size: 12px; color: var(--light-grayish); margin-bottom: 18px; }

        /* Step label + field */
        .cp-field { margin-bottom: 18px; }
        .cp-field label { display: flex; align-items: center; gap: 8px; }
        .cp-step { width: 20px; height: 20px; border-radius: 50%; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; font-size: 10.5px; font-weight: 700; background: var(--burnt-umber); color: #fff; }

        .cp-input { position: relative; }
        .cp-wrap .cp-input input[type="password"],
        .cp-wrap .cp-input input[type="text"] { width: 100%; padding: 12px 48px 12px 44px; font-size: 14px; border-radius: 8px; }
        .cp-wrap .cp-input .lead { z-index: 1; }
        .cp-input .lead { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); width: 17px; height: 17px; color: var(--light-grayish); pointer-events: none; }
        .cp-input .lead svg { width: 17px; height: 17px; display: block; }
        .cp-input:focus-within .lead { color: var(--redwood); }
        .cp-toggle { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); width: 34px; height: 34px; border: 0; border-radius: 8px; background: none; color: var(--light-grayish); cursor: pointer; display: flex; align-items: center; justify-content: center; padding: 0; }
        .cp-toggle:hover { background: rgba(var(--border-rgb), .18); color: var(--macadamia); }
        .cp-toggle svg { width: 17px; height: 17px; }
        .cp-toggle .off { display: none; }
        .cp-toggle.showing .on { display: none; }
        .cp-toggle.showing .off { display: block; }

        .cp-hint { display: flex; align-items: center; gap: 6px; margin-top: 7px; font-size: 11.5px; min-height: 16px; color: var(--light-grayish); }
        .cp-hint svg { width: 13px; height: 13px; flex-shrink: 0; }
        .cp-hint.ok { color: #3f7a5c; }
        .cp-hint.bad { color: var(--critical); }

        .cp-divider { height: 1px; background: rgba(var(--border-rgb), .18); margin: 4px 0 20px; }

        /* Strength meter */
        .cp-meter { display: grid; grid-template-columns: repeat(4, 1fr); gap: 5px; margin-top: 10px; }
        .cp-meter i { height: 5px; border-radius: 10px; background: rgba(var(--border-rgb), .2); transition: background .2s; }
        .cp-meter[data-level="1"] i:nth-child(-n+1) { background: var(--critical); }
        .cp-meter[data-level="2"] i:nth-child(-n+2) { background: var(--high); }
        .cp-meter[data-level="3"] i:nth-child(-n+3) { background: var(--moderate); }
        .cp-meter[data-level="4"] i:nth-child(-n+4) { background: var(--low); }
        .cp-strength { font-size: 11.5px; margin-top: 6px; color: var(--light-grayish); }

        .cp-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 6px; }
        .cp-actions .btn-primary { padding: 12px 24px; }
        .cp-actions .btn-primary svg { width: 16px; height: 16px; }
        .cp-actions .btn-primary:disabled { opacity: .45; cursor: not-allowed; }
        .cp-cancel { display: inline-flex; align-items: center; padding: 12px 20px; border-radius: 50px; font-size: 13px; font-weight: 600; text-decoration: none; color: var(--light-grayish); border: 1px solid rgba(var(--border-rgb), .3); }
        .cp-cancel:hover { color: var(--macadamia); border-color: var(--light-grayish); }

        /* Requirements side card */
        .cp-req { list-style: none; margin-top: 14px; }
        .cp-req li { display: flex; align-items: center; gap: 10px; padding: 9px 0; font-size: 12.5px; color: var(--light-grayish); border-bottom: 1px dashed rgba(var(--border-rgb), .18); }
        .cp-req li:last-child { border-bottom: 0; }
        .cp-req .dot { width: 20px; height: 20px; border-radius: 50%; flex-shrink: 0; display: flex; align-items: center; justify-content: center; border: 1.5px solid rgba(var(--border-rgb), .4); color: transparent; transition: all .15s; }
        .cp-req .dot svg { width: 11px; height: 11px; }
        .cp-req li.done { color: var(--macadamia); }
        .cp-req li.done .dot { background: #3f7a5c; border-color: #3f7a5c; color: #fff; }
        .cp-tip { display: flex; gap: 10px; margin-top: 16px; padding: 12px 14px; border-radius: 10px; font-size: 12px; line-height: 1.5; color: var(--light-grayish); background: rgba(var(--surface-rgb), .05); border: 1px solid rgba(var(--border-rgb), .18); }
        .cp-tip svg { width: 16px; height: 16px; flex-shrink: 0; margin-top: 1px; color: var(--burnt-umber); }

        @media (max-width: 900px) { .cp-wrap { grid-template-columns: minmax(0, 1fr); } }
        @media (max-width: 600px) {
            .cp-card { padding: 16px; }
            .cp-actions > * { flex: 1 1 100%; justify-content: center; }
        }
    </style>
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

    <a class="cp-back" href="<?php echo htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php echo cp_icon('back'); ?> Back to dashboard</a>

    <div class="cp-head">
        <div class="cp-head-ico"><?php echo cp_icon('shield'); ?></div>
        <div>
            <h1>Change password</h1>
            <p>Keep your HopeLine account secure by updating your password.</p>
        </div>
    </div>

    <?php if ($flash !== ''): ?>
        <div class="alert-banner <?php echo $flashType === 'error' ? 'alert-error' : 'alert-success'; ?>" role="alert" style="display:flex;align-items:center;gap:10px;">
            <span style="width:18px;height:18px;flex-shrink:0;display:inline-flex;"><?php echo cp_icon($flashType === 'error' ? 'alert' : 'check'); ?></span>
            <?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php endif; ?>

    <div class="cp-wrap">

        <!-- ============ Form ============ -->
        <section class="card cp-card">
            <h2>Update your password</h2>
            <div class="cp-sub">Fill in the three fields below, top to bottom.</div>

            <form method="POST" action="" autocomplete="on" id="cpForm">
                <?php echo csrfField(); ?>

                <div class="cp-field">
                    <label for="current_password"><span class="cp-step">1</span> Current password</label>
                    <div class="cp-input">
                        <span class="lead"><?php echo cp_icon('lock'); ?></span>
                        <input id="current_password" type="password" name="current_password" autocomplete="current-password" placeholder="Enter your current password" required>
                        <button type="button" class="cp-toggle" data-target="current_password" aria-label="Show password"><span class="on"><?php echo cp_icon('eye'); ?></span><span class="off"><?php echo cp_icon('eyeoff'); ?></span></button>
                    </div>
                </div>

                <div class="cp-divider"></div>

                <div class="cp-field">
                    <label for="new_password"><span class="cp-step">2</span> New password</label>
                    <div class="cp-input">
                        <span class="lead"><?php echo cp_icon('key'); ?></span>
                        <input id="new_password" type="password" name="new_password" minlength="12" autocomplete="new-password" placeholder="At least 12 characters" required>
                        <button type="button" class="cp-toggle" data-target="new_password" aria-label="Show password"><span class="on"><?php echo cp_icon('eye'); ?></span><span class="off"><?php echo cp_icon('eyeoff'); ?></span></button>
                    </div>
                    <div class="cp-meter" id="cpMeter" data-level="0"><i></i><i></i><i></i><i></i></div>
                    <div class="cp-strength" id="cpStrength">Enter a password to see its strength.</div>
                </div>

                <div class="cp-field">
                    <label for="confirm_password"><span class="cp-step">3</span> Confirm new password</label>
                    <div class="cp-input">
                        <span class="lead"><?php echo cp_icon('key'); ?></span>
                        <input id="confirm_password" type="password" name="confirm_password" minlength="12" autocomplete="new-password" placeholder="Re-enter the new password" required>
                        <button type="button" class="cp-toggle" data-target="confirm_password" aria-label="Show password"><span class="on"><?php echo cp_icon('eye'); ?></span><span class="off"><?php echo cp_icon('eyeoff'); ?></span></button>
                    </div>
                    <div class="cp-hint" id="cpMatch"></div>
                </div>

                <div class="cp-actions">
                    <button class="btn-primary" type="submit" id="cpSubmit"><?php echo cp_icon('check'); ?> Change password</button>
                    <a class="cp-cancel" href="<?php echo htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8'); ?>">Cancel</a>
                </div>
            </form>
        </section>

        <!-- ============ Requirements ============ -->
        <aside class="card cp-card">
            <h2>Password requirements</h2>
            <div class="cp-sub" style="margin-bottom:0;">Your new password must include:</div>
            <ul class="cp-req" id="cpReq">
                <li data-rule="len"><span class="dot"><?php echo cp_icon('check'); ?></span> At least 12 characters</li>
                <li data-rule="upper"><span class="dot"><?php echo cp_icon('check'); ?></span> One uppercase letter (A–Z)</li>
                <li data-rule="lower"><span class="dot"><?php echo cp_icon('check'); ?></span> One lowercase letter (a–z)</li>
                <li data-rule="num"><span class="dot"><?php echo cp_icon('check'); ?></span> One number (0–9)</li>
                <li data-rule="sym"><span class="dot"><?php echo cp_icon('check'); ?></span> One symbol (e.g. ! @ # $ %)</li>
                <li data-rule="diff"><span class="dot"><?php echo cp_icon('check'); ?></span> Different from current password</li>
            </ul>
            <div class="cp-tip"><?php echo cp_icon('info'); ?><span>Tip: a short sentence with numbers and symbols is easy to remember and hard to guess.</span></div>
        </aside>

    </div>
</main>

<script>
(function () {
    var $ = function (id) { return document.getElementById(id); };
    var cur = $('current_password'), np = $('new_password'), cf = $('confirm_password');

    /* Show / hide password */
    document.querySelectorAll('.cp-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = $(btn.dataset.target);
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.classList.toggle('showing', show);
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
            input.focus();
        });
    });

    /* Live requirements + strength */
    var tests = {
        len:   function (v) { return v.length >= 12; },
        upper: function (v) { return /[A-Z]/.test(v); },
        lower: function (v) { return /[a-z]/.test(v); },
        num:   function (v) { return /[0-9]/.test(v); },
        sym:   function (v) { return /[^A-Za-z0-9]/.test(v); },
        diff:  function (v) { return v !== '' && v !== cur.value; }
    };
    var labels = ['Too weak', 'Weak', 'Fair', 'Good', 'Strong'];

    function checkNew() {
        var v = np.value, passed = 0, complexity = 0;
        document.querySelectorAll('#cpReq li').forEach(function (li) {
            var rule = li.dataset.rule, ok = tests[rule](v);
            li.classList.toggle('done', ok);
            if (ok && rule !== 'diff') passed++;
        });
        ['upper', 'lower', 'num', 'sym'].forEach(function (r) { if (tests[r](v)) complexity++; });
        var level = v === '' ? 0 : Math.min(4, Math.max(1, complexity - (v.length < 12 ? 1 : 0)));
        if (v !== '' && v.length >= 16 && complexity === 4) level = 4;
        $('cpMeter').dataset.level = level;
        $('cpStrength').textContent = v === '' ? 'Enter a password to see its strength.' : 'Strength: ' + labels[level];
        checkMatch();
    }

    function checkMatch() {
        var box = $('cpMatch');
        if (cf.value === '') { box.className = 'cp-hint'; box.innerHTML = ''; return; }
        var ok = cf.value === np.value;
        box.className = 'cp-hint ' + (ok ? 'ok' : 'bad');
        box.innerHTML = (ok
            ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg> Passwords match'
            : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg> Passwords do not match');
    }

    np.addEventListener('input', checkNew);
    cur.addEventListener('input', checkNew);
    cf.addEventListener('input', checkMatch);

    /* Block submit only when the client can already tell it will fail (server still validates) */
    $('cpForm').addEventListener('submit', function (e) {
        if (np.value !== cf.value) { e.preventDefault(); cf.focus(); checkMatch(); }
    });
})();
</script>
</body>
</html>