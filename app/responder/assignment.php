<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';

// Get this responder's assigned PTV unit
$unitStmt = $pdo->prepare("SELECT * FROM ptv_units WHERE responder_id = ? LIMIT 1");
$unitStmt->execute([$_SESSION['user_id']]);
$unit = $unitStmt->fetch(PDO::FETCH_ASSOC);
$unitStatus = $unit['status'] ?? 'Available';

// Get the active dispatch (if any) for this unit
$dispatch = null;
if ($unit) {
    $dStmt = $pdo->prepare("
        SELECT d.*, c.clip_ref, c.caller_name, c.caller_contact, c.barangay, c.sitio_purok,
               c.landmark, c.latitude, c.longitude, c.incident_type, c.severity, c.problem_resources, c.problem_notes
        FROM dispatch d
        JOIN clip_reports c ON c.id = d.clip_report_id
        WHERE d.unit_id = ? AND d.status IN ('assigned','en_route','on_site')
        ORDER BY d.dispatched_at DESC
        LIMIT 1
    ");
    $dStmt->execute([$unit['id']]);
    $dispatch = $dStmt->fetch(PDO::FETCH_ASSOC);
}

$unreadAlerts = 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Assigned Incident — HopeLine</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/sidebar.css">
<style>
    :root {
        --burnt-umber:#6d120b; --redwood:#b02029; --macadamia:#fbf0d8;
        --cool-blue:#113047; --light-grayish:#739ab9;
        --critical:#b02029; --high:#d9752b; --moderate:#d4ab2b; --low:#3f7a5c;
    }
    * { box-sizing:border-box; margin:0; padding:0; }
    body { font-family:'Segoe UI',-apple-system,BlinkMacSystemFont,sans-serif; background:#0c2334; display:flex; min-height:100vh; }
    .main { flex:1; padding:26px 32px 50px; color:var(--macadamia); max-width:900px; }
    .page-head { margin-bottom:20px; }
    .page-head h1 { font-size:21px; margin-bottom:4px; }
    .page-head p { color:var(--light-grayish); font-size:13px; }

    .card { background:rgba(251,240,216,0.04); border:1px solid rgba(115,154,185,0.18); border-radius:12px; padding:22px 24px; margin-bottom:18px; }

    .incident-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; flex-wrap:wrap; gap:10px; }
    .sev-badge { font-size:11px; font-weight:700; text-transform:uppercase; padding:4px 12px; border-radius:20px; letter-spacing:0.4px; }
    .sev-Critical { background:rgba(176,32,41,0.2); color:var(--critical); }
    .sev-High { background:rgba(217,117,43,0.2); color:var(--high); }
    .sev-Moderate { background:rgba(212,171,43,0.2); color:var(--moderate); }
    .sev-Low { background:rgba(63,122,92,0.2); color:var(--low); }

    .incident-title { font-size:18px; font-weight:700; }
    .clip-ref { font-size:11px; color:var(--light-grayish); font-family:monospace; margin-top:2px; }

    .info-grid { display:grid; grid-template-columns: 1fr 1fr; gap:16px; margin-bottom:18px; }
    .info-block .label { font-size:10.5px; text-transform:uppercase; letter-spacing:0.4px; color:var(--light-grayish); margin-bottom:4px; }
    .info-block .value { font-size:13.5px; color:var(--macadamia); font-weight:600; }
    .info-block.full { grid-column: 1 / -1; }

    #map { height:220px; border-radius:8px; margin-bottom:18px; border:1px solid rgba(115,154,185,0.3); }

    .resources-list { display:flex; gap:6px; flex-wrap:wrap; }
    .resource-tag { background:rgba(115,154,185,0.15); color:var(--macadamia); font-size:11.5px; padding:4px 10px; border-radius:20px; }

    .cta-btn {
        display:block; text-align:center; width:100%; padding:14px; border-radius:10px;
        background:var(--burnt-umber); color:var(--macadamia); font-weight:700; font-size:14.5px;
        text-decoration:none; margin-top:6px; transition:background 0.15s;
    }
    .cta-btn:hover { background:var(--redwood); }

    .call-btn {
        display:inline-flex; align-items:center; gap:6px; background:rgba(115,154,185,0.15);
        color:var(--macadamia); font-size:12.5px; font-weight:600; padding:8px 14px; border-radius:20px; text-decoration:none;
    }
    .call-btn svg { width:14px; height:14px; }

    .empty-state {
        text-align:center; padding:70px 20px; color:var(--light-grayish);
    }
    .empty-state svg { width:44px; height:44px; margin-bottom:14px; opacity:0.5; }
    .empty-state h2 { font-size:16px; color:var(--macadamia); margin-bottom:6px; }
    .empty-state p { font-size:13px; }

    .status-strip { font-size:11px; color:var(--light-grayish); margin-bottom:16px; display:flex; align-items:center; gap:6px; }
    .status-strip .dot { width:8px; height:8px; border-radius:50%; background:#3f7a5c; }
</style>
</head>
<body>

<?php require_once __DIR__ . '/../../assets/layouts/responder/responder_sidebar.php'; ?>

<main class="main">
    <div class="page-head">
        <h1>Assigned Incident</h1>
        <p>Your current dispatch details.</p>
    </div>

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

        <div class="card">
            <div class="incident-header">
                <div>
                    <div class="incident-title"><?php echo htmlspecialchars($dispatch['incident_type']); ?> — <?php echo htmlspecialchars($dispatch['barangay']); ?></div>
                    <div class="clip-ref"><?php echo htmlspecialchars($dispatch['clip_ref']); ?></div>
                </div>
                <span class="sev-badge sev-<?php echo $dispatch['severity']; ?>"><?php echo $dispatch['severity']; ?></span>
            </div>

            <?php if ($dispatch['latitude'] && $dispatch['longitude']): ?>
                <div id="map"></div>
            <?php endif; ?>

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

            <a href="<?php echo BASE_URL; ?>/app/responder/eta-log.php" class="cta-btn">
                <?php
                if ($dispatch['status'] === 'assigned') echo 'Go to Depart / Arrive Log →';
                elseif ($dispatch['status'] === 'en_route') echo 'Mark Arrived at Site →';
                else echo 'View Dispatch Status →';
                ?>
            </a>
        </div>
    <?php endif; ?>
</main>

<?php if (!empty($dispatch['latitude']) && !empty($dispatch['longitude'])): ?>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
    const map = L.map('map').setView([<?php echo $dispatch['latitude']; ?>, <?php echo $dispatch['longitude']; ?>], 15);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap contributors', maxZoom: 19 }).addTo(map);
    L.marker([<?php echo $dispatch['latitude']; ?>, <?php echo $dispatch['longitude']; ?>]).addTo(map)
        .bindPopup('<?php echo htmlspecialchars(addslashes($dispatch['barangay'])); ?>').openPopup();
</script>
<?php endif; ?>

</body>
</html>