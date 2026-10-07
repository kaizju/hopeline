<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';

requireRole('user');

function hle($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* First non-empty value among several possible column names (schema-tolerant) */
function pick($row, array $keys, $default = '—') {
    if (!$row) return $default;
    foreach ($keys as $k) {
        if (isset($row[$k]) && trim((string)$row[$k]) !== '') return $row[$k];
    }
    return $default;
}

/* ---------------- Unit (the team's PTV) ---------------- */
$unitStmt = $pdo->prepare("SELECT * FROM ptv_units WHERE responder_id = ? LIMIT 1");
$unitStmt->execute([$_SESSION['user_id']]);
$unit = $unitStmt->fetch(PDO::FETCH_ASSOC);

/* ---------------- Team lead / responder account ---------------- */
$userStmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$userStmt->execute([$_SESSION['user_id']]);
$member = $userStmt->fetch(PDO::FETCH_ASSOC);

$unitStatus = $unit['status'] ?? 'Available';

$stats = ['total' => 0, 'resolved' => 0, 'avg_response' => null, 'avg_duration' => null];
$recent = [];

if ($unit) {
    $sStmt = $pdo->prepare("
        SELECT COUNT(*) AS total,
               SUM(status = 'resolved') AS resolved,
               AVG(CASE WHEN arrived_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, dispatched_at, arrived_at) END) AS avg_response,
               AVG(CASE WHEN returned_at IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, dispatched_at, returned_at) END) AS avg_duration
        FROM dispatch WHERE unit_id = ?
    ");
    $sStmt->execute([$unit['id']]);
    $stats = $sStmt->fetch(PDO::FETCH_ASSOC) ?: $stats;

    $rStmt = $pdo->prepare("
        SELECT d.id, d.status, d.dispatched_at, d.arrived_at, c.clip_ref, c.incident_type, c.barangay, c.severity
        FROM dispatch d
        JOIN clip_reports c ON c.id = d.clip_report_id
        WHERE d.unit_id = ?
        ORDER BY d.dispatched_at DESC, d.id DESC
        LIMIT 5
    ");
    $rStmt->execute([$unit['id']]);
    $recent = $rStmt->fetchAll(PDO::FETCH_ASSOC);
}

$unitName   = pick($unit, ['unit_name', 'name', 'unit_code', 'callsign'], 'PTV Unit #' . ($unit['id'] ?? ''));
$plate      = pick($unit, ['plate_number', 'plate_no', 'plate']);
$vehicle    = pick($unit, ['vehicle_type', 'vehicle', 'type', 'model']);
$capacity   = pick($unit, ['capacity', 'crew_size', 'seats']);
$station    = pick($unit, ['station', 'base', 'assigned_barangay', 'barangay'], 'LDRRMO Manolo Fortich');

$leadName   = pick($member, ['full_name', 'name', 'fullname'], '');
$leadEmail  = pick($member, ['email'], $_SESSION['email'] ?? '—');
if ($leadName === '') $leadName = ucfirst(strstr($leadEmail, '@', true) ?: 'Team lead');
$leadPhone  = pick($member, ['phone', 'contact', 'contact_number', 'mobile']);
$initials   = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $leadName), 0, 2)) ?: 'TL';

$statusClass = strtolower(str_replace(' ', '-', $unitStatus));
$hasPos = $unit && !empty($unit['current_lat']) && !empty($unit['current_lng']);

