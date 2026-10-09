<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';
requireRole('admin');

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create_team' || $action === 'update_team') {
            $name     = trim($_POST['name'] ?? '');
            $desc     = trim($_POST['description'] ?? '');
            $leaderId = (int)($_POST['leader_id'] ?? 0);
            $teamId   = (int)($_POST['team_id'] ?? 0);

            // members: one name per line, trimmed, de-duplicated
            $members = [];
            foreach (preg_split('/\R/', $_POST['members'] ?? '') as $line) {
                $line = mb_substr(trim($line), 0, 100);
                if ($line !== '' && !in_array(mb_strtolower($line), array_map('mb_strtolower', $members), true)) {
                    $members[] = $line;
                }
            }

            if ($name === '') {
                $flash = 'Team name is required.';
            } elseif ($leaderId <= 0) {
                $flash = 'Please choose a team leader.';
            } else {
                // leader must be an active responder and not leading another active team
                $chk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = ? AND role = 'user' AND archived_at IS NULL");
                $chk->execute([$leaderId]);
                $busy = $pdo->prepare("SELECT name FROM teams WHERE leader_id = ? AND archived_at IS NULL AND id <> ?");
                $busy->execute([$leaderId, $teamId]);
                $busyName = $busy->fetchColumn();

                if (!$chk->fetchColumn()) {
                    $flash = 'Selected leader is not a valid responder.';
                } elseif ($busyName) {
                    $flash = 'That responder already leads ' . $busyName . '.';
                } else {
                    $pdo->beginTransaction();
                    if ($action === 'create_team') {
                        $pdo->prepare("INSERT INTO teams (name, description, leader_id) VALUES (?, ?, ?)")
                            ->execute([$name, $desc, $leaderId]);
                        $teamId = (int)$pdo->lastInsertId();
                    } else {
                        $pdo->prepare("UPDATE teams SET name = ?, description = ?, leader_id = ? WHERE id = ?")
                            ->execute([$name, $desc, $leaderId, $teamId]);
                        $pdo->prepare("UPDATE users SET team_id = NULL WHERE team_id = ?")->execute([$teamId]);
                    }
                    // leader account is tied to the team
                    $pdo->prepare("UPDATE users SET team_id = ? WHERE id = ? AND role = 'user'")
                        ->execute([$teamId, $leaderId]);

                    // members: replace the list
                    $pdo->prepare("DELETE FROM team_members WHERE team_id = ?")->execute([$teamId]);
                    $ins = $pdo->prepare("INSERT INTO team_members (team_id, name) VALUES (?, ?)");
                    foreach ($members as $m) $ins->execute([$teamId, $m]);

                    $pdo->commit();
                    if (function_exists('logActivity')) logActivity($pdo, $_SESSION['user_id'], $_SESSION['email'], $action, 'success');
                    $flash = $action === 'create_team' ? 'Team created.' : 'Team updated.';
                }
            }
        }
        if ($action === 'archive_team') {
            $id = (int)($_POST['team_id'] ?? 0);
            $pdo->prepare("UPDATE teams SET archived_at = NOW(), leader_id = NULL WHERE id = ?")->execute([$id]);
            $pdo->prepare("UPDATE users SET team_id = NULL WHERE team_id = ?")->execute([$id]);
            if (function_exists('logActivity')) logActivity($pdo, $_SESSION['user_id'], $_SESSION['email'], 'team_archived', 'success');
            $flash = 'Team archived. Its leader is now unassigned.';
        }
        if ($action === 'restore_team') {
            $pdo->prepare("UPDATE teams SET archived_at = NULL WHERE id = ?")->execute([(int)($_POST['team_id'] ?? 0)]);
            if (function_exists('logActivity')) logActivity($pdo, $_SESSION['user_id'], $_SESSION['email'], 'team_restored', 'success');
            $flash = 'Team restored. Edit it to assign a leader.';
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $flash = 'Action failed: ' . $e->getMessage();
    }
}

