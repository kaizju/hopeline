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

$flash = '';
if ($unit && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $dispatchId = (int)($_POST['dispatch_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'depart') {
            $pdo->prepare("UPDATE dispatch SET status='en_route', departed_at=NOW() WHERE id=? AND unit_id=?")
                ->execute([$dispatchId, $unit['id']]);
            $pdo->prepare("
                UPDATE ptv_units u
                JOIN dispatch d ON d.id = ?
                JOIN clip_reports c ON c.id = d.clip_report_id
                SET u.status='En Route', u.current_lat = c.latitude, u.current_lng = c.longitude
                WHERE u.id = ?
            ")->execute([$dispatchId, $unit['id']]);
            if (function_exists('logActivity')) logActivity($pdo, $_SESSION['user_id'], $_SESSION['email'], 'departed_command_center', 'success');
            $flash = 'Departure logged. Drive safe.';
        }

        if ($action === 'arrive') {
            $pdo->prepare("UPDATE dispatch SET status='on_site', arrived_at=NOW() WHERE id=? AND unit_id=?")
                ->execute([$dispatchId, $unit['id']]);
            $pdo->prepare("UPDATE ptv_units SET status='On Site' WHERE id=?")->execute([$unit['id']]);
            if (function_exists('logActivity')) logActivity($pdo, $_SESSION['user_id'], $_SESSION['email'], 'arrived_at_site', 'success');
            $flash = 'Arrival logged.';
        }

        if ($action === 'return_to_base') {
            $details = trim($_POST['incident_details'] ?? '');
            $photoPath = null;
            if (!empty($_FILES['incident_photo']['name']) && $_FILES['incident_photo']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['incident_photo']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                    $uploadDir = __DIR__ . '/../../assets/uploads/incidents/';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                    $filename = 'incident_' . $dispatchId . '_' . time() . '.' . $ext;
                    if (move_uploaded_file($_FILES['incident_photo']['tmp_name'], $uploadDir . $filename)) {
                        $photoPath = 'assets/uploads/incidents/' . $filename;
                    }
                }
            }
            $sql = "UPDATE dispatch SET status='resolved', returned_at=NOW(), incident_details=?"
                 . ($photoPath ? ", incident_photo=?" : "") . " WHERE id=? AND unit_id=?";
            $params = [$details];
            if ($photoPath) $params[] = $photoPath;
            $params[] = $dispatchId;
            $params[] = $unit['id'];
            $pdo->prepare($sql)->execute($params);
            $pdo->prepare("UPDATE ptv_units SET status='Available', current_lat = 8.371714652741774, current_lng = 124.85717564826615 WHERE id=?")
                ->execute([$unit['id']]);
            if (function_exists('logActivity')) logActivity($pdo, $_SESSION['user_id'], $_SESSION['email'], 'returned_to_command_center', 'success');
            $flash = 'Welcome back! Unit marked Available.';
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

// Get the active dispatch (if any) for this unit
$dispatch = null;
if ($unit) {
    $dStmt = $pdo->prepare("
        SELECT d.*, c.clip_ref, c.caller_name, c.caller_contact, c.barangay, c.sitio_purok,
               c.landmark, c.latitude, c.longitude, c.incident_type, c.severity, c.problem_resources, c.problem_notes
        FROM dispatch d
        JOIN clip_reports c ON c.id = d.clip_report_id
        WHERE d.unit_id = ? AND d.status IN ('assigned','en_route','on_site','returning')
        ORDER BY d.dispatched_at DESC
        LIMIT 1
    ");
    $dStmt->execute([$unit['id']]);
    $dispatch = $dStmt->fetch(PDO::FETCH_ASSOC);
}

// Navigation config handed to the JS below
$showNav = false;
$nav = [];
if ($dispatch) {
    $hasIncidentPin = !empty($dispatch['latitude']) && !empty($dispatch['longitude']);
    $isReturning    = $dispatch['status'] === 'returning';
    $showNav        = $hasIncidentPin || $isReturning;
    $nav = [
        'mode'   => $isReturning ? 'return' : 'incident',
        'status' => $dispatch['status'],
        'lat'    => $hasIncidentPin ? (float)$dispatch['latitude']  : null,
        'lng'    => $hasIncidentPin ? (float)$dispatch['longitude'] : null,
        'label'  => $isReturning
            ? 'LDRRMO Manolo Fortich (Command Center)'
            : ($dispatch['incident_type'] . ' — ' . $dispatch['barangay']),
    ];
}

$unreadAlerts = 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Assigned Incident — HopeLine</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/vendor/leaflet/leaflet.css" />
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/app.css">
</head>
<body>

<?php require_once __DIR__ . '/../../assets/layouts/responder/responder_sidebar.php'; ?>

<main class="main">
    <div class="page-head">
        <h1>Assigned Incident</h1>
        <p>Your current dispatch details.</p>
    </div>
<?php if ($flash): ?><div class="flash"><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>
    <?php if (!$unit): ?>
        <div class="card">
            <div class="empty-state">
                <h2>No PTV unit linked to your account</h2>
                <p>Contact your LDRRMO admin to have a unit assigned to your profile.</p>
            </div>
        </div>
    <?php elseif (!$dispatch): ?>
        <div class="card">
            <div class="empty-state">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M4 15V7a2 2 0 0 1 2-2h5l2 2h5a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/></svg>
                <h2>No active assignment</h2>
                <p>You're marked <strong><?php echo htmlspecialchars($unitStatus); ?></strong>. You'll be notified here as soon as a dispatch comes in.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="status-strip"><span class="dot"></span> Unit: <?php echo htmlspecialchars($unit['unit_name']); ?> (<?php echo htmlspecialchars($unit['plate_no']); ?>)</div>
<?php $step = $dispatch['status']; ?>
<div class="timeline">
    <div class="tl-step <?php echo stepClass('assigned', $step) ?: 'done'; ?>">
        <div class="tl-circle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg></div>
        <div class="tl-label">Assigned</div>
        <div class="tl-time"><?php echo date('g:i A', strtotime($dispatch['dispatched_at'])); ?></div>
    </div>
    <div class="tl-step <?php echo stepClass('en_route', $step); ?>">
        <div class="tl-line"></div>
        <div class="tl-circle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M14 9h4l4 4v4a1 1 0 0 1-1 1h-2"/><circle cx="6.5" cy="18.5" r="2.5"/><circle cx="17.5" cy="18.5" r="2.5"/></svg></div>
        <div class="tl-label">Departed</div>
        <div class="tl-time"><?php echo $dispatch['departed_at'] ? date('g:i A', strtotime($dispatch['departed_at'])) : '—'; ?></div>
    </div>
    <div class="tl-step <?php echo stepClass('on_site', $step); ?>">
        <div class="tl-line"></div>
        <div class="tl-circle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 12-9 12s-9-5-9-12a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg></div>
        <div class="tl-label">Arrived</div>
        <div class="tl-time"><?php echo $dispatch['arrived_at'] ? date('g:i A', strtotime($dispatch['arrived_at'])) : '—'; ?></div>
    </div>
    <div class="tl-step <?php echo stepClass('returning', $step); ?>">
        <div class="tl-line"></div>
        <div class="tl-circle"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12h18M3 12l6-6M3 12l6 6"/></svg></div>
        <div class="tl-label">Returned</div>
        <div class="tl-time"><?php echo $dispatch['returned_at'] ? date('g:i A', strtotime($dispatch['returned_at'])) : '—'; ?></div>
    </div>
</div>

<div class="action-card" style="margin-bottom:18px;">
<?php if ($step === 'assigned'): ?>
    <div class="elapsed-label">Ready to head out?</div>
    <form method="POST">
        <input type="hidden" name="action" value="depart">
        <input type="hidden" name="dispatch_id" value="<?php echo $dispatch['id']; ?>">
        <button type="submit" class="btn-action">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
            Depart Command Center
        </button>
    </form>

<?php elseif ($step === 'en_route'): ?>
    <div class="elapsed-timer" id="elapsedTimer">00:00:00</div>
    <div class="elapsed-label">Time en route</div>
    <form method="POST">
        <input type="hidden" name="action" value="arrive">
        <input type="hidden" name="dispatch_id" value="<?php echo $dispatch['id']; ?>">
        <button type="submit" class="btn-action">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M20 6 9 17l-5-5"/></svg>
            Arrived at Site
        </button>
    </form>
    <a href="<?php echo BASE_URL; ?>/app/responder/report-delay.php" class="btn-secondary">Running late? Report a delay →</a>
    <script>
        const startedAt = new Date("<?php echo date('c', strtotime($dispatch['departed_at'])); ?>").getTime();
    </script>

<?php elseif ($step === 'returning'): ?>
    <div class="elapsed-timer" id="elapsedTimer">00:00:00</div>
    <div class="elapsed-label">Time returning to command center</div>
    <form method="POST" enctype="multipart/form-data" style="text-align:left; max-width:420px; margin:0 auto 20px;">
        <input type="hidden" name="action" value="return_to_base">
        <input type="hidden" name="dispatch_id" value="<?php echo $dispatch['id']; ?>">
        <label for="incident_details">Incident Details <span style="color:var(--light-grayish); font-weight:400;">(optional)</span></label>
        <textarea name="incident_details" id="incident_details" placeholder="What happened on-site — condition on arrival, actions taken, outcome"></textarea>
        <label for="incident_photo" style="margin-top:14px;">Incident Photo <span style="color:var(--light-grayish); font-weight:400;">(optional)</span></label>
        <input type="file" name="incident_photo" id="incident_photo" accept="image/*" capture="environment">
        <button type="submit" class="btn-action" style="width:100%; justify-content:center; margin-top:18px;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
            Back to Command Center
        </button>
    </form>
    <script>
        const startedAt = new Date("<?php echo date('c', strtotime($dispatch['resolved_at'])); ?>").getTime();
    </script>

<?php else: ?>
    <div class="done-msg">✅ You've arrived on site. Awaiting resolution from the command center.</div>
<?php endif; ?>
</div>

<?php if (in_array($step, ['en_route', 'on_site', 'returning'])): ?>
<script>
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
})();
   (function tick() {
    const el = document.getElementById('elapsedTimer');
    if (!el || typeof startedAt === 'undefined') return;
    const diff = Math.max(0, Date.now() - startedAt);
    const p = n => String(n).padStart(2, '0');
    el.textContent = p(Math.floor(diff / 3600000)) + ':' + p(Math.floor((diff % 3600000) / 60000)) + ':' + p(Math.floor((diff % 60000) / 1000));
    setTimeout(tick, 1000);
})();
</script>
<?php endif; ?>
        <?php if ($showNav): ?>
        <!-- ================= Navigation ================= -->
        <div class="nav-card">
            <div class="nav-banner">
                <div class="nav-arrow" id="navArrow">↑</div>
                <div>
                    <div class="nav-instr" id="navInstr">Calculating route…</div>
                    <div class="nav-sub" id="navSub"></div>
                </div>
            </div>
            <div class="nav-map-wrap">
                <div id="navMap"></div>
                <button type="button" class="btn-recenter" id="btnRecenter">Recenter</button>
            </div>
            <div class="nav-stats">
                <div><div class="v" id="navTime">—</div><div class="l">Time left</div></div>
                <div><div class="v" id="navDist">—</div><div class="l">Distance</div></div>
                <div><div class="v" id="navArrive">—</div><div class="l">Arrive at</div></div>
            </div>
            <div class="nav-note" id="navNote"></div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="incident-header">
                <div>
                    <div class="incident-title"><?php echo htmlspecialchars($dispatch['incident_type']); ?> — <?php echo htmlspecialchars($dispatch['barangay']); ?></div>
                    <div class="clip-ref"><?php echo htmlspecialchars($dispatch['clip_ref']); ?></div>
                </div>
                <?php if (!empty($dispatch['severity'])): ?>
                <span class="sev-badge sev-<?php echo htmlspecialchars($dispatch['severity']); ?>"><?php echo htmlspecialchars($dispatch['severity']); ?></span>
                <?php endif; ?>
            </div>

            <div class="info-grid">
                <div class="info-block">
                    <div class="label">Caller</div>
                    <div class="value"><?php echo htmlspecialchars($dispatch['caller_name']); ?></div>
                </div>
                <div class="info-block">
                    <div class="label">Contact</div>
                    <div class="value">
                        <?php if ($dispatch['caller_contact']): ?>
                            <a class="call-btn" href="tel:<?php echo htmlspecialchars($dispatch['caller_contact']); ?>">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                                <?php echo htmlspecialchars($dispatch['caller_contact']); ?>
                            </a>
                        <?php else: echo '—'; endif; ?>
                    </div>
                </div>
                <div class="info-block">
                    <div class="label">Sitio / Purok</div>
                    <div class="value"><?php echo htmlspecialchars($dispatch['sitio_purok'] ?: '—'); ?></div>
                </div>
                <div class="info-block">
                    <div class="label">Dispatched At</div>
                    <div class="value"><?php echo date('g:i A', strtotime($dispatch['dispatched_at'])); ?></div>
                </div>
                <div class="info-block full">
                    <div class="label">Additional Location Details</div>
                    <div class="value"><?php echo nl2br(htmlspecialchars($dispatch['landmark'] ?: 'None provided')); ?></div>
                </div>
                <div class="info-block full">
                    <div class="label">Resources Needed</div>
                    <div class="resources-list">
                        <?php foreach (explode(',', $dispatch['problem_resources']) as $res): ?>
                            <span class="resource-tag"><?php echo htmlspecialchars(trim($res)); ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php if ($dispatch['problem_notes']): ?>
                <div class="info-block full">
                    <div class="label">Notes</div>
                    <div class="value" style="font-weight:400;"><?php echo nl2br(htmlspecialchars($dispatch['problem_notes'])); ?></div>
                </div>
                <?php endif; ?>
            </div>

            
                <?php
                if ($dispatch['status'] === 'assigned') echo 'Go to Depart / Arrive Log →';
                elseif ($dispatch['status'] === 'en_route') echo 'Mark Arrived at Site →';
                else echo 'View Dispatch Status →';
                ?>
            </a>
        </div>
    <?php endif; ?>
</main>

<?php if ($showNav): ?>
<script src="<?php echo BASE_URL; ?>/assets/vendor/leaflet/leaflet.js"></script>
<script>
(function () {
    'use strict';

    var NAV  = <?php echo json_encode($nav); ?>;
    var BASE = { lat: 8.371714652741774, lng: 124.85717564826615 }; // LDRRMO / PTV base
    var TARGET = NAV.mode === 'return' ? BASE : { lat: NAV.lat, lng: NAV.lng };
    var OSRM = 'https://router.project-osrm.org/route/v1/driving'; // swap for your own OSRM server in production
    var ROUTING_ENABLED = NAV.status !== 'on_site';

    /* ---------- map ---------- */
    var map = L.map('navMap', { zoomControl: false }).setView([TARGET.lat, TARGET.lng], 15);
    L.control.zoom({ position: 'topright' }).addTo(map);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors', maxZoom: 19
    }).addTo(map);

    var pinIcon = L.divIcon({
        className: '',
        html: '<div style="width:20px;height:20px;border-radius:50% 50% 50% 0;background:#b02029;transform:rotate(-45deg);border:2px solid #fff;"></div>',
        iconSize: [20, 20], iconAnchor: [10, 20]
    });
    L.marker([TARGET.lat, TARGET.lng], { icon: pinIcon }).addTo(map).bindPopup(NAV.label);

    var casing = L.polyline([], { color: '#ffffff', weight: 11, opacity: 0.95 }).addTo(map);
    var line   = L.polyline([], { color: '#2b8cff', weight: 7,  opacity: 1 }).addTo(map);

    var meIcon = L.divIcon({
        className: '',
        html: '<div class="me-arrow"><svg viewBox="0 0 24 24"><path d="M12 2 L20 21 L12 17 L4 21 Z" fill="#2b8cff" stroke="#ffffff" stroke-width="2" stroke-linejoin="round"/></svg></div>',
        iconSize: [26, 26], iconAnchor: [13, 13]
    });

    /* ---------- state ---------- */
    var route = null, myPos = null, me = null;
    var follow = true, firstFix = true, liveMode = false;
    var fetching = false, lastFetch = 0;

    var el = function (id) { return document.getElementById(id); };
    var btnRecenter = el('btnRecenter');

    map.on('dragstart', function () { follow = false; btnRecenter.classList.add('show'); });
    btnRecenter.addEventListener('click', function () {
        follow = true; btnRecenter.classList.remove('show');
        if (myPos) map.setView([myPos.lat, myPos.lng], Math.max(map.getZoom(), 16));
    });

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
        var best = { dist: Infinity, along: 0 };
        for (var i = 0; i < coords.length - 1; i++) {
            var A = xy(coords[i], p), B = xy(coords[i + 1], p);
            var dx = B[0] - A[0], dy = B[1] - A[1], len2 = dx * dx + dy * dy;
            var t = len2 ? Math.max(0, Math.min(1, -(A[0] * dx + A[1] * dy) / len2)) : 0;
            var cx = A[0] + t * dx, cy = A[1] + t * dy, d = Math.sqrt(cx * cx + cy * cy);
            if (d < best.dist) best = { dist: d, along: cum[i] + t * Math.sqrt(len2) };
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
        el('navArrow').textContent = arrow; el('navInstr').textContent = title; el('navSub').textContent = sub || '';
    }
    function setStats(distM, sec) {
        el('navDist').textContent = fmtDist(distM);
        el('navTime').textContent = fmtTime(sec);
        el('navArrive').textContent = new Date(Date.now() + sec * 1000).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
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
            var steps = [], acc = 0;
            r0.legs[0].steps.forEach(function (s) {
                steps.push({ start: acc, end: acc + s.distance, maneuver: s.maneuver, name: s.name });
                acc += s.distance;
            });
            route = { coords: coords, cum: cum, total: cum[cum.length - 1] || 1, duration: r0.duration, steps: steps, stepsTotal: acc };
            casing.setLatLngs(coords); line.setLatLngs(coords);
            liveMode = live;
            if (!myPos || !follow) map.fitBounds(line.getBounds(), { padding: [40, 40] });
            updateProgress();
        }).catch(function () {
            fetching = false;
            setBanner('!', 'Route unavailable', 'Check your internet connection — retrying');
        });
    }

    /* ---------- live progress along the route ---------- */
    function updateProgress() {
        if (!route) return;
        if (!myPos) {
            setStats(route.total, route.duration);
            setBanner('↑', NAV.mode === 'return' ? 'Route back to command center' : 'Suggested route from command center', 'Waiting for your GPS…');
            return;
        }
        var n = nearest(myPos, route.coords, route.cum);
        var remaining = Math.max(0, route.total - n.along);
        setStats(remaining, route.duration * (remaining / route.total));

        // Drifted off the route? Ask OSRM for a new one (Waze-style reroute)
        if (n.dist > 60 && Date.now() - lastFetch > 10000) { fetchRoute(myPos, true); setBanner('↻', 'Rerouting…', ''); return; }

        var traveled = n.along * (route.stepsTotal / route.total);
        var k = 0;
        while (k < route.steps.length - 1 && route.steps[k].end <= traveled) k++;
        var cur = route.steps[k], next = route.steps[k + 1];

        if (!next) {
            setBanner('⚑', 'Arrive at destination', fmtDist(remaining) + ' ahead');
        } else {
            setBanner(arrowFor(next.maneuver), instruction(next), 'in ' + fmtDist(Math.max(0, cur.end - traveled)));
        }

        var note = el('navNote');
        if (NAV.status === 'en_route' && remaining < 40) note.textContent = 'You are at the destination — open the Depart / Arrive Log and tap "Arrived at Site".';
        else if (NAV.mode === 'return') note.textContent = 'Returning to the command center.';
        else note.textContent = '';
    }

    /* ---------- GPS from gps-tracker.js ---------- */
    function onPosition(f) {
        myPos = { lat: f.lat, lng: f.lng, accuracy: f.accuracy };
        if (!me) me = L.marker([f.lat, f.lng], { icon: meIcon, zIndexOffset: 1000 }).addTo(map);
        else me.setLatLng([f.lat, f.lng]);

        if (f.heading !== null && f.heading !== undefined) {
            var a = me.getElement() && me.getElement().querySelector('.me-arrow');
            if (a) a.style.transform = 'rotate(' + f.heading + 'deg)';
        }
        if (follow) {
            if (firstFix) { map.setView([f.lat, f.lng], 17); firstFix = false; }
            else map.panTo([f.lat, f.lng], { animate: true });
        }
        if (ROUTING_ENABLED && !liveMode && !fetching && Date.now() - lastFetch > 3000) fetchRoute(myPos, true);
        updateProgress();
    }
    window.addEventListener('hopegps:position', function (e) { onPosition(e.detail); });

    /* ---------- go ---------- */
    if (!ROUTING_ENABLED) {
        setBanner('⚑', 'You are on site', 'Waiting for the command center to resolve the incident');
        el('navTime').textContent = el('navDist').textContent = el('navArrive').textContent = '—';
    } else {
        fetchRoute(BASE, false); // instant "suggested route" preview before the phone has a GPS fix
    }
    if (window.HopeGPS && window.HopeGPS.getLast()) onPosition(window.HopeGPS.getLast());
})();
</script>
<?php endif; ?>

</body>
</html>