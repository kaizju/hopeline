<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';



$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'create_unit') {
            $unitName = trim($_POST['unit_name'] ?? '');
            $plateNo = trim($_POST['plate_no'] ?? '');
            $responderId = $_POST['responder_id'] !== '' ? (int)$_POST['responder_id'] : null;

            if ($unitName === '') {
                $flash = 'Unit name is required.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO ptv_units (unit_name, plate_no, responder_id, status) VALUES (?, ?, ?, 'Available')");
                $stmt->execute([$unitName, $plateNo, $responderId]);
                $flash = "Unit \"$unitName\" added.";
            }
        }

        if ($action === 'assign_responder') {
            $unitId = (int)($_POST['unit_id'] ?? 0);
            $responderId = $_POST['responder_id'] !== '' ? (int)$_POST['responder_id'] : null;
            $pdo->prepare("UPDATE ptv_units SET responder_id = ? WHERE id = ?")->execute([$responderId, $unitId]);
            $flash = 'Responder assignment updated.';
        }

        if ($action === 'set_status') {
            $unitId = (int)($_POST['unit_id'] ?? 0);
            $status = $_POST['status'] ?? 'Available';
            $pdo->prepare("UPDATE ptv_units SET status = ? WHERE id = ?")->execute([$status, $unitId]);
            $flash = 'Unit status updated.';
        }
    } catch (PDOException $e) {
        $flash = 'Action failed: ' . $e->getMessage();
    }
}

