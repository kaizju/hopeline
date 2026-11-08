<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';
require_once __DIR__ . '/../../config/closeout.php';

requireRole('admin');

$flash = consumeFlash();
$flashType = consumeFlashType();
$postFlashType = 'success';
$dispatchId = (int)($_POST['dispatch_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {

    if ($action === 'force_cancel') {
    $id = (int)($_POST['clip_report_id'] ?? 0);
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT status FROM clip_reports WHERE id = ? FOR UPDATE");
        $st->execute([$id]);
        $cur = $st->fetchColumn();
        if ($cur === false || in_array($cur, ['resolved', 'cancelled'], true)) {
            $pdo->rollBack();
            $postFlashType = 'error';
            $flash = 'Only pending or dispatched incidents can be cancelled.';
        } else {
            $act = "('assigned','en_route','on_site','returning')";
            // release vehicles BEFORE the dispatch rows stop counting as active
            $pdo->prepare("UPDATE ptv_units u JOIN dispatch d ON d.unit_id = u.id
                           SET u.status = 'Available', u.current_lat = ?, u.current_lng = ?
                           WHERE d.clip_report_id = ? AND d.status IN $act")
                ->execute([PTV_BASE_LAT, PTV_BASE_LNG, $id]);
            $pdo->prepare("UPDATE dispatch SET status = 'cancelled' WHERE clip_report_id = ? AND status IN $act")->execute([$id]);
            $pdo->prepare("UPDATE clip_reports SET status = 'cancelled' WHERE id = ?")->execute([$id]);
            $pdo->commit();
            if (function_exists('logActivity')) logActivity($pdo, $_SESSION['user_id'], $_SESSION['email'], 'incident_cancelled', 'success');
            $flash = 'Incident cancelled and its vehicles released.';
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log($e->getMessage());
        $postFlashType = 'error';
        $flash = 'Cancel failed. Please try again.';
    }
}

    // ---- Archive (replaces Delete) — only resolved/cancelled incidents can be archived ----
    if ($action === 'archive_incident') {
        $id = (int)($_POST['clip_report_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT status FROM clip_reports WHERE id = ?");
        $stmt->execute([$id]);
        $status = $stmt->fetchColumn();

        if (!in_array($status, ['resolved', 'cancelled'])) {
            $postFlashType = 'error';
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

    // ---- Case closeout details (feeds the CSV report export) ----
       if ($action === 'save_closeout') {
       $flash = saveCloseout($pdo, $dispatchId);
   }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('HopeLine incident action failed: ' . $e->getMessage());
        $postFlashType = 'error';
        $flash = 'Incident action failed. Check the incident details and try again.';
    }
    redirectAfterPost('incidents.php', $flash ?: 'No incident action was completed.', $postFlashType);
}

$view = ($_GET['view'] ?? '') === 'archived' ? 'archived' : 'active';
$statusFilter = $_GET['status'] ?? '';
$severityFilter = $_GET['severity'] ?? '';
$barangayFilter = $_GET['barangay'] ?? '';
$search = $_GET['q'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset = ($page - 1) * $perPage;

$in = "'" . implode("','", purokList()) . "'";
$where = ($view === 'archived' ? "WHERE c.archived_at IS NOT NULL" : "WHERE c.archived_at IS NULL") . " AND c.barangay IN ($in)";
$params = [];
if ($statusFilter !== '') { $where .= " AND c.status = ?"; $params[] = $statusFilter; }
if ($severityFilter !== '') { $where .= " AND c.severity = ?"; $params[] = $severityFilter; }
if ($barangayFilter !== '') { $where .= " AND c.barangay = ?"; $params[] = $barangayFilter; }
if ($search !== '') { $where .= " AND (c.clip_ref LIKE ? OR c.caller_name LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }

$totalStmt = $pdo->prepare("SELECT COUNT(*) FROM clip_reports c $where");
$totalStmt->execute($params);
$totalRows = $totalStmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT c.*, d.id AS dispatch_id, d.status AS dispatch_status, d.departed_at, d.arrived_at,
           (SELECT GROUP_CONCAT(u2.unit_name ORDER BY u2.unit_name SEPARATOR ', ')
              FROM dispatch dx JOIN ptv_units u2 ON u2.id = dx.unit_id
              WHERE dx.clip_report_id = c.id AND dx.status <> 'cancelled') AS unit_name,
           d.patient_name, d.patient_age_group, d.patient_sex, d.victim_count, d.vital_signs,
           d.alcohol_breath, d.incident_details, d.incident_photo, d.closeout_remarks
    FROM clip_reports c
    LEFT JOIN dispatch d ON d.id = (SELECT MAX(d2.id) FROM dispatch d2 WHERE d2.clip_report_id = c.id)
    $where
    ORDER BY c.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$incidents = $stmt->fetchAll(PDO::FETCH_ASSOC);

$archivedCount = $pdo->query("SELECT COUNT(*) FROM clip_reports WHERE archived_at IS NOT NULL")->fetchColumn();
$barangays = purokList();
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
    <div class="page-head page-head--flex">
        <div>
            <h1>Incident Records</h1>
            <p>Full log of every CLIP report system-wide — <?php echo $totalRows; ?> <?php echo $view; ?>.</p>
        </div>
      <?php echo exportPeriodForm(BASE_URL . '/app/admin/export-cases.php', array_diff_key($_GET, array_flip(['period','ref','from','to','page']))); ?>
    </div>

    <?php if ($flash): ?><div class="<?php echo $flashType === 'error' ? 'alert-banner alert-error' : 'flash'; ?>"><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>

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
            <option value="resolved" <?php echo $statusFilter==='resolved'?'selected':''; ?>>Completed</option>
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
            <option value="">All Puroks</option>
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
            <th>CLIP Ref</th><th>Caller</th><th>Purok</th><th>Incident</th><th>Severity</th>
            <th>Unit</th><th>Reported</th><th>Status</th><th>Photo</th><th>Case Details</th><th>Actions</th>
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
                <td><?php echo date('M j, H:i', strtotime($inc['created_at'])); ?></td>
                <td><span class="status-badge status-<?php echo $inc['status']; ?>"><?php echo statusLabel($inc['status']); ?></span></td>

                <!-- Photo -->
                <td>
                    <?php if (!empty($inc['incident_photo'])): ?>
                        <a href="<?php echo BASE_URL . '/' . htmlspecialchars($inc['incident_photo']); ?>" target="_blank">
                            <img src="<?php echo BASE_URL . '/' . htmlspecialchars($inc['incident_photo']); ?>" style="width:44px;height:44px;object-fit:cover;border-radius:6px;">
                        </a>
                    <?php else: ?>—<?php endif; ?>
                </td>

                <!-- Case Details (this cell was missing) -->
                <!-- Case Details -->
<td>
    <?php if (canEditCloseout($inc)): closeoutButton($inc); else: ?>—<?php endif; ?>
</td>

                <!-- Actions -->
                <td class="row-actions">
                    <?php if ($view === 'active'): ?>
                        <?php if (!in_array($inc['status'], ['resolved','cancelled'])): ?>
                        <form method="POST" onsubmit="return confirm('Cancel this incident?');" style="display:inline;">
                            <input type="hidden" name="action" value="force_cancel">
                            <input type="hidden" name="clip_report_id" value="<?php echo $inc['id']; ?>">
                            <button type="submit" class="btn-cancel-inc">Cancel</button>
                        </form>
                        <?php else: ?>
                        <form method="POST" onsubmit="return confirm('Archive this incident?');" style="display:inline;">
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

    <?php foreach ($incidents as $inc) if (canEditCloseout($inc)) closeoutModal($inc); ?>
    <?php endif; ?>
</main>

</body>
</html>
