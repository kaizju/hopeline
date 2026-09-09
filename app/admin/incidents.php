<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';

requireRole('admin');

$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'force_cancel') {
        $id = (int)($_POST['clip_report_id'] ?? 0);
        $pdo->prepare("UPDATE clip_reports SET status = 'cancelled' WHERE id = ?")->execute([$id]);
        $pdo->prepare("UPDATE dispatch SET status = 'cancelled' WHERE clip_report_id = ? AND status NOT IN ('resolved','cancelled')")->execute([$id]);
        $flash = 'Incident cancelled.';
    }

    // ---- Archive (replaces Delete) — only resolved/cancelled incidents can be archived ----
    if ($action === 'archive_incident') {
        $id = (int)($_POST['clip_report_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT status FROM clip_reports WHERE id = ?");
        $stmt->execute([$id]);
        $status = $stmt->fetchColumn();

        if (!in_array($status, ['resolved', 'cancelled'])) {
            $flash = "Only resolved or cancelled incidents can be archived.";
        } else {
            $pdo->prepare("UPDATE clip_reports SET archived_at = NOW() WHERE id = ?")->execute([$id]);
            if (function_exists('logActivity')) {
                logActivity($pdo, $_SESSION['user_id'], $_SESSION['email'], 'incident_archived', 'success');
            }
            $flash = 'Incident archived.';
        }
    }

    if ($action === 'restore_incident') {
        $id = (int)($_POST['clip_report_id'] ?? 0);
        $pdo->prepare("UPDATE clip_reports SET archived_at = NULL WHERE id = ?")->execute([$id]);
        if (function_exists('logActivity')) {
            logActivity($pdo, $_SESSION['user_id'], $_SESSION['email'], 'incident_restored', 'success');
        }
        $flash = 'Incident restored.';
    }
}

$view = ($_GET['view'] ?? '') === 'archived' ? 'archived' : 'active';
$statusFilter = $_GET['status'] ?? '';
$severityFilter = $_GET['severity'] ?? '';
$barangayFilter = $_GET['barangay'] ?? '';
$search = $_GET['q'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$where = $view === 'archived' ? "WHERE c.archived_at IS NOT NULL" : "WHERE c.archived_at IS NULL";
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

$archivedCount = $pdo->query("SELECT COUNT(*) FROM clip_reports WHERE archived_at IS NOT NULL")->fetchColumn();
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
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/app.css">
</head>
<body>

<?php require_once __DIR__ . '/../../assets/layouts/admin/admin_sidebar.php'; ?>

<main class="main main-1320">
    <div class="page-head">
        <h1>Incident Records</h1>
        <p>Full log of every CLIP report system-wide — <?php echo $totalRows; ?> <?php echo $view; ?>.</p>
    </div>

    <?php if ($flash): ?><div class="flash"><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>

    <div class="tabs">
        <a href="?view=active" class="tab <?php echo $view === 'active' ? 'active' : ''; ?>" style="text-decoration:none;">Active</a>
        <a href="?view=archived" class="tab <?php echo $view === 'archived' ? 'active' : ''; ?>" style="text-decoration:none;">Archived (<?php echo $archivedCount; ?>)</a>
    </div>

    <form method="GET" class="filter-bar">
        <input type="hidden" name="view" value="<?php echo $view; ?>">
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
        <div class="empty-state"><?php echo $view === 'archived' ? 'No archived incidents.' : 'No incidents match these filters.'; ?></div>
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
                <td class="row-actions">
                    <?php if ($view === 'active'): ?>
                        <?php if (!in_array($inc['status'], ['resolved','cancelled'])): ?>
                        <form method="POST" onsubmit="return confirm('Cancel this incident?');" style="display:inline;">
                            <input type="hidden" name="action" value="force_cancel">
                            <input type="hidden" name="clip_report_id" value="<?php echo $inc['id']; ?>">
                            <button type="submit" class="btn-cancel-inc">Cancel</button>
                        </form>
                        <?php else: ?>
                        <form method="POST" onsubmit="return confirm('Archive this incident? It stays in your database but leaves the default view.');" style="display:inline;">
                            <input type="hidden" name="action" value="archive_incident">
                            <input type="hidden" name="clip_report_id" value="<?php echo $inc['id']; ?>">
                            <button type="submit" class="btn-mini btn-deactivate">Archive</button>
                        </form>
                        <?php endif; ?>
                    <?php else: ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="restore_incident">
                            <input type="hidden" name="clip_report_id" value="<?php echo $inc['id']; ?>">
                            <button type="submit" class="btn-mini btn-activate">Restore</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <div class="pagination">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <a href="?view=<?php echo $view; ?>&status=<?php echo urlencode($statusFilter); ?>&severity=<?php echo urlencode($severityFilter); ?>&barangay=<?php echo urlencode($barangayFilter); ?>&q=<?php echo urlencode($search); ?>&page=<?php echo $p; ?>"
               class="<?php echo $p===$page?'active':''; ?>"><?php echo $p; ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</main>

</body>
</html>