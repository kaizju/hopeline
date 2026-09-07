<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';



$userCounts = $pdo->query("SELECT role, COUNT(*) AS total FROM users GROUP BY role")->fetchAll(PDO::FETCH_KEY_PAIR);
$unitCounts = $pdo->query("SELECT status, COUNT(*) AS total FROM ptv_units GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$totalIncidents = $pdo->query("SELECT COUNT(*) FROM clip_reports")->fetchColumn();
$todayIncidents = $pdo->query("SELECT COUNT(*) FROM clip_reports WHERE DATE(created_at) = CURDATE()")->fetchColumn();
$activeIncidents = $pdo->query("SELECT COUNT(*) FROM clip_reports WHERE status NOT IN ('resolved','cancelled')")->fetchColumn();
$avgResponse = $pdo->query("
    SELECT AVG(TIMESTAMPDIFF(SECOND, c.created_at, d.arrived_at))
    FROM clip_reports c JOIN dispatch d ON d.clip_report_id = c.id
    WHERE d.arrived_at IS NOT NULL
")->fetchColumn();

$recentAudit = $pdo->query("SELECT * FROM activity_log ORDER BY created_at DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);

$totalUsers = array_sum($userCounts);
$totalUnits = array_sum($unitCounts);
$unreadAlerts = 0;

function fmtAvgResponse($seconds) {
    if (!$seconds) return '—';
    $m = floor($seconds / 60);
    return $m . ' min';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin Dashboard — HopeLine</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/sidebar.css">
<style>
    :root { --burnt-umber:#6d120b; --redwood:#b02029; --macadamia:#fbf0d8; --cool-blue:#113047; --light-grayish:#739ab9; }
    * { box-sizing:border-box; margin:0; padding:0; }
    body { font-family:'Segoe UI',-apple-system,BlinkMacSystemFont,sans-serif; background:#0c2334; display:flex; min-height:100vh; }
    .main { flex:1; padding:26px 32px 50px; color:var(--macadamia); max-width:1280px; }
    .page-head { margin-bottom:22px; }
    .page-head h1 { font-size:21px; margin-bottom:4px; }
    .page-head p { color:var(--light-grayish); font-size:13px; }

    .stats-grid { display:grid; grid-template-columns:repeat(4, 1fr); gap:14px; margin-bottom:20px; }
    .stat-card { background:rgba(251,240,216,0.04); border:1px solid rgba(115,154,185,0.18); border-radius:10px; padding:16px 18px; }
    .stat-value { font-size:24px; font-weight:700; }
    .stat-label { font-size:11.5px; color:var(--light-grayish); margin-top:2px; }

    .dash-layout { display:grid; grid-template-columns:1.4fr 1fr; gap:18px; }
    .card { background:rgba(251,240,216,0.04); border:1px solid rgba(115,154,185,0.18); border-radius:10px; padding:18px 20px; margin-bottom:18px; }
    .card-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; }
    .card-header h2 { font-size:14.5px; font-weight:700; }
    .card-header a { font-size:11.5px; color:var(--light-grayish); text-decoration:none; }

    .breakdown-row { margin-bottom:14px; }
    .breakdown-row:last-child { margin-bottom:0; }
    .breakdown-label { display:flex; justify-content:space-between; font-size:12px; margin-bottom:6px; }
    .breakdown-label .n { font-weight:700; }
    .bar-track { height:7px; background:rgba(115,154,185,0.15); border-radius:20px; overflow:hidden; }
    .bar-fill { height:100%; border-radius:20px; }

    .audit-row { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:9px 0; border-bottom:1px solid rgba(115,154,185,0.1); font-size:12px; }
    .audit-row:last-child { border-bottom:none; }
    .audit-action { font-weight:600; }
    .audit-meta { color:var(--light-grayish); font-size:10.5px; }
    .audit-status { font-size:9.5px; font-weight:700; text-transform:uppercase; padding:2px 8px; border-radius:20px; }
    .status-success { background:rgba(63,122,92,0.2); color:#3f7a5c; }
    .status-failed { background:rgba(176,32,41,0.2); color:var(--redwood); }

    .empty-mini { text-align:center; padding:20px; color:var(--light-grayish); font-size:12px; }

    @media (max-width: 1050px) { .stats-grid { grid-template-columns:repeat(2,1fr); } .dash-layout { grid-template-columns:1fr; } }
</style>
</head>
<body>

<?php require_once __DIR__ . '/../../assets/layouts/admin/admin_sidebar.php'; ?>

<main class="main">
    <div class="page-head">
        <h1>Admin Dashboard</h1>
        <p>System-wide overview — <?php echo date('l, F j, Y'); ?></p>
    </div>

    <div class="stats-grid">
        <div class="stat-card"><div class="stat-value"><?php echo $totalUsers; ?></div><div class="stat-label">Total Users</div></div>
        <div class="stat-card"><div class="stat-value"><?php echo $totalUnits; ?></div><div class="stat-label">PTV Units</div></div>
        <div class="stat-card"><div class="stat-value"><?php echo $activeIncidents; ?></div><div class="stat-label">Active Incidents</div></div>
        <div class="stat-card"><div class="stat-value"><?php echo fmtAvgResponse($avgResponse); ?></div><div class="stat-label">Avg. Response Time</div></div>
    </div>

    <div class="dash-layout">
        <div>
            <div class="card">
                <div class="card-header"><h2>Incident Volume</h2><a href="<?php echo BASE_URL; ?>/app/admin/incidents.php">View records →</a></div>
                <div class="breakdown-row"><div class="breakdown-label"><span>Total incidents logged (all time)</span><span class="n"><?php echo $totalIncidents; ?></span></div></div>
                <div class="breakdown-row"><div class="breakdown-label"><span>Reported today</span><span class="n"><?php echo $todayIncidents; ?></span></div></div>
                <div class="breakdown-row"><div class="breakdown-label"><span>Currently active</span><span class="n"><?php echo $activeIncidents; ?></span></div></div>
            </div>

            <div class="card">
                <div class="card-header"><h2>Recent System Activity</h2><a href="<?php echo BASE_URL; ?>/app/admin/audit-trail.php">View full trail →</a></div>
                <?php if (empty($recentAudit)): ?>
                    <div class="empty-mini">No activity logged yet.</div>
                <?php else: foreach ($recentAudit as $a): ?>
                <div class="audit-row">
                    <div>
                        <div class="audit-action"><?php echo htmlspecialchars(ucwords(str_replace('_',' ', $a['action']))); ?></div>
                        <div class="audit-meta"><?php echo htmlspecialchars($a['email'] ?? 'unknown'); ?> · <?php echo date('M j, g:i A', strtotime($a['created_at'])); ?></div>
                    </div>
                    <span class="audit-status status-<?php echo $a['status']; ?>"><?php echo $a['status']; ?></span>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <div>
            <div class="card">
                <div class="card-header"><h2>Users by Role</h2><a href="<?php echo BASE_URL; ?>/app/admin/users.php">Manage →</a></div>
                <?php
                $roleMeta = ['admin' => ['label' => 'Admin', 'color' => '#b02029'], 'manager' => ['label' => 'Manager', 'color' => '#d9752b'], 'user' => ['label' => 'Responder', 'color' => '#3f7a5c']];
                foreach ($roleMeta as $role => $meta):
                    $n = $userCounts[$role] ?? 0;
                    $pct = $totalUsers > 0 ? round(($n / $totalUsers) * 100) : 0;
                ?>
                <div class="breakdown-row">
                    <div class="breakdown-label"><span><?php echo $meta['label']; ?></span><span class="n"><?php echo $n; ?></span></div>
                    <div class="bar-track"><div class="bar-fill" style="width:<?php echo $pct; ?>%; background:<?php echo $meta['color']; ?>"></div></div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="card">
                <div class="card-header"><h2>PTV Unit Status</h2><a href="<?php echo BASE_URL; ?>/app/admin/units.php">Manage →</a></div>
                <?php
                $statusMeta = ['Available' => '#3f7a5c', 'En Route' => '#d9752b', 'On Site' => '#b02029', 'Returning' => '#739ab9', 'Offline' => '#4a5c6b'];
                foreach ($statusMeta as $label => $color):
                    $n = $unitCounts[$label] ?? 0;
                    $pct = $totalUnits > 0 ? round(($n / $totalUnits) * 100) : 0;
                    if ($n === 0 && !isset($unitCounts[$label])) continue;
                ?>
                <div class="breakdown-row">
                    <div class="breakdown-label"><span><?php echo $label; ?></span><span class="n"><?php echo $n; ?></span></div>
                    <div class="bar-track"><div class="bar-fill" style="width:<?php echo $pct; ?>%; background:<?php echo $color; ?>"></div></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</main>

</body>
</html>