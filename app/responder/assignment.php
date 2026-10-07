<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';

requireRole('user');

$unitStmt = $pdo->prepare("SELECT * FROM ptv_units WHERE responder_id = ? LIMIT 1");
$unitStmt->execute([$_SESSION['user_id']]);
$unit = $unitStmt->fetch(PDO::FETCH_ASSOC);

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
        if ($action === 'depart') {
            $st = $pdo->prepare("UPDATE dispatch SET status='en_route', departed_at=NOW()
                                 WHERE id=? AND unit_id=? AND status='assigned'");
            $st->execute([$dispatchId, $unit['id']]);
            if ($st->rowCount()) {
                $pdo->prepare("
                    UPDATE ptv_units u
                    JOIN dispatch d ON d.id = ?
                    JOIN clip_reports c ON c.id = d.clip_report_id
                    SET u.status='En Route', u.current_lat = c.latitude, u.current_lng = c.longitude
                    WHERE u.id = ?
                ")->execute([$dispatchId, $unit['id']]);
                $flash = 'Departure logged. Drive safe.';
            } else {
                $flash = 'This dispatch has changed. Your screen was refreshed.';
            }
        }

        if ($action === 'arrive') {
            $st = $pdo->prepare("UPDATE dispatch SET status='on_site', arrived_at=NOW()
                                 WHERE id=? AND unit_id=? AND status='en_route'");
            $st->execute([$dispatchId, $unit['id']]);
            if ($st->rowCount()) {
                $pdo->prepare("UPDATE ptv_units SET status='On Site' WHERE id=?")->execute([$unit['id']]);
                $flash = 'Arrival logged.';
            } else {
                $flash = 'This dispatch has changed. Your screen was refreshed.';
            }
        }

        if ($action === 'save_site_report') {
            $details = trim($_POST['incident_details'] ?? '');
            $photoPath = null;
            if (!empty($_FILES['incident_photo']['name']) && $_FILES['incident_photo']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['incident_photo']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg','jpeg','png','webp']) && @getimagesize($_FILES['incident_photo']['tmp_name'])) {
                    $uploadDir = __DIR__ . '/../../assets/uploads/incidents/';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                    $filename = 'incident_' . $dispatchId . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['incident_photo']['tmp_name'], $uploadDir . $filename)) {
                        $photoPath = 'assets/uploads/incidents/' . $filename;
                    }
                }
            }
            $sql = "UPDATE dispatch SET incident_details=?" . ($photoPath ? ", incident_photo=?" : "")
                 . " WHERE id=? AND unit_id=? AND status='on_site'";
            $params = [$details];
            if ($photoPath) $params[] = $photoPath;
            $params[] = $dispatchId; $params[] = $unit['id'];
            $pdo->prepare($sql)->execute($params);
            $flash = 'Site report saved.';
        }

        if ($action === 'return_to_base') {
            $st = $pdo->prepare("UPDATE dispatch SET status='resolved', returned_at=NOW()
                                 WHERE id=? AND unit_id=? AND status='returning'");
            $st->execute([$dispatchId, $unit['id']]);
            if ($st->rowCount()) {
                $pdo->prepare("UPDATE ptv_units SET status='Available', current_lat = 8.371714652741774, current_lng = 124.85717564826615 WHERE id=?")
                    ->execute([$unit['id']]);
                if (function_exists('logActivity')) logActivity($pdo, $_SESSION['user_id'], $_SESSION['email'], 'returned_to_command_center', 'success');
                $flash = 'Welcome back! Unit marked Available.';
            } else {
                $flash = 'This dispatch has changed. Your screen was refreshed.';
            }
        }
    } catch (PDOException $e) {
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
        WHERE d.unit_id = ? AND d.status IN ('assigned','en_route','on_site','returning')
        ORDER BY d.dispatched_at DESC, d.id DESC
        LIMIT 1
    ");
    $dStmt->execute([$unit['id']]);
    $dispatch = $dStmt->fetch(PDO::FETCH_ASSOC);
}

