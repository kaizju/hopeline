<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';

if (!isLoggedIn() || $_SESSION['role'] !== 'manager') {
    redirect('/index.php');
    exit;
}

// ---- Filters ----
$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo   = $_GET['to'] ?? date('Y-m-d');
$barangayFilter = $_GET['barangay'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

try {
    $where = "WHERE c.created_at BETWEEN ? AND ?";
    $params = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];
    if ($barangayFilter !== '') {
        $where .= " AND c.barangay = ?";
        $params[] = $barangayFilter;
    }

    $totalRows = $pdo->prepare("SELECT COUNT(*) FROM clip_reports c $where");
    $totalRows->execute($params);
    $totalRows = $totalRows->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT c.clip_ref, c.caller_name, c.barangay, c.incident_type, c.severity, c.status,
               c.created_at AS report_received_at,
               d.departed_at, d.arrived_at, u.unit_name,
               TIMESTAMPDIFF(SECOND, d.departed_at, d.arrived_at) AS travel_seconds,
               TIMESTAMPDIFF(SECOND, c.created_at, d.arrived_at) AS total_response_seconds,
               (SELECT COUNT(*) FROM delay_logs dl WHERE dl.dispatch_id = d.id) AS delay_count
        FROM clip_reports c
        LEFT JOIN dispatch d ON d.clip_report_id = c.id
        LEFT JOIN ptv_units u ON u.id = d.unit_id
        $where
        ORDER BY c.created_at DESC
        LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute($params);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $barangays = $pdo->query("SELECT DISTINCT barangay FROM clip_reports ORDER BY barangay")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $history = [
        ['clip_ref'=>'CLIP-20260814-Z9Y8X','caller_name'=>'Pedro Reyes','barangay'=>'Damilag','incident_type'=>'Vehicular Accident','severity'=>'High','status'=>'resolved','report_received_at'=>date('Y-m-d H:i:s', strtotime('-1 day -2 hours')),'departed_at'=>date('Y-m-d H:i:s', strtotime('-1 day -1 hour -55 minutes')),'arrived_at'=>date('Y-m-d H:i:s', strtotime('-1 day -1 hour -40 minutes')),'unit_name'=>'PTV Charlie','travel_seconds'=>900,'total_response_seconds'=>1200,'delay_count'=>1],
        ['clip_ref'=>'CLIP-20260813-Q1W2E','caller_name'=>'Ana Lim','barangay'=>'Dahilayan','incident_type'=>'Flood / Landslide','severity'=>'Moderate','status'=>'resolved','report_received_at'=>date('Y-m-d H:i:s', strtotime('-2 days -3 hours')),'departed_at'=>date('Y-m-d H:i:s', strtotime('-2 days -2 hours -50 minutes')),'arrived_at'=>date('Y-m-d H:i:s', strtotime('-2 days -1 hour -30 minutes')),'unit_name'=>'PTV Echo','travel_seconds'=>4800,'total_response_seconds'=>5400,'delay_count'=>1],
        ['clip_ref'=>'CLIP-20260812-M3N4O','caller_name'=>'Juan Dela Cruz','barangay'=>'Tankulan (Poblacion)','incident_type'=>'Medical Emergency','severity'=>'Critical','status'=>'resolved','report_received_at'=>date('Y-m-d H:i:s', strtotime('-3 days -5 hours')),'departed_at'=>date('Y-m-d H:i:s', strtotime('-3 days -4 hours -58 minutes')),'arrived_at'=>date('Y-m-d H:i:s', strtotime('-3 days -4 hours -53 minutes')),'unit_name'=>'PTV Alpha','travel_seconds'=>258,'total_response_seconds'=>420,'delay_count'=>0],
    ];
    $barangays = ['Damilag', 'Dahilayan', 'Tankulan (Poblacion)', 'Maluko'];
    $totalRows = count($history);
}

