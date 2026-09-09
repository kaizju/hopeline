<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';

requireRole('admin');

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
    $stmt = $pdo->prepare("INSERT INTO ptv_units (unit_name, plate_no, responder_id, status, current_lat, current_lng) VALUES (?, ?, ?, 'Available', ?, ?)");
    $stmt->execute([$unitName, $plateNo, $responderId, 8.371714652741774, 124.85717564826615]);
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
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/app.css">

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
        <div class="ptv-unit-card">
            <div class="ptv-unit-top">
                <div>
                    <div class="ptv-unit-name"><?php echo htmlspecialchars($u['unit_name']); ?></div>
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