/* Navigation config handed to the JS below */
$nav = [];
$unitPos = null;
$sevColors = ['Critical' => '#b02029', 'High' => '#b02029', 'Moderate' => '#d4ab2b', 'Low' => '#3f7a5c'];
if ($dispatch) {
    $hasIncidentPin = !empty($dispatch['latitude']) && !empty($dispatch['longitude']);
    $isReturning    = $dispatch['status'] === 'returning';
    $nav = [
        'mode'   => $isReturning ? 'return' : 'incident',
        'status' => $dispatch['status'],
        'lat'    => $hasIncidentPin ? (float)$dispatch['latitude']  : null,
        'lng'    => $hasIncidentPin ? (float)$dispatch['longitude'] : null,
        'color'  => $sevColors[$dispatch['severity']] ?? '#b02029',
        'label'  => $isReturning
            ? 'LDRRMO Manolo Fortich (Command Center)'
            : ($dispatch['incident_type'] . ' — ' . $dispatch['barangay']),
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
    $fmt = function ($t) { return $t ? date('g:i A', strtotime($t)) : '—'; };
    $steps = [
        ['key' => 'assigned',  'label' => 'Assigned', 'time' => $fmt($dispatch['dispatched_at']), 'icon' => 'check', 'cls' => stepClass('assigned', $cur) ?: 'done'],
        ['key' => 'en_route',  'label' => 'Departed', 'time' => $fmt($dispatch['departed_at']),   'icon' => 'truck', 'cls' => stepClass('en_route', $cur)],
        ['key' => 'on_site',   'label' => 'Arrived',  'time' => $fmt($dispatch['arrived_at']),    'icon' => 'pin',   'cls' => stepClass('on_site', $cur)],
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

<?php else: ?>
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

            <?php elseif ($step === 'on_site'): ?>
                <button type="button" class="btn-action" disabled><?php echo hl_ico('lock'); ?> Waiting for command center to release unit</button>
                <button type="button" class="hud-link hud-link-btn" data-open-tab="updates">Add site report</button>

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
                <p><?php echo hle($dispatch['barangay']); ?></p>
            </div>
            <button type="button" class="drawer-close" id="drawerClose" aria-label="Close incident panel"><?php echo hl_ico('close'); ?></button>
        </div>

        <div class="drawer-tabs" role="tablist">
            <button type="button" role="tab" class="dtab active" data-tab="details">Details</button>
            <button type="button" role="tab" class="dtab" data-tab="updates">Updates</button>
            <button type="button" role="tab" class="dtab" data-tab="route">Route</button>
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
                        <div class="is-s">Reported <?php echo date('g:i A', strtotime($dispatch['reported_at'])); ?></div>
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
                                <div class="is-s">Released by command center at <?php echo date('g:i A', strtotime($dispatch['resolved_at'])); ?></div>
                            <?php endif; ?>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ol>

                <?php if ($step === 'on_site'): ?>
                <form method="POST" enctype="multipart/form-data" class="site-report">
                    <input type="hidden" name="action" value="save_site_report">
                    <input type="hidden" name="dispatch_id" value="<?php echo (int)$dispatch['id']; ?>">
                    <div class="is-k">Site report</div>
                    <label for="incident_details">Incident details</label>
                    <textarea name="incident_details" id="incident_details" placeholder="Condition on arrival, actions taken, outcome"><?php echo hle($dispatch['incident_details'] ?? ''); ?></textarea>
                    <label for="incident_photo">Incident photo</label>
                    <input type="file" name="incident_photo" id="incident_photo" accept="image/*" capture="environment">
                    <?php if (!empty($dispatch['incident_photo'])): ?>
                        <img src="<?php echo BASE_URL . '/' . hle($dispatch['incident_photo']); ?>" alt="Saved incident photo" class="site-photo">
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
    var ROUTING_ENABLED = NAV.status !== 'on_site' && (NAV.mode === 'return' || hasPin);

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

    var toast = $('hudToast');
    if (toast) setTimeout(function () { toast.classList.add('hide'); }, 6000);

    /* =====================================================
       Map
       ===================================================== */
    var map = L.map('navMap', { zoomControl: false }).setView([TARGET.lat, TARGET.lng], 15);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors', maxZoom: 19
    }).addTo(map);

    var pinIcon = L.divIcon({
        className: '',
        html: '<div class="inc-pin" style="--c:' + NAV.color + '"></div>',
        iconSize: [22, 22], iconAnchor: [11, 22]
    });
    if (hasPin || NAV.mode === 'return') {
        L.marker([TARGET.lat, TARGET.lng], { icon: pinIcon }).addTo(map).bindPopup(NAV.label);
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
        put('arrive', new Date(Date.now() + sec * 1000).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }));
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

    /* ---------- go ---------- */
    if (!ROUTING_ENABLED) {
        var msg = NAV.status === 'on_site'
            ? ['You are on site', 'Waiting for the command center to release your unit', 'On site']
            : ['No incident pin set', 'The command center did not drop a map pin for this incident', 'No destination pin'];
        setBanner('⚑', msg[0], msg[1]);
        put('navstatus', msg[2]);
    } else {
        fetchRoute(BASE, false); // instant "suggested route" preview before the phone has a GPS fix
    }
    if (window.HopeGPS && window.HopeGPS.getLast()) onPosition(window.HopeGPS.getLast());
})();
</script>
<?php endif; ?>

</body>
</html>