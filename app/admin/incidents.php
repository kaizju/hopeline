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

    // ---- Case closeout details (feeds the CSV report export) ----
    if ($action === 'save_closeout') {
        $dispatchId   = (int)($_POST['dispatch_id'] ?? 0);
        $patientName  = trim($_POST['patient_name'] ?? '');
        $ageGroup     = $_POST['patient_age_group'] ?: null;
        $sex          = $_POST['patient_sex'] ?: null;
        $victimCount  = $_POST['victim_count'] !== '' ? (int)$_POST['victim_count'] : null;
        $vitalSigns   = $_POST['vital_signs'] ?: null;
        $alcohol      = $_POST['alcohol_breath'] ?: null;
        $remarks      = trim($_POST['closeout_remarks'] ?? '');

        $pdo->prepare("UPDATE dispatch SET
                patient_name = ?, patient_age_group = ?, patient_sex = ?, victim_count = ?,
                vital_signs = ?, alcohol_breath = ?, closeout_remarks = ?
            WHERE id = ?")
            ->execute([$patientName, $ageGroup, $sex, $victimCount, $vitalSigns, $alcohol, $remarks, $dispatchId]);

        if (function_exists('logActivity')) {
            logActivity($pdo, $_SESSION['user_id'], $_SESSION['email'], 'incident_closeout_saved', 'success');
        }
        $flash = 'Case details saved.';
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
    SELECT c.*, d.id AS dispatch_id, d.status AS dispatch_status, d.departed_at, d.arrived_at, u.unit_name,
           d.patient_name, d.patient_age_group, d.patient_sex, d.victim_count, d.vital_signs, d.alcohol_breath, d.closeout_remarks
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
    <div class="page-head page-head--flex">
        <div>
            <h1>Incident Records</h1>
            <p>Full log of every CLIP report system-wide — <?php echo $totalRows; ?> <?php echo $view; ?>.</p>
        </div>
        <a href="<?php echo BASE_URL; ?>/app/admin/reports.php" class="btn-primary">Export Case Report →</a>
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
            <th>Unit</th><th>Reported</th><th>Status</th><th>Case Details</th><th>Actions</th>
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
                    <?php if ($inc['dispatch_id'] && $inc['status'] === 'resolved'): ?>
                        <button type="button" class="btn-mini <?php echo $inc['patient_name'] ? 'btn-activate' : 'btn-deactivate'; ?>"
                                onclick="document.getElementById('closeout-<?php echo $inc['dispatch_id']; ?>').classList.add('show')">
                            <?php echo $inc['patient_name'] ? 'Edit' : 'Add Details'; ?>
                        </button>
                    <?php else: echo '—'; endif; ?>
                </td>
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

            <?php if ($inc['dispatch_id'] && $inc['status'] === 'resolved'): ?>
            <div class="modal-overlay" id="closeout-<?php echo $inc['dispatch_id']; ?>">
                <div class="modal">
                    <h3>Case Details — <?php echo htmlspecialchars($inc['clip_ref']); ?></h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="save_closeout">
                        <input type="hidden" name="dispatch_id" value="<?php echo $inc['dispatch_id']; ?>">

                        <div class="field">
                            <label>Name of Patient</label>
                            <input type="text" name="patient_name" value="<?php echo htmlspecialchars($inc['patient_name'] ?? ''); ?>" placeholder="Defaults to caller name if left blank">
                        </div>
                        <div class="field-row">
                            <div class="field">
                                <label>Age Group</label>
                                <select name="patient_age_group">
                                    <option value="">— Select —</option>
                                    <option value="Minor" <?php echo $inc['patient_age_group']==='Minor'?'selected':''; ?>>Minor (18 below)</option>
                                    <option value="Matured" <?php echo $inc['patient_age_group']==='Matured'?'selected':''; ?>>Matured (19 above)</option>
                                    <option value="Senior" <?php echo $inc['patient_age_group']==='Senior'?'selected':''; ?>>Senior</option>
                                </select>
                            </div>
                            <div class="field">
                                <label>Sex</label>
                                <select name="patient_sex">
                                    <option value="">— Select —</option>
                                    <option value="Male" <?php echo $inc['patient_sex']==='Male'?'selected':''; ?>>Male</option>
                                    <option value="Female" <?php echo $inc['patient_sex']==='Female'?'selected':''; ?>>Female</option>
                                </select>
                            </div>
                        </div>
                        <div class="field-row">
                            <div class="field">
                                <label># of Victims</label>
                                <input type="number" name="victim_count" min="0" value="<?php echo htmlspecialchars($inc['victim_count'] ?? ''); ?>">
                            </div>
                            <div class="field">
                                <label>Vital Signs</label>
                                <select name="vital_signs">
                                    <option value="">— Select —</option>
                                    <option value="Negative" <?php echo $inc['vital_signs']==='Negative'?'selected':''; ?>>Negative</option>
                                    <option value="Positive" <?php echo $inc['vital_signs']==='Positive'?'selected':''; ?>>Positive</option>
                                </select>
                            </div>
                        </div>
                        <div class="field">
                            <label>Alcohol Breath Test</label>
                            <select name="alcohol_breath">
                                <option value="">— Select —</option>
                                <option value="Positive" <?php echo $inc['alcohol_breath']==='Positive'?'selected':''; ?>>Positive</option>
                                <option value="Negative" <?php echo $inc['alcohol_breath']==='Negative'?'selected':''; ?>>Negative</option>
                                <option value="Not Tested" <?php echo $inc['alcohol_breath']==='Not Tested'?'selected':''; ?>>Not Tested</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>Remarks</label>
                            <textarea name="closeout_remarks" rows="2"><?php echo htmlspecialchars($inc['closeout_remarks'] ?? ''); ?></textarea>
                        </div>

                        <div class="modal-actions">
                            <button type="button" class="btn-cancel" onclick="document.getElementById('closeout-<?php echo $inc['dispatch_id']; ?>').classList.remove('show')">Cancel</button>
                            <button type="submit" class="btn-primary">Save</button>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
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