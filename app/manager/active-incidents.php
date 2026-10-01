<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';

requireRole('manager');

// Show + clear flash from the previous request
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $msg = '';
    try {
        if ($action === 'dispatch') {
            $clipId = (int)($_POST['clip_report_id'] ?? 0);
            $unitId = (int)($_POST['unit_id'] ?? 0);

            $pdo->beginTransaction();

            // Is this exact unit already on this incident?
            $dup = $pdo->prepare("SELECT COUNT(*) FROM dispatch
                                  WHERE clip_report_id = ? AND unit_id = ?
                                    AND status = 'on_site'
                                  FOR UPDATE");
            $dup->execute([$clipId, $unitId]);

            // Is the unit still free?
            $u = $pdo->prepare("SELECT status FROM ptv_units WHERE id = ? FOR UPDATE");
            $u->execute([$unitId]);
            $unitStatus = $u->fetchColumn();

            if ($dup->fetchColumn() > 0) {
                $pdo->rollBack();
                $msg = 'That unit is already assigned to this incident.';
            } elseif ($unitStatus !== 'Available') {
                $pdo->rollBack();
                $msg = 'That unit is no longer available.';
            } else {
                $pdo->prepare("INSERT INTO dispatch (clip_report_id, unit_id, dispatched_by, status, predicted_eta_minutes)
               SELECT ?, ?, ?, 'assigned', predicted_eta_minutes FROM clip_reports WHERE id = ?")
    ->execute([$clipId, $unitId, currentUserId(), $clipId]);
                $pdo->prepare("UPDATE clip_reports SET status='dispatched' WHERE id=?")->execute([$clipId]);
                $pdo->prepare("UPDATE ptv_units SET status='Assigned' WHERE id=?")->execute([$unitId]);
                $pdo->commit();

                if (function_exists('logActivity')) {
                    logActivity($pdo, $_SESSION['user_id'], $_SESSION['email'], 'dispatch_assigned', 'success');
                }
                $msg = 'Unit dispatched successfully.';
            }
        }

        if ($action === 'resolve') {
            $clipId = (int)($_POST['clip_report_id'] ?? 0);
            $pdo->beginTransaction();

            $find = $pdo->prepare("SELECT id, unit_id FROM dispatch
                                   WHERE clip_report_id = ? AND status IN ('assigned','en_route','on_site')
                                   FOR UPDATE");
            $find->execute([$clipId]);
            $active = $find->fetchAll(PDO::FETCH_ASSOC);

            if (!$active) {
                $pdo->rollBack();
                $msg = 'Can\'t resolve yet — the PTV hasn\'t arrived on site.';
            } else {
                $pdo->prepare("UPDATE clip_reports SET status='resolved' WHERE id=?")->execute([$clipId]);
                foreach ($active as $d) {
                    $pdo->prepare("UPDATE dispatch SET status='returning', resolved_at=NOW() WHERE id=?")->execute([$d['id']]);
                    $pdo->prepare("UPDATE ptv_units SET status='Returning' WHERE id=?")->execute([$d['unit_id']]);
                }
                $pdo->commit();
                $msg = 'Incident marked as resolved. Unit is now returning to command center.';
            }
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $msg = 'Action failed: ' . $e->getMessage();
    }

    // Post/Redirect/Get: this is what stops reload from re-submitting
    $_SESSION['flash'] = $msg;
    header('Location: active-incidents.php');
    exit;
}

// ---- Fetch data ----
try {
    $incidents = $pdo->query("
    SELECT c.*, d.id AS dispatch_id, d.status AS dispatch_status, d.unit_id, u.unit_name
    FROM clip_reports c
    LEFT JOIN dispatch d ON d.id = (
        SELECT MAX(d2.id) FROM dispatch d2
        WHERE d2.clip_report_id = c.id
          AND d2.status IN ('assigned','en_route','on_site')
    )
    LEFT JOIN ptv_units u ON u.id = d.unit_id
    WHERE c.status NOT IN ('resolved','cancelled')
    ORDER BY FIELD(c.severity,'Critical','High','Moderate','Low'), c.created_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

    $availableUnits = $pdo->query("SELECT id, unit_name, plate_no FROM ptv_units WHERE status='Available' ORDER BY unit_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {

}


?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Active Incidents — HopeLine</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/app.css">
</head>
<body>

<?php require_once __DIR__ . '/../../assets/layouts/manager/manager_sidebar.php'; ?>

<main class="main">
    <div class="page-head">
        <h1>Active Incidents</h1>
        <p>All pending and in-progress incidents, sorted by severity.</p>
    </div>

    <?php if ($flash): ?><div class="flash"><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>

    <div class="toolbar">
        <input type="text" id="searchBox" placeholder="Search CLIP ref, caller, barangay...">
        <select id="severityFilter">
            <option value="all">All Severities</option>
            <option value="Critical">Critical</option>
            <option value="High">High</option>
            <option value="Moderate">Moderate</option>
            <option value="Low">Low</option>
        </select>
        <select id="statusFilter">
            <option value="all">All Statuses</option>
            <option value="pending">Pending</option>
            <option value="dispatched">Dispatched / En Route</option>
        </select>
    </div>

    <div id="incidentList">
        <?php if (empty($incidents)): ?>
            <div class="empty-state">No active incidents. All clear.</div>
        <?php else: ?>
            <?php foreach ($incidents as $inc):
                $mins = round((time() - strtotime($inc['created_at'])) / 60);
                $statusClass = 'status-' . ($inc['dispatch_status'] ?? $inc['status']);
                $statusLabel = $inc['dispatch_status'] ? ucfirst(str_replace('_',' ', $inc['dispatch_status'])) : 'Pending';
            ?>
            <div class="incident-card"
                 data-severity="<?php echo htmlspecialchars($inc['severity']); ?>"
                 data-status="<?php echo $inc['dispatch_id'] ? 'dispatched' : 'pending'; ?>"
                 data-search="<?php echo htmlspecialchars(strtolower($inc['clip_ref'].' '.$inc['caller_name'].' '.$inc['barangay'])); ?>">
                <div class="incident-top">
                    <div class="incident-title-wrap">
                        <span class="sev-badge sev-<?php echo $inc['severity']; ?>"><?php echo $inc['severity']; ?></span>
                        <div>
                            <div class="incident-title"><?php echo htmlspecialchars($inc['incident_type']); ?> — <?php echo htmlspecialchars($inc['barangay']); ?></div>
                            <div class="clip-ref"><?php echo htmlspecialchars($inc['clip_ref']); ?> · <?php echo $mins; ?>m ago</div>
                        </div>
                    </div>
                    <span class="status-pill <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></span>
                </div>

                <div class="incident-body">
                    <div class="info-block"><div class="label">Caller</div><div class="value"><?php echo htmlspecialchars($inc['caller_name']); ?></div></div>
                    <div class="info-block"><div class="label">Sitio/Purok</div><div class="value"><?php echo htmlspecialchars($inc['sitio_purok'] ?: '—'); ?></div></div>
                    <div class="info-block"><div class="label">Resources Needed</div><div class="value"><?php echo htmlspecialchars($inc['problem_resources']); ?></div></div>
                    <div class="info-block"><div class="label">Reported</div><div class="value"><?php echo date('g:i A', strtotime($inc['created_at'])); ?></div></div>
                </div>

                <div class="incident-actions">
                    <?php if ($inc['dispatch_status'] === 'on_site'): ?>
<form method="POST" style="margin-left:auto;">
    <input type="hidden" name="action" value="resolve">
    <input type="hidden" name="clip_report_id" value="<?php echo $inc['id']; ?>">
    <button type="submit" class="btn btn-resolve">Mark Resolved</button>
</form>
<?php else: ?>
<button type="button" class="btn btn-resolve" disabled style="margin-left:auto;opacity:.4;cursor:not-allowed;" title="Unlocks when the PTV arrives on site">🔒 Awaiting arrival</button>

                        <form method="POST" class="dispatch-form">
                            <input type="hidden" name="action" value="dispatch">
                            <input type="hidden" name="clip_report_id" value="<?php echo $inc['id']; ?>">
                            <select name="unit_id" required>
                                <option value="">Assign a unit…</option>
                                <?php foreach ($availableUnits as $u): ?>
                                    <option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['unit_name']); ?> (<?php echo htmlspecialchars($u['plate_no']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-dispatch">Dispatch</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</main>

<script>
    const searchBox = document.getElementById('searchBox');
    const sevFilter = document.getElementById('severityFilter');
    const statusFilter = document.getElementById('statusFilter');
    const cards = document.querySelectorAll('.incident-card');

    function applyFilters() {
        const term = searchBox.value.toLowerCase();
        const sev = sevFilter.value;
        const stat = statusFilter.value;
        cards.forEach(card => {
            const matchesSearch = card.dataset.search.includes(term);
            const matchesSev = sev === 'all' || card.dataset.severity === sev;
            const matchesStatus = stat === 'all' || card.dataset.status === stat;
            card.style.display = (matchesSearch && matchesSev && matchesStatus) ? '' : 'none';
        });
    }

    searchBox.addEventListener('input', applyFilters);
    sevFilter.addEventListener('change', applyFilters);
    statusFilter.addEventListener('change', applyFilters);
</script>

</body>
</html>