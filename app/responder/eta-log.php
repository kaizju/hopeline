<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';


$unitStmt = $pdo->prepare("SELECT * FROM ptv_units WHERE responder_id = ? LIMIT 1");
$unitStmt->execute([$_SESSION['user_id']]);
$unit = $unitStmt->fetch(PDO::FETCH_ASSOC);

$flash = '';

if ($unit && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $dispatchId = (int)($_POST['dispatch_id'] ?? 0);
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'depart') {
            $pdo->prepare("UPDATE dispatch SET status='en_route', departed_at=NOW() WHERE id=? AND unit_id=?")
                ->execute([$dispatchId, $unit['id']]);
            $pdo->prepare("UPDATE ptv_units SET status='En Route' WHERE id=?")->execute([$unit['id']]);
            if (function_exists('logActivity')) {
                logActivity($pdo, $_SESSION['user_id'], $_SESSION['email'], 'departed_command_center', 'success');
            }
            $flash = 'Departure logged. Drive safe.';
        }

        if ($action === 'arrive') {
            $pdo->prepare("UPDATE dispatch SET status='on_site', arrived_at=NOW() WHERE id=? AND unit_id=?")
                ->execute([$dispatchId, $unit['id']]);
            $pdo->prepare("UPDATE ptv_units SET status='On Site' WHERE id=?")->execute([$unit['id']]);
            if (function_exists('logActivity')) {
                logActivity($pdo, $_SESSION['user_id'], $_SESSION['email'], 'arrived_at_site', 'success');
            }
            $flash = 'Arrival logged.';
        }
    } catch (PDOException $e) {
        $flash = 'Action failed: ' . $e->getMessage();
    }
}

