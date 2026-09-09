<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';

requireRole('admin');

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
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/app.css">

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