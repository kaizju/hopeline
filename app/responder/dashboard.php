<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';



// Responder's linked unit
$unitStmt = $pdo->prepare("SELECT * FROM ptv_units WHERE responder_id = ? LIMIT 1");
$unitStmt->execute([$_SESSION['user_id']]);
$unit = $unitStmt->fetch(PDO::FETCH_ASSOC);
$unitStatus = $unit['status'] ?? 'Available';

$dispatch = null;
$activeDelay = null;
$todayCount = 0;
$recentHistory = [];

if ($unit) {
    // Active dispatch
    $dStmt = $pdo->prepare("
        SELECT d.*, c.clip_ref, c.barangay, c.incident_type, c.severity
        FROM dispatch d
        JOIN clip_reports c ON c.id = d.clip_report_id
        WHERE d.unit_id = ? AND d.status IN ('assigned','en_route','on_site')
        ORDER BY d.dispatched_at DESC LIMIT 1
    ");
    $dStmt->execute([$unit['id']]);
    $dispatch = $dStmt->fetch(PDO::FETCH_ASSOC);

    // Active delay tied to that dispatch
    if ($dispatch) {
        $delStmt = $pdo->prepare("SELECT * FROM delay_logs WHERE dispatch_id = ? AND resolved_at IS NULL ORDER BY started_at DESC LIMIT 1");
        $delStmt->execute([$dispatch['id']]);
        $activeDelay = $delStmt->fetch(PDO::FETCH_ASSOC);
    }

    // Dispatches completed today
    $todayStmt = $pdo->prepare("SELECT COUNT(*) FROM dispatch WHERE unit_id = ? AND status = 'resolved' AND DATE(resolved_at) = CURDATE()");
    $todayStmt->execute([$unit['id']]);
    $todayCount = $todayStmt->fetchColumn();

    // Last 3 completed dispatches
    $histStmt = $pdo->prepare("
        SELECT d.*, c.clip_ref, c.barangay, c.incident_type, c.severity,
               TIMESTAMPDIFF(SECOND, d.departed_at, d.arrived_at) AS travel_seconds
        FROM dispatch d
        JOIN clip_reports c ON c.id = d.clip_report_id
        WHERE d.unit_id = ? AND d.status = 'resolved'
        ORDER BY d.resolved_at DESC LIMIT 3
    ");
    $histStmt->execute([$unit['id']]);
    $recentHistory = $histStmt->fetchAll(PDO::FETCH_ASSOC);
}

$unreadAlerts = 0;

function fmtDuration($seconds) {
    if ($seconds === null) return '—';
    $m = floor($seconds / 60);
    $s = $seconds % 60;
    return $m . 'm ' . $s . 's';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Responder Dashboard — HopeLine</title>
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
    .main { flex:1; padding:26px 32px 50px; color:var(--macadamia); max-width:900px; }
    .page-head { margin-bottom:20px; }
    .page-head h1 { font-size:21px; margin-bottom:4px; }
    .page-head p { color:var(--light-grayish); font-size:13px; }

    /* Status banner */
    .status-banner {
        display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap;
        border-radius:12px; padding:16px 20px; margin-bottom:20px; border:1px solid;
    }
    .status-banner.available { background:rgba(63,122,92,0.1); border-color:#3f7a5c; }
    .status-banner.assigned  { background:rgba(217,117,43,0.1); border-color:var(--high); }
    .status-banner.en-route  { background:rgba(217,117,43,0.14); border-color:var(--high); }
    .status-banner.on-site   { background:rgba(176,32,41,0.1); border-color:var(--redwood); }

    .status-banner .left { display:flex; align-items:center; gap:12px; }
    .status-banner .icon-wrap {
        width:40px; height:40px; border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0;
        background:rgba(251,240,216,0.08);
    }
    .status-banner .icon-wrap svg { width:20px; height:20px; }
    .status-banner .title { font-size:14.5px; font-weight:700; }
    .status-banner .sub { font-size:12px; color:var(--light-grayish); }

    .btn-inline {
        background:var(--burnt-umber); color:var(--macadamia); border:0; padding:10px 20px; border-radius:50px;
        font-weight:700; font-size:12.5px; cursor:pointer; text-decoration:none; display:inline-block; white-space:nowrap;
    }
    .btn-inline:hover { background:var(--redwood); }

    .delay-note { font-size:11.5px; color:var(--high); margin-top:6px; font-weight:600; }

    /* Stat cards */
    .stats-grid { display:grid; grid-template-columns:repeat(2, 1fr); gap:14px; margin-bottom:20px; }
    .stat-card { background:rgba(251,240,216,0.04); border:1px solid rgba(115,154,185,0.18); border-radius:10px; padding:16px 18px; }
    .stat-value { font-size:24px; font-weight:700; }
    .stat-label { font-size:11.5px; color:var(--light-grayish); margin-top:2px; }

    /* Quick links */
    .quick-links { display:grid; grid-template-columns:repeat(2, 1fr); gap:12px; margin-bottom:20px; }
    .quick-link {
        display:flex; align-items:center; gap:12px; background:rgba(251,240,216,0.04); border:1px solid rgba(115,154,185,0.18);
        border-radius:10px; padding:14px 16px; text-decoration:none; color:var(--macadamia); transition:border-color 0.15s;
    }
    .quick-link:hover { border-color:var(--light-grayish); }
    .quick-link .qi { width:34px; height:34px; border-radius:8px; background:rgba(115,154,185,0.15); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .quick-link .qi svg { width:16px; height:16px; color:var(--macadamia); }
    .quick-link .qt { font-size:13px; font-weight:700; }
    .quick-link .qs { font-size:11px; color:var(--light-grayish); }

    /* Recent activity */
    .card { background:rgba(251,240,216,0.04); border:1px solid rgba(115,154,185,0.18); border-radius:10px; padding:18px 20px; }
    .card-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
    .card-header h2 { font-size:14px; font-weight:700; }
    .card-header a { font-size:11.5px; color:var(--light-grayish); text-decoration:none; }

    .hist-row { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:9px 0; border-bottom:1px solid rgba(115,154,185,0.1); }
    .hist-row:last-child { border-bottom:none; }
    .hist-title { font-size:12.5px; font-weight:600; }
    .hist-sub { font-size:10.5px; color:var(--light-grayish); }
    .sev-badge { font-size:9px; font-weight:700; text-transform:uppercase; padding:2px 8px; border-radius:20px; }
    .sev-Critical { background:rgba(176,32,41,0.2); color:var(--critical); }
    .sev-High { background:rgba(217,117,43,0.2); color:var(--high); }
    .sev-Moderate { background:rgba(212,171,43,0.2); color:var(--moderate); }
    .sev-Low { background:rgba(63,122,92,0.2); color:var(--low); }

    .empty-mini { text-align:center; padding:20px; color:var(--light-grayish); font-size:12px; }
    .empty-state { text-align:center; padding:60px 20px; color:var(--light-grayish); }
</style>
</head>
<body>

<?php require_once __DIR__ . '/../../assets/layouts/responder/responder_sidebar.php'; ?>

<main class="main">
    <div class="page-head">
        <h1>Welcome back<?php echo isset($_SESSION['email']) ? ', ' . htmlspecialchars(explode('@', $_SESSION['email'])[0]) : ''; ?></h1>
        <p><?php echo date('l, F j, Y'); ?></p>
    </div>

    <?php if (!$unit): ?>
        <div class="empty-state">No PTV unit linked to your account yet. Contact your LDRRMO admin.</div>
    <?php else: ?>

        <?php if ($dispatch): ?>
            <?php
            $bannerClass = ['assigned' => 'assigned', 'en_route' => 'en-route', 'on_site' => 'on-site'][$dispatch['status']];
            $ctaLabel = ['assigned' => 'Depart Now', 'en_route' => 'Mark Arrived', 'on_site' => 'View Status'][$dispatch['status']];
            $statusText = ['assigned' => 'Awaiting departure', 'en_route' => 'En route', 'on_site' => 'On site'][$dispatch['status']];
            ?>
            <div class="status-banner <?php echo $bannerClass; ?>">
                <div class="left">
                    <div class="icon-wrap">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 15V7a2 2 0 0 1 2-2h5l2 2h5a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/></svg>
                    </div>
                    <div>
                        <div class="title"><?php echo htmlspecialchars($dispatch['incident_type']); ?> — <?php echo htmlspecialchars($dispatch['barangay']); ?></div>
                        <div class="sub"><?php echo htmlspecialchars($dispatch['clip_ref']); ?> · <?php echo $statusText; ?></div>
                        <?php if ($activeDelay): ?><div class="delay-note">⚠ Active delay: <?php echo htmlspecialchars($activeDelay['reason']); ?></div><?php endif; ?>
                    </div>
                </div>
                <a href="<?php echo BASE_URL; ?>/app/responder/eta-log.php" class="btn-inline"><?php echo $ctaLabel; ?></a>
            </div>
        <?php else: ?>
            <div class="status-banner available">
                <div class="left">
                    <div class="icon-wrap">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>
                    </div>
                    <div>
                        <div class="title">You're Available</div>
                        <div class="sub">No active assignment right now</div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo htmlspecialchars($unit['unit_name']); ?></div>
                <div class="stat-label">Your Unit (<?php echo htmlspecialchars($unit['plate_no']); ?>)</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $todayCount; ?></div>
                <div class="stat-label">Dispatches Completed Today</div>
            </div>
        </div>

        <div class="quick-links">
            <a href="<?php echo BASE_URL; ?>/app/responder/assignment.php" class="quick-link">
                <div class="qi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 15V7a2 2 0 0 1 2-2h5l2 2h5a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/></svg></div>
                <div><div class="qt">Assigned Incident</div><div class="qs">View current dispatch details</div></div>
            </a>
            <a href="<?php echo BASE_URL; ?>/app/responder/eta-log.php" class="quick-link">
                <div class="qi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></div>
                <div><div class="qt">Depart / Arrive Log</div><div class="qs">Log your ETA</div></div>
            </a>
            <a href="<?php echo BASE_URL; ?>/app/responder/report-delay.php" class="quick-link">
                <div class="qi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg></div>
                <div><div class="qt">Report Delay</div><div class="qs">Flag anything slowing you down</div></div>
            </a>
            <a href="<?php echo BASE_URL; ?>/app/responder/my-history.php" class="quick-link">
                <div class="qi"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 3v18h18"/><path d="M18 17V9M13 17V5M8 17v-3"/></svg></div>
                <div><div class="qt">My Dispatch History</div><div class="qs">Past completed dispatches</div></div>
            </a>
        </div>

        <div class="card">
            <div class="card-header">
                <h2>Recent Activity</h2>
                <a href="<?php echo BASE_URL; ?>/app/responder/my-history.php">View all →</a>
            </div>
            <?php if (empty($recentHistory)): ?>
                <div class="empty-mini">No completed dispatches yet.</div>
            <?php else: ?>
                <?php foreach ($recentHistory as $h): ?>
                <div class="hist-row">
                    <div>
                        <div class="hist-title"><?php echo htmlspecialchars($h['incident_type']); ?> — <?php echo htmlspecialchars($h['barangay']); ?></div>
                        <div class="hist-sub"><?php echo htmlspecialchars($h['clip_ref']); ?> · Travel: <?php echo fmtDuration($h['travel_seconds']); ?></div>
                    </div>
                    <span class="sev-badge sev-<?php echo $h['severity']; ?>"><?php echo $h['severity']; ?></span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

    <?php endif; ?>
</main>

</body>
</html>