// Re-fetch current dispatch + unit after any action
$dispatch = null;
if ($unit) {
    $unitStmt->execute([$_SESSION['user_id']]);
    $unit = $unitStmt->fetch(PDO::FETCH_ASSOC);

    $dStmt = $pdo->prepare("
        SELECT d.*, c.clip_ref, c.barangay, c.incident_type, c.severity
        FROM dispatch d
        JOIN clip_reports c ON c.id = d.clip_report_id
        WHERE d.unit_id = ? AND d.status IN ('assigned','en_route','on_site')
        ORDER BY d.dispatched_at DESC LIMIT 1
    ");
    $dStmt->execute([$unit['id']]);
    $dispatch = $dStmt->fetch(PDO::FETCH_ASSOC);
}

$unitStatus = $unit['status'] ?? 'Available';
$unreadAlerts = 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Depart / Arrive Log — HopeLine</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/sidebar.css">
<style>
    :root {
        --burnt-umber:#6d120b; --redwood:#b02029; --macadamia:#fbf0d8;
        --cool-blue:#113047; --light-grayish:#739ab9;
        --critical:#b02029; --high:#d9752b; --moderate:#d4ab2b; --low:#3f7a5c;
    }
    * { box-sizing:border-box; margin:0; padding:0; }
    body { font-family:'Segoe UI',-apple-system,BlinkMacSystemFont,sans-serif; background:#0c2334; display:flex; min-height:100vh; }
    .main { flex:1; padding:26px 32px 50px; color:var(--macadamia); max-width:720px; }
    .page-head { margin-bottom:22px; }
    .page-head h1 { font-size:21px; margin-bottom:4px; }
    .page-head p { color:var(--light-grayish); font-size:13px; }

    .flash { background:rgba(63,122,92,0.18); border:1px solid #3f7a5c; color:#b7ecd1; padding:10px 14px; border-radius:6px; font-size:13px; margin-bottom:16px; }

    .incident-strip {
        background:rgba(251,240,216,0.04); border:1px solid rgba(115,154,185,0.18); border-radius:10px;
        padding:14px 18px; margin-bottom:22px; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;
    }
    .incident-strip .title { font-size:14px; font-weight:700; }
    .incident-strip .sub { font-size:11px; color:var(--light-grayish); font-family:monospace; }
    .sev-badge { font-size:10px; font-weight:700; text-transform:uppercase; padding:3px 10px; border-radius:20px; }
    .sev-Critical { background:rgba(176,32,41,0.2); color:var(--critical); }
    .sev-High { background:rgba(217,117,43,0.2); color:var(--high); }
    .sev-Moderate { background:rgba(212,171,43,0.2); color:var(--moderate); }
    .sev-Low { background:rgba(63,122,92,0.2); color:var(--low); }

    /* Timeline */
    .timeline { display:flex; align-items:flex-start; margin-bottom:30px; }
    .tl-step { flex:1; text-align:center; position:relative; }
    .tl-circle {
        width:44px; height:44px; border-radius:50%; margin:0 auto 10px;
        display:flex; align-items:center; justify-content:center;
        background:rgba(115,154,185,0.12); border:2px solid rgba(115,154,185,0.3); color:var(--light-grayish);
    }
    .tl-step.done .tl-circle { background:var(--burnt-umber); border-color:var(--burnt-umber); color:var(--macadamia); }
    .tl-step.current .tl-circle { border-color:var(--redwood); color:var(--macadamia); animation:tlpulse 1.6s infinite; }
    @keyframes tlpulse { 0%{box-shadow:0 0 0 0 rgba(176,32,41,0.4);} 70%{box-shadow:0 0 0 8px rgba(176,32,41,0);} 100%{box-shadow:0 0 0 0 rgba(176,32,41,0);} }
    .tl-circle svg { width:18px; height:18px; }
    .tl-label { font-size:12px; font-weight:700; color:var(--macadamia); }
    .tl-time { font-size:10.5px; color:var(--light-grayish); margin-top:2px; }
    .tl-line { position:absolute; top:22px; left:calc(-50% + 22px); width:calc(100% - 44px); height:2px; background:rgba(115,154,185,0.25); z-index:-1; }
    .tl-step.done .tl-line, .tl-step.current .tl-line { background:var(--burnt-umber); }
    .tl-step:first-child .tl-line { display:none; }

    .action-card { background:rgba(251,240,216,0.04); border:1px solid rgba(115,154,185,0.18); border-radius:12px; padding:26px; text-align:center; }
    .elapsed-timer { font-size:36px; font-weight:700; font-family:monospace; margin-bottom:6px; }
    .elapsed-label { font-size:11.5px; color:var(--light-grayish); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:20px; }

    .btn-action {
        display:inline-flex; align-items:center; gap:10px; border:0; padding:16px 36px; border-radius:50px;
        background:var(--burnt-umber); color:var(--macadamia); font-weight:700; font-size:15px; cursor:pointer;
        transition:background 0.15s;
    }
    .btn-action:hover { background:var(--redwood); }
    .btn-action svg { width:18px; height:18px; }

    .btn-secondary {
        display:inline-block; margin-top:14px; font-size:12px; color:var(--light-grayish); text-decoration:none;
    }

    .done-msg { font-size:14px; color:var(--macadamia); }
    .empty-state { text-align:center; padding:60px 20px; color:var(--light-grayish); }
</style>
</head>
<body>

<?php require_once __DIR__ . '/../../assets/layouts/responder/responder_sidebar.php'; ?>

<main class="main">
    <div class="page-head">
        <h1>Depart / Arrive Log</h1>
        <p>Log your ETA — one tap when you leave, one tap when you arrive.</p>
    </div>

    <?php if ($flash): ?><div class="flash"><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>

    <?php if (!$unit): ?>
        <div class="empty-state">No PTV unit linked to your account. Contact your admin.</div>
    <?php elseif (!$dispatch): ?>
        <div class="empty-state">No active dispatch to log right now.</div>
    <?php else: ?>
        <div class="incident-strip">
            <div>
                <div class="title"><?php echo htmlspecialchars($dispatch['incident_type']); ?> — <?php echo htmlspecialchars($dispatch['barangay']); ?></div>
                <div class="sub"><?php echo htmlspecialchars($dispatch['clip_ref']); ?></div>
            </div>
            <span class="sev-badge sev-<?php echo $dispatch['severity']; ?>"><?php echo $dispatch['severity']; ?></span>
        </div>

        <?php
        $step = $dispatch['status']; // assigned | en_route | on_site
        function stepClass($target, $current) {
            $order = ['assigned' => 0, 'en_route' => 1, 'on_site' => 2];
            if ($order[$target] < $order[$current]) return 'done';
            if ($order[$target] === $order[$current]) return 'current';
            return '';
        }
        ?>
        <div class="timeline">
            <div class="tl-step <?php echo stepClass('assigned', $step) ?: 'done'; ?>">
                <div class="tl-circle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg></div>
                <div class="tl-label">Assigned</div>
                <div class="tl-time"><?php echo date('g:i A', strtotime($dispatch['dispatched_at'])); ?></div>
            </div>
            <div class="tl-step <?php echo stepClass('en_route', $step); ?>">
                <div class="tl-line"></div>
                <div class="tl-circle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M14 9h4l4 4v4a1 1 0 0 1-1 1h-2"/><circle cx="6.5" cy="18.5" r="2.5"/><circle cx="17.5" cy="18.5" r="2.5"/></svg></div>
                <div class="tl-label">Departed</div>
                <div class="tl-time"><?php echo $dispatch['departed_at'] ? date('g:i A', strtotime($dispatch['departed_at'])) : '—'; ?></div>
            </div>
            <div class="tl-step <?php echo stepClass('on_site', $step); ?>">
                <div class="tl-line"></div>
                <div class="tl-circle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 12-9 12s-9-5-9-12a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg></div>
                <div class="tl-label">Arrived</div>
                <div class="tl-time"><?php echo $dispatch['arrived_at'] ? date('g:i A', strtotime($dispatch['arrived_at'])) : '—'; ?></div>
            </div>
        </div>

        <div class="action-card">
            <?php if ($step === 'assigned'): ?>
                <div class="elapsed-label">Ready to head out?</div>
                <form method="POST">
                    <input type="hidden" name="action" value="depart">
                    <input type="hidden" name="dispatch_id" value="<?php echo $dispatch['id']; ?>">
                    <button type="submit" class="btn-action">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                        Depart Command Center
                    </button>
                </form>

            <?php elseif ($step === 'en_route'): ?>
                <div class="elapsed-timer" id="elapsedTimer">00:00:00</div>
                <div class="elapsed-label">Time en route</div>
                <form method="POST">
                    <input type="hidden" name="action" value="arrive">
                    <input type="hidden" name="dispatch_id" value="<?php echo $dispatch['id']; ?>">
                    <button type="submit" class="btn-action">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M20 6 9 17l-5-5"/></svg>
                        Arrived at Site
                    </button>
                </form>
                <a href="<?php echo BASE_URL; ?>/app/responder/report-delay.php" class="btn-secondary">Running late? Report a delay →</a>
                <script>
                    const departedAt = new Date("<?php echo date('c', strtotime($dispatch['departed_at'])); ?>").getTime();
                    function tick() {
                        const diff = Math.max(0, Date.now() - departedAt);
                        const h = String(Math.floor(diff / 3600000)).padStart(2,'0');
                        const m = String(Math.floor((diff % 3600000) / 60000)).padStart(2,'0');
                        const s = String(Math.floor((diff % 60000) / 1000)).padStart(2,'0');
                        document.getElementById('elapsedTimer').textContent = h + ':' + m + ':' + s;
                    }
                    tick(); setInterval(tick, 1000);
                </script>

            <?php else: ?>
                <div class="done-msg">✅ You've arrived on site. Awaiting resolution from the command center.</div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</main>

</body>
</html>