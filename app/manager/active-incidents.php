<?php
if (session_status() === PHP_SESSION_NONE) session_start();
function parseNeeds($s) {
    $out = [];
    foreach (explode(',', (string)$s) as $part) {
        if (preg_match('/^\s*(.+?)\s+x(\d+)\s*$/i', $part, $m)) $out[$m[1]] = (int)$m[2];
    }
    return $out;
}
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
    $teamId = (int)($_POST['team_id'] ?? 0);

    $pdo->beginTransaction();

    $t = $pdo->prepare("SELECT name FROM teams WHERE id = ? AND archived_at IS NULL");
    $t->execute([$teamId]);
    $teamName = $t->fetchColumn();

    $c = $pdo->prepare("SELECT problem_resources FROM clip_reports WHERE id = ? AND status NOT IN ('resolved','cancelled') FOR UPDATE");
    $c->execute([$clipId]);
    $needs = parseNeeds($c->fetchColumn());

    $busy = $pdo->prepare("SELECT COUNT(*) FROM dispatch WHERE team_id = ? AND status IN ('assigned','en_route','on_site','returning') FOR UPDATE");
    $busy->execute([$teamId]);
    $already = $pdo->prepare("SELECT COUNT(*) FROM dispatch WHERE clip_report_id = ? AND status IN ('assigned','en_route','on_site','returning') FOR UPDATE");
    $already->execute([$clipId]);
    if (!$teamName) {
        $pdo->rollBack();
        $msg = 'Team not found.';
        } elseif ($already->fetchColumn() > 0) {
        $pdo->rollBack();
        $msg = 'This incident is already assigned to a team.';
    } elseif ($busy->fetchColumn() > 0) {
        $pdo->rollBack();
        $msg = $teamName . ' is already on another dispatch.';
    } else {
        $picked = []; $missing = [];
        foreach ($needs as $type => $qty) {
            $q = $pdo->prepare("SELECT id FROM ptv_units
                                WHERE vehicle_type = ? AND status = 'Available' AND archived_at IS NULL
                                ORDER BY unit_name LIMIT " . (int)$qty . " FOR UPDATE");
            $q->execute([$type]);
            $ids = $q->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $id) $picked[] = (int)$id;
            if (count($ids) < $qty) $missing[] = $type . ' x' . ($qty - count($ids));
        }

        if (!$picked) {
            $pdo->rollBack();
            $msg = 'No available vehicle for the types this incident needs.';
        } else {
            $ins = $pdo->prepare("INSERT INTO dispatch (clip_report_id, unit_id, team_id, dispatched_by, status, predicted_eta_minutes)
                                  SELECT ?, ?, ?, ?, 'assigned', predicted_eta_minutes FROM clip_reports WHERE id = ?");
            $upd = $pdo->prepare("UPDATE ptv_units SET status='Assigned' WHERE id=?");
            foreach ($picked as $uid) {
                $ins->execute([$clipId, $uid, $teamId, currentUserId(), $clipId]);
                $upd->execute([$uid]);
            }
            $pdo->prepare("UPDATE clip_reports SET status='dispatched' WHERE id=?")->execute([$clipId]);
            $pdo->commit();

            if (function_exists('logActivity')) {
                logActivity($pdo, $_SESSION['user_id'], $_SESSION['email'], 'dispatch_assigned', 'success');
            }
            $msg = $teamName . ' dispatched with ' . count($picked) . ' vehicle(s).'
                 . ($missing ? ' Not available: ' . implode(', ', $missing) . '.' : '');
        }
    }
}

        if ($action === 'resolve') {
            $clipId = (int)($_POST['clip_report_id'] ?? 0);
            $pdo->beginTransaction();

            $find = $pdo->prepare("SELECT id, unit_id FROM dispatch
                       WHERE clip_report_id = ? AND status = 'on_site'
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
$teams = []; $busyTeams = [];
// ---- Fetch data ----
try {
        $incidents = $pdo->query("
    SELECT c.*, d.id AS dispatch_id, d.status AS dispatch_status, d.unit_id, u.unit_name,
       d.incident_photo, d.incident_details, d.photo_lat, d.photo_lng, d.photo_location,
       d.photo_taken_at, d.photo_gps_source, d.site_outcome, d.hospital_name,
           (SELECT GROUP_CONCAT(u3.unit_name ORDER BY u3.unit_name SEPARATOR ', ')
              FROM dispatch d3 JOIN ptv_units u3 ON u3.id = d3.unit_id
              WHERE d3.clip_report_id = c.id AND d3.status IN ('assigned','en_route','on_site')) AS vehicles
    FROM clip_reports c
    LEFT JOIN dispatch d ON d.id = (
        SELECT MAX(d2.id) FROM dispatch d2
        WHERE d2.clip_report_id = c.id
          AND d2.status IN ('assigned','en_route','on_site')
    )
    LEFT JOIN ptv_units u ON u.id = d.unit_id
    LEFT JOIN teams t ON t.id = d.team_id
    WHERE c.status NOT IN ('resolved','cancelled')
    ORDER BY FIELD(c.severity,'Critical','High','Moderate','Low'), c.created_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

     $teams = $pdo->query("SELECT t.id, t.name FROM teams t WHERE t.archived_at IS NULL ORDER BY t.name")->fetchAll(PDO::FETCH_ASSOC);
    $busyTeams = array_map('intval', $pdo->query("SELECT DISTINCT team_id FROM dispatch WHERE team_id IS NOT NULL AND status IN ('assigned','en_route','on_site','returning')")->fetchAll(PDO::FETCH_COLUMN));
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
                    <div class="info-block"><div class="label">Reported</div><div class="value"><?php echo date('H:i', strtotime($inc['created_at'])); ?></div></div>
                </div>
<?php if (!empty($inc['incident_photo'])): ?>
<div style="display:flex;gap:12px;align-items:flex-start;margin:12px 0;">
    <a href="<?php echo BASE_URL . '/' . htmlspecialchars($inc['incident_photo']); ?>" target="_blank">
        <img src="<?php echo BASE_URL . '/' . htmlspecialchars($inc['incident_photo']); ?>" style="width:130px;height:96px;object-fit:cover;border-radius:8px;">
    </a>
    <div class="info-block">
        <div class="label">Site photo<?php echo $inc['photo_taken_at'] ? ' · ' . date('M j, H:i', strtotime($inc['photo_taken_at'])) : ''; ?></div>
        <?php if ($inc['photo_lat']): ?>
        <div class="value"><a href="https://www.openstreetmap.org/?mlat=<?php echo $inc['photo_lat']; ?>&mlon=<?php echo $inc['photo_lng']; ?>#map=18/<?php echo $inc['photo_lat']; ?>/<?php echo $inc['photo_lng']; ?>" target="_blank">📍 <?php echo round($inc['photo_lat'], 6) . ', ' . round($inc['photo_lng'], 6); ?></a></div>
        <div class="value"><?php echo htmlspecialchars($inc['photo_location'] ?: ''); ?></div>
        <?php endif; ?>
        <?php if ($inc['site_outcome']): ?>
        <div class="value">Outcome: <?php echo $inc['site_outcome'] === 'hospital' ? 'To hospital' . ($inc['hospital_name'] ? ' — ' . htmlspecialchars($inc['hospital_name']) : '') : 'Minor (bandage only)'; ?></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
                <div class="incident-actions">
    <?php if ($inc['team_name']): ?>
        <div class="responder-tag" style="font-weight:600;">
            ★ Assigned to <?php echo htmlspecialchars($inc['team_name']); ?><?php echo $inc['vehicles'] ? ' · ' . htmlspecialchars($inc['vehicles']) : ''; ?>
        </div>
    <?php endif; ?>

    <?php if ($inc['dispatch_status'] === 'on_site'): ?>
        <form method="POST" style="margin-left:auto;">
            <input type="hidden" name="action" value="resolve">
            <input type="hidden" name="clip_report_id" value="<?php echo $inc['id']; ?>">
            <button type="submit" class="btn btn-resolve">Mark Resolved</button>
        </form>
    <?php else: ?>
        <button type="button" class="btn btn-resolve" disabled style="margin-left:auto;opacity:.4;cursor:not-allowed;" title="Unlocks when the team arrives on site">🔒 Awaiting arrival</button>

        <?php if (!$inc['dispatch_id']): ?>
        <form method="POST" class="dispatch-form">
            <input type="hidden" name="action" value="dispatch">
            <input type="hidden" name="clip_report_id" value="<?php echo $inc['id']; ?>">
            <select name="team_id" required>
                <option value="">Assign a team…</option>
                <?php foreach ($teams as $t): $isBusy = in_array((int)$t['id'], $busyTeams, true); ?>
                    <option value="<?php echo $t['id']; ?>" <?php echo $isBusy ? 'disabled' : ''; ?>>
                        <?php echo htmlspecialchars($t['name']); ?><?php echo $isBusy ? ' (on dispatch)' : ''; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-dispatch">Dispatch</button>
        </form>
                <?php endif; ?>
    <?php endif; ?>
</div><!-- /.incident-actions -->
            </div><!-- /.incident-card -->
            <?php endforeach; ?>
        <?php endif; ?>
    </div><!-- /#incidentList -->
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