<![CDATA[
<?php
session_start();

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';

requireRole('manager');
$validTypes = ['Medical Emergency','Vehicular Accident'];
$flash = consumeFlash();
$flashType = consumeFlashType();
$errors  = [];
$success = false;
$vehicleStock = [];
foreach ($pdo->query("SELECT vehicle_type, COUNT(*) AS total, SUM(status = 'Available') AS available
                      FROM ptv_units
                      WHERE archived_at IS NULL AND vehicle_type IS NOT NULL AND vehicle_type <> ''
                      GROUP BY vehicle_type ORDER BY vehicle_type") as $r) {
    $vehicleStock[$r['vehicle_type']] = ['total' => (int)$r['total'], 'available' => (int)$r['available']];
}
$vehicleFleet = array_map(function ($s) { return $s['total']; }, $vehicleStock);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $callerName    = postStr('caller_name', 150);
$callerContact = postStr('caller_contact', 20);
$barangay      = postStr('barangay', 100);
$reportSource = in_array($_POST['report_source'] ?? '', ['Phone Call','Walk-in'], true) ? $_POST['report_source'] : 'Phone Call';
$landmark      = postStr('landmark', 255);
$latitude      = postStr('latitude', 20);
$longitude     = postStr('longitude', 20);
$incidentType  = postStr('incident_type', 50);
$problemNotes  = postStr('problem_notes', 2000);
$resourcesRaw  = is_array($_POST['resources'] ?? null) ? $_POST['resources'] : [];
$severity = postStr('severity', 20);
if (!is_numeric($latitude)  || abs((float)$latitude)  > 90)  $latitude  = '';
if (!is_numeric($longitude) || abs((float)$longitude) > 180) $longitude = '';

    // Fleet caps — must match VEHICLE_FLEET in the JS below.
    

    // Save requested vehicle types even when those vehicles are currently busy.
    $resources = [];
    foreach ($vehicleFleet as $vehicleName => $total) {
        $qty = max(0, (int) ($resourcesRaw[$vehicleName] ?? 0));
        if ($qty > $total) {
            $errors[] = 'Only ' . $total . ' ' . $vehicleName . ' registered (requested ' . $qty . ').';
            continue;
        }
        if ($qty > 0) $resources[$vehicleName] = $qty;
    }
if ($latitude === '' || $longitude === '') $errors[] = 'Drop a map pin on the incident location.';
    if ($callerName === '')    $errors[] = 'Caller name is required.';
    if (!in_array($barangay, purokList(), true)) $errors[] = 'Select a valid Purok from the list.';
if ($incidentType !== '' && !in_array($incidentType, $validTypes, true)) $errors[] = 'Invalid incident type.';
if (!in_array($severity, ['Critical','High','Moderate','Low'], true))    $errors[] = 'Severity is required.';
    
        if (empty($resources) && array_sum($vehicleFleet) > 0) {
        $errors[] = 'Select at least one vehicle type needed for this incident.';
    }

    if (empty($errors)) {
        $clipRef = 'CLIP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
        $resourceParts = [];
        foreach ($resources as $vehicleName => $qty) {
            $resourceParts[] = $vehicleName . ' x' . $qty;
        }
        $resourcesStr = implode(', ', $resourceParts);
        $reportedBy = $_SESSION['user_id'] ?? 1; // fallback if session value is missing
        $reportedByEmail = $_SESSION['email'] ?? 'unknown';

        try {
            $stmt = $pdo->prepare("INSERT INTO clip_reports
    (clip_ref, caller_name, caller_contact, barangay, report_source, landmark, latitude, longitude, incident_type, severity, problem_resources, problem_notes, predicted_eta_minutes, reported_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->execute([
    $clipRef, $callerName, $callerContact, $barangay, $reportSource, $landmark,
    $latitude ?: null, $longitude ?: null, $incidentType, $severity,
    $resourcesStr, $problemNotes,
    ($_POST['eta_minutes'] ?? '') !== '' ? (float)$_POST['eta_minutes'] : null,
    $reportedBy
]);

            if (function_exists('logActivity')) {
                logActivity($pdo, $reportedBy, $reportedByEmail, 'clip_report_created', 'success');
            }

            $success = true;
            $lastClipRef = $clipRef;
        } catch (PDOException $e) {
            error_log('HopeLine incident report could not be saved: ' . $e->getMessage());
            $errors[] = 'The report could not be saved. Check the details and try again.';
        }
    }
    if ($success) {
        redirectAfterPost('clip-report.php', 'Incident ' . $lastClipRef . ' logged successfully. You can now dispatch a unit from Active Incidents.');
    }
    redirectAfterPost('clip-report.php', implode(' ', $errors) ?: 'The incident report could not be submitted.', 'error');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>New CLIP Report — HopeLine</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
  <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/vendor/leaflet/leaflet.css" />
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/vendor/leaflet-routing-machine/leaflet-routing-machine.css" />
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/app.css">
    <style>
      
        .qty-control { display: flex; align-items: center; gap: 8px; }
        .qty-btn {
            width: 30px; height: 30px;
            border: 1px solid #cbd2d9;
            border-radius: 6px;
            background: #fff;
            font-size: 16px;
            line-height: 1;
            cursor: pointer;
        }
        .qty-btn:hover { background: #f2f4f7; }
        #resourceGrid .qty-input {
            display: inline-block !important;
            visibility: visible !important;
            width: 34px !important;
            height: 30px !important;
            box-sizing: border-box !important;
            text-align: center !important;
            border: 1px solid #dfe3e8 !important;
            border-radius: 6px !important;
            background: #ffffff !important;
            color: #111827 !important;
            -webkit-text-fill-color: #111827 !important;
            opacity: 1 !important;
            font-weight: 700 !important;
            font-size: 15px !important;
            font-family: inherit !important;
            padding: 0 !important;
            margin: 0 !important;
            -moz-appearance: textfield;
        }
        .qty-input::-webkit-outer-spin-button,
        .qty-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/../../assets/layouts/manager/manager_sidebar.php'; ?>

<main class="main main-1180">
    <div class="page-head">
        <h1>New CLIP Report</h1>
        <p>Log every caller report using the Caller · Location · Incident · Problem framework.</p>
        <span class="clip-ref-preview" id="clipRefPreview">Reference: generating on submit…</span>
    </div>

    <?php if ($flash): ?>
        <div class="alert-banner alert-<?php echo $flashType === 'error' ? 'error' : 'success'; ?>"><?php echo htmlspecialchars($flash, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php elseif ($success): ?>
        <div class="alert-banner alert-success">
            ✅ CLIP report <strong><?php echo htmlspecialchars($lastClipRef); ?></strong> logged successfully. You can now dispatch a unit from <strong>Active Incidents</strong>.
        </div>
    <?php elseif (!empty($errors)): ?>
        <div class="alert-banner alert-error">
            Please fix the following:
            <ul><?php foreach ($errors as $err): ?><li><?php echo htmlspecialchars($err); ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <form method="POST" id="clipForm">
        <div class="layout">
            <div class="col-main">
                <!-- C: Caller -->
                <div class="card">
                    <div class="card-title"><div class="step-num">C</div><h2>Caller</h2></div>
                    <div class="card-sub">Who is reporting this incident?</div>

                    <div class="field-row">
                        <div class="field">
                            <label for="caller_name">Name of Caller</label>
                            <input type="text" id="caller_name" name="caller_name" placeholder="e.g. Juan Dela Cruz" required>
                        </div>
                        <div class="field">
                            <label for="caller_contact">Contact Number <span class="optional">(for callback/verification)</span></label>
                            <input type="tel" id="caller_contact" name="caller_contact" placeholder="09XX XXX XXXX">
                        </div>
                    </div>
                </div>

                <!-- L: Location -->
                <div class="card">
                    <div class="card-title"><div class="step-num">L</div><h2>Location of the Incident</h2></div>
                    <div class="card-sub">Drop a pin or use GPS for the fastest, most accurate fix.</div>

                    <div class="loc-tools">
                        <button type="button" class="btn-tool" id="btnGeolocate">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/></svg>
                            Use My Location
                        </button>
                        <button type="button" class="btn-tool" id="btnClearPin">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                            Clear Pin
                        </button>
                    </div>

                    <div id="map"></div>
                    <div class="coords-readout" id="coordsReadout">📍 No pin dropped yet — click the map or use GPS.</div>

                    <!-- ETA panel: shows PTV ETA computed from OSRM route -->
                    <div class="eta-panel eta-pending" id="etaPanel">
                        <div class="eta-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                        </div>
                        <div class="eta-main">
                            <div class="eta-label">PTV Estimated Time of Arrival</div>
                            <div class="eta-value" id="etaValue">Select a Purok or drop a pin</div>
                        </div>
                        <div class="eta-breakdown" id="etaBreakdown"></div>
                    </div>

                    <input type="hidden" id="latitude" name="latitude">
                    <input type="hidden" id="longitude" name="longitude">
                    <input type="hidden" id="eta_minutes" name="eta_minutes">

                    <div class="field-row">
                        <div class="field">
    <label for="barangay">Purok <span class="optional" id="barangayAutoTag" style="display:none;">(auto-detected from pin)</span></label>
    <select id="barangay" name="barangay" required>
        <option value="">Select a Purok</option>
        <?php foreach (purokList() as $purok): ?>
            <option value="<?php echo htmlspecialchars($purok, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($purok, ENT_QUOTES, 'UTF-8'); ?></option>
        <?php endforeach; ?>
    </select>
</div>
                    <div class="field">
  <label>Report received via</label>
  <div class="incident-grid" style="grid-template-columns:repeat(2,minmax(0,1fr));">
    <div class="incident-option"><input type="radio" name="report_source" id="src_phone" value="Phone Call" checked><label for="src_phone">📞 Phone Call</label></div>
    <div class="incident-option"><input type="radio" name="report_source" id="src_walk" value="Walk-in"><label for="src_walk">🚶 Walk-in</label></div>
  </div>
</div>    
                    </div>

                    <div class="field">
                        <label for="landmark">Additional Location Details <span class="optional">(nearest landmark, house color, road, etc.)</span></label>
                        <textarea id="landmark" name="landmark" placeholder="e.g. Beside Mercury Drug, blue two-story house, 50m from the covered court"></textarea>
                    </div>
                </div>

                <!-- I: Incident -->
                <div class="card">
                    <div class="card-title"><div class="step-num">I</div><h2>Incident</h2></div>
                  <div class="card-sub">What kind of incident is being reported?</div>

<div class="incident-grid" style="grid-template-columns:repeat(2,minmax(0,1fr));">
    <div class="incident-option">
        <input type="radio" name="incident_type" id="inc_medical" value="Medical Emergency" required>
        <label for="inc_medical">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M2 12h20"/></svg>
            Medical Emergency
        </label>
    </div>
    <div class="incident-option">
        <input type="radio" name="incident_type" id="inc_vehicular" value="Vehicular Accident">
        <label for="inc_vehicular">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 17h14M5 17a2 2 0 1 0 4 0M15 17a2 2 0 1 0 4 0M3 17V9l2-5h14l2 5v8"/></svg>
            Vehicular Accident
        </label>
    </div>
</div><!-- /.incident-grid -->

<div class="severity-wrap show">
    <label>Severity Level <span class="optional">(auto-suggested from incident type, adjustable)</span></label>
    <div class="severity-grid">
        <div class="sev-option sev-critical"><input type="radio" name="severity" id="sev_critical" value="Critical" required><label for="sev_critical">Critical</label></div>
        <div class="sev-option sev-high"><input type="radio" name="severity" id="sev_high" value="High"><label for="sev_high">High</label></div>
        <div class="sev-option sev-moderate"><input type="radio" name="severity" id="sev_moderate" value="Moderate"><label for="sev_moderate">Moderate</label></div>
                <div class="sev-option sev-low"><input type="radio" name="severity" id="sev_low" value="Low"><label for="sev_low">Low</label></div>
    </div><!-- /.severity-grid -->
</div><!-- /.severity-wrap -->
</div><!-- /Incident .card -->
</div><!-- /.col-main -->

<div class="col-side">


<!-- P: Problem -->
<div class="card">
    <div class="card-title"><div class="step-num">P</div><h2>Problem</h2></div>
    <div class="card-sub">What does the caller need from LDRRMO? (auto-suggested from incident type — adjust as needed)</div>

    <div class="resource-grid" id="resourceGrid"></div>

    <div class="field">
        <label for="problem_notes">Additional Notes <span class="optional">(optional)</span></label>
        <textarea id="problem_notes" name="problem_notes" placeholder="Any other details the responding team should know"></textarea>
    </div>
</div>

<!-- Live summary -->
 <div class="side-sticky">
<div class="summary-card">
    <h3>Report Summary</h3>

    <div class="summary-row">
        <div class="label">Caller</div>
        <div class="value empty" id="sumCaller">Not filled yet</div>
    </div>
    <div class="summary-row">
        <div class="label">Location</div>
        <div class="value empty" id="sumLocation">Not filled yet</div>
    </div>
    <div class="summary-row">
        <div class="label">PTV ETA</div>
        <div class="value empty" id="sumEta">Not calculated yet</div>
    </div>
    <div class="summary-row">
        <div class="label">Incident</div>
        <div class="value empty" id="sumIncident">Not filled yet</div>
    </div>
    <div class="summary-row">
        <div class="label">Resources Needed</div>
        <div class="value empty" id="sumResources">Not filled yet</div>
    </div>

    <ul class="checklist" id="checklist">
        <li id="chkCaller"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg> Caller name</li>
       <li id="chkLocation"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg> Purok selected</li>
        <li id="chkPin"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg> Map pin dropped</li>
        <li id="chkIncident"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg> Incident type</li>
        <li id="chkResources"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg> Resource(s) selected</li>
    </ul>
</div>

<div class="submit-row">
    <button type="submit" id="submitBtn">Log CLIP Report</button>
</div>
</div><!-- /.side-sticky -->
            </div><!-- /.col-side -->
        </div><!-- /.layout -->
    </form>
</main>

<script src="<?php echo BASE_URL; ?>/assets/vendor/leaflet/leaflet.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/vendor/leaflet-routing-machine/leaflet-routing-machine.min.js"></script>
<script>
    // ---------- Map setup ----------
    const defaultCenter = [8.3736, 124.8694]; // Tankulan, Manolo Fortich
    const map = L.map('map').setView(defaultCenter, 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; OpenStreetMap contributors',
        maxZoom: 19
    }).addTo(map);

    let marker = null;
    const latInput = document.getElementById('latitude');
    const lngInput = document.getElementById('longitude');
    const coordsReadout = document.getElementById('coordsReadout');

    // LDRRMO Manolo Fortich office — confirmed PTV dispatch origin (Point A).
    const PTV_BASE = { lat: 8.371714652741774, lng: 124.85717564826615, label: 'LDRRMO Manolo Fortich (PTV Base)' };

    // OSRM HTTP routing service. Point this at your self-hosted OSRM
    // instance (see Docker/osrm-backend setup) for production use — the
    // public demo server is rate-limited and not reliable for a live
    // dispatch system.
    const OSRM_SERVICE_URL = 'https://router.project-osrm.org/route/v1';

    // ETA formula constants: ETA = Σ(distance / speed) + delays
    const AVERAGE_SPEED_KMH = 40;
    const DISPATCH_DELAY_MIN = 5;
function haversineDistance(lat1, lng1, lat2, lng2) {
    const R = 6371; // km
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLng = (lng2 - lng1) * Math.PI / 180;
    const a = Math.sin(dLat / 2) ** 2 +
              Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
              Math.sin(dLng / 2) ** 2;
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

function findNearestBarangay(lat, lng) {
    let nearest = null;
    let minDist = Infinity;
    for (const [name, coords] of Object.entries(barangayCoords)) {
        const dist = haversineDistance(lat, lng, coords.lat, coords.lng);
        if (dist < minDist) { minDist = dist; nearest = name; }
    }
    // Known Tankulan-area suggestions are only approximate; never label a
    // pin in a more distant location as one of these by default.
    return minDist <= 3 ? nearest : null;
}
    // Approximate barangay centers for Manolo Fortich, Bukidnon.
    // ⚠️ PLACEHOLDER COORDINATES — verify against LGU/LDRRMO GIS data
    // before using this for real dispatch.
    const barangayCoords = {
    'Kihare':           { lat: 8.359970, lng: 124.865290 },
    'Lower Kalanawan':  { lat: 8.3720, lng: 124.8680 },
    'Lower Pol-ton':    { lat: 8.374958, lng: 124.865291 },
    'Lower Sosohon':    { lat: 8.376105, lng: 124.874424 },
    'Mangima':          { lat: 8.376699, lng: 124.881113 },
    'Saint Joseph':     { lat: 8.365037, lng: 124.867884 },
    'Upper Kalanawan':  { lat: 8.371148, lng: 124.856005 },
    'Upper Pol-ton':    { lat: 8.371392, lng: 124.865464 },
    'Upper Sosohon':    { lat: 8.368717, lng: 124.868770 },
    'Mullberry Village':{ lat: 8.364938, lng: 124.861387 }
};

    let routingControl = null;
    let lastRouteDestination = null;
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
// in option.innerHTML:  esc(vehicleName) in resource-name, and name="resources[' + esc(vehicleName) + ']"
    // ---------- Vehicle fleet + Problem resource grid ----------
    // Total registered units by type; requests can be saved while units are busy.
    const VEHICLE_STOCK = <?php echo json_encode($vehicleStock, JSON_HEX_TAG | JSON_HEX_AMP); ?>;
const VEHICLE_FLEET = {};
Object.keys(VEHICLE_STOCK).forEach(k => VEHICLE_FLEET[k] = VEHICLE_STOCK[k].total);

    const resourceGrid = document.getElementById('resourceGrid');

    Object.keys(VEHICLE_FLEET).forEach(vehicleName => {
        const avail = VEHICLE_STOCK[vehicleName].available;
        const total = VEHICLE_STOCK[vehicleName].total;
        const max = total;
        const option = document.createElement('div');
        option.className = 'resource-option' + (avail === 0 ? ' unavailable' : '');
        option.dataset.value = vehicleName;
        option.dataset.max = max;

        option.innerHTML =
            '<div class="resource-info">' +
                '<span class="resource-name">' + vehicleName + '</span>' +
                             '<span class="resource-available"><span><strong>' + avail + '</strong> of ' + total + ' available' + (avail === 0 ? ' · none free' : '') + '</span></span>' +
            '</div>' +
            '<div class="qty-control">' +
                '<button type="button" class="qty-btn qty-minus" aria-label="Decrease"' + (total === 0 ? ' disabled' : '') + '>−</button>' +
                '<input type="text" inputmode="numeric" pattern="[0-9]*" class="qty-input" name="resources[' + vehicleName + ']" value="0" readonly>' +
                '<button type="button" class="qty-btn qty-plus" aria-label="Increase"' + (total === 0 ? ' disabled' : '') + '>+</button>' +
            '</div>';

        resourceGrid.appendChild(option);
    });
    if (!Object.keys(VEHICLE_STOCK).some(k => VEHICLE_STOCK[k].available > 0)) {
        const note = document.createElement('div');
        note.className = 'alert-banner';
        note.style.cssText = 'background:rgba(217,117,43,.14);border:1px solid #d9752b;color:var(--high)';
        note.textContent = 'No response units are free right now. You can still log this report; it will remain pending until a unit can be dispatched.';
        resourceGrid.before(note);
    }
    function setVehicleQty(vehicleName, qty) {
        const option = document.querySelector('.resource-option[data-value="' + CSS.escape(vehicleName) + '"]');
        if (!option) return;
        const max = parseInt(option.dataset.max, 10);
        const input = option.querySelector('.qty-input');
        const clamped = Math.max(0, Math.min(max, qty));
        input.value = clamped;
        option.classList.toggle('has-qty', clamped > 0);
        return clamped;
    }

    function getVehicleQty(vehicleName) {
        const option = document.querySelector('.resource-option[data-value="' + CSS.escape(vehicleName) + '"]');
        if (!option) return 0;
        return parseInt(option.querySelector('.qty-input').value, 10) || 0;
    }

    resourceGrid.addEventListener('click', (e) => {
        const btn = e.target.closest('.qty-btn');
        if (!btn) return;

        const option = btn.closest('.resource-option');
        const input = option.querySelector('.qty-input');
        const max = parseInt(option.dataset.max, 10);
        let val = parseInt(input.value, 10) || 0;

        if (btn.classList.contains('qty-plus')) val = Math.min(max, val + 1);
        if (btn.classList.contains('qty-minus')) val = Math.max(0, val - 1);

        input.value = val;
        option.classList.toggle('has-qty', val > 0);
        // Manually-adjusted qty overrides any auto-suggestion tag.
        const tag = option.querySelector('.suggest-tag');
        if (tag) tag.remove();
        option.classList.remove('suggested');

        updateSummary();
    });

    const etaPanel = document.getElementById('etaPanel');
    const etaValueEl = document.getElementById('etaValue');
    const etaBreakdownEl = document.getElementById('etaBreakdown');
    const etaMinutesInput = document.getElementById('eta_minutes');

    function setEtaPending(message) {
        etaPanel.classList.add('eta-pending');
        etaValueEl.textContent = message;
        etaBreakdownEl.innerHTML = '';
        etaMinutesInput.value = '';
        setSummary('sumEta', '');
    }

    function formatEta(totalMinutes) {
        const mins = Math.round(totalMinutes);
        if (mins >= 60) {
            const h = Math.floor(mins / 60);
            const m = mins % 60;
            return h + 'h ' + m + 'm';
        }
        return mins + ' min';
    }

    function calculateRoute(destLat, destLng) {
        lastRouteDestination = { lat: destLat, lng: destLng };

        if (routingControl) {
            map.removeControl(routingControl);
            routingControl = null;
        }

        etaValueEl.textContent = 'Calculating route…';

        routingControl = L.Routing.control({
            waypoints: [
                L.latLng(PTV_BASE.lat, PTV_BASE.lng),
                L.latLng(destLat, destLng)
            ],
            router: L.Routing.osrmv1({
                serviceUrl: OSRM_SERVICE_URL,
                profile: 'driving'
            }),
            lineOptions: {
                styles: [
                    { color: '#ffffff', weight: 9, opacity: 0.35 },
                    { color: '#d9752b', weight: 5, opacity: 0.9 }
                ]
            },
            createMarker: function(i, wp) {
                if (i === 0) {
                    return L.circleMarker(wp.latLng, {
                        radius: 7,
                        color: '#113047',
                        weight: 2,
                        fillColor: '#739ab9',
                        fillOpacity: 1
                    }).bindTooltip(PTV_BASE.label, { permanent: false });
                }
                return null;
            },
            addWaypoints: false,
            draggableWaypoints: false,
            fitSelectedRoutes: true,
            show: false
        }).on('routesfound', async function (e) {
    const route = e.routes[0];
    const distanceKm = route.summary.totalDistance / 1000;
    const travelMinutes = (distanceKm / AVERAGE_SPEED_KMH) * 60;

    // Fetch dynamic delays from backend
    let weatherDelay = 0;
    let rushDelay = 0;
    let weatherCond = 'Clear';

    try {
        const res = await fetch(`../../api/delay-factors.php?lat=${destLat}&lng=${destLng}`);
        const data = await res.json();
        if (data.ok) {
            weatherDelay = data.weather_delay_min;
            rushDelay = data.rush_delay_min;
            weatherCond = data.weather_condition;
        }
    } catch (err) {
        console.warn('Delay factors offline, using standard ETA');
    }

    const totalMinutes = travelMinutes + DISPATCH_DELAY_MIN + weatherDelay + rushDelay;

    etaPanel.classList.remove('eta-pending');
    etaValueEl.innerHTML = formatEta(totalMinutes) + ' <span>estimated arrival</span>';
    etaBreakdownEl.innerHTML =
        'Distance: <strong>' + distanceKm.toFixed(2) + ' km</strong> · ' +
        'Travel: <strong>' + travelMinutes.toFixed(1) + ' min</strong> · ' +
        'Dispatch: <strong>+' + DISPATCH_DELAY_MIN + ' min</strong>' +
        (weatherDelay > 0 ? ` · Weather (${weatherCond}): <strong>+${weatherDelay} min</strong>` : '') +
        (rushDelay > 0 ? ` · Rush hour: <strong>+${rushDelay} min</strong>` : '');

                etaMinutesInput.value = totalMinutes.toFixed(1);
            setSummary('sumEta', formatEta(totalMinutes));
            document.getElementById('sumEta').classList.add('eta-highlight');
        }).on('routingerror', function () {
            etaPanel.classList.add('eta-pending');
            etaValueEl.textContent = 'Route unavailable — check connection to OSRM.';
            etaBreakdownEl.innerHTML = '';
        }).addTo(map);
    } // <-- closed calculateRoute

        function dropPin(lat, lng, keepPurok) {
        if (marker) map.removeLayer(marker);
        marker = L.marker([lat, lng], { draggable: true }).addTo(map);
        marker.on('dragend', () => {
            const pos = marker.getLatLng();
            setCoords(pos.lat, pos.lng);
        });
        map.setView([lat, lng], 15);
        setCoords(lat, lng, keepPurok);
    }

     function setCoords(lat, lng, keepPurok) {
    latInput.value = lat.toFixed(6);
    lngInput.value = lng.toFixed(6);
    coordsReadout.innerHTML = '📍 <strong>' + lat.toFixed(6) + ', ' + lng.toFixed(6) + '</strong> — pin set';

    if (!keepPurok) {   // only auto-detect the purok when the pin was placed by hand/GPS
        const barangaySelect = document.getElementById('barangay');
        const nearest = findNearestBarangay(lat, lng);
        if (nearest) {
            barangaySelect.value = nearest;
            document.getElementById('barangayAutoTag').style.display = 'inline';
        }
    }

    updateSummary();
    calculateRoute(lat, lng);
}


    map.on('click', (e) => dropPin(e.latlng.lat, e.latlng.lng));

    document.getElementById('btnGeolocate').addEventListener('click', () => {
        if (!navigator.geolocation) {
            alert('Geolocation is not supported by this browser.');
            return;
        }
        coordsReadout.textContent = '📡 Getting current location…';
        navigator.geolocation.getCurrentPosition(
            (pos) => dropPin(pos.coords.latitude, pos.coords.longitude),
            () => { coordsReadout.textContent = '⚠️ Could not get location — drop a pin manually.'; }
        );
    });

    document.getElementById('btnClearPin').addEventListener('click', () => {
        if (marker) { map.removeLayer(marker); marker = null; }
        latInput.value = ''; lngInput.value = '';
        coordsReadout.textContent = '📍 No pin dropped yet — click the map or use GPS.';
        if (routingControl) { map.removeControl(routingControl); routingControl = null; }
        setEtaPending('Select a barangay or drop a pin');
        updateSummary();
    });

        document.getElementById('barangay').addEventListener('change', function () {
        document.getElementById('barangayAutoTag').style.display = 'none';
        const coords = barangayCoords[this.value];
        if (!coords) return;
        dropPin(coords.lat, coords.lng, true);
    });

    // ---------- Incident type -> resource auto-suggest ----------
    // Values are the suggested QUANTITY to pre-fill for that vehicle (capped
    // to what's available in VEHICLE_FLEET automatically).
    const suggestionMap = {
        'Medical Emergency':   { 'Ambulance': 1, 'Patient Transport Vehicle (PTV)': 1 },
        'Vehicular Accident':  { 'Ambulance': 1, 'Patient Transport Vehicle (PTV)': 1 },
    };

    document.querySelectorAll('input[name="incident_type"]').forEach(radio => {
        radio.addEventListener('change', () => {
            const sevDefault = {'Medical Emergency':'High','Vehicular Accident':'High'};
const sv = document.getElementById('sev_' + (sevDefault[radio.value] || 'Moderate').toLowerCase());
if (sv) sv.checked = true;
            document.querySelectorAll('.resource-option').forEach(opt => {
                opt.classList.remove('suggested');
                const tag = opt.querySelector('.suggest-tag');
                if (tag) tag.remove();
                setVehicleQty(opt.dataset.value, 0);
            });

            const suggested = suggestionMap[radio.value] || {};
            Object.keys(suggested).forEach(vehicleName => {
                const opt = document.querySelector('.resource-option[data-value="' + vehicleName + '"]');
                               if (opt && parseInt(opt.dataset.max, 10) > 0) {
                    setVehicleQty(vehicleName, suggested[vehicleName]);
                    opt.classList.add('suggested');
                    const tag = document.createElement('span');
                    tag.className = 'suggest-tag';
                    tag.textContent = 'Suggested';
                    opt.querySelector('.resource-info').appendChild(tag);
                }
            });

            updateSummary();
        });
    });

    // ---------- Live summary panel ----------
    function updateSummary() {
        const callerName = document.getElementById('caller_name').value.trim();
        setSummary('sumCaller', callerName);
        toggleCheck('chkCaller', callerName !== '');

        const barangay = document.getElementById('barangay').value;
        const locStr = barangay;
        setSummary('sumLocation', locStr);
        toggleCheck('chkLocation', barangay !== '');
        toggleCheck('chkPin', latInput.value !== '');

        const incidentRadio = document.querySelector('input[name="incident_type"]:checked');
        setSummary('sumIncident', incidentRadio ? incidentRadio.value : '');
        toggleCheck('chkIncident', !!incidentRadio);

               const resourceParts = [];
        Object.keys(VEHICLE_FLEET).forEach(vehicleName => {
            const qty = getVehicleQty(vehicleName);
            if (qty > 0) resourceParts.push(vehicleName + ' x' + qty);
        });
        setSummary('sumResources', resourceParts.join(', '));
        toggleCheck('chkResources', resourceParts.length > 0);
    }

    function setSummary(id, text) {
        const el = document.getElementById(id);
        if (text) { el.textContent = text; el.classList.remove('empty'); }
        else { el.textContent = (id === 'sumEta' ? 'Not calculated yet' : 'Not filled yet'); el.classList.add('empty'); }
    }

    function toggleCheck(id, done) {
        const el = document.getElementById(id);
        el.classList.toggle('done', done);
    }

    ['caller_name','barangay'].forEach(id => {
        document.getElementById(id).addEventListener('input', updateSummary);
        document.getElementById(id).addEventListener('change', updateSummary);
    });
    // Vehicle quantity changes are already handled by the qty-btn click
    // listener on #resourceGrid, which calls updateSummary() itself.

    // ---------- CLIP reference preview ----------
    const today = new Date();
    const y = today.getFullYear();
    const m = String(today.getMonth() + 1).padStart(2, '0');
    const d = String(today.getDate()).padStart(2, '0');
    document.getElementById('clipRefPreview').textContent =
        'Reference: CLIP-' + y + m + d + '-XXXXX (assigned on submit)';

    updateSummary();
    document.getElementById('clipForm').addEventListener('submit', function (e) {
    if (!latInput.value) { e.preventDefault(); alert('Drop a pin on the map first.'); }
});
</script>

</body>
</html>

]]>