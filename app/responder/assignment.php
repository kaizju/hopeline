<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';

requireRole('user');

$me = $pdo->prepare("SELECT team_id FROM users WHERE id = ?");
$me->execute([$_SESSION['user_id']]);
$myTeamId = (int)$me->fetchColumn();

$unitStmt = $pdo->prepare("
    SELECT u.* FROM ptv_units u
    JOIN dispatch d ON d.unit_id = u.id AND d.team_id = ? AND d.status IN ('assigned','en_route','on_site','returning')
    ORDER BY d.dispatched_at DESC, d.id DESC LIMIT 1");
$unitStmt->execute([$myTeamId]);
$unit = $unitStmt->fetch(PDO::FETCH_ASSOC);
if (!$unit) $unit = ['id' => 0, 'unit_name' => 'No vehicle assigned', 'plate_no' => '', 'status' => 'Available'];

function stepClass($target, $current) {
    $order = ['assigned' => 0, 'en_route' => 1, 'on_site' => 2, 'returning' => 3];
    if ($order[$target] < $order[$current]) return 'done';
    if ($order[$target] === $order[$current]) return 'current';
    return '';
}

/* ---------------------------------------------------------------
   POST handling — unchanged from the previous version
   --------------------------------------------------------------- */
$flash = '';
if ($unit && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $dispatchId = (int)($_POST['dispatch_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'arrive' && defined('ARRIVE_GEOFENCE_M') && ARRIVE_GEOFENCE_M > 0) {
    $g = $pdo->prepare("SELECT u.current_lat ulat, u.current_lng ulng, c.latitude clat, c.longitude clng
        FROM dispatch d JOIN ptv_units u ON u.id = d.unit_id JOIN clip_reports c ON c.id = d.clip_report_id
        WHERE d.id = ? AND d.team_id = ?");
    $g->execute([$dispatchId, $myTeamId]);
    $g = $g->fetch(PDO::FETCH_ASSOC);
    if ($g && $g['clat'] !== null && $g['ulat'] !== null) {
        $k = M_PI / 180;
        $a = sin(($g['clat'] - $g['ulat']) * $k / 2) ** 2 + cos($g['ulat'] * $k) * cos($g['clat'] * $k) * sin(($g['clng'] - $g['ulng']) * $k / 2) ** 2;
        $m = 6371000 * 2 * atan2(sqrt($a), sqrt(1 - $a));
        if ($m > ARRIVE_GEOFENCE_M) { $flash = 'You are still ' . round($m) . ' m from the incident.'; $action = ''; }
    }
}
        if (in_array($action, ['depart', 'arrive', 'return_to_base'], true)) {
    $map = [
        'depart'         => ['from' => 'assigned',  'to' => 'en_route', 'col' => 'departed_at', 'unit' => 'En Route',  'msg' => 'Departure logged. Drive safe.'],
        'arrive'         => ['from' => 'en_route',  'to' => 'on_site',  'col' => 'arrived_at',  'unit' => 'On Site',   'msg' => 'Arrival logged.'],
        'return_to_base' => ['from' => 'returning', 'to' => 'resolved', 'col' => 'returned_at', 'unit' => 'Available', 'msg' => 'Welcome back! Units marked Available.'],
    ][$action];

    $pdo->beginTransaction();
    $sel = $pdo->prepare("SELECT d.id, d.unit_id FROM dispatch d
        JOIN dispatch me ON me.id = ? AND me.clip_report_id = d.clip_report_id
        WHERE d.team_id = ? AND d.status = ? FOR UPDATE");
    $sel->execute([$dispatchId, $myTeamId, $map['from']]);
    $rows = $sel->fetchAll(PDO::FETCH_ASSOC);

    if (!$rows) {
        $pdo->rollBack();
        $flash = 'This dispatch has changed. Your screen was refreshed.';
    } else {
        $ids   = array_column($rows, 'id');
        $units = array_column($rows, 'unit_id');
        $inD = implode(',', array_fill(0, count($ids), '?'));
        $inU = implode(',', array_fill(0, count($units), '?'));

        $pdo->prepare("UPDATE dispatch SET status = ?, {$map['col']} = NOW() WHERE id IN ($inD)")
            ->execute(array_merge([$map['to']], $ids));

        if ($action === 'return_to_base') {
            $pdo->prepare("UPDATE ptv_units SET status = 'Available', current_lat = ?, current_lng = ? WHERE id IN ($inU)")
                ->execute(array_merge([PTV_BASE_LAT, PTV_BASE_LNG], $units));
        } else {
            // status only: no more "teleport to the incident pin" on depart
            $pdo->prepare("UPDATE ptv_units SET status = ? WHERE id IN ($inU)")
                ->execute(array_merge([$map['unit']], $units));
        }
        $pdo->commit();

        if ($action === 'return_to_base' && function_exists('logActivity')) {
            logActivity($pdo, $_SESSION['user_id'], $_SESSION['email'], 'returned_to_command_center', 'success');
        }
        $flash = $map['msg'];
    }
}

        if ($action === 'save_site_report') {
           $details = isset($_POST['incident_details']) ? trim($_POST['incident_details']) : null;
$photoPath = null; $gps = null; $gpsSrc = null; $geoText = null; $takenAt = null;
if (!empty($_FILES['incident_photo']['name']) && $_FILES['incident_photo']['error'] === UPLOAD_ERR_OK) {
    $ext = strtolower(pathinfo($_FILES['incident_photo']['name'], PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg','jpeg','png','webp']) && @getimagesize($_FILES['incident_photo']['tmp_name'])) {
        $uploadDir = __DIR__ . '/../../assets/uploads/incidents/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        $filename = 'incident_' . $dispatchId . '_' . time() . '.' . $ext;
        if (move_uploaded_file($_FILES['incident_photo']['tmp_name'], $uploadDir . $filename)) {
            $photoPath = 'assets/uploads/incidents/' . $filename;

            $gps = exifGps($uploadDir . $filename); $gpsSrc = 'exif';
            if (!$gps && is_numeric($_POST['client_lat'] ?? '') && is_numeric($_POST['client_lng'] ?? '')) {
                $gps = ['lat' => (float)$_POST['client_lat'], 'lng' => (float)$_POST['client_lng'], 'taken' => null];
                $gpsSrc = 'device';   // phone GPS at upload time (EXIF had no GPS)
            }
            if ($gps && abs($gps['lat']) <= 90 && abs($gps['lng']) <= 180) {
                $geo = reverseGeocode($gps['lat'], $gps['lng']);
                $geoText = $geo['address'] ?? null;
                $takenAt = $gps['taken'] ?: date('Y-m-d H:i:s');
            } else { $gps = null; $gpsSrc = null; }
        }
    }
}
$sql = "UPDATE dispatch SET incident_details=COALESCE(?, incident_details)";
$params = [$details];
if ($photoPath) {
    $sql .= ", incident_photo=?, photo_lat=?, photo_lng=?, photo_location=?, photo_taken_at=?, photo_gps_source=?";
    array_push($params, $photoPath, $gps['lat'] ?? null, $gps['lng'] ?? null, $geoText, $takenAt, $gpsSrc);
}
$sql .= " WHERE id=? AND team_id=? AND status='on_site'";
$params[] = $dispatchId; $params[] = $myTeamId;
            $had = $pdo->prepare("SELECT incident_photo FROM dispatch WHERE id=? AND team_id=?");
$had->execute([$dispatchId, $myTeamId]);
if (!$photoPath && !$had->fetchColumn()) {
    $flash = 'A site photo is required. Tap the camera button.';
} else {
    $pdo->prepare($sql)->execute($params);
    $flash = ($gps || !$photoPath) ? 'Site report saved.' : 'Photo saved, but no GPS location was found.';
}
        }
                if (in_array($action, ['choose_minor','choose_hospital','hospital_declined','hospital_arrived','hospital_accepted','return_to_command'], true)) {
    $q = $pdo->prepare("SELECT clip_report_id, hospital_name, hospital_arrived_at, hospital_accepted_at,incident_photo, declined_hospitals
                        FROM dispatch WHERE id=? AND team_id=? AND status='on_site'");
    $q->execute([$dispatchId, $myTeamId]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
} elseif (empty($row['incident_photo']) && in_array($action, ['choose_minor','choose_hospital'], true)) {
    $flash = 'Take the site photo first.';
    if (!$row) {
        $flash = 'This dispatch has changed. Your screen was refreshed.';

    } elseif ($action === 'choose_minor') {
        $pdo->prepare("UPDATE dispatch SET site_outcome='minor', outcome_at=COALESCE(outcome_at,NOW()),
            hospital_name=NULL, hospital_lat=NULL, hospital_lng=NULL, hospital_arrived_at=NULL, hospital_accepted_at=NULL WHERE id=?")->execute([$dispatchId]);
        $flash = 'Logged: minor case, bandage only.';

    } elseif ($action === 'choose_hospital') {
        $name = mb_substr(trim($_POST['hospital_name'] ?? ''), 0, 150);
        $hl = $_POST['hospital_lat'] ?? ''; $hg = $_POST['hospital_lng'] ?? '';
        if ($name === '' || !is_numeric($hl) || !is_numeric($hg)) {
            $flash = 'Invalid hospital.';
        } else {
            $pdo->prepare("UPDATE dispatch SET site_outcome='hospital', outcome_at=COALESCE(outcome_at,NOW()),
                hospital_name=?, hospital_lat=?, hospital_lng=?, hospital_arrived_at=NULL, hospital_accepted_at=NULL WHERE id=?")
                ->execute([$name, (float)$hl, (float)$hg, $dispatchId]);
            $flash = 'Routing to ' . $name . '.';
        }

    } elseif ($action === 'hospital_declined') {
        if (!empty($row['hospital_accepted_at'])) {
            $flash = 'The hospital already accepted the patient.';
        } else {
            $list = json_decode($row['declined_hospitals'] ?? '[]', true) ?: [];
            if ($row['hospital_name']) $list[] = $row['hospital_name'];
            $pdo->prepare("UPDATE dispatch SET declined_hospitals=?, hospital_name=NULL, hospital_lat=NULL,
                hospital_lng=NULL, hospital_arrived_at=NULL, hospital_accepted_at=NULL WHERE id=?")
                ->execute([json_encode(array_values(array_unique($list))), $dispatchId]);
            $flash = 'Hospital declined. Pick another hospital.';
        }

    } elseif ($action === 'hospital_arrived') {
        $pdo->prepare("UPDATE dispatch SET hospital_arrived_at=COALESCE(hospital_arrived_at,NOW()) WHERE id=?")->execute([$dispatchId]);
        $flash = 'Arrival at hospital logged.';

    } elseif ($action === 'hospital_accepted') {
        if (empty($row['hospital_arrived_at'])) {
            $flash = 'Log your arrival at the hospital first.';
        } else {
            $pdo->prepare("UPDATE dispatch SET hospital_accepted_at=COALESCE(hospital_accepted_at,NOW()) WHERE id=?")->execute([$dispatchId]);
            $flash = 'Hospital accepted the patient. You can now return to the command center.';
        }

    } else { // return_to_command
        if (empty($row['hospital_accepted_at'])) {
            $flash = 'Locked: confirm that the hospital accepted the patient first.';
        } else {
            $pdo->beginTransaction();
            $f = $pdo->prepare("SELECT id, unit_id FROM dispatch WHERE clip_report_id=? AND team_id=? AND status='on_site' FOR UPDATE");
            $f->execute([$row['clip_report_id'], $myTeamId]);
            $active = $f->fetchAll(PDO::FETCH_ASSOC);

            if (!$active) {
                $pdo->rollBack();
                $flash = 'This dispatch has changed. Your screen was refreshed.';
            } else {
                $pdo->prepare("UPDATE clip_reports SET status='resolved' WHERE id=?")->execute([$row['clip_report_id']]);
                $ud = $pdo->prepare("UPDATE dispatch SET status='returning', resolved_at=NOW() WHERE id=?");
                $uu = $pdo->prepare("UPDATE ptv_units SET status='Returning' WHERE id=?");
                foreach ($active as $d) {
                    $ud->execute([$d['id']]);
                    $uu->execute([$d['unit_id']]);
                }
                $pdo->commit();
                $flash = 'Returning to the command center.';
            }
        }
    }
}
   } catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $flash = 'Action failed: ' . $e->getMessage();
}

    $_SESSION['flash'] = $flash;
    redirect('/app/responder/assignment.php');
}
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$unitStatus = $unit['status'] ?? 'Available';
    
/* ---------------------------------------------------------------
   Active dispatch for this unit
   --------------------------------------------------------------- */
$dispatch = null;
if ($unit) {
    $dStmt = $pdo->prepare("
        SELECT d.*,
               TIMESTAMPDIFF(SECOND, d.departed_at, NOW()) AS en_route_secs,
               TIMESTAMPDIFF(SECOND, d.resolved_at, NOW()) AS returning_secs,
               c.clip_ref, c.caller_name, c.caller_contact, c.barangay, c.sitio_purok,
               c.landmark, c.latitude, c.longitude, c.incident_type, c.severity,
               c.problem_resources, c.problem_notes, c.created_at AS reported_at
        FROM dispatch d
        JOIN clip_reports c ON c.id = d.clip_report_id
               WHERE d.team_id = ? AND d.status IN ('assigned','en_route','on_site','returning')
        ORDER BY d.dispatched_at DESC, d.id DESC
        LIMIT 1
    ");
        $dStmt->execute([$myTeamId]);
    $dispatch = $dStmt->fetch(PDO::FETCH_ASSOC);
}

/* Navigation config handed to the JS below */
$nav = [];
$unitPos = null;
$sevColors = ['Critical' => '#b02029', 'High' => '#b02029', 'Moderate' => '#d4ab2b', 'Low' => '#3f7a5c'];
if ($dispatch) {
    $hasIncidentPin = !empty($dispatch['latitude']) && !empty($dispatch['longitude']);
    $isReturning    = $dispatch['status'] === 'returning';
    $toHospital = $dispatch['status'] === 'on_site' && $dispatch['site_outcome'] === 'hospital'
           && !empty($dispatch['hospital_lat']) && empty($dispatch['hospital_arrived_at']);
$nav = [
    'hasPhoto' => !empty($dispatch['incident_photo']),
    'mode'    => $isReturning ? 'return' : ($toHospital ? 'hospital' : 'incident'),
    'status'  => $dispatch['status'],
    'outcome' => $dispatch['site_outcome'],
    'lat'     => $toHospital ? (float)$dispatch['hospital_lat'] : ($hasIncidentPin ? (float)$dispatch['latitude']  : null),
    'lng'     => $toHospital ? (float)$dispatch['hospital_lng'] : ($hasIncidentPin ? (float)$dispatch['longitude'] : null),
    'origin'  => $hasIncidentPin ? ['lat' => (float)$dispatch['latitude'], 'lng' => (float)$dispatch['longitude']] : null,
    'color'   => $sevColors[$dispatch['severity']] ?? '#b02029',
    'label'   => $isReturning
        ? 'LDRRMO Manolo Fortich (Command Center)'
        : ($toHospital ? $dispatch['hospital_name'] : ($dispatch['incident_type'] . ' — ' . $dispatch['barangay'])),
];
    if (!empty($unit['current_lat']) && !empty($unit['current_lng'])) {
        $unitPos = ['lat' => (float)$unit['current_lat'], 'lng' => (float)$unit['current_lng']];
    }
}

/* ---------------------------------------------------------------
   View helpers
   --------------------------------------------------------------- */
function hle($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function hl_ico($n) {
    static $p = [
        'pin'    => '<path d="M21 10c0 7-9 12-9 12s-9-5-9-12a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
        'user'   => '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        'alert'  => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/>',
        'kit'    => '<rect x="3" y="7" width="18" height="14" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M12 11v6M9 14h6"/>',
        'clock'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'route'  => '<circle cx="6" cy="19" r="2"/><circle cx="18" cy="5" r="2"/><path d="M8 19h6a4 4 0 0 0 0-8h-4a4 4 0 0 1 0-8h6"/>',
        'phone'  => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>',
        'check'  => '<path d="M20 6 9 17l-5-5"/>',
        'truck'  => '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M14 9h4l4 4v4a1 1 0 0 1-1 1h-2"/><circle cx="6.5" cy="18.5" r="2.5"/><circle cx="17.5" cy="18.5" r="2.5"/>',
        'arrow'  => '<path d="M5 12h14M12 5l7 7-7 7"/>',
        'back'   => '<path d="M3 12h18M3 12l6-6M3 12l6 6"/>',
        'close'  => '<path d="M18 6 6 18M6 6l12 12"/>',
        'plus'   => '<path d="M12 5v14M5 12h14"/>',
        'minus'  => '<path d="M5 12h14"/>',
        'locate' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/>',
        'panel'  => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M15 4v16"/>',
        'lock'   => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'cam'    => '<path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$n] ?? '') . '</svg>';
}

$steps = [];
if ($dispatch) {
    $cur = $dispatch['status'];
    $fmt = function ($t) { return $t ? date('H:i', strtotime($t)) : '—'; };
    $ocCls = !empty($dispatch['outcome_at']) ? 'done' : ($cur === 'on_site' ? 'current' : ($cur === 'returning' ? 'done' : ''));
    $steps = [
        ['key' => 'assigned',  'label' => 'Assigned', 'time' => $fmt($dispatch['dispatched_at']), 'icon' => 'check', 'cls' => stepClass('assigned', $cur) ?: 'done'],
        ['key' => 'en_route',  'label' => 'Departed', 'time' => $fmt($dispatch['departed_at']),   'icon' => 'truck', 'cls' => stepClass('en_route', $cur)],
        ['key' => 'on_site',   'label' => 'Arrived',  'time' => $fmt($dispatch['arrived_at']),    'icon' => 'pin',   'cls' => stepClass('on_site', $cur)],
        ['key' => 'outcome', 'label' => 'Outcome', 'time' => $fmt($dispatch['outcome_at'] ?? null), 'icon' => 'kit', 'cls' => $ocCls],
        ['key' => 'returning', 'label' => 'Returned', 'time' => $fmt($dispatch['returned_at']),   'icon' => 'back',  'cls' => stepClass('returning', $cur)],
    ];
}
$prioLabel = $dispatch ? strtoupper($dispatch['severity'] ?: 'Moderate') . ' PRIORITY' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Assigned Incident — HopeLine</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/vendor/leaflet/leaflet.css" />
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/app.css">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/assignment.css">
</head>
<body class="assign-page">

<?php require_once __DIR__ . '/../../assets/layouts/responder/responder_sidebar.php'; ?>

<main class="main main-fullscreen">

<?php if (!$unit): ?>
    <div class="ws-empty">
        <div class="empty-state">
            <h2>No PTV unit linked to your account</h2>
            <p>Contact your LDRRMO admin to have a unit assigned to your profile.</p>
        </div>
    </div>

<?php elseif (!$dispatch): ?>
    <div class="ws-empty">
        <div class="empty-state">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 15V7a2 2 0 0 1 2-2h5l2 2h5a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/></svg>
            <h2>No active assignment</h2>
            <p>You're marked <strong><?php echo hle($unitStatus); ?></strong>. This page updates on its own when a dispatch comes in.</p>
        </div>
    </div>

<?php else: $declined = json_decode($dispatch['declined_hospitals'] ?? '[]', true) ?: []; ?>
    
<?php $step = $dispatch['status']; ?>
<div class="ws" id="ws">

    <!-- ============ MAP (primary workspace) ============ -->
    <div id="navMap"></div>

    <!-- ============ Floating status tracker ============ -->
    <div class="hud-top">
        <div class="hud-card">
            <?php if ($flash): ?><div class="hud-toast" id="hudToast"><?php echo hle($flash); ?></div><?php endif; ?>

            <div class="timeline" aria-label="Dispatch progress">
                <?php foreach ($steps as $i => $s): ?>
                <div class="tl-step <?php echo $s['cls']; ?>">
                    <?php if ($i > 0): ?><div class="tl-line"></div><?php endif; ?>
                    <div class="tl-circle"><?php echo hl_ico($s['icon']); ?></div>
                    <div class="tl-label"><?php echo $s['label']; ?></div>
                    <div class="tl-time"><?php echo hle($s['time']); ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="hud-actions">
            <?php if ($step === 'assigned'): ?>
                <form method="POST">
                    <input type="hidden" name="action" value="depart">
                    <input type="hidden" name="dispatch_id" value="<?php echo (int)$dispatch['id']; ?>">
                    <button type="submit" class="btn-action"><?php echo hl_ico('arrow'); ?> Depart Now</button>
                </form>

            <?php elseif ($step === 'en_route'): ?>
                <div class="hud-meta"><div class="hud-timer" id="elapsedTimer">00:00:00</div><div class="hud-sub">Time en route</div></div>
                <form method="POST">
                    <input type="hidden" name="action" value="arrive">
                    <input type="hidden" name="dispatch_id" value="<?php echo (int)$dispatch['id']; ?>">
                    <button type="submit" class="btn-action"><?php echo hl_ico('arrow'); ?> Mark as Arrived</button>
                </form>
                <a href="<?php echo BASE_URL; ?>/app/responder/report-delay.php" class="hud-link">Running late? Report a delay</a>

            <?php elseif ($step === 'on_site'):
    $so = $dispatch['site_outcome']; $hasPhoto = !empty($dispatch['incident_photo']);
    $hArr = !empty($dispatch['hospital_arrived_at']); $hAcc = !empty($dispatch['hospital_accepted_at']);
    $did = (int)$dispatch['id']; ?>
    <form method="POST" enctype="multipart/form-data" id="camForm" style="display:none">
        <input type="hidden" name="action" value="save_site_report">
        <input type="hidden" name="dispatch_id" value="<?php echo $did; ?>">
        <input type="hidden" name="client_lat"><input type="hidden" name="client_lng">
        <input type="file" name="incident_photo" id="camInput" accept="image/*" capture="environment">
    </form>
    <?php if (!$hasPhoto): ?>
        <button type="button" class="btn-action js-cam"><?php echo hl_ico('cam'); ?> Take site photo (required)</button>
    <?php elseif (!$so): ?>
        <button type="button" class="btn-action" data-open-tab="outcome">Choose patient outcome</button>
    <?php elseif ($so === 'minor'): ?>
        <button type="button" class="btn-action" disabled><?php echo hl_ico('lock'); ?> Minor case — waiting for command center</button>
    <?php else:
        [$act, $label] = !$hArr ? ['hospital_arrived', 'Arrived at hospital']
                       : (!$hAcc ? ['hospital_accepted', 'Hospital accepted the patient']
                                 : ['return_to_command', 'Return to command center']); ?>
        <form method="POST">
            <input type="hidden" name="action" value="<?php echo $act; ?>">
            <input type="hidden" name="dispatch_id" value="<?php echo $did; ?>">
            <button type="submit" class="btn-action"><?php echo hl_ico('check'); ?> <?php echo $label; ?></button>
        </form>
    <?php endif; ?>
    <?php if ($hasPhoto): ?><button type="button" class="hud-cam js-cam" aria-label="Retake site photo"><?php echo hl_ico('cam'); ?></button><?php endif; ?>

            <?php else: /* returning */ ?>
                <div class="hud-meta"><div class="hud-timer" id="elapsedTimer">00:00:00</div><div class="hud-sub">Returning to command center</div></div>
                <form method="POST">
                    <input type="hidden" name="action" value="return_to_base">
                    <input type="hidden" name="dispatch_id" value="<?php echo (int)$dispatch['id']; ?>">
                    <button type="submit" class="btn-action"><?php echo hl_ico('check'); ?> Complete Dispatch</button>
                </form>
            <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ============ Map controls ============ -->
    <div class="map-ctrl">
        <button type="button" id="ctlZoomIn"  aria-label="Zoom in"><?php echo hl_ico('plus'); ?></button>
        <button type="button" id="ctlZoomOut" aria-label="Zoom out"><?php echo hl_ico('minus'); ?></button>
        <button type="button" id="ctlLocate"  aria-label="Center on my location"><?php echo hl_ico('locate'); ?></button>
    </div>

    <!-- ============ Navigation HUD ============ -->
    <div class="hud-bottom">
        <div class="nav-hud">
            <div class="nav-banner">
                <div class="nav-arrow" id="navArrow">↑</div>
                <div>
                    <div class="nav-instr" id="navInstr">Calculating route…</div>
                    <div class="nav-sub" id="navSub"></div>
                </div>
            </div>
            <div class="nav-stats">
                <div><div class="v" data-eta="time">—</div><div class="l">Time left</div></div>
                <div><div class="v" data-eta="dist">—</div><div class="l">Distance</div></div>
                <div><div class="v" data-eta="arrive">—</div><div class="l">Arrive at</div></div>
                <div><span class="gps-chip" id="gpsChip"><span class="dot"></span><span id="gpsChipText">GPS…</span></span></div>
            </div>
            <div class="nav-note" id="navNote"></div>
        </div>
    </div>

    <!-- ============ Drawer handle (when closed) ============ -->
    <button type="button" class="drawer-handle" id="drawerHandle" aria-controls="drawer" aria-expanded="false">
        <?php echo hl_ico('panel'); ?><span>Incident</span>
    </button>

    <!-- ============ Slide-out incident panel ============ -->
    <aside class="drawer" id="drawer" aria-label="Incident details" aria-hidden="true">
        <div class="drawer-head">
            <div class="drawer-title">
                <div class="drawer-badges">
                    <span class="prio prio-<?php echo hle($dispatch['severity']); ?>"><?php echo hle($prioLabel); ?></span>
                    <span class="inc-id">ID: <?php echo hle($dispatch['clip_ref']); ?></span>
                </div>
                <h2><?php echo hle($dispatch['incident_type']); ?></h2>
                <p><?php echo hle($dispatch['barangay'] . ', Manolo Fortich, Bukidnon'); ?></p>
            </div>
            <button type="button" class="drawer-close" id="drawerClose" aria-label="Close incident panel"><?php echo hl_ico('close'); ?></button>
        </div>

        <div class="drawer-tabs" role="tablist">
            <button type="button" role="tab" class="dtab active" data-tab="details">Details</button>
            <button type="button" role="tab" class="dtab" data-tab="updates">Updates</button>
            <button type="button" role="tab" class="dtab" data-tab="route">Route</button>
            <?php if ($step === 'on_site'): ?><button type="button" role="tab" class="dtab" data-tab="outcome">Outcome</button><?php endif; ?>
        </div>

        <div class="drawer-body">

            <!-- ---- Details ---- -->
            <section class="dpane active" data-pane="details">
                <div class="is-row">
                    <div class="is-ico"><?php echo hl_ico('pin'); ?></div>
                    <div>
                        <div class="is-k">Location</div>
                        <div class="is-v"><?php echo nl2br(hle($dispatch['landmark'] ?: 'No landmark provided')); ?></div>
                        <div class="is-s"><?php echo hle(trim(($dispatch['sitio_purok'] ? $dispatch['sitio_purok'] . ', ' : '') . $dispatch['barangay'] . ', Manolo Fortich, Bukidnon')); ?></div>
                    </div>
                </div>

                <div class="is-row">
                    <div class="is-ico"><?php echo hl_ico('user'); ?></div>
                    <div>
                        <div class="is-k">Caller / Reported by</div>
                        <div class="is-v"><?php echo hle($dispatch['caller_name']); ?></div>
                        <div class="is-s">Reported <?php echo date('H:i', strtotime($dispatch['reported_at'])); ?></div>
                        <?php if ($dispatch['caller_contact']): ?>
                        <a class="call-btn" href="tel:<?php echo hle($dispatch['caller_contact']); ?>"><?php echo hl_ico('phone'); ?> <?php echo hle($dispatch['caller_contact']); ?></a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="is-row">
                    <div class="is-ico"><?php echo hl_ico('alert'); ?></div>
                    <div>
                        <div class="is-k">Incident type</div>
                        <div class="is-v"><?php echo hle($dispatch['incident_type']); ?></div>
                    </div>
                </div>

                <div class="is-row">
                    <div class="is-ico"><?php echo hl_ico('kit'); ?></div>
                    <div>
                        <div class="is-k">Assistance needed</div>
                        <div class="resources-list">
                            <?php foreach (explode(',', (string)$dispatch['problem_resources']) as $res): if (trim($res) === '') continue; ?>
                                <span class="resource-tag"><?php echo hle(trim($res)); ?></span>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($dispatch['problem_notes']): ?>
                            <div class="is-s" style="margin-top:8px;"><?php echo nl2br(hle($dispatch['problem_notes'])); ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="eta-card">
                    <div class="is-k">ETA (current)</div>
                    <div class="eta-big"><span data-eta="time">—</span><span class="eta-sep"></span><span data-eta="dist">—</span></div>
                    <div class="is-s"><span data-eta="via">Via —</span></div>
                </div>
            </section>

            <!-- ---- Updates ---- -->
            <section class="dpane" data-pane="updates">
                <ol class="ud-list">
                    <?php foreach ($steps as $s): ?>
                    <li class="ud-item <?php echo $s['cls']; ?>">
                        <span class="ud-dot"><?php echo $s['cls'] === 'done' ? hl_ico('check') : ''; ?></span>
                        <div>
                            <div class="ud-label"><?php echo $s['label']; ?></div>
                            <div class="ud-time"><?php echo hle($s['time']); ?></div>
                            <?php if ($s['key'] === 'returning' && $step === 'returning' && !empty($dispatch['resolved_at'])): ?>
                                <div class="is-s">Released by command center at <?php echo date('H:i', strtotime($dispatch['resolved_at'])); ?></div>
                            <?php endif; ?>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ol>

                <?php if ($step === 'on_site'): ?>
                <form method="POST" enctype="multipart/form-data" class="site-report">
                    <input type="hidden" name="action" value="save_site_report">
                    <input type="hidden" name="client_lat" value=""><input type="hidden" name="client_lng" value="">
                    <input type="hidden" name="dispatch_id" value="<?php echo (int)$dispatch['id']; ?>">
                    <div class="is-k">Site report</div>
                    <label for="incident_details">Incident details</label>
                    <textarea name="incident_details" id="incident_details" placeholder="Condition on arrival, actions taken, outcome"><?php echo hle($dispatch['incident_details'] ?? ''); ?></textarea>
                    <label for="incident_photo">Incident photo</label>
                    <input type="file" name="incident_photo" id="incident_photo" accept="image/*" capture="environment">
                    <?php if (!empty($dispatch['incident_photo'])): ?>
                        <img src="<?php echo BASE_URL . '/' . hle($dispatch['incident_photo']); ?>" alt="Saved incident photo" class="site-photo">
                        <?php if (!empty($dispatch['photo_lat'])): ?>
    <div class="is-s">📍 <?php echo hle(round($dispatch['photo_lat'], 6) . ', ' . round($dispatch['photo_lng'], 6)); ?> (<?php echo hle($dispatch['photo_gps_source']); ?>)<br><?php echo hle($dispatch['photo_location']); ?></div>
<?php endif; ?>
                    <?php endif; ?>
                    <button type="submit" class="btn-primary" style="margin-top:14px;width:100%;justify-content:center;">Save site report</button>
                </form>
                <?php endif; ?>
            </section>

            <!-- ---- Route ---- -->
            <section class="dpane" data-pane="route">
                <div class="route-grid">
                    <div class="rg"><div class="is-k">Current ETA</div><div class="rg-v" data-eta="time">—</div></div>
                    <div class="rg"><div class="is-k">Distance remaining</div><div class="rg-v" data-eta="dist">—</div></div>
                    <div class="rg"><div class="is-k">Estimated arrival</div><div class="rg-v" data-eta="arrive">—</div></div>
                    <div class="rg"><div class="is-k">Dispatch estimate</div><div class="rg-v"><?php echo $dispatch['predicted_eta_minutes'] !== null ? round((float)$dispatch['predicted_eta_minutes']) . ' min' : '—'; ?></div></div>
                </div>

                <div class="is-row">
                    <div class="is-ico"><?php echo hl_ico('route'); ?></div>
                    <div>
                        <div class="is-k">Current route</div>
                        <div class="is-v" data-eta="summary">—</div>
                        <div class="is-s" data-eta="via">Via —</div>
                    </div>
                </div>
                <div class="is-row">
                    <div class="is-ico"><?php echo hl_ico('pin'); ?></div>
                    <div>
                        <div class="is-k">Destination</div>
                        <div class="is-v"><?php echo hle($nav['label']); ?></div>
                    </div>
                </div>
                <div class="is-row">
                    <div class="is-ico"><?php echo hl_ico('locate'); ?></div>
                    <div>
                        <div class="is-k">Navigation status</div>
                        <div class="is-v" data-eta="navstatus">Starting…</div>
                    </div>
                </div>
            </section>
            <?php if ($step === 'on_site'): ?>
<section class="dpane" data-pane="outcome">
    <div class="is-k">Patient outcome</div>
    <?php if (empty($dispatch['incident_photo'])): ?><div class="is-s">📷 Take the site photo first. Outcome unlocks after it is saved.</div><?php else: ?>
        <?php if ($dispatch['site_outcome'] === 'hospital' && $dispatch['hospital_name']):
        $hArrived  = !empty($dispatch['hospital_arrived_at']);
        $hAccepted = !empty($dispatch['hospital_accepted_at']); ?>

        <div class="is-v"><?php echo hle($dispatch['hospital_name']); ?></div>
        <div class="is-s">
            <?php
            if ($hAccepted)      echo 'Accepted the patient at ' . date('H:i', strtotime($dispatch['hospital_accepted_at']));
            elseif ($hArrived)   echo 'Arrived ' . date('H:i', strtotime($dispatch['hospital_arrived_at'])) . ' · waiting for the hospital to accept';
            else                 echo 'Route shown on the map';
            ?>
        </div>

        <?php if (!$hArrived): ?>
        <form method="POST" style="margin-top:12px;">
            <input type="hidden" name="action" value="hospital_arrived">
            <input type="hidden" name="dispatch_id" value="<?php echo (int)$dispatch['id']; ?>">
            <button type="submit" class="btn-action" style="width:100%;">Arrived at hospital</button>
        </form>
        <?php elseif (!$hAccepted): ?>
        <form method="POST" style="margin-top:12px;" onsubmit="return confirm('Confirm that the hospital accepted the patient?');">
            <input type="hidden" name="action" value="hospital_accepted">
            <input type="hidden" name="dispatch_id" value="<?php echo (int)$dispatch['id']; ?>">
            <button type="submit" class="btn-action" style="width:100%;">✔ Hospital accepted the patient</button>
        </form>
        <?php endif; ?>

        <?php if (!$hAccepted): ?>
        <form method="POST" style="margin-top:8px;" onsubmit="return confirm('Hospital declined the patient? You will pick another hospital.');">
            <input type="hidden" name="action" value="hospital_declined">
            <input type="hidden" name="dispatch_id" value="<?php echo (int)$dispatch['id']; ?>">
            <button type="submit" class="btn-primary" style="width:100%;">Hospital declined — refer another</button>
        </form>
        <?php endif; ?>

        <?php $stage = $hAccepted ? 3 : ($hArrived ? 2 : 1); ?>
<div class="is-v"><?php echo hle($dispatch['hospital_name']); ?></div>
<ol class="oc-steps">
    <li class="<?php echo $stage > 1 ? 'done' : 'current'; ?>">Heading to hospital</li>
    <li class="<?php echo $stage > 2 ? 'done' : ($stage == 2 ? 'current' : ''); ?>">Arrived<?php echo $hArrived ? ' · ' . date('H:i', strtotime($dispatch['hospital_arrived_at'])) : ''; ?></li>
    <li class="<?php echo $stage >= 3 ? 'done' : ''; ?>">Accepted<?php echo $hAccepted ? ' · ' . date('H:i', strtotime($dispatch['hospital_accepted_at'])) : ''; ?></li>
</ol>
<form method="POST">
    <input type="hidden" name="action" value="<?php echo ['hospital_arrived','hospital_accepted','return_to_command'][$stage-1]; ?>">
    <input type="hidden" name="dispatch_id" value="<?php echo (int)$dispatch['id']; ?>">
    <button type="submit" class="btn-action" style="width:100%;"><?php echo ['Arrived at hospital','✔ Hospital accepted the patient','Return to command center'][$stage-1]; ?></button>
</form>
<?php if (!$hAccepted): ?>
<form method="POST" onsubmit="return confirm('Hospital declined the patient? You will pick another hospital.');">
    <input type="hidden" name="action" value="hospital_declined">
    <input type="hidden" name="dispatch_id" value="<?php echo (int)$dispatch['id']; ?>">
    <button type="submit" class="btn-ghost-danger">Hospital declined — refer another</button>
</form>
<?php endif; ?>
        <button type="button" class="btn-action" disabled style="width:100%;margin-top:14px;opacity:.4;cursor:not-allowed;" title="Unlocks when the hospital accepts the patient">
            <?php echo hl_ico('lock'); ?> Return to command center
        </button>
        <div class="is-s" style="margin-top:6px;">Unlocks after the hospital accepts the patient.</div>
        <?php endif; ?>
    <?php else: ?>
        <?php if ($declined): ?><div class="is-s">Declined: <?php echo hle(implode(', ', $declined)); ?></div><?php endif; ?>
        <button type="button" class="btn-action" id="btnFindHosp" style="width:100%;margin-top:10px;">🏥 <?php echo $declined ? 'Find other hospitals' : 'Deliver to nearest hospital'; ?></button>
        <div id="hospList" style="margin-top:12px;"></div>
        <form method="POST" style="margin-top:14px;">
            <input type="hidden" name="action" value="choose_minor">
            <input type="hidden" name="dispatch_id" value="<?php echo (int)$dispatch['id']; ?>">
            <button type="submit" class="btn-primary" style="width:100%;">Minor — bandage only</button>
        </form>
        <?php if ($dispatch['site_outcome'] === 'minor'): ?><div class="is-s" style="margin-top:8px;">Currently logged: minor case.</div><?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>
</section>
<?php endif; ?>
        </div>
    </aside>
</div>
<?php endif; ?>
</main>

<?php if ($unit): ?>
<script>
/* Auto-refresh when the command center changes this dispatch, + elapsed timer */
(function () {
    var current = <?php echo json_encode($dispatch ? $dispatch['id'] . ':' . $dispatch['status'] : 'none'); ?>;
    function check() {
        fetch('<?php echo BASE_URL; ?>/api/dispatch-status.php', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (d) { if (d && d.ok && d.key !== current) location.reload(); })
            .catch(function () {});
    }
    setInterval(check, 5000);
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'visible') check();
    });

    <?php if ($dispatch && in_array($dispatch['status'], ['en_route', 'returning'])): ?>
    var elapsedStart = <?php echo max(0, (int)($dispatch['status'] === 'en_route' ? $dispatch['en_route_secs'] : $dispatch['returning_secs'])); ?>;
    var t0 = Date.now(), el = document.getElementById('elapsedTimer');
    function p(n) { return String(n).padStart(2, '0'); }
    (function tick() {
        if (!el) return;
        var diff = Math.max(0, elapsedStart * 1000 + (Date.now() - t0));
        el.textContent = p(Math.floor(diff / 3600000)) + ':' + p(Math.floor((diff % 3600000) / 60000)) + ':' + p(Math.floor((diff % 60000) / 1000));
        setTimeout(tick, 1000);
    })();
    <?php endif; ?>
})();
</script>
<?php endif; ?>

<?php if ($dispatch): ?>
<script src="<?php echo BASE_URL; ?>/assets/vendor/leaflet/leaflet.js"></script>
<script>
(function () {
    'use strict';

    var NAV      = <?php echo json_encode($nav, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    var UNIT_POS = <?php echo json_encode($unitPos); ?>;
    var BASE     = { lat: 8.371714652741774, lng: 124.85717564826615 }; // LDRRMO / PTV base
    var OSRM     = 'https://router.project-osrm.org/route/v1/driving'; // swap for your own OSRM server in production

    var hasPin   = NAV.lat !== null && NAV.lng !== null;
    var TARGET   = (NAV.mode === 'return' || !hasPin) ? BASE : { lat: NAV.lat, lng: NAV.lng };
   var ROUTING_ENABLED = (NAV.status !== 'on_site' || NAV.mode === 'hospital') && (NAV.mode === 'return' || hasPin);

    var $ = function (id) { return document.getElementById(id); };
    var ws = $('ws'), drawer = $('drawer'), handle = $('drawerHandle');

    /* =====================================================
       Incident drawer: open / close / tabs
       ===================================================== */
    function panelOpen() { return ws.classList.contains('panel-open'); }

    function selectTab(name) {
        document.querySelectorAll('.dtab').forEach(function (t) {
            var on = t.dataset.tab === name;
            t.classList.toggle('active', on);
            t.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        document.querySelectorAll('.dpane').forEach(function (p) {
            p.classList.toggle('active', p.dataset.pane === name);
        });
    }
    function setPanel(open, tab) {
        ws.classList.toggle('panel-open', open);
        drawer.setAttribute('aria-hidden', open ? 'false' : 'true');
        handle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open && tab) selectTab(tab);
        if (open) { var c = $('drawerClose'); if (c && window.innerWidth <= 700) c.focus({ preventScroll: true }); }
    }
    $('drawerClose').addEventListener('click', function () { setPanel(false); });
    handle.addEventListener('click', function () { setPanel(true); });
    document.querySelectorAll('.dtab').forEach(function (t) {
        t.addEventListener('click', function () { selectTab(t.dataset.tab); });
    });
    document.querySelectorAll('[data-open-tab]').forEach(function (b) {
        b.addEventListener('click', function () { setPanel(true, b.dataset.openTab); });
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && panelOpen()) setPanel(false); });

    // Open by default on wide screens; keep the map clear on phones/tablets
    setPanel(window.innerWidth > 1100);
if (NAV.status === 'on_site' && !NAV.outcome && NAV.hasPhoto) setPanel(true, 'outcome');
    var toast = $('hudToast');
    if (toast) setTimeout(function () { toast.classList.add('hide'); }, 6000);

    /* =====================================================
       Map
       ===================================================== */
    var map = L.map('navMap', { zoomControl: false }).setView([TARGET.lat, TARGET.lng], 15);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors', maxZoom: 19
    }).addTo(map);
function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
    var pinIcon = L.divIcon({
        className: '',
        html: '<div class="inc-pin" style="--c:' + NAV.color + '"></div>',
        iconSize: [22, 22], iconAnchor: [11, 22]
    });
    if (hasPin || NAV.mode === 'return') {
        L.marker([TARGET.lat, TARGET.lng], { icon: pinIcon }).addTo(map).bindPopup(esc(NAV.label));
    }
    if (NAV.mode === 'incident') {
        L.circleMarker([BASE.lat, BASE.lng], { radius: 7, color: '#fff', weight: 2, fillColor: '#739ab9', fillOpacity: 1 })
            .addTo(map).bindTooltip('LDRRMO command center');
    }

    var casing = L.polyline([], { color: '#ffffff', weight: 11, opacity: 0.95 }).addTo(map);
    var line   = L.polyline([], { color: '#2b8cff', weight: 7,  opacity: 1 }).addTo(map);

    var meIcon = L.divIcon({
        className: '',
        html: '<div class="me-arrow"><svg viewBox="0 0 24 24"><path d="M12 2 L20 21 L12 17 L4 21 Z" fill="#2b8cff" stroke="#ffffff" stroke-width="2" stroke-linejoin="round"/></svg></div>',
        iconSize: [26, 26], iconAnchor: [13, 13]
    });

    // PTV marker shows the last known position until the phone's GPS fix arrives
    var me = null;
    if (UNIT_POS) me = L.marker([UNIT_POS.lat, UNIT_POS.lng], { icon: meIcon, zIndexOffset: 1000 }).addTo(map).bindTooltip('Your PTV');

    function fitOpts() {
        var w = window.innerWidth, open = panelOpen();
        return {
            paddingTopLeft: [40, w <= 700 ? 190 : 200],
            paddingBottomRight: [40 + (open && w > 700 ? drawer.offsetWidth : 0), 120 + (open && w <= 700 ? drawer.offsetHeight : 0)]
        };
    }

    /* ---------- controls ---------- */
    var follow = true, firstFix = true, liveMode = false;
    var route = null, myPos = null, fetching = false, lastFetch = 0;
    var btnLocate = $('ctlLocate');

    $('ctlZoomIn').addEventListener('click',  function () { map.zoomIn(); });
    $('ctlZoomOut').addEventListener('click', function () { map.zoomOut(); });
    map.on('dragstart', function () { follow = false; btnLocate.classList.add('off'); });
    btnLocate.addEventListener('click', function () {
        follow = true; btnLocate.classList.remove('off');
        if (myPos) map.setView([myPos.lat, myPos.lng], Math.max(map.getZoom(), 16));
        else if (line.getLatLngs().length) map.fitBounds(line.getBounds(), fitOpts());
    });

    /* ---------- GPS chip: mirrors the status pill from gps-tracker.js ---------- */
    var pill = $('gpsPill'), chip = $('gpsChip'), chipText = $('gpsChipText');
    function mirrorGps() {
        if (!pill) return;
        chip.className = 'gps-chip ' + pill.className;
        chipText.textContent = pill.textContent || 'GPS…';
    }
    if (pill) {
        mirrorGps();
        new MutationObserver(mirrorGps).observe(pill, { attributes: true, childList: true, subtree: true, characterData: true });
    }

    /* ---------- helpers ---------- */
    var R = 6371000, K = Math.PI / 180;
    function haversine(a, b) {
        var dLat = (b[0] - a[0]) * K, dLng = (b[1] - a[1]) * K;
        var h = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                Math.cos(a[0] * K) * Math.cos(b[0] * K) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
        return 2 * R * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
    }
    function xy(c, ref) { return [(c[1] - ref.lng) * K * R * Math.cos(ref.lat * K), (c[0] - ref.lat) * K * R]; }

    // closest point on the route to `p`: distance off-route + distance travelled along it
    function nearest(p, coords, cum) {
        var best = { dist: Infinity, along: 0, idx: 0 };
        for (var i = 0; i < coords.length - 1; i++) {
            var A = xy(coords[i], p), B = xy(coords[i + 1], p);
            var dx = B[0] - A[0], dy = B[1] - A[1], len2 = dx * dx + dy * dy;
            var t = len2 ? Math.max(0, Math.min(1, -(A[0] * dx + A[1] * dy) / len2)) : 0;
            var cx = A[0] + t * dx, cy = A[1] + t * dy, d = Math.sqrt(cx * cx + cy * cy);
            if (d < best.dist) best = { dist: d, along: cum[i] + t * Math.sqrt(len2), idx: i };
        }
        return best;
    }

    function fmtDist(m) { return m < 1000 ? Math.max(10, Math.round(m / 10) * 10) + ' m' : (m / 1000).toFixed(1) + ' km'; }
    function fmtTime(s) {
        var m = Math.round(s / 60);
        if (m < 1) return '<1 min';
        if (m < 60) return m + ' min';
        return Math.floor(m / 60) + ' h ' + (m % 60) + ' min';
    }

    // write one live value into every place it is shown (HUD, Details tab, Route tab)
    function put(key, val) {
        var n = document.querySelectorAll('[data-eta="' + key + '"]');
        for (var i = 0; i < n.length; i++) n[i].textContent = val;
    }

    var ARROWS = { 'left': '←', 'right': '→', 'straight': '↑', 'slight left': '↖', 'slight right': '↗', 'sharp left': '↙', 'sharp right': '↘', 'uturn': '↶' };
    function arrowFor(m) {
        if (m.type === 'arrive') return '⚑';
        if (m.type === 'roundabout' || m.type === 'rotary') return '⟳';
        return ARROWS[m.modifier] || '↑';
    }
    function instruction(s) {
        var m = s.maneuver, mod = m.modifier || '', on = s.name ? ' onto ' + s.name : '';
        switch (m.type) {
            case 'arrive': return 'Arrive at destination';
            case 'roundabout': case 'rotary': return 'Enter the roundabout' + (m.exit ? ', exit ' + m.exit : '');
            case 'fork': return 'Keep ' + (mod.indexOf('left') > -1 ? 'left' : 'right') + ' at the fork';
            case 'merge': return 'Merge' + on;
            case 'on ramp': case 'off ramp': return 'Take the ramp' + on;
            case 'continue': case 'new name': return 'Continue' + on;
            case 'depart': return 'Head out' + on;
            default:
                if (mod === 'straight') return 'Continue straight' + on;
                if (mod === 'uturn') return 'Make a U-turn';
                return 'Turn ' + mod + on;
        }
    }

    function setBanner(arrow, title, sub) {
        $('navArrow').textContent = arrow; $('navInstr').textContent = title; $('navSub').textContent = sub || '';
    }
    function setStats(distM, sec) {
        put('dist', fmtDist(distM));
        put('time', fmtTime(sec));
       put('arrive', new Date(Date.now() + sec * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }));
        put('summary', fmtDist(distM) + ' • ' + fmtTime(sec));
    }

    /* ---------- OSRM ---------- */
    function fetchRoute(from, live) {
        if (fetching) return;
        fetching = true; lastFetch = Date.now();
        var url = OSRM + '/' + from.lng + ',' + from.lat + ';' + TARGET.lng + ',' + TARGET.lat +
                  '?overview=full&geometries=geojson&steps=true';
        fetch(url).then(function (r) { return r.json(); }).then(function (d) {
            fetching = false;
            if (!d.routes || !d.routes.length) throw new Error('no route');
            var r0 = d.routes[0];
            var coords = r0.geometry.coordinates.map(function (c) { return [c[1], c[0]]; });
            var cum = [0];
            for (var i = 1; i < coords.length; i++) cum.push(cum[i - 1] + haversine(coords[i - 1], coords[i]));

            var steps = [], acc = 0, roadLen = {}, via = '';
            r0.legs[0].steps.forEach(function (s) {
                steps.push({ start: acc, end: acc + s.distance, maneuver: s.maneuver, name: s.name });
                acc += s.distance;
                if (s.name) roadLen[s.name] = (roadLen[s.name] || 0) + s.distance;
            });
            Object.keys(roadLen).forEach(function (k) { if (!via || roadLen[k] > roadLen[via]) via = k; });
            put('via', via ? 'Via ' + via : 'Via —');

            route = { coords: coords, cum: cum, total: cum[cum.length - 1] || 1, duration: r0.duration, steps: steps, stepsTotal: acc };
            casing.setLatLngs(coords); line.setLatLngs(coords);
            liveMode = live;
            if (!myPos || !follow) map.fitBounds(line.getBounds(), fitOpts());
            updateProgress();
            if (!live && myPos) fetchRoute(myPos, true); // replace the command-center preview with a route from where you are
        }).catch(function () {
            fetching = false;
            setBanner('!', 'Route unavailable', 'Check your internet connection — retrying');
            put('navstatus', 'Route unavailable');
        });
    }

    /* ---------- live progress along the route ---------- */
    function updateProgress() {
        if (!route) return;
        if (!myPos) {
            setStats(route.total, route.duration);
            setBanner('↑', NAV.mode === 'return' ? 'Route back to command center' : 'Suggested route from command center', 'Waiting for your GPS…');
            put('navstatus', 'Waiting for GPS — showing suggested route');
            return;
        }
        var n = nearest(myPos, route.coords, route.cum);
        if (n.dist <= 60) { // on the route: draw only what's ahead
            var rest = [[myPos.lat, myPos.lng]].concat(route.coords.slice(n.idx + 1));
            casing.setLatLngs(rest);
            line.setLatLngs(rest);
        }
        var remaining = Math.max(0, route.total - n.along);
        setStats(remaining, route.duration * (remaining / route.total));

        // Drifted off the route? Ask OSRM for a new one
        if (n.dist > 60 && Date.now() - lastFetch > 10000) {
            fetchRoute(myPos, true); setBanner('↻', 'Rerouting…', ''); put('navstatus', 'Rerouting…'); return;
        }
        put('navstatus', 'Live GPS navigation');

        var traveled = n.along * (route.stepsTotal / route.total);
        var k = 0;
        while (k < route.steps.length - 1 && route.steps[k].end <= traveled) k++;
        var cur = route.steps[k], next = route.steps[k + 1];

        if (!next) setBanner('⚑', 'Arrive at destination', fmtDist(remaining) + ' ahead');
        else setBanner(arrowFor(next.maneuver), instruction(next), 'in ' + fmtDist(Math.max(0, cur.end - traveled)));

        var note = $('navNote');
        if (NAV.status === 'en_route' && remaining < 40) note.textContent = 'You are at the destination — tap "Mark as Arrived" above.';
        else if (NAV.mode === 'return') note.textContent = 'Returning to the command center.';
        else note.textContent = '';
    }

    /* ---------- GPS fixes broadcast by gps-tracker.js ---------- */
    function onPosition(f) {
        myPos = { lat: f.lat, lng: f.lng, accuracy: f.accuracy };
        if (!me) me = L.marker([f.lat, f.lng], { icon: meIcon, zIndexOffset: 1000 }).addTo(map).bindTooltip('Your PTV');
        else me.setLatLng([f.lat, f.lng]);

        if (f.heading !== null && f.heading !== undefined) {
            var a = me.getElement() && me.getElement().querySelector('.me-arrow');
            if (a) a.style.transform = 'rotate(' + f.heading + 'deg)';
        }
        if (follow) {
            if (firstFix) { map.setView([f.lat, f.lng], 17); firstFix = false; }
            else map.panTo([f.lat, f.lng], { animate: true });
        }
        if (ROUTING_ENABLED && !liveMode && !fetching && (route || Date.now() - lastFetch > 3000)) fetchRoute(myPos, true);
        updateProgress();
    }
    window.addEventListener('hopegps:position', function (e) { onPosition(e.detail); });
    var camForm = $('camForm'), camInput = $('camInput');
document.querySelectorAll('.js-cam').forEach(function (b) { b.addEventListener('click', function () { camInput.click(); }); });
if (camInput) camInput.addEventListener('change', function () {
    if (!camInput.files.length) return;
    var f = window.HopeGPS && window.HopeGPS.getLast && window.HopeGPS.getLast();
    if (f) { camForm.elements.client_lat.value = f.lat; camForm.elements.client_lng.value = f.lng; }
    camForm.submit();
});
var sr = document.querySelector('.site-report');
if (sr) sr.addEventListener('submit', function () {
    var f = window.HopeGPS && window.HopeGPS.getLast && window.HopeGPS.getLast();
    if (f) { sr.elements.client_lat.value = f.lat; sr.elements.client_lng.value = f.lng; }
});
/* ---------- Hospital picker helpers ---------- */
var DISPATCH_ID = <?php echo (int)$dispatch['id']; ?>;
var DECLINED = <?php echo json_encode(array_map('mb_strtolower', $declined)); ?>;
var OSRM_TABLE = 'https://router.project-osrm.org/table/v1/driving';
var hospBtn = $('btnFindHosp'), hospList = $('hospList');

function postAction(fields) {
    // reuse the CSRF token that the page's existing forms already carry
    var src = document.querySelector(
        'form input[type="hidden"][name*="csrf" i], form input[type="hidden"][name*="token" i]'
    );
    if (src) fields[src.name] = src.value;

    var f = document.createElement('form'); f.method = 'POST';
    Object.keys(fields).forEach(function (k) {
        var i = document.createElement('input'); i.type = 'hidden'; i.name = k; i.value = fields[k]; f.appendChild(i);
    });
    document.body.appendChild(f); f.submit();
}

function renderHospitals(list) {
    hospList.textContent = '';
    list.forEach(function (h) {
        var b = document.createElement('button');
        b.type = 'button'; b.className = 'btn-action'; b.style.cssText = 'width:100%;margin-bottom:8px;text-align:left;';
        b.textContent = h.name + ' · ' + (h.sec != null ? fmtTime(h.sec) + ' · ' + fmtDist(h.m) : fmtDist(h.air) + ' (straight line)');
        b.addEventListener('click', function () {
            postAction({ action: 'choose_hospital', dispatch_id: DISPATCH_ID, hospital_name: h.name, hospital_lat: h.lat, hospital_lng: h.lng });
        });
        hospList.appendChild(b);
    });
}
var HOSPITALS = [
    { name: 'Camp Philips Memorial Hospital', lat: 8.325902, lng: 124.817519 },
    { name: 'Bukidnon Provincial Hospital',   lat: 8.384815, lng: 124.839063 }
];

function findHospitals() {
    var o = myPos || (NAV.origin ? NAV.origin : (hasPin ? { lat: NAV.lat, lng: NAV.lng } : BASE));

    // Hide hospitals that already declined the patient
    var list = HOSPITALS
        .filter(function (h) { return DECLINED.indexOf(h.name.toLowerCase()) === -1; })
        .map(function (h) {
            return { name: h.name, lat: h.lat, lng: h.lng, air: haversine([o.lat, o.lng], [h.lat, h.lng]) };
        })
        .sort(function (a, b) { return a.air - b.air; });

    if (!list.length) {
        hospList.textContent = 'No other hospital available. Contact the command center.';
        return;
    }

    renderHospitals(list); // shows instantly (straight-line distance)

    // Refine with real drive time; if OSRM is slow or down, the list above stays
    var coords = [o.lng + ',' + o.lat].concat(list.map(function (h) { return h.lng + ',' + h.lat; })).join(';');
    var ctrl = new AbortController();
    var timer = setTimeout(function () { ctrl.abort(); }, 6000);

    fetch(OSRM_TABLE + '/' + coords + '?sources=0&annotations=duration,distance', { signal: ctrl.signal })
        .then(function (r) { return r.json(); })
        .then(function (t) {
            clearTimeout(timer);
            list.forEach(function (h, i) { h.sec = t.durations[0][i + 1]; h.m = t.distances[0][i + 1]; });
            list.sort(function (a, b) { return (a.sec == null ? 1e9 : a.sec) - (b.sec == null ? 1e9 : b.sec); });
            renderHospitals(list);
        })
        .catch(function () { clearTimeout(timer); });
}
if (hospBtn) hospBtn.addEventListener('click', findHospitals);
if (hospBtn && DECLINED.length) findHospitals(); // after a decline, show the next options right away
    /* ---------- go ---------- */
    if (!ROUTING_ENABLED) {
        var msg = NAV.status === 'on_site'
            ? ['You are on site', 'Waiting for the command center to release your unit', 'On site']
            : ['No incident pin set', 'The command center did not drop a map pin for this incident', 'No destination pin'];
        setBanner('⚑', msg[0], msg[1]);
        put('navstatus', msg[2]);
    } else {
       fetchRoute(NAV.mode === 'hospital' && NAV.origin ? NAV.origin : BASE, false);
    }
    if (window.HopeGPS && window.HopeGPS.getLast()) onPosition(window.HopeGPS.getLast());
})();
</script>
<?php endif; ?>

</body>
</html>