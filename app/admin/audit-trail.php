<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';



$actionFilter = $_GET['action_type'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$search = $_GET['q'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$where = "WHERE 1=1";
$params = [];
if ($actionFilter !== '') { $where .= " AND action = ?"; $params[] = $actionFilter; }
if ($statusFilter !== '') { $where .= " AND status = ?"; $params[] = $statusFilter; }
if ($search !== '') { $where .= " AND email LIKE ?"; $params[] = "%$search%"; }

$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM activity_log $where");
$totalStmt->execute($params);
$totalRows = $totalStmt->fetchColumn();

$stmt = $pdo->prepare("SELECT * FROM activity_log $where ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

$actionTypes = $pdo->query("SELECT DISTINCT action FROM activity_log ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
$totalPages = max(1, ceil($totalRows / $perPage));
$unreadAlerts = 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Audit Trail — HopeLine</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/sidebar.css">
<style>
    :root { --burnt-umber:#6d120b; --redwood:#b02029; --macadamia:#fbf0d8; --cool-blue:#113047; --light-grayish:#739ab9; }
    * { box-sizing:border-box; margin:0; padding:0; }
    body { font-family:'Segoe UI',-apple-system,BlinkMacSystemFont,sans-serif; background:#0c2334; display:flex; min-height:100vh; }
    .main { flex:1; padding:26px 32px 50px; color:var(--macadamia); max-width:1100px; }
    .page-head { margin-bottom:18px; }
    .page-head h1 { font-size:21px; margin-bottom:4px; }
    .page-head p { color:var(--light-grayish); font-size:13px; }

    .filter-bar { display:flex; gap:10px; margin-bottom:18px; flex-wrap:wrap;
        background:rgba(251,240,216,0.04); border:1px solid rgba(115,154,185,0.18); border-radius:10px; padding:14px 16px; }
    .filter-bar input, .filter-bar select {
        background:rgba(251,240,216,0.06); border:1px solid rgba(115,154,185,0.28); border-radius:6px;
        padding:8px 10px; color:var(--macadamia); font-size:12.5px; outline:none;
    }
    .btn-filter { background:var(--burnt-umber); color:var(--macadamia); border:0; padding:9px 18px; border-radius:20px; font-weight:700; font-size:12px; cursor:pointer; }

    table { width:100%; border-collapse:collapse; background:rgba(251,240,216,0.04); border:1px solid rgba(115,154,185,0.18); border-radius:10px; overflow:hidden; }
    thead th { text-align:left; font-size:10.5px; text-transform:uppercase; color:var(--light-grayish); padding:12px 14px; border-bottom:1px solid rgba(115,154,185,0.18); }
    tbody td { padding:11px 14px; font-size:12.5px; border-bottom:1px solid rgba(115,154,185,0.08); }
    tbody tr:last-child td { border-bottom:none; }

    .status-badge { font-size:9.5px; font-weight:700; text-transform:uppercase; padding:2px 9px; border-radius:20px; }
    .status-success { background:rgba(63,122,92,0.2); color:#3f7a5c; }
    .status-failed { background:rgba(176,32,41,0.2); color:var(--redwood); }

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
        <h1>Audit Trail</h1>
        <p><?php echo $totalRows; ?> logged event(s) system-wide.</p>
    </div>

    <form method="GET" class="filter-bar">
        <input type="text" name="q" placeholder="Search by email..." value="<?php echo htmlspecialchars($search); ?>">
        <select name="action_type">
            <option value="">All Actions</option>
            <?php foreach ($actionTypes as $a): ?>
                <option value="<?php echo htmlspecialchars($a); ?>" <?php echo $actionFilter===$a?'selected':''; ?>><?php echo htmlspecialchars(ucwords(str_replace('_',' ',$a))); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status">
            <option value="">All Outcomes</option>
            <option value="success" <?php echo $statusFilter==='success'?'selected':''; ?>>Success</option>
            <option value="failed" <?php echo $statusFilter==='failed'?'selected':''; ?>>Failed</option>
        </select>
        <button type="submit" class="btn-filter">Apply</button>
    </form>

    <?php if (empty($logs)): ?>
        <div class="empty-state">No activity recorded yet.</div>
    <?php else: ?>
    <table>
        <thead><tr><th>Timestamp</th><th>User</th><th>Action</th><th>Outcome</th></tr></thead>
        <tbody>
            <?php foreach ($logs as $l): ?>
            <tr>
                <td><?php echo date('M j, Y g:i:s A', strtotime($l['created_at'])); ?></td>
                <td><?php echo htmlspecialchars($l['email'] ?? 'Unknown'); ?></td>
                <td><?php echo htmlspecialchars(ucwords(str_replace('_',' ',$l['action']))); ?></td>
                <td><span class="status-badge status-<?php echo $l['status']; ?>"><?php echo $l['status']; ?></span></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="pagination">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <a href="?action_type=<?php echo urlencode($actionFilter); ?>&status=<?php echo urlencode($statusFilter); ?>&q=<?php echo urlencode($search); ?>&page=<?php echo $p; ?>"
               class="<?php echo $p===$page?'active':''; ?>"><?php echo $p; ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</main>

</body>
</html>