function mins($v) {
    if ($v === null || $v === '') return '—';
    $m = (int)round((float)$v);
    return $m < 60 ? $m . ' min' : floor($m / 60) . ' h ' . ($m % 60) . ' min';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Team Profile — HopeLine</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/app.css">
<style>
    .tp-hero { display: flex; align-items: center; gap: 16px; }
    .tp-avatar { width: 56px; height: 56px; border-radius: 14px; background: var(--burnt-umber); color: #fff; font-weight: 700; font-size: 20px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .tp-hero h2 { font-size: 17px; margin-bottom: 2px; }
    .tp-hero p { font-size: 12px; color: var(--light-grayish); }
    .tp-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
    .tp-row { display: flex; justify-content: space-between; gap: 12px; padding: 10px 0; border-bottom: 1px solid rgba(var(--border-rgb), .12); font-size: 12.5px; }
    .tp-row:last-child { border-bottom: 0; }
    .tp-row .k { color: var(--light-grayish); }
    .tp-row .v { font-weight: 600; text-align: right; min-width: 0; overflow-wrap: anywhere; }
    @media (max-width: 700px) { .tp-grid { grid-template-columns: minmax(0, 1fr); } }
</style>
</head>
<body>

<?php require_once __DIR__ . '/../../assets/layouts/responder/responder_sidebar.php'; ?>

<main class="main main-1100">

    <div class="page-head">
        <h1>Team profile</h1>
        <p>Your unit, crew lead and dispatch record.</p>
    </div>

<?php if (!$unit): ?>
    <div class="card">
        <div class="empty-state">
            <h2>No PTV unit linked to your account</h2>
            <p>Contact your LDRRMO admin to have a unit assigned to your profile.</p>
        </div>
    </div>

<?php else: ?>

    <div class="status-banner <?php echo hle($statusClass); ?>">
        <div class="left">
            <div class="tp-avatar" style="width:40px;height:40px;font-size:14px;border-radius:10px;"><?php echo hle(strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $unitName), 0, 3))); ?></div>
            <div>
                <div class="title"><?php echo hle($unitName); ?></div>
                <div class="sub">Current status: <strong><?php echo hle($unitStatus); ?></strong></div>
            </div>
        </div>
        <a class="btn-inline" href="<?php echo BASE_URL; ?>/app/responder/assignment.php">View assignment</a>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo (int)$stats['total']; ?></div>
            <div class="stat-label">Total dispatches</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo (int)$stats['resolved']; ?></div>
            <div class="stat-label">Resolved</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo hle(mins($stats['avg_response'])); ?></div>
            <div class="stat-label">Avg. time to arrive</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo hle(mins($stats['avg_duration'])); ?></div>
            <div class="stat-label">Avg. full dispatch</div>
        </div>
    </div>

    <div class="tp-grid">
        <div class="card">
            <div class="card-header"><h2>Unit details</h2></div>
            <div class="tp-row"><span class="k">Unit name</span><span class="v"><?php echo hle($unitName); ?></span></div>
            <div class="tp-row"><span class="k">Plate number</span><span class="v"><?php echo hle($plate); ?></span></div>
            <div class="tp-row"><span class="k">Vehicle type</span><span class="v"><?php echo hle($vehicle); ?></span></div>
            <div class="tp-row"><span class="k">Crew capacity</span><span class="v"><?php echo hle($capacity); ?></span></div>
            <div class="tp-row"><span class="k">Home base</span><span class="v"><?php echo hle($station); ?></span></div>
            <div class="tp-row"><span class="k">Last known position</span>
                <span class="v"><?php echo $hasPos ? hle(round((float)$unit['current_lat'], 5) . ', ' . round((float)$unit['current_lng'], 5)) : '—'; ?></span>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2>Team lead</h2></div>
            <div class="tp-hero" style="margin-bottom:10px;">
                <div class="tp-avatar"><?php echo hle($initials); ?></div>
                <div>
                    <h2><?php echo hle($leadName); ?></h2>
                    <p>Responder · <?php echo hle($unitName); ?></p>
                </div>
            </div>
            <div class="tp-row"><span class="k">Email</span><span class="v"><?php echo hle($leadEmail); ?></span></div>
            <div class="tp-row"><span class="k">Contact</span><span class="v"><?php echo hle($leadPhone); ?></span></div>
            <div class="tp-row"><span class="k">Role</span><span class="v">Field responder</span></div>
        </div>
    </div>

    <div class="card" style="margin-top:16px;">
        <div class="card-header">
            <h2>Recent dispatches</h2>
            <a href="<?php echo BASE_URL; ?>/app/responder/my-history.php">View all</a>
        </div>
        <?php if (!$recent): ?>
            <div class="empty-mini">No dispatches yet.</div>
        <?php else: ?>
            <div class="table-scroll">
                <table>
                    <thead><tr><th>Ref</th><th>Incident</th><th>Barangay</th><th>Severity</th><th>Dispatched</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($recent as $r): ?>
                        <tr>
                            <td class="clip-ref-cell"><?php echo hle($r['clip_ref']); ?></td>
                            <td><?php echo hle($r['incident_type']); ?></td>
                            <td><?php echo hle($r['barangay']); ?></td>
                            <td><span class="sev-badge sev-<?php echo hle($r['severity']); ?>"><?php echo hle($r['severity']); ?></span></td>
                            <td><?php echo $r['dispatched_at'] ? hle(date('M j, g:i A', strtotime($r['dispatched_at']))) : '—'; ?></td>
                            <td><span class="status-badge status-<?php echo hle($r['status']); ?>"><?php echo hle(str_replace('_', ' ', $r['status'])); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

<?php endif; ?>
</main>

</body>
</html>