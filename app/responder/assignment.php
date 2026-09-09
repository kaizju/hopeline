<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';

requireRole('user');

$unitStmt = $pdo->prepare("SELECT * FROM ptv_units WHERE responder_id = ? LIMIT 1");

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
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/app.css">

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