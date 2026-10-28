<?php
/** Saves case details. Only allowed once the responder has arrived on scene. */
function saveCloseout(PDO $pdo, int $dispatchId): string {
    $chk = $pdo->prepare("SELECT arrived_at FROM dispatch WHERE id = ?");
    $chk->execute([$dispatchId]);
    $row = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$row || empty($row['arrived_at'])) {
        return 'Case details can only be added after the responder has arrived at the scene.';
    }

    $pick = function ($k, array $allowed) {
        $v = $_POST[$k] ?? '';
        return (is_string($v) && in_array($v, $allowed, true)) ? $v : null;
    };
    $patientName = postStr('patient_name', 150);
    $ageGroup    = $pick('patient_age_group', ['Minor', 'Matured', 'Senior']);
    $sex         = $pick('patient_sex', ['Male', 'Female']);
    $vitalSigns  = $pick('vital_signs', ['Positive', 'Negative']);
    $alcohol     = $pick('alcohol_breath', ['Positive', 'Negative', 'Not Tested']);
    $victimCount = (isset($_POST['victim_count']) && is_numeric($_POST['victim_count']))
                   ? max(0, (int)$_POST['victim_count']) : null;
    $remarks     = postStr('closeout_remarks', 255);
    $details     = postStr('incident_details', 5000);

    $photoPath = null;
    if (!empty($_FILES['incident_photo']['name']) && $_FILES['incident_photo']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['incident_photo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) && @getimagesize($_FILES['incident_photo']['tmp_name'])) {
            $dir = __DIR__ . '/../assets/uploads/incidents/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $name = 'incident_' . $dispatchId . '_' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['incident_photo']['tmp_name'], $dir . $name)) {
                $photoPath = 'assets/uploads/incidents/' . $name;
            }
        }
    }

    $sql = "UPDATE dispatch SET patient_name=?, patient_age_group=?, patient_sex=?, victim_count=?,
            vital_signs=?, alcohol_breath=?, closeout_remarks=?, incident_details=?"
         . ($photoPath ? ", incident_photo=?, photo_lat=NULL, photo_lng=NULL, photo_location=NULL,
                          photo_taken_at=NULL, photo_gps_source=NULL" : "")
         . " WHERE id=?";
    $params = [$patientName, $ageGroup, $sex, $victimCount, $vitalSigns, $alcohol, $remarks, $details];
    if ($photoPath) $params[] = $photoPath;
    $params[] = $dispatchId;
    $pdo->prepare($sql)->execute($params);

    if (function_exists('logActivity')) {
        logActivity($pdo, $_SESSION['user_id'], $_SESSION['email'], 'incident_closeout_saved', 'success');
    }
    return 'Case details saved.';
}

/** True when the Add/Edit Details button should show. */
function canEditCloseout(array $r): bool {
    return !empty($r['dispatch_id']) && !empty($r['arrived_at']);
}

function closeoutModal(array $r): void {
    $h   = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
    $sel = fn($a, $b) => $a === $b ? 'selected' : '';
    $id  = (int)$r['dispatch_id']; ?>
    <div class="modal-overlay" id="closeout-<?php echo $id; ?>" onclick="if(event.target===this)this.classList.remove('show')">
        <div class="modal">
            <h3>Case Details — <?php echo $h($r['clip_ref']); ?></h3>
            <form method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" name="action" value="save_closeout">
                <input type="hidden" name="dispatch_id" value="<?php echo $id; ?>">

                <div class="field"><label>Name of Patient</label>
                    <input type="text" name="patient_name" value="<?php echo $h($r['patient_name']); ?>" placeholder="Defaults to caller name if left blank"></div>
                <div class="field-row">
                    <div class="field"><label>Age Group</label>
                        <select name="patient_age_group">
                            <option value="">— Select —</option>
                            <option value="Minor"   <?php echo $sel($r['patient_age_group'],'Minor'); ?>>Minor (18 below)</option>
                            <option value="Matured" <?php echo $sel($r['patient_age_group'],'Matured'); ?>>Matured (19 above)</option>
                            <option value="Senior"  <?php echo $sel($r['patient_age_group'],'Senior'); ?>>Senior</option>
                        </select></div>
                    <div class="field"><label>Sex</label>
                        <select name="patient_sex">
                            <option value="">— Select —</option>
                            <option value="Male"   <?php echo $sel($r['patient_sex'],'Male'); ?>>Male</option>
                            <option value="Female" <?php echo $sel($r['patient_sex'],'Female'); ?>>Female</option>
                        </select></div>
                </div>
                <div class="field-row">
                    <div class="field"><label># of Victims</label>
                        <input type="number" name="victim_count" min="0" value="<?php echo $h($r['victim_count']); ?>"></div>
                    <div class="field"><label>Vital Signs</label>
                        <select name="vital_signs">
                            <option value="">— Select —</option>
                            <option value="Negative" <?php echo $sel($r['vital_signs'],'Negative'); ?>>Negative</option>
                            <option value="Positive" <?php echo $sel($r['vital_signs'],'Positive'); ?>>Positive</option>
                        </select></div>
                </div>
                <div class="field"><label>Alcohol Breath Test</label>
                    <select name="alcohol_breath">
                        <option value="">— Select —</option>
                        <option value="Positive"   <?php echo $sel($r['alcohol_breath'],'Positive'); ?>>Positive</option>
                        <option value="Negative"   <?php echo $sel($r['alcohol_breath'],'Negative'); ?>>Negative</option>
                        <option value="Not Tested" <?php echo $sel($r['alcohol_breath'],'Not Tested'); ?>>Not Tested</option>
                    </select></div>
                <div class="field"><label>Remarks</label>
                    <textarea name="closeout_remarks" rows="2"><?php echo $h($r['closeout_remarks']); ?></textarea></div>
                <div class="field"><label>Incident Details</label>
                    <textarea name="incident_details" rows="3"><?php echo $h($r['incident_details']); ?></textarea></div>
                <div class="field"><label>Incident Photo</label>
                    <input type="file" name="incident_photo" accept="image/*">
                    <?php if (!empty($r['incident_photo'])): ?>
                        <div style="margin-top:8px;"><img src="<?php echo BASE_URL . '/' . $h($r['incident_photo']); ?>" style="max-width:140px;border-radius:8px;"></div>
                    <?php endif; ?></div>

                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="document.getElementById('closeout-<?php echo $id; ?>').classList.remove('show')">Cancel</button>
                    <button type="submit" class="btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
<?php }
function closeoutButton(array $r): void {
    $has = !empty($r['patient_name']); ?>
    <button type="button" class="btn-mini <?php echo $has ? 'btn-activate' : 'btn-deactivate'; ?>"
            onclick="document.getElementById('closeout-<?php echo (int)$r['dispatch_id']; ?>').classList.add('show')">
        <?php echo $has ? 'Edit' : 'Add Details'; ?>
    </button>
<?php }