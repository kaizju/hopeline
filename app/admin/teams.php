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
            $name = trim($_POST['name'] ?? '');
            $desc = trim($_POST['description'] ?? '');
            $members = array_map('intval', $_POST['members'] ?? []);
            if ($name === '') {
                $flash = 'Team name is required.';
            } else {
                $pdo->beginTransaction();
                if ($action === 'create_team') {
                    $pdo->prepare("INSERT INTO teams (name, description) VALUES (?, ?)")->execute([$name, $desc]);
                    $teamId = (int)$pdo->lastInsertId();
                } else {
                    $teamId = (int)($_POST['team_id'] ?? 0);
                    $pdo->prepare("UPDATE teams SET name = ?, description = ? WHERE id = ?")->execute([$name, $desc, $teamId]);
                    $pdo->prepare("UPDATE users SET team_id = NULL WHERE team_id = ?")->execute([$teamId]);
                }
                if ($members) {
                    $in = implode(',', array_fill(0, count($members), '?'));
                    $pdo->prepare("UPDATE users SET team_id = ? WHERE role = 'user' AND id IN ($in)")
                        ->execute(array_merge([$teamId], $members));
                }
                $pdo->commit();
                if (function_exists('logActivity')) logActivity($pdo, $_SESSION['user_id'], $_SESSION['email'], $action, 'success');
                $flash = $action === 'create_team' ? 'Team created.' : 'Team updated.';
            }
        }
        if ($action === 'archive_team') {
            $id = (int)($_POST['team_id'] ?? 0);
            $pdo->prepare("UPDATE teams SET archived_at = NOW() WHERE id = ?")->execute([$id]);
            $pdo->prepare("UPDATE users SET team_id = NULL WHERE team_id = ?")->execute([$id]);
            if (function_exists('logActivity')) logActivity($pdo, $_SESSION['user_id'], $_SESSION['email'], 'team_archived', 'success');
            $flash = 'Team archived. Its members are now unassigned.';
        }
        if ($action === 'restore_team') {
            $pdo->prepare("UPDATE teams SET archived_at = NULL WHERE id = ?")->execute([(int)($_POST['team_id'] ?? 0)]);
            if (function_exists('logActivity')) logActivity($pdo, $_SESSION['user_id'], $_SESSION['email'], 'team_restored', 'success');
            $flash = 'Team restored. Assign members again as needed.';
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $flash = 'Action failed: ' . $e->getMessage();
    }
}

$view = ($_GET['view'] ?? '') === 'archived' ? 'archived' : 'active';
$teams = $pdo->query("SELECT * FROM teams WHERE archived_at IS " . ($view === 'archived' ? "NOT NULL" : "NULL") . " ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$archivedCount = $pdo->query("SELECT COUNT(*) FROM teams WHERE archived_at IS NOT NULL")->fetchColumn();
$teamNames = $pdo->query("SELECT id, name FROM teams")->fetchAll(PDO::FETCH_KEY_PAIR);
$responders = $pdo->query("SELECT id, name, team_id FROM users WHERE role = 'user' AND archived_at IS NULL ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$byTeam = [];
foreach ($responders as $r) if ($r['team_id']) $byTeam[$r['team_id']][] = $r;
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
                        'members' => array_map(fn($m) => (int)$m['id'], $ms)];
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
                <?php if ($ms): foreach ($ms as $m): ?>
                    <div class="responder-tag"><?php echo htmlspecialchars($m['name']); ?></div>
                <?php endforeach; else: ?>
                    <div class="no-responder">No members yet</div>
                <?php endif; ?>

                <div class="row-actions" style="margin-top:14px; padding-top:12px; border-top:1px dashed rgba(var(--border-rgb),0.2);">
                    <button type="button" class="btn-mini btn-activate"
                            onclick='openTeam(<?php echo json_encode($payload, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP); ?>)'>Edit</button>
                    <form method="POST" onsubmit="return confirm('Archive this team? Members will be unassigned.');">
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
                <label>Members (responders)</label>
                <div style="max-height:220px; overflow-y:auto;">
                <?php foreach ($responders as $r): ?>
                    <label style="display:flex; gap:8px; align-items:center; font-weight:400; margin-bottom:6px;">
                        <input type="checkbox" name="members[]" value="<?php echo $r['id']; ?>">
                        <?php echo htmlspecialchars($r['name']); ?>
                        <?php if ($r['team_id'] && isset($teamNames[$r['team_id']])): ?>
                            <span class="optional">(<?php echo htmlspecialchars($teamNames[$r['team_id']]); ?>)</span>
                        <?php endif; ?>
                    </label>
                <?php endforeach; ?>
                </div>
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
    document.querySelectorAll('#teamModal input[name="members[]"]').forEach(function (c) {
        c.checked = !!t && t.members.indexOf(+c.value) > -1;
    });
    document.getElementById('teamModal').classList.add('show');
}
</script>
</body>
</html>