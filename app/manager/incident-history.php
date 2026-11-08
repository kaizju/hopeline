<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../config/closeout.php';

requireRole('manager');   // must run BEFORE handling the POST

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_closeout') {
    try {
        redirectAfterPost('incident-history.php', saveCloseout($pdo, (int)($_POST['dispatch_id'] ?? 0)));
    } catch (Throwable $e) {
        error_log('HopeLine case details could not be saved: ' . $e->getMessage());
        redirectAfterPost('incident-history.php', 'Case details could not be saved. Refresh and try again.', 'error');
    }
}
$flash = consumeFlash();
$flashType = consumeFlashType();

$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo   = $_GET['to'] ?? date('Y-m-d');
$barangayFilter = $_GET['barangay'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;
$history = []; $totalRows = 0; $barangays = purokList(); $loadError = '';
try {
    $in = "'" . implode("','", purokList()) . "'";
    $where = "WHERE c.created_at BETWEEN ? AND ? AND c.barangay IN ($in)";
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
       c.id AS clip_id, d.id AS dispatch_id,
       d.patient_name, d.patient_age_group, d.patient_sex, d.victim_count,
       d.vital_signs, d.alcohol_breath, d.closeout_remarks,
       c.created_at AS report_received_at,
       d.departed_at, d.arrived_at, d.incident_photo, d.incident_details, d.hospital_name, d.hospital_arrived_at,
       (SELECT GROUP_CONCAT(u2.unit_name ORDER BY u2.unit_name SEPARATOR ', ')
          FROM dispatch dx JOIN ptv_units u2 ON u2.id = dx.unit_id
          WHERE dx.clip_report_id = c.id AND dx.status <> 'cancelled') AS unit_name,
       TIMESTAMPDIFF(SECOND, d.departed_at, d.arrived_at) AS travel_seconds,
       TIMESTAMPDIFF(SECOND, c.created_at, d.arrived_at) AS total_response_seconds,
       TIMESTAMPDIFF(SECOND, c.created_at, d.dispatched_at) AS dispatch_wait_seconds,
       TIMESTAMPDIFF(SECOND, d.dispatched_at, d.departed_at) AS turnout_seconds,
       (SELECT COUNT(*) FROM delay_logs dl JOIN dispatch dd ON dd.id=dl.dispatch_id WHERE dd.clip_report_id=c.id) AS delay_count,
       (SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE, dl.started_at, dl.resolved_at)),0) FROM delay_logs dl JOIN dispatch dd ON dd.id=dl.dispatch_id WHERE dd.clip_report_id=c.id) AS total_delay_minutes,
       (SELECT dl.reason FROM delay_logs dl JOIN dispatch dd ON dd.id=dl.dispatch_id WHERE dd.clip_report_id=c.id GROUP BY dl.reason ORDER BY COUNT(*) DESC, MIN(dl.started_at) ASC LIMIT 1) AS primary_delay_reason
       FROM clip_reports c
       LEFT JOIN dispatch d ON d.id = (SELECT MAX(d2.id) FROM dispatch d2 WHERE d2.clip_report_id = c.id)
       $where
       ORDER BY c.created_at DESC
       LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute($params);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $barangays = purokList();
} catch (PDOException $e) {
    error_log('HopeLine incident history could not be loaded: ' . $e->getMessage());
    $loadError = 'Incident history could not be loaded. Refresh the page or contact an administrator.';
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
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/app.css">
</head>
<body>

<?php require_once __DIR__ . '/../../assets/layouts/manager/manager_sidebar.php'; ?>

<main class="main main-1320">
    <?php if ($loadError): ?><div class="alert-banner alert-error"><?php echo htmlspecialchars($loadError, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <div class="page-head">
        <?php if ($flash): ?><div class="<?php echo $flashType === 'error' ? 'alert-banner alert-error' : 'flash'; ?>"><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>
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
            <label for="barangay">Purok</label>
            <select name="barangay" id="barangay">
                <option value="">All Puroks</option>
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
                <th>Purok</th>
                <th>Incident</th>
                <th>Severity</th>
                <th>Unit</th>
                <th>Dispatch Wait</th>
<th>Turnout</th>
                <th>Reported</th>
                <th>Departed</th>
                <th>Arrived</th>
                <th>Travel Time</th>
                <th>Total Response</th>
                <th>Delays</th>
                <th>Hospital</th>
                <th>Hospital Arrival</th>
<th>Photo</th>
<th>Case Details</th>
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
                <td><?php echo fmtDuration($h['dispatch_wait_seconds']); ?></td>
                <td><?php echo fmtDuration($h['turnout_seconds']); ?></td>
                <td><?php echo date('M j, H:i', strtotime($h['report_received_at'])); ?></td>
                <td><?php echo $h['departed_at'] ? date('H:i', strtotime($h['departed_at'])) : '—'; ?></td>
                <td><?php echo $h['arrived_at'] ? date('H:i', strtotime($h['arrived_at'])) : '—'; ?></td>
                <td><?php echo fmtDuration($h['travel_seconds']); ?></td>
                <td><?php echo fmtDuration($h['total_response_seconds']); ?></td>
                <td class="<?php echo $h['delay_count'] > 0 ? 'delay-flag' : 'no-delay'; ?>"><?php echo (int)$h['delay_count'] > 0 ? '+' . (int)$h['total_delay_minutes'] . 'm · ' . htmlspecialchars($h['primary_delay_reason'] ?: 'Delay') : '—'; ?></td>
                <td><?php echo htmlspecialchars($h['hospital_name'] ?? '—'); ?></td>
                <td><?php echo $h['hospital_arrived_at'] ? date('H:i', strtotime($h['hospital_arrived_at'])) : '—'; ?></td>
                <td>
                    <?php if (!empty($h['incident_photo'])): ?>
                        <a href="<?php echo BASE_URL . '/' . htmlspecialchars($h['incident_photo']); ?>" target="_blank">
                            <img src="<?php echo BASE_URL . '/' . htmlspecialchars($h['incident_photo']); ?>" style="width:44px;height:44px;object-fit:cover;border-radius:6px;">
                        </a>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td><?php if (canEditCloseout($h)) closeoutButton($h); else echo '—'; ?></td>
                <td><span class="status-badge <?php echo $h['status'] === 'resolved' ? 'status-resolved' : ''; ?>"><?php echo statusLabel($h['status']); ?></span></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>
<?php foreach ($history as $h) if (canEditCloseout($h)) closeoutModal($h); ?>
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
