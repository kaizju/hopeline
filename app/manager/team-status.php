<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';

requireLogin();
if (($_SESSION['role'] ?? '') === 'user') redirect('/app/responder/dashboard.php');
requireRole(['admin', 'manager']);
$isAdmin = (($_SESSION['role'] ?? '') === 'admin');
if ($isAdmin && strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'fetch' && function_exists('logActivity')) {
    logActivity($pdo, currentUserId(), currentUserEmail(), 'ops_viewed', 'success');
}

$teams = [];
$loadError = '';
try {
    $stmt = $pdo->prepare("
        SELECT t.id, t.name AS team_name, leader.name AS leader_name,
               (SELECT COUNT(*) FROM team_members tm WHERE tm.team_id = t.id) AS member_count,
               d.id AS dispatch_id, d.status AS dispatch_status, d.departed_at,
               TIMESTAMPDIFF(SECOND, d.departed_at, NOW()) AS elapsed_seconds,
               c.clip_ref, c.incident_type, c.barangay, c.severity,
               (SELECT GROUP_CONCAT(DISTINCT unit.unit_name ORDER BY unit.unit_name SEPARATOR ', ')
                  FROM dispatch vehicle_dispatch
                  JOIN ptv_units unit ON unit.id = vehicle_dispatch.unit_id
                 WHERE vehicle_dispatch.team_id = t.id
                   AND vehicle_dispatch.clip_report_id = d.clip_report_id
                   AND vehicle_dispatch.status IN ('assigned','en_route','on_site','returning')) AS vehicles,
               (SELECT delay.reason FROM delay_logs delay
                 WHERE delay.dispatch_id = d.id AND delay.resolved_at IS NULL
                 ORDER BY delay.started_at DESC LIMIT 1) AS active_delay_reason
        FROM teams t
        LEFT JOIN users leader ON leader.id = t.leader_id
        LEFT JOIN dispatch d ON d.id = (
            SELECT MAX(latest.id)
            FROM dispatch latest
            WHERE latest.team_id = t.id
              AND latest.status IN ('assigned','en_route','on_site','returning')
        )
        LEFT JOIN clip_reports c ON c.id = d.clip_report_id
        WHERE t.archived_at IS NULL
        ORDER BY t.name
    ");
    $stmt->execute();
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('HopeLine team status could not be loaded: ' . $e->getMessage());
    $loadError = 'Team status could not be loaded. Refresh the page or contact an administrator.';
}

$statusCounts = ['Available' => 0, 'assigned' => 0, 'en_route' => 0, 'on_site' => 0, 'returning' => 0];
foreach ($teams as $team) {
    $status = $team['dispatch_status'] ?: 'Available';
    if (isset($statusCounts[$status])) $statusCounts[$status]++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Team Status - HopeLine</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/app.css">
    <style>
        .team-summary, .team-filters { display:flex; flex-wrap:wrap; gap:10px; margin:16px 0; }
        .team-count, .team-filter { border:1px solid rgba(var(--border-rgb), .25); border-radius:999px; padding:8px 14px; background:rgba(var(--surface-rgb), .04); color:var(--macadamia); }
        .team-filter { cursor:pointer; }
        .team-filter.active { background:var(--burnt-umber); border-color:var(--burnt-umber); color:#fff; }
        .team-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:16px; }
        .team-card { background:rgba(var(--surface-rgb), .04); border:1px solid rgba(var(--border-rgb), .18); border-radius:10px; padding:18px; color:var(--macadamia); }
        .team-card h2 { margin:0 0 8px; font-size:1.15rem; color:var(--macadamia); }
        .team-card p { margin:7px 0; }
        .team-state { display:inline-block; border-radius:999px; padding:5px 10px; font-weight:700; background:rgba(63,122,92,.2); color:var(--low); }
        .team-state.busy { background:rgba(217,117,43,.2); color:var(--high); }
        .team-delay { color:var(--high); font-weight:600; }
    </style>
</head>
<body>
<?php
$unreadAlerts = 0;
if ($isAdmin) require_once __DIR__ . '/../../assets/layouts/admin/admin_sidebar.php';
else require_once __DIR__ . '/../../assets/layouts/manager/manager_sidebar.php';
?>
<main class="main">
    <div class="page-head">
        <h1>Team Status</h1>
        <p>Live availability and active dispatch details for every team.</p>
    </div>
    <?php if ($loadError): ?><div class="alert-banner alert-error"><?php echo htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>

    <div id="teamList">
        <div class="team-summary" aria-label="Team counts by status">
            <?php foreach ($statusCounts as $status => $count): ?>
                <div class="team-count"><strong><?php echo htmlspecialchars($status === 'Available' ? $status : ucfirst(str_replace('_', ' ', $status)), ENT_QUOTES, 'UTF-8'); ?>:</strong> <?php echo (int)$count; ?></div>
            <?php endforeach; ?>
        </div>
        <div class="team-filters" role="group" aria-label="Filter teams by status">
            <button type="button" class="team-filter active" data-team-filter="all">All</button>
            <?php foreach ($statusCounts as $status => $count): ?>
                <button type="button" class="team-filter" data-team-filter="<?php echo htmlspecialchars((string)$status, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($status === 'Available' ? $status : ucfirst(str_replace('_', ' ', $status)), ENT_QUOTES, 'UTF-8'); ?></button>
            <?php endforeach; ?>
        </div>
        <?php if ($loadError): ?>
            <div class="empty-state">Team information is temporarily unavailable.</div>
        <?php elseif (!$teams): ?>
            <div class="empty-state">No active teams found.</div>
        <?php else: ?>
            <div class="team-grid">
                <?php foreach ($teams as $team):
                    $status = $team['dispatch_status'] ?: 'Available';
                    $statusLabel = $status === 'Available' ? $status : ucfirst(str_replace('_', ' ', $status));
                    $elapsed = max(0, (int)$team['elapsed_seconds']);
                    $elapsedLabel = floor($elapsed / 3600) . 'h ' . floor(($elapsed % 3600) / 60) . 'm';
                ?>
                    <article class="team-card" data-team-status="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>">
                        <h2><?php echo htmlspecialchars($team['team_name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                        <p><strong>Leader:</strong> <?php echo htmlspecialchars($team['leader_name'] ?: 'Not assigned', ENT_QUOTES, 'UTF-8'); ?></p>
                        <p><strong>Members:</strong> <?php echo (int)$team['member_count']; ?></p>
                        <p><span class="team-state<?php echo $status === 'Available' ? '' : ' busy'; ?>"><?php echo htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8'); ?></span></p>
                        <?php if ($team['dispatch_id']): ?>
                            <hr>
                            <p><strong>CLIP:</strong> <?php echo htmlspecialchars($team['clip_ref'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></p>
                            <p><strong>Incident:</strong> <?php echo htmlspecialchars($team['incident_type'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></p>
                            <p><strong>Purok:</strong> <?php echo htmlspecialchars($team['barangay'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></p>
                            <p><strong>Severity:</strong> <?php echo htmlspecialchars($team['severity'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></p>
                            <p><strong>Vehicles:</strong> <?php echo htmlspecialchars($team['vehicles'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></p>
                            <p><strong>Departed:</strong> <?php echo $team['departed_at'] ? htmlspecialchars(date('M j, H:i', strtotime($team['departed_at'])), ENT_QUOTES, 'UTF-8') : 'Not departed'; ?></p>
                            <p><strong>Total time since departure:</strong> <?php echo $team['departed_at'] ? htmlspecialchars($elapsedLabel, ENT_QUOTES, 'UTF-8') : 'Not started'; ?></p>
                            <?php if ($team['active_delay_reason']): ?>
                                <p class="team-delay"><strong>Active delay:</strong> <?php echo htmlspecialchars($team['active_delay_reason'], ENT_QUOTES, 'UTF-8'); ?></p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>
<script>
    const teamList = document.getElementById('teamList');
    let activeTeamFilter = 'all';

    function applyTeamFilter() {
        teamList.querySelectorAll('.team-card').forEach(card => {
            card.hidden = activeTeamFilter !== 'all' && card.dataset.teamStatus !== activeTeamFilter;
        });
        teamList.querySelectorAll('.team-filter').forEach(button => {
            button.classList.toggle('active', button.dataset.teamFilter === activeTeamFilter);
        });
    }

    teamList.addEventListener('click', event => {
        const button = event.target.closest('.team-filter');
        if (!button) return;
        activeTeamFilter = button.dataset.teamFilter;
        applyTeamFilter();
    });

    setInterval(async () => {
        if (document.hidden) return;
        try {
            const response = await fetch(location.href, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'X-Requested-With': 'fetch' }
            });
            if (!response.ok) return;
            const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
            const freshTeamList = doc.getElementById('teamList');
            if (freshTeamList) {
                teamList.innerHTML = freshTeamList.innerHTML;
                applyTeamFilter();
            }
        } catch (error) {
            console.warn('Could not refresh team status.', error);
        }
    }, 10000);
</script>
</body>
</html>
