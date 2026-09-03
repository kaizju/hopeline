<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';



$unitStmt = $pdo->prepare("SELECT * FROM ptv_units WHERE responder_id = ? LIMIT 1");
$unitStmt->execute([$_SESSION['user_id']]);
$unit = $unitStmt->fetch(PDO::FETCH_ASSOC);
$unitStatus = $unit['status'] ?? 'Available';

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$history = [];
$totalRows = 0;

if ($unit) {
    $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM dispatch WHERE unit_id = ? AND status = 'resolved'");
    $totalStmt->execute([$unit['id']]);
    $totalRows = $totalStmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT d.*, c.clip_ref, c.barangay, c.incident_type, c.severity,
               TIMESTAMPDIFF(SECOND, d.departed_at, d.arrived_at) AS travel_seconds,
               (SELECT COUNT(*) FROM delay_logs dl WHERE dl.dispatch_id = d.id) AS delay_count
        FROM dispatch d
        JOIN clip_reports c ON c.id = d.clip_report_id
        WHERE d.unit_id = ? AND d.status = 'resolved'
        ORDER BY d.resolved_at DESC
        LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute([$unit['id']]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$totalPages = max(1, ceil($totalRows / $perPage));
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
<title>My Dispatch History — HopeLine</title>
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
    .main { flex:1; padding:26px 32px 50px; color:var(--macadamia); max-width:1000px; }
    .page-head { margin-bottom:20px; }
    .page-head h1 { font-size:21px; margin-bottom:4px; }
    .page-head p { color:var(--light-grayish); font-size:13px; }

    .history-card {
        background:rgba(251,240,216,0.04); border:1px solid rgba(115,154,185,0.18); border-radius:10px;
        padding:15px 18px; margin-bottom:10px;
    }
    .hc-top { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:10px; flex-wrap:wrap; }
    .hc-title { font-size:13.5px; font-weight:700; }
    .hc-ref { font-size:10.5px; color:var(--light-grayish); font-family:monospace; }
    .sev-badge { font-size:9.5px; font-weight:700; text-transform:uppercase; padding:2px 9px; border-radius:20px; }
    .sev-Critical { background:rgba(176,32,41,0.2); color:var(--critical); }
    .sev-High { background:rgba(217,117,43,0.2); color:var(--high); }
    .sev-Moderate { background:rgba(212,171,43,0.2); color:var(--moderate); }
    .sev-Low { background:rgba(63,122,92,0.2); color:var(--low); }

    .hc-grid { display:grid; grid-template-columns:repeat(5, 1fr); gap:12px; }
    .hc-block .label { font-size:9.5px; text-transform:uppercase; color:var(--light-grayish); margin-bottom:3px; }
    .hc-block .value { font-size:12px; font-weight:600; color:var(--macadamia); }
    .hc-block .value.delay { color:var(--high); }

    .pagination { display:flex; justify-content:center; gap:6px; margin-top:18px; }
    .pagination a { padding:7px 12px; border-radius:6px; font-size:12px; color:var(--light-grayish); text-decoration:none; border:1px solid rgba(115,154,185,0.2); }
    .pagination a.active { background:var(--burnt-umber); color:var(--macadamia); border-color:var(--burnt-umber); }

    .empty-state { text-align:center; padding:60px 20px; color:var(--light-grayish); }

    @media (max-width: 700px) { .hc-grid { grid-template-columns:repeat(2, 1fr); } }
</style>
</head>
<body>

<?php require_once __DIR__ . '/../../assets/layouts/responder/responder_sidebar.php'; ?>

<main class="main">
    <div class="page-head">
        <h1>My Dispatch History</h1>
        <p>Your completed dispatches, most recent first.</p>
    </div>

    <?php if (!$unit): ?>
        <div class="empty-state">No PTV unit linked to your account. Contact your admin.</div>
    <?php elseif (empty($history)): ?>
        <div class="empty-state">No completed dispatches yet.</div>
    <?php else: ?>
        <?php foreach ($history as $h): ?>
        <div class="history-card">
            <div class="hc-top">
                <div>
                    <div class="hc-title"><?php echo htmlspecialchars($h['incident_type']); ?> — <?php echo htmlspecialchars($h['barangay']); ?></div>
                    <div class="hc-ref"><?php echo htmlspecialchars($h['clip_ref']); ?></div>
                </div>
                <span class="sev-badge sev-<?php echo $h['severity']; ?>"><?php echo $h['severity']; ?></span>
            </div>
            <div class="hc-grid">
                <div class="hc-block"><div class="label">Dispatched</div><div class="value"><?php echo date('M j, g:i A', strtotime($h['dispatched_at'])); ?></div></div>
                <div class="hc-block"><div class="label">Departed</div><div class="value"><?php echo $h['departed_at'] ? date('g:i A', strtotime($h['departed_at'])) : '—'; ?></div></div>
                <div class="hc-block"><div class="label">Arrived</div><div class="value"><?php echo $h['arrived_at'] ? date('g:i A', strtotime($h['arrived_at'])) : '—'; ?></div></div>
                <div class="hc-block"><div class="label">Travel Time</div><div class="value"><?php echo fmtDuration($h['travel_seconds']); ?></div></div>
                <div class="hc-block"><div class="label">Delays</div><div class="value <?php echo $h['delay_count'] > 0 ? 'delay' : ''; ?>"><?php echo $h['delay_count']; ?></div></div>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="pagination">
            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                <a href="?page=<?php echo $p; ?>" class="<?php echo $p === $page ? 'active' : ''; ?>"><?php echo $p; ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</main>

</body>
</html>