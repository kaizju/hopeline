<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';


$unitStmt = $pdo->prepare("SELECT * FROM ptv_units WHERE responder_id = ? LIMIT 1");
$unitStmt->execute([$_SESSION['user_id']]);
$unit = $unitStmt->fetch(PDO::FETCH_ASSOC);

$flash = '';

if ($unit && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'report_delay') {
            $dispatchId = (int)($_POST['dispatch_id'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');
            $notes = trim($_POST['notes'] ?? '');

            $stmt = $pdo->prepare("INSERT INTO delay_logs (dispatch_id, unit_id, reason, notes, logged_by, is_manual) VALUES (?, ?, ?, ?, ?, 0)");
            $stmt->execute([$dispatchId, $unit['id'], $reason, $notes, $_SESSION['user_id']]);
            if (function_exists('logActivity')) {
                logActivity($pdo, $_SESSION['user_id'], $_SESSION['email'], 'delay_reported', 'success');
            }
            $flash = 'Delay reported. The command center has been notified.';
        }

        if ($action === 'resolve_delay') {
            $delayId = (int)($_POST['delay_id'] ?? 0);
            $pdo->prepare("UPDATE delay_logs SET resolved_at = NOW() WHERE id = ? AND unit_id = ?")->execute([$delayId, $unit['id']]);
            $flash = "Delay cleared — you're moving again.";
        }
    } catch (PDOException $e) {
        $flash = 'Action failed: ' . $e->getMessage();
    }
}

$dispatch = null;
$activeDelay = null;

