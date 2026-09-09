<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';

requireRole('admin');

// ---- Filters ----
$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo   = $_GET['to'] ?? date('Y-m-d');
$reasonFilter = $_GET['reason'] ?? '';
$typeFilter = $_GET['type'] ?? ''; // manual | auto
$statusFilter = $_GET['status'] ?? ''; // active | resolved
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$where = "WHERE dl.started_at BETWEEN ? AND ?";
$params = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];

if ($reasonFilter !== '') { $where .= " AND dl.reason = ?"; $params[] = $reasonFilter; }
if ($typeFilter === 'manual') { $where .= " AND dl.is_manual = 1"; }
if ($typeFilter === 'auto') { $where .= " AND dl.is_manual = 0"; }
if ($statusFilter === 'active') { $where .= " AND dl.resolved_at IS NULL"; }
if ($statusFilter === 'resolved') { $where .= " AND dl.resolved_at IS NOT NULL"; }

// ---- Summary stats (respect date range only, not the other filters) ----
$summaryStmt = $pdo->prepare("
    SELECT COUNT(*) AS total,
           SUM(is_manual) AS manual_count,
           AVG(CASE WHEN resolved_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, started_at, resolved_at) END) AS avg_duration,
           SUM(CASE WHEN resolved_at IS NULL THEN 1 ELSE 0 END) AS active_count
    FROM delay_logs
    WHERE started_at BETWEEN ? AND ?
");
$summaryStmt->execute([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
$summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);

$topReasonStmt = $pdo->prepare("
    SELECT reason, COUNT(*) AS total FROM delay_logs
    WHERE started_at BETWEEN ? AND ?
    GROUP BY reason ORDER BY total DESC LIMIT 1
");
$topReasonStmt->execute([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
$topReason = $topReasonStmt->fetch(PDO::FETCH_ASSOC);

// ---- Table data ----
$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM delay_logs dl $where");
$totalStmt->execute($params);
$totalRows = $totalStmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT dl.*, u.unit_name, c.clip_ref, c.barangay, c.severity, usr.name AS logged_by_name
    FROM delay_logs dl
    JOIN ptv_units u ON u.id = dl.unit_id
    JOIN dispatch d ON d.id = dl.dispatch_id
    JOIN clip_reports c ON c.id = d.clip_report_id
    LEFT JOIN users usr ON usr.id = dl.logged_by
    $where
    ORDER BY dl.started_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$delays = $stmt->fetchAll(PDO::FETCH_ASSOC);

$reasonOptions = [
    'Road obstruction/traffic', 'Vehicle breakdown/mechanical issue', 'Weather/flooding',
    'Wrong/unclear location', 'Fuel issue', 'Waiting for backup unit', 'Unit unreachable', 'Other'
];
$totalPages = max(1, ceil($totalRows / $perPage));
$unreadAlerts = 0;

function fmtDuration($seconds) {
    if ($seconds === null) return '—';
    $m = floor($seconds / 60);
    return $m . ' min';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Delay Reports — HopeLine</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/app.css">

</head>
<body>

<?php require_once __DIR__ . '/../../assets/layouts/admin/admin_sidebar.php'; ?>

<main class="main">
    <div class="page-head">
        <h1>Delay Reports</h1>
        <p>System-wide delay history for LGU reporting and infrastructure advocacy.</p>
    </div>

    <div class="stats-grid">
        <div class="stat-card"><div class="stat-value"><?php echo (int)$summary['total']; ?></div><div class="stat-label">Total Delays (range)</div></div>
        <div class="stat-card"><div class="stat-value"><?php echo (int)$summary['active_count']; ?></div><div class="stat-label">Currently Active</div></div>
        <div class="stat-card"><div class="stat-value"><?php echo fmtDuration($summary['avg_duration']); ?></div><div class="stat-label">Avg. Delay Duration</div></div>
        <div class="stat-card"><div class="stat-value" style="font-size:14px; line-height:1.4;"><?php echo $topReason ? htmlspecialchars($topReason['reason']) : '—'; ?></div><div class="stat-label">Most Common Cause</div></div>
    </div>

    <form method="GET" class="filter-bar">
        <div class="filter-field"><label>From</label><input type="date" name="from" value="<?php echo htmlspecialchars($dateFrom); ?>"></div>
        <div class="filter-field"><label>To</label><input type="date" name="to" value="<?php echo htmlspecialchars($dateTo); ?>"></div>
        <div class="filter-field">
            <label>Reason</label>
            <select name="reason">
                <option value="">All Reasons</option>
                <?php foreach ($reasonOptions as $r): ?>
                    <option value="<?php echo htmlspecialchars($r); ?>" <?php echo $reasonFilter===$r?'selected':''; ?>><?php echo htmlspecialchars($r); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-field">
            <label>Type</label>
            <select name="type">
                <option value="">All Types</option>
                <option value="auto" <?php echo $typeFilter==='auto'?'selected':''; ?>>Responder-reported</option>
                <option value="manual" <?php echo $typeFilter==='manual'?'selected':''; ?>>Manager-logged (manual)</option>
            </select>
        </div>
        <div class="filter-field">
            <label>Status</label>
            <select name="status">
                <option value="">All</option>
                <option value="active" <?php echo $statusFilter==='active'?'selected':''; ?>>Active</option>
                <option value="resolved" <?php echo $statusFilter==='resolved'?'selected':''; ?>>Resolved</option>
            </select>
        </div>
        <button type="submit" class="btn-filter">Apply Filters</button>
    </form>

    <?php if (empty($delays)): ?>
        <div class="empty-state">No delays found for this range.</div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table>
        <thead><tr>
            <th>Unit</th><th>CLIP Ref</th><th>Barangay</th><th>Reason</th><th>Logged By</th>
            <th>Started</th><th>Duration</th><th>Type</th><th>Status</th>
        </tr></thead>
        <tbody>
            <?php foreach ($delays as $d):
                $isActive = $d['resolved_at'] === null;
                $duration = $isActive
                    ? round((time() - strtotime($d['started_at'])) / 60) . ' min (ongoing)'
                    : round((strtotime($d['resolved_at']) - strtotime($d['started_at'])) / 60) . ' min';
            ?>
            <tr>
                <td><?php echo htmlspecialchars($d['unit_name']); ?></td>
                <td class="clip-ref-cell"><?php echo htmlspecialchars($d['clip_ref']); ?></td>
                <td><?php echo htmlspecialchars($d['barangay']); ?></td>
                <td><?php echo htmlspecialchars($d['reason']); ?></td>
                <td><?php echo htmlspecialchars($d['logged_by_name'] ?? 'Responder'); ?></td>
                <td><?php echo date('M j, g:i A', strtotime($d['started_at'])); ?></td>
                <td><?php echo $duration; ?></td>
                <td><span class="tag <?php echo $d['is_manual'] ? 'tag-manual' : 'tag-auto'; ?>"><?php echo $d['is_manual'] ? 'Manual' : 'Reported'; ?></span></td>
                <td><span class="tag <?php echo $isActive ? 'tag-active' : 'tag-resolved'; ?>"><?php echo $isActive ? 'Active' : 'Resolved'; ?></span></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <div class="pagination">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <a href="?from=<?php echo urlencode($dateFrom); ?>&to=<?php echo urlencode($dateTo); ?>&reason=<?php echo urlencode($reasonFilter); ?>&type=<?php echo urlencode($typeFilter); ?>&status=<?php echo urlencode($statusFilter); ?>&page=<?php echo $p; ?>"
               class="<?php echo $p===$page?'active':''; ?>"><?php echo $p; ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</main>

</body>
</html>