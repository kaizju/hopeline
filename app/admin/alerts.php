<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';

requireRole('admin');

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function ago($mins) {
    $mins = (int)$mins;
    return $mins < 60 ? $mins . ' min' : floor($mins / 60) . ' h ' . ($mins % 60) . ' min';
}

// ---- Thresholds (from System Settings, with safe fallbacks) ----
$delayThreshold = 15;
try {
    $v = $pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'delay_threshold_minutes'")->fetchColumn();
    if ($v !== false && (int)$v > 0) $delayThreshold = (int)$v;
} catch (PDOException $e) {}
$gpsStaleMinutes   = 5;   // responder phone hasn't reported
$unassignedMinutes = 10;  // incident waiting for a unit

$alerts = ['delay' => [], 'overdue' => [], 'gps' => [], 'unassigned' => [], 'logins' => []];

try {
    // 1. Active (unresolved) delays reported by responders / logged by managers
    $alerts['delay'] = $pdo->query("
        SELECT dl.id, dl.reason, dl.notes, dl.is_manual, dl.started_at,
               TIMESTAMPDIFF(MINUTE, dl.started_at, NOW()) AS mins,
               u.unit_name, c.clip_ref, c.barangay, c.severity
        FROM delay_logs dl
        JOIN ptv_units u ON u.id = dl.unit_id
        JOIN dispatch d ON d.id = dl.dispatch_id
        JOIN clip_reports c ON c.id = d.clip_report_id
        WHERE dl.resolved_at IS NULL
        ORDER BY dl.started_at ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 2. En-route dispatches past the delay threshold without arriving
    $st = $pdo->prepare("
        SELECT d.id, d.departed_at, d.predicted_eta_minutes,
               TIMESTAMPDIFF(MINUTE, d.departed_at, NOW()) AS mins,
               u.unit_name, c.clip_ref, c.barangay, c.severity
        FROM dispatch d
        JOIN ptv_units u ON u.id = d.unit_id
        JOIN clip_reports c ON c.id = d.clip_report_id
        WHERE d.status = 'en_route' AND d.departed_at IS NOT NULL
          AND TIMESTAMPDIFF(MINUTE, d.departed_at, NOW()) > ?
        ORDER BY d.departed_at ASC
    ");
    $st->execute([$delayThreshold]);
    $alerts['overdue'] = $st->fetchAll(PDO::FETCH_ASSOC);

    // 3. Units on duty whose phone GPS has gone quiet
    $st = $pdo->prepare("
        SELECT u.id, u.unit_name, u.plate_no, u.status, u.last_location_at,
               TIMESTAMPDIFF(MINUTE, u.last_location_at, NOW()) AS mins
        FROM ptv_units u
        WHERE u.archived_at IS NULL AND u.responder_id IS NOT NULL
          AND u.status IN ('En Route','On Site','Returning')
          AND (u.last_location_at IS NULL OR u.last_location_at < NOW() - INTERVAL ? MINUTE)
        ORDER BY u.unit_name
    ");
    $st->execute([$gpsStaleMinutes]);
    $alerts['gps'] = $st->fetchAll(PDO::FETCH_ASSOC);

    // 4. Incidents still waiting for a unit
    $st = $pdo->prepare("
        SELECT c.id, c.clip_ref, c.incident_type, c.barangay, c.severity, c.created_at,
               TIMESTAMPDIFF(MINUTE, c.created_at, NOW()) AS mins
        FROM clip_reports c
        WHERE c.status = 'pending' AND c.archived_at IS NULL
          AND c.created_at < NOW() - INTERVAL ? MINUTE
        ORDER BY FIELD(c.severity,'Critical','High','Moderate','Low'), c.created_at ASC
    ");
    $st->execute([$unassignedMinutes]);
    $alerts['unassigned'] = $st->fetchAll(PDO::FETCH_ASSOC);

    // 5. Failed logins in the last 24 hours, grouped by email
    $alerts['logins'] = $pdo->query("
        SELECT COALESCE(email, 'unknown') AS email, COUNT(*) AS attempts, MAX(created_at) AS last_at
        FROM activity_log
        WHERE action = 'login' AND status = 'failed' AND created_at > NOW() - INTERVAL 24 HOUR
        GROUP BY email
        ORDER BY attempts DESC, last_at DESC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $loadError = $e->getMessage();
}

$counts = array_map('count', $alerts);
$total = array_sum($counts);
$unreadAlerts = $total;

$tabs = [
    'all'        => 'All (' . $total . ')',
    'delay'      => 'Reported delays (' . $counts['delay'] . ')',
    'overdue'    => 'Overdue en route (' . $counts['overdue'] . ')',
    'gps'        => 'GPS silent (' . $counts['gps'] . ')',
    'unassigned' => 'Unassigned (' . $counts['unassigned'] . ')',
    'logins'     => 'Failed logins (' . $counts['logins'] . ')',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>System Alerts — HopeLine</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/app.css">
</head>
<body>

<?php require_once __DIR__ . '/../../assets/layouts/admin/admin_sidebar.php'; ?>

<main class="main main-1100">
    <div class="page-head">
        <h1>System Alerts</h1>
        <p><?php echo $total; ?> item<?php echo $total === 1 ? '' : 's'; ?> need attention right now. Refreshes every 30 seconds.</p>
    </div>

    <?php if (!empty($loadError)): ?>
        <div class="alert-banner alert-error">Some alerts could not be loaded: <?php echo h($loadError); ?></div>
    <?php endif; ?>

    <div class="stats-grid">
        <div class="stat-card"><div class="stat-value"><?php echo $counts['delay']; ?></div><div class="stat-label">Reported delays</div></div>
        <div class="stat-card"><div class="stat-value"><?php echo $counts['overdue']; ?></div><div class="stat-label">Overdue (&gt; <?php echo $delayThreshold; ?> min en route)</div></div>
        <div class="stat-card"><div class="stat-value"><?php echo $counts['gps']; ?></div><div class="stat-label">Units with silent GPS</div></div>
        <div class="stat-card"><div class="stat-value"><?php echo $counts['unassigned']; ?></div><div class="stat-label">Incidents unassigned</div></div>
    </div>

    <div class="tabs" id="alertTabs">
        <?php foreach ($tabs as $key => $label): ?>
            <div class="tab <?php echo $key === 'all' ? 'active' : ''; ?>" data-filter="<?php echo $key; ?>"><?php echo h($label); ?></div>
        <?php endforeach; ?>
    </div>

    <?php if ($total === 0): ?>
        <div class="empty-state">All clear — no alerts at the moment.</div>
    <?php endif; ?>

    <div id="alertList">

    <?php foreach ($alerts['delay'] as $a): ?>
        <div class="delay-card active-delay" data-type="delay">
            <div class="delay-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg></div>
            <div class="delay-main">
                <div class="delay-top">
                    <div class="delay-title"><?php echo h($a['unit_name']); ?> — <?php echo h($a['reason']); ?></div>
                    <div style="display:flex; gap:6px;">
                        <span class="delay-tag tag-active">Delay</span>
                        <?php if ($a['is_manual']): ?><span class="delay-tag tag-manual">Manual</span><?php endif; ?>
                    </div>
                </div>
                <div class="delay-meta"><?php echo h($a['clip_ref']); ?> · <?php echo h($a['barangay']); ?> · <?php echo h($a['severity']); ?></div>
                <?php if (!empty($a['notes'])): ?><div class="delay-notes"><?php echo h($a['notes']); ?></div><?php endif; ?>
            </div>
            <div class="delay-side">
                <div class="delay-duration"><strong><?php echo ago($a['mins']); ?></strong><br>since <?php echo date('g:i A', strtotime($a['started_at'])); ?></div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php foreach ($alerts['overdue'] as $a): ?>
        <div class="delay-card active-delay" data-type="overdue">
            <div class="delay-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg></div>
            <div class="delay-main">
                <div class="delay-top">
                    <div class="delay-title"><?php echo h($a['unit_name']); ?> — not arrived after <?php echo ago($a['mins']); ?></div>
                    <span class="delay-tag tag-active">Overdue</span>
                </div>
                <div class="delay-meta"><?php echo h($a['clip_ref']); ?> · <?php echo h($a['barangay']); ?> · <?php echo h($a['severity']); ?></div>
                <?php if ($a['predicted_eta_minutes'] !== null): ?>
                    <div class="delay-notes">Predicted ETA was <?php echo round((float)$a['predicted_eta_minutes']); ?> min.</div>
                <?php endif; ?>
            </div>
            <div class="delay-side">
                <div class="delay-duration">departed <?php echo date('g:i A', strtotime($a['departed_at'])); ?></div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php foreach ($alerts['gps'] as $a): ?>
        <div class="delay-card" data-type="gps">
            <div class="delay-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/></svg></div>
            <div class="delay-main">
                <div class="delay-top">
                    <div class="delay-title"><?php echo h($a['unit_name']); ?> — GPS not reporting</div>
                    <span class="delay-tag tag-manual"><?php echo h($a['status']); ?></span>
                </div>
                <div class="delay-meta"><?php echo h($a['plate_no'] ?: 'No plate'); ?></div>
                <div class="delay-notes">Check the responder's phone: location permission, GPS and connection.</div>
            </div>
            <div class="delay-side">
                <div class="delay-duration"><?php echo $a['last_location_at'] ? 'last fix ' . ago($a['mins']) . ' ago' : 'no fix received'; ?></div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php foreach ($alerts['unassigned'] as $a): ?>
        <div class="delay-card active-delay" data-type="unassigned">
            <div class="delay-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15V7a2 2 0 0 1 2-2h5l2 2h5a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/></svg></div>
            <div class="delay-main">
                <div class="delay-top">
                    <div class="delay-title"><?php echo h($a['incident_type']); ?> — <?php echo h($a['barangay']); ?></div>
                    <span class="sev-badge sev-<?php echo h($a['severity']); ?>"><?php echo h($a['severity']); ?></span>
                </div>
                <div class="delay-meta"><?php echo h($a['clip_ref']); ?> · waiting for a unit</div>
            </div>
            <div class="delay-side">
                <div class="delay-duration"><strong><?php echo ago($a['mins']); ?></strong><br>since <?php echo date('g:i A', strtotime($a['created_at'])); ?></div>
                <a class="btn-resolve" style="text-decoration:none;" href="<?php echo BASE_URL; ?>/app/admin/incidents.php?q=<?php echo urlencode($a['clip_ref']); ?>">View</a>
            </div>
        </div>
    <?php endforeach; ?>

    <?php foreach ($alerts['logins'] as $a): ?>
        <div class="delay-card <?php echo $a['attempts'] >= 5 ? 'active-delay' : ''; ?>" data-type="logins">
            <div class="delay-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M9 11V6a3 3 0 0 1 6 0v5"/></svg></div>
            <div class="delay-main">
                <div class="delay-top">
                    <div class="delay-title"><?php echo h($a['email']); ?></div>
                    <span class="delay-tag <?php echo $a['attempts'] >= 5 ? 'tag-active' : 'tag-manual'; ?>"><?php echo $a['attempts'] >= 5 ? 'Repeated failures' : 'Failed login'; ?></span>
                </div>
                <div class="delay-meta"><?php echo (int)$a['attempts']; ?> failed attempt<?php echo $a['attempts'] == 1 ? '' : 's'; ?> in the last 24 hours</div>
            </div>
            <div class="delay-side">
                <div class="delay-duration">last <?php echo date('M j, g:i A', strtotime($a['last_at'])); ?></div>
                <a class="btn-resolve" style="text-decoration:none;" href="<?php echo BASE_URL; ?>/app/admin/audit-trail.php?action_type=login&status=failed&q=<?php echo urlencode($a['email']); ?>">Audit trail</a>
            </div>
        </div>
    <?php endforeach; ?>

    </div>
</main>

<script>
document.querySelectorAll('#alertTabs .tab').forEach(function (tab) {
    tab.addEventListener('click', function () {
        document.querySelectorAll('#alertTabs .tab').forEach(function (t) { t.classList.remove('active'); });
        tab.classList.add('active');
        var f = tab.dataset.filter;
        document.querySelectorAll('#alertList .delay-card').forEach(function (c) {
            c.style.display = (f === 'all' || c.dataset.type === f) ? '' : 'none';
        });
    });
});
// Keep the page fresh without losing the selected tab's place
setTimeout(function () { location.reload(); }, 30000);
</script>

</body>
</html>