<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';

$flash = '';

// ---- Handle dispatch / resolve actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (($_POST['action'] ?? '') === 'dispatch') {
            $clipId = (int)($_POST['clip_report_id'] ?? 0);
            $unitId = (int)($_POST['unit_id'] ?? 0);

            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO dispatch (clip_report_id, unit_id, dispatched_by, status) VALUES (?, ?, ?, 'assigned')");
            $stmt->execute([$clipId, $unitId, $_SESSION['user_id']]);
            $pdo->prepare("UPDATE clip_reports SET status='dispatched' WHERE id=?")->execute([$clipId]);
            $pdo->prepare("UPDATE ptv_units SET status='En Route' WHERE id=?")->execute([$unitId]);
            $pdo->commit();

            if (function_exists('logActivity')) {
                logActivity($pdo, $_SESSION['user_id'], $_SESSION['email'], 'dispatch_assigned', 'success');
            }
            $flash = 'Unit dispatched successfully.';
        }

        if (($_POST['action'] ?? '') === 'resolve') {
            $clipId = (int)($_POST['clip_report_id'] ?? 0);
            $dispatchId = (int)($_POST['dispatch_id'] ?? 0);
            $unitId = (int)($_POST['unit_id'] ?? 0);

            $pdo->beginTransaction();
            $pdo->prepare("UPDATE clip_reports SET status='resolved' WHERE id=?")->execute([$clipId]);
            if ($dispatchId) {
                $pdo->prepare("UPDATE dispatch SET status='resolved', resolved_at=NOW() WHERE id=?")->execute([$dispatchId]);
            }
            if ($unitId) {
                $pdo->prepare("UPDATE ptv_units SET status='Available' WHERE id=?")->execute([$unitId]);
            }
            $pdo->commit();
            $flash = 'Incident marked as resolved.';
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $flash = 'Action failed: ' . $e->getMessage();
    }
}