$totalPages = max(1, ceil($totalRows / $perPage));
$unreadAlerts = 2;

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
<title>Incident History — HopeLine</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    :root {
        --burnt-umber:#6d120b; --redwood:#b02029; --macadamia:#fbf0d8;
        --cool-blue:#113047; --light-grayish:#739ab9;
        --critical:#b02029; --high:#d9752b; --moderate:#d4ab2b; --low:#3f7a5c;
    }
    * { box-sizing:border-box; margin:0; padding:0; }
    body { font-family:'Segoe UI',-apple-system,BlinkMacSystemFont,sans-serif; background:#0c2334; display:flex; min-height:100vh; }
    .main { flex:1; padding:26px 32px 50px; color:var(--macadamia); max-width:1320px; overflow-x:auto; }
    .page-head { margin-bottom:18px; }
    .page-head h1 { font-size:21px; margin-bottom:4px; }
    .page-head p { color:var(--light-grayish); font-size:13px; }

    .filter-bar {
        display:flex; gap:10px; align-items:flex-end; margin-bottom:18px; flex-wrap:wrap;
        background: rgba(251,240,216,0.04); border:1px solid rgba(115,154,185,0.18); border-radius:10px; padding:14px 16px;
    }
    .filter-field label { display:block; font-size:10.5px; color:var(--light-grayish); margin-bottom:5px; text-transform:uppercase; letter-spacing:0.4px; }
    .filter-field input, .filter-field select {
        background: rgba(251,240,216,0.06); border:1px solid rgba(115,154,185,0.28);
        border-radius:6px; padding:8px 10px; color:var(--macadamia); font-size:12.5px; outline:none;
    }
    .btn-filter { background:var(--burnt-umber); color:var(--macadamia); border:0; padding:9px 18px; border-radius:20px; font-weight:700; font-size:12px; cursor:pointer; }
    .btn-filter:hover { background:var(--redwood); }

    table { width:100%; border-collapse:collapse; background:rgba(251,240,216,0.04); border:1px solid rgba(115,154,185,0.18); border-radius:10px; overflow:hidden; }
    thead th {
        text-align:left; font-size:10.5px; text-transform:uppercase; letter-spacing:0.4px; color:var(--light-grayish);
        padding:12px 14px; border-bottom:1px solid rgba(115,154,185,0.18); white-space:nowrap;
    }
    tbody td { padding:12px 14px; font-size:12.5px; border-bottom:1px solid rgba(115,154,185,0.08); vertical-align:top; white-space:nowrap; }
    tbody tr:last-child td { border-bottom:none; }
    tbody tr:hover { background: rgba(251,240,216,0.03); }

    .clip-ref-cell { font-family:monospace; font-size:11px; color:var(--light-grayish); }
    .sev-badge { font-size:9.5px; font-weight:700; text-transform:uppercase; padding:2px 8px; border-radius:20px; }
    .sev-Critical { background:rgba(176,32,41,0.2); color:var(--critical); }
    .sev-High { background:rgba(217,117,43,0.2); color:var(--high); }
    .sev-Moderate { background:rgba(212,171,43,0.2); color:var(--moderate); }
    .sev-Low { background:rgba(63,122,92,0.2); color:var(--low); }

    .status-badge { font-size:9.5px; font-weight:700; text-transform:uppercase; padding:2px 8px; border-radius:20px; background:rgba(115,154,185,0.2); color:var(--light-grayish); }
    .status-resolved { background:rgba(63,122,92,0.2); color:#3f7a5c; }

    .delay-flag { color:var(--high); font-weight:700; }
    .no-delay { color:var(--light-grayish); }

    .pagination { display:flex; justify-content:center; gap:6px; margin-top:18px; }
    .pagination a {
        display:inline-block; padding:7px 12px; border-radius:6px; font-size:12px;
        color:var(--light-grayish); text-decoration:none; border:1px solid rgba(115,154,185,0.2);
    }
    .pagination a.active { background:var(--burnt-umber); color:var(--macadamia); border-color:var(--burnt-umber); }
    .pagination a:hover:not(.active) { border-color:var(--light-grayish); }

    .empty-state { text-align:center; padding:50px 20px; color:var(--light-grayish); font-size:13px; }
</style>
</head>
<body>

<?php require_once __DIR__ . '/../../assets/layouts/manager/manager_sidebar.php'; ?>

<main class="main">
    <div class="page-head">
        <h1>Incident History</h1>
        <p>Full CLIP-to-arrival timeline for all logged incidents.</p>
    </div>

    <form method="GET" class="filter-bar">
        <div class="filter-field">
            <label for="from">From</label>
            <input type="date" name="from" id="from" value="<?php echo htmlspecialchars($dateFrom); ?>">
        </div>
        <div class="filter-field">
            <label for="to">To</label>
            <input type="date" name="to" id="to" value="<?php echo htmlspecialchars($dateTo); ?>">
        </div>
        <div class="filter-field">
            <label for="barangay">Barangay</label>
            <select name="barangay" id="barangay">
                <option value="">All Barangays</option>
                <?php foreach ($barangays as $b): ?>
                    <option value="<?php echo htmlspecialchars($b); ?>" <?php echo $barangayFilter === $b ? 'selected' : ''; ?>><?php echo htmlspecialchars($b); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn-filter">Apply Filters</button>
    </form>

    <?php if (empty($history)): ?>
        <div class="empty-state">No incidents found for this date range.</div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table>
        <thead>
            <tr>
                <th>CLIP Ref</th>
                <th>Caller</th>
                <th>Barangay</th>
                <th>Incident</th>
                <th>Severity</th>
                <th>Unit</th>
                <th>Reported</th>
                <th>Departed</th>
                <th>Arrived</th>
                <th>Travel Time</th>
                <th>Total Response</th>
                <th>Delays</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($history as $h): ?>
            <tr>
                <td class="clip-ref-cell"><?php echo htmlspecialchars($h['clip_ref']); ?></td>
                <td><?php echo htmlspecialchars($h['caller_name']); ?></td>
                <td><?php echo htmlspecialchars($h['barangay']); ?></td>
                <td><?php echo htmlspecialchars($h['incident_type']); ?></td>
                <td><span class="sev-badge sev-<?php echo $h['severity']; ?>"><?php echo $h['severity']; ?></span></td>
                <td><?php echo htmlspecialchars($h['unit_name'] ?? '—'); ?></td>
                <td><?php echo date('M j, g:i A', strtotime($h['report_received_at'])); ?></td>
                <td><?php echo $h['departed_at'] ? date('g:i A', strtotime($h['departed_at'])) : '—'; ?></td>
                <td><?php echo $h['arrived_at'] ? date('g:i A', strtotime($h['arrived_at'])) : '—'; ?></td>
                <td><?php echo fmtDuration($h['travel_seconds']); ?></td>
                <td><?php echo fmtDuration($h['total_response_seconds']); ?></td>
                <td class="<?php echo $h['delay_count'] > 0 ? 'delay-flag' : 'no-delay'; ?>"><?php echo $h['delay_count']; ?></td>
                <td><span class="status-badge <?php echo $h['status'] === 'resolved' ? 'status-resolved' : ''; ?>"><?php echo ucfirst($h['status']); ?></span></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <div class="pagination">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <a href="?from=<?php echo urlencode($dateFrom); ?>&to=<?php echo urlencode($dateTo); ?>&barangay=<?php echo urlencode($barangayFilter); ?>&page=<?php echo $p; ?>"
               class="<?php echo $p === $page ? 'active' : ''; ?>"><?php echo $p; ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</main>

</body>
</html>