$view = ($_GET['view'] ?? '') === 'archived' ? 'archived' : 'active';
$teams = $pdo->query("
    SELECT t.*, u.name AS leader_name
    FROM teams t LEFT JOIN users u ON u.id = t.leader_id
    WHERE t.archived_at IS " . ($view === 'archived' ? "NOT NULL" : "NULL") . "
    ORDER BY t.name
")->fetchAll(PDO::FETCH_ASSOC);
$archivedCount = $pdo->query("SELECT COUNT(*) FROM teams WHERE archived_at IS NOT NULL")->fetchColumn();

// responders (potential leaders) + which active team each already leads
$responders = $pdo->query("
    SELECT u.id, u.name, t.id AS leads_id, t.name AS leads_name
    FROM users u
    LEFT JOIN teams t ON t.leader_id = u.id AND t.archived_at IS NULL
    WHERE u.role = 'user' AND u.archived_at IS NULL
    ORDER BY u.name
")->fetchAll(PDO::FETCH_ASSOC);

// members grouped by team
$byTeam = [];
foreach ($pdo->query("SELECT team_id, name FROM team_members ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) as $m) {
    $byTeam[$m['team_id']][] = $m['name'];
}
$unreadAlerts = 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Teams — HopeLine</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/app.css">
</head>
<body>
<?php require_once __DIR__ . '/../../assets/layouts/admin/admin_sidebar.php'; ?>

<main class="main">
    <div class="page-head page-head--flex">
        <div><h1>Teams</h1><p><?php echo count($teams); ?> team(s)</p></div>
        <?php if ($view === 'active'): ?><button class="btn-primary" onclick="openTeam(null)">+ Add Team</button><?php endif; ?>
    </div>

    <?php if ($flash): ?><div class="flash"><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>

    <div class="tabs">
        <a href="?view=active" class="tab <?php echo $view === 'active' ? 'active' : ''; ?>" style="text-decoration:none;">Active</a>
        <a href="?view=archived" class="tab <?php echo $view === 'archived' ? 'active' : ''; ?>" style="text-decoration:none;">Archived (<?php echo $archivedCount; ?>)</a>
    </div>

    <?php if (empty($teams)): ?>
        <div class="empty-state"><?php echo $view === 'archived' ? 'No archived teams.' : 'No teams yet.'; ?></div>
    <?php else: ?>
    <div class="unit-grid">
        <?php foreach ($teams as $t):
            $ms = $byTeam[$t['id']] ?? [];
            $payload = ['id' => (int)$t['id'], 'name' => $t['name'], 'description' => $t['description'] ?? '',
                        'leader_id' => (int)($t['leader_id'] ?? 0),
                        'members' => implode("\n", $ms)];
        ?>
        <div class="ptv-unit-card">
            <div class="ptv-unit-top">
                <div>
                    <div class="ptv-unit-name"><?php echo htmlspecialchars($t['name']); ?></div>
                    <div class="unit-plate"><?php echo htmlspecialchars($t['description'] ?: 'No description'); ?></div>
                </div>
                <span class="status-badge status-active"><?php echo count($ms); ?> member<?php echo count($ms) === 1 ? '' : 's'; ?></span>
            </div>

            <?php if ($view === 'active'): ?>
                <div class="responder-tag" style="font-weight:600;">
                    ★ Leader: <?php echo htmlspecialchars($t['leader_name'] ?? 'Not assigned'); ?>
                </div>
                <?php if ($ms): foreach ($ms as $m): ?>
                    <div class="responder-tag"><?php echo htmlspecialchars($m); ?></div>
                <?php endforeach; else: ?>
                    <div class="no-responder">No members yet</div>
                <?php endif; ?>

                <div class="row-actions" style="margin-top:14px; padding-top:12px; border-top:1px dashed rgba(var(--border-rgb),0.2);">
                    <button type="button" class="btn-mini btn-activate"
                            onclick='openTeam(<?php echo json_encode($payload, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>)'>Edit</button>
                    <form method="POST" onsubmit="return confirm('Archive this team? Its leader will be unassigned.');">
                        <input type="hidden" name="action" value="archive_team">
                        <input type="hidden" name="team_id" value="<?php echo $t['id']; ?>">
                        <button type="submit" class="btn-mini btn-deactivate">Archive</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="no-responder">Archived <?php echo date('M j, Y', strtotime($t['archived_at'])); ?></div>
                <form method="POST" style="margin-top:14px;">
                    <input type="hidden" name="action" value="restore_team">
                    <input type="hidden" name="team_id" value="<?php echo $t['id']; ?>">
                    <button type="submit" class="btn-mini btn-activate" style="width:100%;">Restore Team</button>
                </form>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</main>

<div class="modal-overlay" id="teamModal">
    <div class="modal">
        <h3 id="tTitle">Add Team</h3>
        <form method="POST">
            <input type="hidden" name="action" id="tAction" value="create_team">
            <input type="hidden" name="team_id" id="tId">
            <div class="field"><label>Team Name</label><input type="text" name="name" id="tName" placeholder="e.g. Team 1" required></div>
            <div class="field"><label>Description <span class="optional">(optional)</span></label><input type="text" name="description" id="tDesc"></div>

            <div class="field">
                <label>Team Leader (responder account)</label>
                <select name="leader_id" id="tLeader" required>
                    <option value="">— Select leader —</option>
                    <?php foreach ($responders as $r): ?>
                        <option value="<?php echo $r['id']; ?>" data-leads="<?php echo (int)$r['leads_id']; ?>">
                            <?php echo htmlspecialchars($r['name']); ?><?php echo $r['leads_name'] ? ' (leads ' . htmlspecialchars($r['leads_name']) . ')' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label>Members <span class="optional">(one name per line — no account needed)</span></label>
                <textarea name="members" id="tMembers" rows="6" placeholder="Juan Dela Cruz&#10;Maria Santos"></textarea>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="document.getElementById('teamModal').classList.remove('show')">Cancel</button>
                <button type="submit" class="btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<script>
function openTeam(t) {
    document.getElementById('tAction').value = t ? 'update_team' : 'create_team';
    document.getElementById('tId').value = t ? t.id : '';
    document.getElementById('tTitle').textContent = t ? 'Edit Team' : 'Add Team';
    document.getElementById('tName').value = t ? t.name : '';
    document.getElementById('tDesc').value = t ? t.description : '';
    document.getElementById('tMembers').value = t ? t.members : '';

    var sel = document.getElementById('tLeader');
    var teamId = t ? t.id : 0;
    Array.prototype.forEach.call(sel.options, function (o) {
        var leads = +o.dataset.leads || 0;
        // can't pick someone who already leads a different team
        o.disabled = leads !== 0 && leads !== teamId;
    });
    sel.value = t && t.leader_id ? t.leader_id : '';
    document.getElementById('teamModal').classList.add('show');
}
</script>
</body>
</html>