// ---- Fetch data ----
try {
    $incidents = $pdo->query("
        SELECT c.*, d.id AS dispatch_id, d.status AS dispatch_status, d.unit_id, u.unit_name
        FROM clip_reports c
        LEFT JOIN dispatch d ON d.clip_report_id = c.id AND d.status IN ('assigned','en_route','on_site')
        LEFT JOIN ptv_units u ON u.id = d.unit_id
        WHERE c.status != 'resolved' AND c.status != 'cancelled'
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
<style>
    :root {
        --burnt-umber:#6d120b; --redwood:#b02029; --macadamia:#fbf0d8;
        --cool-blue:#113047; --light-grayish:#739ab9;
        --critical:#b02029; --high:#d9752b; --moderate:#d4ab2b; --low:#3f7a5c;
    }
    * { box-sizing:border-box; margin:0; padding:0; }
    body { font-family:'Segoe UI',-apple-system,BlinkMacSystemFont,sans-serif; background:#0c2334; display:flex; min-height:100vh; }
    .main { flex:1; padding:26px 32px 50px; color:var(--macadamia); max-width:1280px; }
    .page-head { margin-bottom:18px; }
    .page-head h1 { font-size:21px; margin-bottom:4px; }
    .page-head p { color:var(--light-grayish); font-size:13px; }

    .flash { background:rgba(63,122,92,0.18); border:1px solid #3f7a5c; color:#b7ecd1; padding:10px 14px; border-radius:6px; font-size:13px; margin-bottom:16px; }

    .toolbar { display:flex; gap:10px; margin-bottom:18px; flex-wrap:wrap; }
    .toolbar select, .toolbar input {
        background: rgba(251,240,216,0.06); border:1px solid rgba(115,154,185,0.28);
        border-radius:6px; padding:8px 11px; color:var(--macadamia); font-size:12.5px; outline:none;
    }
    .toolbar input:focus, .toolbar select:focus { border-color:var(--redwood); }

    .incident-card {
        background: rgba(251,240,216,0.04); border:1px solid rgba(115,154,185,0.18);
        border-radius:10px; padding:16px 18px; margin-bottom:12px;
    }
    .incident-top { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:10px; flex-wrap:wrap; }
    .incident-title-wrap { display:flex; align-items:center; gap:10px; }
    .sev-badge { font-size:10px; font-weight:700; text-transform:uppercase; padding:3px 10px; border-radius:20px; letter-spacing:0.4px; }
    .sev-Critical { background:rgba(176,32,41,0.2); color:var(--critical); }
    .sev-High { background:rgba(217,117,43,0.2); color:var(--high); }
    .sev-Moderate { background:rgba(212,171,43,0.2); color:var(--moderate); }
    .sev-Low { background:rgba(63,122,92,0.2); color:var(--low); }

    .incident-title { font-size:14.5px; font-weight:700; }
    .clip-ref { font-size:10.5px; color:var(--light-grayish); font-family:monospace; }

    .status-pill { font-size:10px; font-weight:700; text-transform:uppercase; padding:4px 10px; border-radius:20px; letter-spacing:0.3px; }
    .status-pending { background:rgba(217,117,43,0.18); color:var(--high); }
    .status-dispatched, .status-en_route { background:rgba(115,154,185,0.2); color:var(--light-grayish); }
    .status-on_site { background:rgba(176,32,41,0.18); color:var(--redwood); }

    .incident-body { display:grid; grid-template-columns: repeat(4, 1fr); gap:14px; margin-bottom:14px; }
    .info-block .label { font-size:10px; text-transform:uppercase; letter-spacing:0.4px; color:var(--light-grayish); margin-bottom:3px; }
    .info-block .value { font-size:12.5px; color:var(--macadamia); font-weight:600; }

    .incident-actions { display:flex; align-items:center; gap:10px; padding-top:12px; border-top:1px dashed rgba(115,154,185,0.2); flex-wrap:wrap; }

    .dispatch-form { display:flex; gap:8px; align-items:center; flex:1; }
    .dispatch-form select {
        flex:1; max-width:220px; background:rgba(251,240,216,0.06); border:1px solid rgba(115,154,185,0.3);
        border-radius:6px; padding:7px 10px; color:var(--macadamia); font-size:12px;
    }

    .btn { border:0; border-radius:20px; padding:8px 16px; font-size:12px; font-weight:700; cursor:pointer; }
    .btn-dispatch { background:var(--burnt-umber); color:var(--macadamia); }
    .btn-dispatch:hover { background:var(--redwood); }
    .btn-resolve { background:rgba(63,122,92,0.2); color:#7fd6a5; border:1px solid #3f7a5c; }
    .btn-resolve:hover { background:rgba(63,122,92,0.35); }

    .assigned-unit-tag { display:flex; align-items:center; gap:6px; font-size:12.5px; color:var(--macadamia); font-weight:600; }
    .assigned-unit-tag svg { width:14px; height:14px; color:var(--light-grayish); }

    .empty-state { text-align:center; padding:50px 20px; color:var(--light-grayish); font-size:13px; }
</style>
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
                    <?php if ($inc['dispatch_id']): ?>
                        <div class="assigned-unit-tag">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M14 9h4l4 4v4a1 1 0 0 1-1 1h-2"/><circle cx="6.5" cy="18.5" r="2.5"/><circle cx="17.5" cy="18.5" r="2.5"/></svg>
                            <?php echo htmlspecialchars($inc['unit_name']); ?> assigned
                        </div>
                        <form method="POST" style="margin-left:auto;">
                            <input type="hidden" name="action" value="resolve">
                            <input type="hidden" name="clip_report_id" value="<?php echo $inc['id']; ?>">
                            <input type="hidden" name="dispatch_id" value="<?php echo $inc['dispatch_id']; ?>">
                            <input type="hidden" name="unit_id" value="<?php echo $inc['unit_id']; ?>">
                            <button type="submit" class="btn btn-resolve">Mark Resolved</button>
                        </form>
                    <?php else: ?>
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