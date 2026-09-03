<?php
session_start();

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';

$errors  = [];
$success = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $callerName    = trim($_POST['caller_name'] ?? '');
    $callerContact = trim($_POST['caller_contact'] ?? '');
    $barangay      = trim($_POST['barangay'] ?? '');
    $sitioPurok    = trim($_POST['sitio_purok'] ?? '');
    $landmark      = trim($_POST['landmark'] ?? '');
    $latitude      = trim($_POST['latitude'] ?? '');
    $longitude     = trim($_POST['longitude'] ?? '');
    $incidentType  = trim($_POST['incident_type'] ?? '');
    $severity      = trim($_POST['severity'] ?? '');
    $resources     = $_POST['resources'] ?? [];
    $problemNotes  = trim($_POST['problem_notes'] ?? '');

    if ($callerName === '')    $errors[] = 'Caller name is required.';
    if ($barangay === '')      $errors[] = 'Barangay is required.';
    if ($incidentType === '')  $errors[] = 'Incident type is required.';
    if ($severity === '')      $errors[] = 'Severity classification is required.';
    if (empty($resources))     $errors[] = 'At least one resource/problem needed must be selected.';

    if (empty($errors)) {
        $clipRef = 'CLIP-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
        $resourcesStr = implode(', ', $resources);
        $reportedBy = $_SESSION['user_id'] ?? 1; // fallback if session value is missing
        $reportedByEmail = $_SESSION['email'] ?? 'unknown';

        try {
            $stmt = $pdo->prepare("INSERT INTO clip_reports
                (clip_ref, caller_name, caller_contact, barangay, sitio_purok, landmark, latitude, longitude, incident_type, severity, problem_resources, problem_notes, reported_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $clipRef, $callerName, $callerContact, $barangay, $sitioPurok, $landmark,
                $latitude ?: null, $longitude ?: null, $incidentType, $severity,
                $resourcesStr, $problemNotes, $reportedBy
            ]);

            if (function_exists('logActivity')) {
                logActivity($pdo, $reportedBy, $reportedByEmail, 'clip_report_created', 'success');
            }

            $success = true;
            $lastClipRef = $clipRef;
        } catch (PDOException $e) {
            $errors[] = 'Failed to save report: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>New CLIP Report — HopeLine</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.css" />
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/manager.css">
</head>
<body>

<?php require_once __DIR__ . '/../../assets/layouts/manager/manager_sidebar.php'; ?>

<main class="main main-1180">
    <div class="page-head">
        <h1>New CLIP Report</h1>
        <p>Log every caller report using the Caller · Location · Incident · Problem framework.</p>
        <span class="clip-ref-preview" id="clipRefPreview">Reference: generating on submit…</span>
    </div>

    <?php if ($success): ?>
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
            <div>
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
                            <div class="eta-value" id="etaValue">Select a barangay or drop a pin</div>
                        </div>
                        <div class="eta-breakdown" id="etaBreakdown"></div>
                    </div>

                    <input type="hidden" id="latitude" name="latitude">
                    <input type="hidden" id="longitude" name="longitude">
                    <input type="hidden" id="eta_minutes" name="eta_minutes">

                    <div class="field-row">
                        <div class="field">
                            <label for="barangay">Barangay</label>
                            <select id="barangay" name="barangay" required>
                                <option value="">Select barangay…</option>
                                <option>Agusan Canyon</option>
                                <option>Alae</option>
                                <option>Dahilayan</option>
                                <option>Damilag</option>
                                <option>Dalirig</option>
                                <option>Diclum</option>
                                <option>Guilang-guilang</option>
                                <option>Kalugmanan</option>
                                <option>Lindaban</option>
                                <option>Lingion</option>
                                <option>Lunocan</option>
                                <option>Maluko</option>
                                <option>Mambatangan</option>
                                <option>Mampayag</option>
                                <option>Minsuro</option>
                                <option>San Miguel</option>
                                <option>Sankanan</option>
                                <option>Santiago</option>
                                <option>Tankulan (Poblacion)</option>
                                <option>Ticala</option>
                            </select>
                        </div>
                        <div class="field">
                            <label for="sitio_purok">Sitio / Purok <span class="optional">(optional)</span></label>
                            <input type="text" id="sitio_purok" name="sitio_purok" placeholder="e.g. Purok 3">
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

                    <div class="incident-grid" id="incidentGrid">
                        <div class="incident-option">
                            <input type="radio" name="incident_type" id="inc_medical" value="Medical Emergency" required>
                            <label for="inc_medical">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M2 12h20"/></svg>
                                Medical Emergency
                            </label>
                        </div>
                        <div class="incident-option">
                            <input type="radio" name="incident_type" id="inc_fire" value="Fire">
                            <label for="inc_fire">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2s5 5.5 5 10a5 5 0 0 1-10 0c0-1.5 1-2.5 1-2.5s.5 1.5 1.5 1.5c1.5 0 1-2 0-4C8.5 5 12 2 12 2z"/></svg>
                                Fire
                            </label>
                        </div>
                        <div class="incident-option">
                            <input type="radio" name="incident_type" id="inc_vehicular" value="Vehicular Accident">
                            <label for="inc_vehicular">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 17h14M5 17a2 2 0 1 0 4 0M15 17a2 2 0 1 0 4 0M3 17V9l2-5h14l2 5v8"/></svg>
                                Vehicular Accident
                            </label>
                        </div>
                        <div class="incident-option">
                            <input type="radio" name="incident_type" id="inc_flood" value="Flood / Landslide">
                            <label for="inc_flood">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s2-3 5-3 4 2 7 2 5-3 5-3M2 18s2-3 5-3 4 2 7 2 5-3 5-3"/></svg>
                                Flood / Landslide
                            </label>
                        </div>
                        <div class="incident-option">
                            <input type="radio" name="incident_type" id="inc_assault" value="Violence / Assault">
                            <label for="inc_assault">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
                                Violence / Assault
                            </label>
                        </div>
                        <div class="incident-option">
                            <input type="radio" name="incident_type" id="inc_other" value="Other">
                            <label for="inc_other">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/></svg>
                                Other
                            </label>
                        </div>
                    </div>

                    <div class="severity-wrap" id="severityWrap">
                        <label style="margin-bottom:10px;">Severity Classification</label>
                        <div class="severity-grid">
                            <div class="sev-option sev-critical">
                                <input type="radio" name="severity" id="sev_critical" value="Critical" required>
                                <label for="sev_critical">🔴 Critical</label>
                            </div>
                            <div class="sev-option sev-high">
                                <input type="radio" name="severity" id="sev_high" value="High">
                                <label for="sev_high">🟠 High</label>
                            </div>
                            <div class="sev-option sev-moderate">
                                <input type="radio" name="severity" id="sev_moderate" value="Moderate">
                                <label for="sev_moderate">🟡 Moderate</label>
                            </div>
                            <div class="sev-option sev-low">
                                <input type="radio" name="severity" id="sev_low" value="Low">
                                <label for="sev_low">🟢 Low</label>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- P: Problem -->
                <div class="card">
                    <div class="card-title"><div class="step-num">P</div><h2>Problem</h2></div>
                    <div class="card-sub">What does the caller need from LDRRMO? (auto-suggested from incident type — adjust as needed)</div>

                    <div class="resource-grid" id="resourceGrid">
                        <label class="resource-option" data-value="Ambulance / PTV">
                            <input type="checkbox" name="resources[]" value="Ambulance / PTV"> Ambulance / PTV
                        </label>
                        <label class="resource-option" data-value="Fire Truck">
                            <input type="checkbox" name="resources[]" value="Fire Truck"> Fire Truck
                        </label>
                        <label class="resource-option" data-value="Rescue Team">
                            <input type="checkbox" name="resources[]" value="Rescue Team"> Rescue Team
                        </label>
                        <label class="resource-option" data-value="Extraction Team">
                            <input type="checkbox" name="resources[]" value="Extraction Team"> Extraction Team
                        </label>
                        <label class="resource-option" data-value="Water Rescue / Rubber Boat">
                            <input type="checkbox" name="resources[]" value="Water Rescue / Rubber Boat"> Water Rescue / Boat
                        </label>
                        <label class="resource-option" data-value="Police Assistance">
                            <input type="checkbox" name="resources[]" value="Police Assistance"> Police Assistance
                        </label>
                    </div>

                    <div class="field">
                        <label for="problem_notes">Additional Notes <span class="optional">(optional)</span></label>
                        <textarea id="problem_notes" name="problem_notes" placeholder="Any other details the responding team should know"></textarea>
                    </div>
                </div>

                <div class="submit-row">
                    <button type="submit" id="submitBtn">Log CLIP Report</button>
                </div>
            </div>

            <!-- Live summary -->
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
                    <div class="label">Severity</div>
                    <div class="value empty" id="sumSeverity">Not filled yet</div>
                </div>
                <div class="summary-row">
                    <div class="label">Resources Needed</div>
                    <div class="value empty" id="sumResources">Not filled yet</div>
                </div>

                <ul class="checklist" id="checklist">
                    <li id="chkCaller"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg> Caller name</li>
                    <li id="chkLocation"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg> Barangay selected</li>
                    <li id="chkPin"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg> Map pin dropped</li>
                    <li id="chkIncident"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg> Incident type + severity</li>
                    <li id="chkResources"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg> Resource(s) selected</li>
                </ul>
            </div>
        </div>
    </form>
</main>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet-routing-machine@3.2.12/dist/leaflet-routing-machine.min.js"></script>
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

    // Approximate barangay centers for Manolo Fortich, Bukidnon.
    // ⚠️ PLACEHOLDER COORDINATES — verify against LGU/LDRRMO GIS data
    // before using this for real dispatch.
    const barangayCoords = {
        'Agusan Canyon':        { lat: 8.3220, lng: 124.8080 },
        'Alae':                 { lat: 8.3450, lng: 124.8390 },
        'Dahilayan':             { lat: 8.2810, lng: 124.8480 },
        'Damilag':               { lat: 8.3616, lng: 124.8088 },
        'Dalirig':               { lat: 8.3050, lng: 124.7950 },
        'Diclum':                { lat: 8.3900, lng: 124.9050 },
        'Guilang-guilang':       { lat: 8.3800, lng: 124.8600 },
        'Kalugmanan':            { lat: 8.3550, lng: 124.9150 },
        'Lindaban':              { lat: 8.4100, lng: 124.8300 },
        'Lingion':               { lat: 8.4250, lng: 124.8700 },
        'Lunocan':               { lat: 8.3980, lng: 124.8250 },
        'Maluko':                { lat: 8.3500, lng: 124.8900 },
        'Mambatangan':           { lat: 8.3600, lng: 124.9200 },
        'Mampayag':              { lat: 8.4050, lng: 124.9000 },
        'Minsuro':               { lat: 8.3300, lng: 124.8900 },
        'San Miguel':            { lat: 8.3850, lng: 124.8500 },
        'Sankanan':              { lat: 8.4150, lng: 124.9100 },
        'Santiago':              { lat: 8.3400, lng: 124.8700 },
        'Tankulan (Poblacion)':  { lat: 8.3736, lng: 124.8694 },
        'Ticala':                { lat: 8.3950, lng: 124.7900 }
    };

    let routingControl = null;
    let lastRouteDestination = null;

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
        }).on('routesfound', function (e) {
            const route = e.routes[0];
            const distanceKm = route.summary.totalDistance / 1000;

            const travelMinutes = (distanceKm / AVERAGE_SPEED_KMH) * 60;
            const totalMinutes = travelMinutes + DISPATCH_DELAY_MIN;

            etaPanel.classList.remove('eta-pending');
            etaValueEl.innerHTML = formatEta(totalMinutes) + ' <span>estimated arrival</span>';
            etaBreakdownEl.innerHTML =
                'Distance: <strong>' + distanceKm.toFixed(2) + ' km</strong> · ' +
                'Travel: <strong>' + travelMinutes.toFixed(1) + ' min</strong> @ ' + AVERAGE_SPEED_KMH + ' km/h · ' +
                'Dispatch delay: <strong>+' + DISPATCH_DELAY_MIN + ' min</strong>';

            etaMinutesInput.value = totalMinutes.toFixed(1);
            setSummary('sumEta', formatEta(totalMinutes));
            document.getElementById('sumEta').classList.add('eta-highlight');
        }).on('routingerror', function () {
            etaPanel.classList.add('eta-pending');
            etaValueEl.textContent = 'Route unavailable — check connection to OSRM.';
            etaBreakdownEl.innerHTML = '';
            etaMinutesInput.value = '';
            setSummary('sumEta', '');
        }).addTo(map);
    }

    function dropPin(lat, lng) {
        if (marker) map.removeLayer(marker);
        marker = L.marker([lat, lng], { draggable: true }).addTo(map);
        marker.on('dragend', () => {
            const pos = marker.getLatLng();
            setCoords(pos.lat, pos.lng);
        });
        map.setView([lat, lng], 16);
        setCoords(lat, lng);
    }

    function setCoords(lat, lng) {
        latInput.value = lat.toFixed(6);
        lngInput.value = lng.toFixed(6);
        coordsReadout.innerHTML = '📍 <strong>' + lat.toFixed(6) + ', ' + lng.toFixed(6) + '</strong> — pin set';
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
        const coords = barangayCoords[this.value];
        if (!coords) return;

        map.setView([coords.lat, coords.lng], 14);

        if (!marker) {
            calculateRoute(coords.lat, coords.lng);
        }

        updateSummary();
    });

    // ---------- Incident type -> severity reveal + resource auto-suggest ----------
    const severityWrap = document.getElementById('severityWrap');
    const suggestionMap = {
        'Medical Emergency': ['Ambulance / PTV'],
        'Fire': ['Fire Truck', 'Rescue Team'],
        'Vehicular Accident': ['Ambulance / PTV', 'Extraction Team'],
        'Flood / Landslide': ['Rescue Team', 'Water Rescue / Rubber Boat'],
        'Violence / Assault': ['Police Assistance', 'Ambulance / PTV'],
        'Other': []
    };

    document.querySelectorAll('input[name="incident_type"]').forEach(radio => {
        radio.addEventListener('change', () => {
            severityWrap.classList.add('show');

            document.querySelectorAll('.resource-option').forEach(opt => {
                opt.classList.remove('suggested');
                const tag = opt.querySelector('.suggest-tag');
                if (tag) tag.remove();
            });

            const suggested = suggestionMap[radio.value] || [];
            suggested.forEach(val => {
                const opt = document.querySelector('.resource-option[data-value="' + val + '"]');
                if (opt) {
                    opt.classList.add('suggested');
                    opt.querySelector('input').checked = true;
                    const tag = document.createElement('span');
                    tag.className = 'suggest-tag';
                    tag.textContent = 'Suggested';
                    opt.appendChild(tag);
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
        const sitio = document.getElementById('sitio_purok').value.trim();
        const locStr = barangay ? (barangay + (sitio ? ', ' + sitio : '')) : '';
        setSummary('sumLocation', locStr);
        toggleCheck('chkLocation', barangay !== '');
        toggleCheck('chkPin', latInput.value !== '');

        const incidentRadio = document.querySelector('input[name="incident_type"]:checked');
        setSummary('sumIncident', incidentRadio ? incidentRadio.value : '');

        const sevRadio = document.querySelector('input[name="severity"]:checked');
        const sumSev = document.getElementById('sumSeverity');
        if (sevRadio) {
            sumSev.className = 'value';
            const colors = { Critical: 'var(--critical)', High: 'var(--high)', Moderate: 'var(--moderate)', Low: 'var(--low)' };
            sumSev.innerHTML = '<span class="summary-severity" style="background:' + colors[sevRadio.value] + '22; color:' + colors[sevRadio.value] + '">' + sevRadio.value + '</span>';
        } else {
            sumSev.className = 'value empty';
            sumSev.textContent = 'Not filled yet';
        }
        toggleCheck('chkIncident', !!(incidentRadio && sevRadio));

        const resources = Array.from(document.querySelectorAll('input[name="resources[]"]:checked')).map(r => r.value);
        setSummary('sumResources', resources.join(', '));
        toggleCheck('chkResources', resources.length > 0);
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

    ['caller_name', 'barangay', 'sitio_purok'].forEach(id => {
        document.getElementById(id).addEventListener('input', updateSummary);
        document.getElementById(id).addEventListener('change', updateSummary);
    });
    document.querySelectorAll('input[name="severity"], input[name="resources[]"]').forEach(el => {
        el.addEventListener('change', updateSummary);
    });

    // ---------- CLIP reference preview ----------
    const today = new Date();
    const y = today.getFullYear();
    const m = String(today.getMonth() + 1).padStart(2, '0');
    const d = String(today.getDate()).padStart(2, '0');
    document.getElementById('clipRefPreview').textContent =
        'Reference: CLIP-' + y + m + d + '-XXXXX (assigned on submit)';

    updateSummary();
</script>

</body>
</html>