$units = $pdo->query("
    SELECT u.*, usr.name AS responder_name, usr.email AS responder_email
    FROM ptv_units u
    LEFT JOIN users usr ON usr.id = u.responder_id
    ORDER BY u.unit_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Responders not yet assigned to a unit (+ include currently assigned ones per-row via PHP)
$allResponders = $pdo->query("SELECT id, name, email FROM users WHERE role = 'user' AND is_verified = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$assignedIds = array_filter(array_column($units, 'responder_id'));

$unreadAlerts = 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>PTV / Unit Management — HopeLine</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/sidebar.css">
<style>
    :root { --burnt-umber:#6d120b; --redwood:#b02029; --macadamia:#fbf0d8; --cool-blue:#113047; --light-grayish:#739ab9; }
    * { box-sizing:border-box; margin:0; padding:0; }
    body { font-family:'Segoe UI',-apple-system,BlinkMacSystemFont,sans-serif; background:#0c2334; display:flex; min-height:100vh; }
    .main { flex:1; padding:26px 32px 50px; color:var(--macadamia); max-width:1200px; }
    .page-head { display:flex; align-items:flex-end; justify-content:space-between; margin-bottom:18px; flex-wrap:wrap; gap:10px; }
    .page-head h1 { font-size:21px; margin-bottom:4px; }
    .page-head p { color:var(--light-grayish); font-size:13px; }

    .flash { background:rgba(63,122,92,0.18); border:1px solid #3f7a5c; color:#b7ecd1; padding:10px 14px; border-radius:6px; font-size:13px; margin-bottom:16px; }

    .btn-primary { background:var(--burnt-umber); color:var(--macadamia); border:0; padding:10px 18px; border-radius:50px; font-weight:700; font-size:12.5px; cursor:pointer; }
    .btn-primary:hover { background:var(--redwood); }

    .unit-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(300px, 1fr)); gap:14px; }

    .unit-card { background:rgba(251,240,216,0.04); border:1px solid rgba(115,154,185,0.18); border-radius:10px; padding:18px 20px; }
    .unit-top { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
    .unit-name { font-size:15px; font-weight:700; }
    .unit-plate { font-size:11px; color:var(--light-grayish); font-family:monospace; }

    .status-select {
        font-size:10.5px; font-weight:700; text-transform:uppercase; padding:4px 10px; border-radius:20px;
        border:0; cursor:pointer; letter-spacing:0.3px;
    }
    .st-Available { background:rgba(63,122,92,0.2); color:#3f7a5c; }
    .st-EnRoute, .st-\32 { background:rgba(217,117,43,0.2); color:#d9752b; }
    .st-OnSite { background:rgba(176,32,41,0.2); color:var(--redwood); }
    .st-Returning { background:rgba(115,154,185,0.2); color:var(--light-grayish); }
    .st-Offline { background:rgba(74,92,107,0.3); color:#8ba0b0; }

    .unit-field { margin-bottom:10px; }
    .unit-field .label { font-size:10px; text-transform:uppercase; color:var(--light-grayish); margin-bottom:5px; }
    .unit-field select {
        width:100%; background:rgba(251,240,216,0.06); border:1px solid rgba(115,154,185,0.28);
        border-radius:6px; padding:8px 10px; color:var(--macadamia); font-size:12.5px; outline:none;
    }

    .responder-tag { font-size:12px; color:var(--macadamia); }
    .no-responder { color:var(--light-grayish); font-style:italic; font-size:12px; }

    /* Modal */
    .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:1000; align-items:center; justify-content:center; }
    .modal-overlay.show { display:flex; }
    .modal { background:var(--cool-blue); border:1px solid rgba(115,154,185,0.3); border-radius:10px; padding:22px; width:100%; max-width:420px; }
    .modal h3 { font-size:15px; margin-bottom:14px; }
    .modal .field { margin-bottom:12px; }
    .modal label { display:block; font-size:12px; font-weight:600; margin-bottom:5px; }
    .modal input, .modal select { width:100%; background:rgba(251,240,216,0.06); border:1px solid rgba(115,154,185,0.28); border-radius:6px; padding:8px 10px; color:var(--macadamia); font-size:12.5px; outline:none; }
    .modal-actions { display:flex; justify-content:flex-end; gap:8px; margin-top:16px; }
    .btn-cancel { background:transparent; border:1px solid rgba(115,154,185,0.3); color:var(--light-grayish); border-radius:20px; padding:8px 16px; font-size:12px; cursor:pointer; }
</style>
</head>
<body>

<?php require_once __DIR__ . '/../../assets/layouts/admin/admin_sidebar.php'; ?>

<main class="main">
    <div class="page-head">
        <div><h1>PTV / Unit Management</h1><p><?php echo count($units); ?> unit(s) registered</p></div>
        <button class="btn-primary" onclick="document.getElementById('addUnitModal').classList.add('show')">+ Add Unit</button>
    </div>

    <?php if ($flash): ?><div class="flash"><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>

    <div class="unit-grid">
        <?php foreach ($units as $u): $statusClass = 'st-' . str_replace(' ', '', $u['status']); ?>
        <div class="unit-card">
            <div class="unit-top">
                <div>
                    <div class="unit-name"><?php echo htmlspecialchars($u['unit_name']); ?></div>
                    <div class="unit-plate"><?php echo htmlspecialchars($u['plate_no'] ?: 'No plate on file'); ?></div>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="set_status">
                    <input type="hidden" name="unit_id" value="<?php echo $u['id']; ?>">
                    <select name="status" class="status-select <?php echo $statusClass; ?>" onchange="this.form.submit()">
                        <option value="Available" <?php echo $u['status']==='Available'?'selected':''; ?>>Available</option>
                        <option value="En Route" <?php echo $u['status']==='En Route'?'selected':''; ?>>En Route</option>
                        <option value="On Site" <?php echo $u['status']==='On Site'?'selected':''; ?>>On Site</option>
                        <option value="Returning" <?php echo $u['status']==='Returning'?'selected':''; ?>>Returning</option>
                        <option value="Offline" <?php echo $u['status']==='Offline'?'selected':''; ?>>Offline / Maintenance</option>
                    </select>
                </form>
            </div>

            <div class="unit-field">
                <div class="label">Assigned Responder</div>
                <form method="POST">
                    <input type="hidden" name="action" value="assign_responder">
                    <input type="hidden" name="unit_id" value="<?php echo $u['id']; ?>">
                    <select name="responder_id" onchange="this.form.submit()">
                        <option value="">— Unassigned —</option>
                        <?php foreach ($allResponders as $r):
                            // show if unassigned OR currently assigned to this unit
                            if (in_array($r['id'], $assignedIds) && $r['id'] != $u['responder_id']) continue;
                        ?>
                            <option value="<?php echo $r['id']; ?>" <?php echo $u['responder_id'] == $r['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($r['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <?php if ($u['responder_email']): ?>
                <div class="responder-tag"><?php echo htmlspecialchars($u['responder_email']); ?></div>
            <?php else: ?>
                <div class="no-responder">No responder linked yet</div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</main>

<div class="modal-overlay" id="addUnitModal">
    <div class="modal">
        <h3>Add New PTV Unit</h3>
        <form method="POST">
            <input type="hidden" name="action" value="create_unit">
            <div class="field"><label>Unit Name</label><input type="text" name="unit_name" placeholder="e.g. PTV Foxtrot" required></div>
            <div class="field"><label>Plate Number</label><input type="text" name="plate_no" placeholder="e.g. LGU-106"></div>
            <div class="field">
                <label>Assign Responder (optional)</label>
                <select name="responder_id">
                    <option value="">— Unassigned —</option>
                    <?php foreach ($allResponders as $r): if (in_array($r['id'], $assignedIds)) continue; ?>
                        <option value="<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="document.getElementById('addUnitModal').classList.remove('show')">Cancel</button>
                <button type="submit" class="btn-primary">Add Unit</button>
            </div>
        </form>
    </div>
</div>

</body>
</html>