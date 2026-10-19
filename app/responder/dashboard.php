<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';

requireRole('user');

$me = $pdo->prepare("SELECT team_id FROM users WHERE id = ?");
$me->execute([$_SESSION['user_id']]);
$myTeamId = (int)$me->fetchColumn();

$unitStmt = $pdo->prepare("
    SELECT u.* FROM ptv_units u
    JOIN dispatch d ON d.unit_id = u.id AND d.team_id = ? AND d.status IN ('assigned','en_route','on_site','returning')
    ORDER BY d.dispatched_at DESC, d.id DESC LIMIT 1");
$unitStmt->execute([$myTeamId]);
$unit = $unitStmt->fetch(PDO::FETCH_ASSOC);
if (!$unit) $unit = ['id' => 0, 'unit_name' => 'No vehicle assigned', 'plate_no' => '', 'status' => 'Available'];
$tn = $pdo->prepare("SELECT name FROM teams WHERE id = ?");
$tn->execute([$myTeamId]);
$teamName = $tn->fetchColumn() ?: '—';

$vs = $pdo->prepare("SELECT GROUP_CONCAT(u.unit_name ORDER BY u.unit_name SEPARATOR ', ')
    FROM dispatch d JOIN ptv_units u ON u.id = d.unit_id
    WHERE d.team_id = ? AND d.status IN ('assigned','en_route','on_site','returning')");
$vs->execute([$myTeamId]);
$myVehicles = $vs->fetchColumn() ?: 'No vehicle assigned';
$unitStatus = $unit['status'] ?? 'Available';

$dispatch = null;
$activeDelay = null;
$todayCount = 0;
$recentHistory = [];

if ($myTeamId) {
    // Active dispatch
    $dStmt = $pdo->prepare("
        SELECT d.*, c.clip_ref, c.barangay, c.incident_type, c.severity
        FROM dispatch d
        JOIN clip_reports c ON c.id = d.clip_report_id
        WHERE d.team_id = ? AND d.status IN ('assigned','en_route','on_site','returning')
        ORDER BY d.dispatched_at DESC, d.id DESC LIMIT 1
    ");
    $dStmt->execute([$myTeamId]);
    $dispatch = $dStmt->fetch(PDO::FETCH_ASSOC);

    // Active delay tied to that dispatch
    if ($dispatch) {
        $delStmt = $pdo->prepare("SELECT * FROM delay_logs WHERE dispatch_id = ? AND resolved_at IS NULL ORDER BY started_at DESC LIMIT 1");
        $delStmt->execute([$dispatch['id']]);
        $activeDelay = $delStmt->fetch(PDO::FETCH_ASSOC);
    }

    // Dispatches completed today
    $todayStmt = $pdo->prepare("SELECT COUNT(DISTINCT clip_report_id) FROM dispatch WHERE team_id = ? AND status = 'resolved' AND DATE(resolved_at) = CURDATE()");
    $todayStmt->execute([$myTeamId]);
    $todayCount = $todayStmt->fetchColumn();

    // Last 3 completed dispatches (one row per incident)
    $histStmt = $pdo->prepare("
        SELECT d.*, c.clip_ref, c.barangay, c.incident_type, c.severity,
               TIMESTAMPDIFF(SECOND, d.departed_at, d.arrived_at) AS travel_seconds
        FROM dispatch d
        JOIN clip_reports c ON c.id = d.clip_report_id
        WHERE d.team_id = ? AND d.status = 'resolved'
          AND d.id = (SELECT MAX(d2.id) FROM dispatch d2 WHERE d2.clip_report_id = d.clip_report_id AND d2.team_id = d.team_id)
        ORDER BY d.resolved_at DESC LIMIT 3
    ");
    $histStmt->execute([$myTeamId]);
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
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/app.css">

</head>
<body>

<?php require_once __DIR__ . '/../../assets/layouts/responder/responder_sidebar.php'; ?>

<main class="main">
    <div class="page-head">
        <h1>Welcome back<?php echo isset($_SESSION['email']) ? ', ' . htmlspecialchars(explode('@', $_SESSION['email'])[0]) : ''; ?></h1>
        <p><?php echo date('l, F j, Y'); ?></p>
    </div>

    <?php if (!$myTeamId): ?>
    <div class="empty-state">No team linked to your account yet. Contact your LDRRMO admin.</div>
    <?php else: ?>

        <?php if ($dispatch): ?>
            <?php
           $bannerClass = ['assigned' => 'assigned', 'en_route' => 'en-route', 'on_site' => 'on-site', 'returning' => 'returning'][$dispatch['status']];
$ctaLabel = ['assigned' => 'Depart Now', 'en_route' => 'Mark Arrived', 'on_site' => 'View Status', 'returning' => 'Back to Command Center'][$dispatch['status']];
$statusText = ['assigned' => 'Awaiting departure', 'en_route' => 'En route', 'on_site' => 'On site', 'returning' => 'Returning to command center'][$dispatch['status']];
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
                <a href="<?php echo BASE_URL; ?>/app/responder/assignment.php" class="btn-inline"><?php echo $ctaLabel; ?></a>
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
    <div class="stat-value"><?php echo htmlspecialchars($teamName); ?></div>
    <div class="stat-label">Your Team · <?php echo htmlspecialchars($myVehicles); ?></div>
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