if ($unit) {
    $dStmt = $pdo->prepare("
        SELECT d.*, c.clip_ref, c.barangay, c.incident_type
        FROM dispatch d
        JOIN clip_reports c ON c.id = d.clip_report_id
        WHERE d.unit_id = ? AND d.status IN ('assigned','en_route','on_site')
        ORDER BY d.dispatched_at DESC LIMIT 1
    ");
    $dStmt->execute([$unit['id']]);
    $dispatch = $dStmt->fetch(PDO::FETCH_ASSOC);

    if ($dispatch) {
        $delStmt = $pdo->prepare("SELECT * FROM delay_logs WHERE dispatch_id = ? AND resolved_at IS NULL ORDER BY started_at DESC LIMIT 1");
        $delStmt->execute([$dispatch['id']]);
        $activeDelay = $delStmt->fetch(PDO::FETCH_ASSOC);
    }
}

$unitStatus = $unit['status'] ?? 'Available';
$unreadAlerts = 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Report Delay — HopeLine</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/sidebar.css">
<style>
    :root {
        --burnt-umber:#6d120b; --redwood:#b02029; --macadamia:#fbf0d8;
        --cool-blue:#113047; --light-grayish:#739ab9; --high:#d9752b;
    }
    * { box-sizing:border-box; margin:0; padding:0; }
    body { font-family:'Segoe UI',-apple-system,BlinkMacSystemFont,sans-serif; background:#0c2334; display:flex; min-height:100vh; }
    .main { flex:1; padding:26px 32px 50px; color:var(--macadamia); max-width:640px; }
    .page-head { margin-bottom:20px; }
    .page-head h1 { font-size:21px; margin-bottom:4px; }
    .page-head p { color:var(--light-grayish); font-size:13px; }

    .flash { background:rgba(63,122,92,0.18); border:1px solid #3f7a5c; color:#b7ecd1; padding:10px 14px; border-radius:6px; font-size:13px; margin-bottom:16px; }

    .incident-strip {
        background:rgba(251,240,216,0.04); border:1px solid rgba(115,154,185,0.18); border-radius:10px;
        padding:14px 18px; margin-bottom:20px;
    }
    .incident-strip .title { font-size:14px; font-weight:700; }
    .incident-strip .sub { font-size:11px; color:var(--light-grayish); font-family:monospace; }

    .card { background:rgba(251,240,216,0.04); border:1px solid rgba(115,154,185,0.18); border-radius:12px; padding:22px 24px; }

    /* Active delay banner */
    .active-delay-card {
        background:rgba(217,117,43,0.1); border:1px solid var(--high); border-radius:12px; padding:20px 22px; text-align:center;
    }
    .active-delay-card .icon { width:36px; height:36px; margin:0 auto 10px; color:var(--high); }
    .active-delay-card h2 { font-size:15px; margin-bottom:4px; }
    .active-delay-card .reason { font-size:13px; color:var(--macadamia); margin-bottom:2px; }
    .active-delay-card .duration { font-size:11.5px; color:var(--light-grayish); margin-bottom:16px; }

    .btn-resolve {
        background:rgba(63,122,92,0.2); color:#7fd6a5; border:1px solid #3f7a5c; border-radius:20px;
        padding:10px 22px; font-size:13px; font-weight:700; cursor:pointer;
    }
    .btn-resolve:hover { background:rgba(63,122,92,0.35); }

    label { display:block; font-size:12px; font-weight:600; margin-bottom:6px; }
    select, textarea {
        width:100%; background:rgba(251,240,216,0.06); border:1px solid rgba(115,154,185,0.28);
        border-radius:6px; padding:9px 11px; color:var(--macadamia); font-size:13px; outline:none; font-family:inherit; margin-bottom:16px;
    }
    select:focus, textarea:focus { border-color:var(--redwood); }
    textarea { resize:vertical; min-height:70px; }

    .btn-submit {
        width:100%; background:var(--burnt-umber); color:var(--macadamia); border:0; padding:13px; border-radius:10px;
        font-weight:700; font-size:14px; cursor:pointer;
    }
    .btn-submit:hover { background:var(--redwood); }

    .empty-state { text-align:center; padding:60px 20px; color:var(--light-grayish); }
</style>
</head>
<body>

<?php require_once __DIR__ . '/../../assets/layouts/responder/responder_sidebar.php'; ?>

<main class="main">
    <div class="page-head">
        <h1>Report Delay</h1>
        <p>Let the command center know if something's slowing you down.</p>
    </div>

    <?php if ($flash): ?><div class="flash"><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>

    <?php if (!$unit): ?>
        <div class="empty-state">No PTV unit linked to your account. Contact your admin.</div>
    <?php elseif (!$dispatch): ?>
        <div class="empty-state">No active dispatch right now — nothing to report a delay on.</div>
    <?php else: ?>
        <div class="incident-strip">
            <div class="title"><?php echo htmlspecialchars($dispatch['incident_type']); ?> — <?php echo htmlspecialchars($dispatch['barangay']); ?></div>
            <div class="sub"><?php echo htmlspecialchars($dispatch['clip_ref']); ?></div>
        </div>

        <?php if ($activeDelay): ?>
            <div class="active-delay-card">
                <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 9v4M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
                <h2>Delay currently active</h2>
                <div class="reason"><?php echo htmlspecialchars($activeDelay['reason']); ?></div>
                <div class="duration">Reported <?php echo round((time() - strtotime($activeDelay['started_at'])) / 60); ?>m ago</div>
                <form method="POST">
                    <input type="hidden" name="action" value="resolve_delay">
                    <input type="hidden" name="delay_id" value="<?php echo $activeDelay['id']; ?>">
                    <button type="submit" class="btn-resolve">I'm Moving Again</button>
                </form>
            </div>
        <?php else: ?>
            <div class="card">
                <form method="POST">
                    <input type="hidden" name="action" value="report_delay">
                    <input type="hidden" name="dispatch_id" value="<?php echo $dispatch['id']; ?>">

                    <label for="reason">Reason for Delay</label>
                    <select name="reason" id="reason" required>
                        <option value="Road obstruction/traffic">Road obstruction/traffic</option>
                        <option value="Vehicle breakdown/mechanical issue">Vehicle breakdown/mechanical issue</option>
                        <option value="Weather/flooding">Weather/flooding</option>
                        <option value="Wrong/unclear location">Wrong/unclear location</option>
                        <option value="Fuel issue">Fuel issue</option>
                        <option value="Waiting for backup unit">Waiting for backup unit</option>
                        <option value="Other">Other</option>
                    </select>

                    <label for="notes">Notes <span style="color:var(--light-grayish); font-weight:400;">(optional)</span></label>
                    <textarea name="notes" id="notes" placeholder="Any details that would help the dispatcher"></textarea>

                    <button type="submit" class="btn-submit">Report Delay</button>
                </form>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</main>

</body>
</html>