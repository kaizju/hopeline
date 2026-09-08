<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';


$unitStmt = $pdo->prepare("SELECT * FROM ptv_units WHERE responder_id = ? LIMIT 1");

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
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/app.css">

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