<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';



$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'force_cancel') {
    $id = (int)($_POST['clip_report_id'] ?? 0);
    $pdo->prepare("UPDATE clip_reports SET status = 'cancelled' WHERE id = ?")->execute([$id]);
    $pdo->prepare("UPDATE dispatch SET status = 'cancelled' WHERE clip_report_id = ? AND status NOT IN ('resolved','cancelled')")->execute([$id]);
    $flash = 'Incident cancelled.';
}

$statusFilter = $_GET['status'] ?? '';
$severityFilter = $_GET['severity'] ?? '';
$barangayFilter = $_GET['barangay'] ?? '';
$search = $_GET['q'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$where = "WHERE 1=1";
$params = [];
if ($statusFilter !== '') { $where .= " AND c.status = ?"; $params[] = $statusFilter; }
if ($severityFilter !== '') { $where .= " AND c.severity = ?"; $params[] = $severityFilter; }
if ($barangayFilter !== '') { $where .= " AND c.barangay = ?"; $params[] = $barangayFilter; }
if ($search !== '') { $where .= " AND (c.clip_ref LIKE ? OR c.caller_name LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }

$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM clip_reports c $where");
$totalStmt->execute($params);
$totalRows = $totalStmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT c.*, d.id AS dispatch_id, d.status AS dispatch_status, d.departed_at, d.arrived_at, u.unit_name
    FROM clip_reports c
    LEFT JOIN dispatch d ON d.clip_report_id = c.id
    LEFT JOIN ptv_units u ON u.id = d.unit_id
    $where
    ORDER BY c.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$incidents = $stmt->fetchAll(PDO::FETCH_ASSOC);

$barangays = $pdo->query("SELECT DISTINCT barangay FROM clip_reports ORDER BY barangay")->fetchAll(PDO::FETCH_COLUMN);
$totalPages = max(1, ceil($totalRows / $perPage));
$unreadAlerts = 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Incident Records — HopeLine</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/sidebar.css">
<style>
    :root { --burnt-umber:#6d120b; --redwood:#b02029; --macadamia:#fbf0d8; --cool-blue:#113047; --light-grayish:#739ab9;
            --critical:#b02029; --high:#d9752b; --moderate:#d4ab2b; --low:#3f7a5c; }
    * { box-sizing:border-box; margin:0; padding:0; }
    body { font-family:'Segoe UI',-apple-system,BlinkMacSystemFont,sans-serif; background:#0c2334; display:flex; min-height:100vh; }
    .main { flex:1; padding:26px 32px 50px; color:var(--macadamia); max-width:1320px; overflow-x:auto; }
    .page-head { margin-bottom:18px; }
    .page-head h1 { font-size:21px; margin-bottom:4px; }
    .page-head p { color:var(--light-grayish); font-size:13px; }

    .flash { background:rgba(63,122,92,0.18); border:1px solid #3f7a5c; color:#b7ecd1; padding:10px 14px; border-radius:6px; font-size:13px; margin-bottom:16px; }

    .filter-bar { display:flex; gap:10px; align-items:center; margin-bottom:18px; flex-wrap:wrap;
        background:rgba(251,240,216,0.04); border:1px solid rgba(115,154,185,0.18); border-radius:10px; padding:14px 16px; }
    .filter-bar input, .filter-bar select {
        background:rgba(251,240,216,0.06); border:1px solid rgba(115,154,185,0.28); border-radius:6px;
        padding:8px 10px; color:var(--macadamia); font-size:12.5px; outline:none;
    }
    .btn-filter { background:var(--burnt-umber); color:var(--macadamia); border:0; padding:9px 18px; border-radius:20px; font-weight:700; font-size:12px; cursor:pointer; }

    table { width:100%; border-collapse:collapse; background:rgba(251,240,216,0.04); border:1px solid rgba(115,154,185,0.18); border-radius:10px; overflow:hidden; }
    thead th { text-align:left; font-size:10.5px; text-transform:uppercase; color:var(--light-grayish); padding:12px 14px; border-bottom:1px solid rgba(115,154,185,0.18); white-space:nowrap; }
    tbody td { padding:11px 14px; font-size:12.5px; border-bottom:1px solid rgba(115,154,185,0.08); white-space:nowrap; }
    tbody tr:last-child td { border-bottom:none; }

    .clip-ref-cell { font-family:monospace; font-size:11px; color:var(--light-grayish); }
    .sev-badge { font-size:9.5px; font-weight:700; text-transform:uppercase; padding:2px 8px; border-radius:20px; }
    .sev-Critical { background:rgba(176,32,41,0.2); color:var(--critical); }
    .sev-High { background:rgba(217,117,43,0.2); color:var(--high); }
    .sev-Moderate { background:rgba(212,171,43,0.2); color:var(--moderate); }
    .sev-Low { background:rgba(63,122,92,0.2); color:var(--low); }

    .status-badge { font-size:9.5px; font-weight:700; text-transform:uppercase; padding:2px 8px; border-radius:20px; background:rgba(115,154,185,0.2); color:var(--light-grayish); }
    .status-resolved { background:rgba(63,122,92,0.2); color:#3f7a5c; }
    .status-pending { background:rgba(217,117,43,0.2); color:var(--high); }
    .status-cancelled { background:rgba(115,154,185,0.15); color:var(--light-grayish); text-decoration:line-through; }

    .btn-cancel-inc { background:rgba(176,32,41,0.15); color:var(--redwood); border:0; border-radius:20px; padding:5px 11px; font-size:11px; font-weight:700; cursor:pointer; }

    .pagination { display:flex; justify-content:center; gap:6px; margin-top:18px; }
    .pagination a { padding:7px 12px; border-radius:6px; font-size:12px; color:var(--light-grayish); text-decoration:none; border:1px solid rgba(115,154,185,0.2); }
    .pagination a.active { background:var(--burnt-umber); color:var(--macadamia); border-color:var(--burnt-umber); }

    .empty-state { text-align:center; padding:50px 20px; color:var(--light-grayish); font-size:13px; }
</style>
</head>
<body>

<?php require_once __DIR__ . '/../../assets/layouts/admin/admin_sidebar.php'; ?>

<main class="main">
    <div class="page-head">
        <h1>Incident Records</h1>
        <p>Full log of every CLIP report system-wide — <?php echo $totalRows; ?> total.</p>
    </div>

    <?php if ($flash): ?><div class="flash"><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>

    <form method="GET" class="filter-bar">
        <input type="text" name="q" placeholder="Search CLIP ref or caller..." value="<?php echo htmlspecialchars($search); ?>">
        <select name="status">
            <option value="">All Statuses</option>
            <option value="pending" <?php echo $statusFilter==='pending'?'selected':''; ?>>Pending</option>
            <option value="dispatched" <?php echo $statusFilter==='dispatched'?'selected':''; ?>>Dispatched</option>
            <option value="resolved" <?php echo $statusFilter==='resolved'?'selected':''; ?>>Resolved</option>
            <option value="cancelled" <?php echo $statusFilter==='cancelled'?'selected':''; ?>>Cancelled</option>
        </select>
        <select name="severity">
            <option value="">All Severities</option>
            <option value="Critical" <?php echo $severityFilter==='Critical'?'selected':''; ?>>Critical</option>
            <option value="High" <?php echo $severityFilter==='High'?'selected':''; ?>>High</option>
            <option value="Moderate" <?php echo $severityFilter==='Moderate'?'selected':''; ?>>Moderate</option>
            <option value="Low" <?php echo $severityFilter==='Low'?'selected':''; ?>>Low</option>
        </select>
        <select name="barangay">
            <option value="">All Barangays</option>
            <?php foreach ($barangays as $b): ?>
                <option value="<?php echo htmlspecialchars($b); ?>" <?php echo $barangayFilter===$b?'selected':''; ?>><?php echo htmlspecialchars($b); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-filter">Apply</button>
    </form>

    <?php if (empty($incidents)): ?>
        <div class="empty-state">No incidents match these filters.</div>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table>
        <thead><tr>
            <th>CLIP Ref</th><th>Caller</th><th>Barangay</th><th>Incident</th><th>Severity</th>
            <th>Unit</th><th>Reported</th><th>Status</th><th>Actions</th>
        </tr></thead>
        <tbody>
            <?php foreach ($incidents as $inc): ?>
            <tr>
                <td class="clip-ref-cell"><?php echo htmlspecialchars($inc['clip_ref']); ?></td>
                <td><?php echo htmlspecialchars($inc['caller_name']); ?></td>
                <td><?php echo htmlspecialchars($inc['barangay']); ?></td>
                <td><?php echo htmlspecialchars($inc['incident_type']); ?></td>
                <td><span class="sev-badge sev-<?php echo $inc['severity']; ?>"><?php echo $inc['severity']; ?></span></td>
                <td><?php echo htmlspecialchars($inc['unit_name'] ?? '—'); ?></td>
                <td><?php echo date('M j, g:i A', strtotime($inc['created_at'])); ?></td>
                <td><span class="status-badge status-<?php echo $inc['status']; ?>"><?php echo ucfirst($inc['status']); ?></span></td>
                <td>
                    <?php if (!in_array($inc['status'], ['resolved','cancelled'])): ?>
                    <form method="POST" onsubmit="return confirm('Cancel this incident?');">
                        <input type="hidden" name="action" value="force_cancel">
                        <input type="hidden" name="clip_report_id" value="<?php echo $inc['id']; ?>">
                        <button type="submit" class="btn-cancel-inc">Cancel</button>
                    </form>
                    <?php else: echo '—'; endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <div class="pagination">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <a href="?status=<?php echo urlencode($statusFilter); ?>&severity=<?php echo urlencode($severityFilter); ?>&barangay=<?php echo urlencode($barangayFilter); ?>&q=<?php echo urlencode($search); ?>&page=<?php echo $p; ?>"
               class="<?php echo $p===$page?'active':''; ?>"><?php echo $p; ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</main>

</body>
</html>