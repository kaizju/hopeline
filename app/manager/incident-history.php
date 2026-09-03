<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';


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
   
    $totalRows = count($history);
}

$totalPages = max(1, ceil($totalRows / $perPage));

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
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/manager.css">
</head>
<body>

<?php require_once __DIR__ . '/../../assets/layouts/manager/manager_sidebar.php'; ?>

<main class="main main-1320">
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