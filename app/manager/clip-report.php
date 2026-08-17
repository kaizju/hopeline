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
    <style>
        :root {
            --burnt-umber: #6d120b;
            --redwood: #b02029;
            --macadamia: #fbf0d8;
            --cool-blue: #113047;
            --light-grayish: #739ab9;
            --critical: #b02029;
            --high: #d9752b;
            --moderate: #d4ab2b;
            --low: #3f7a5c;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #0c2334;
            display: flex;
            min-height: 100vh;
        }

        .main {
            flex: 1;
            padding: 26px 32px 60px;
            color: var(--macadamia);
            max-width: 1180px;
        }

        .page-head { margin-bottom: 20px; }
        .page-head h1 { font-size: 21px; margin-bottom: 4px; }
        .page-head p { color: var(--light-grayish); font-size: 13px; }

        .clip-ref-preview {
            display: inline-block;
            margin-top: 8px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.5px;
            color: var(--light-grayish);
            background: rgba(115,154,185,0.12);
            border: 1px solid rgba(115,154,185,0.25);
            padding: 4px 10px;
            border-radius: 20px;
        }

        .alert-banner {
            padding: 12px 16px;
            border-radius: 6px;
            font-size: 13px;
            margin-bottom: 18px;
        }

        .alert-success {
            background: rgba(63,122,92,0.18);
            border: 1px solid #3f7a5c;
            color: #b7ecd1;
        }

        .alert-error {
            background: rgba(176,32,41,0.15);
            border: 1px solid var(--redwood);
            color: #ffd0d3;
        }

        .alert-error ul { margin: 6px 0 0 18px; }

        .layout {
            display: grid;
            grid-template-columns: 1fr 300px;
            gap: 22px;
            align-items: start;
        }

        .card {
            background: rgba(251, 240, 216, 0.04);
            border: 1px solid rgba(115, 154, 185, 0.18);
            border-radius: 10px;
            padding: 20px 22px;
            margin-bottom: 18px;
        }

        .card-title {
            display: flex;
            align-items: center;
            gap: 9px;
            margin-bottom: 4px;
        }

        .step-num {
            width: 22px;
            height: 22px;
            border-radius: 50%;
            background: var(--burnt-umber);
            color: var(--macadamia);
            font-size: 11px;
            font-weight: 700;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .card-title h2 { font-size: 14.5px; font-weight: 700; }
        .card-sub { color: var(--light-grayish); font-size: 11.5px; margin: 2px 0 16px 31px; }

        .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px; }
        .field { margin-bottom: 14px; }
        .field:last-child { margin-bottom: 0; }

        label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: var(--macadamia);
            margin-bottom: 6px;
        }

        label .optional { color: var(--light-grayish); font-weight: 400; font-size: 10.5px; }

        input[type="text"], input[type="tel"], input[type="number"], select, textarea {
            width: 100%;
            background: rgba(251, 240, 216, 0.06);
            border: 1px solid rgba(115, 154, 185, 0.28);
            border-radius: 6px;
            padding: 9px 11px;
            color: var(--macadamia);
            font-size: 13px;
            outline: none;
            font-family: inherit;
        }

        input::placeholder, textarea::placeholder { color: rgba(115,154,185,0.7); }
        input:focus, select:focus, textarea:focus { border-color: var(--redwood); }

        textarea { resize: vertical; min-height: 60px; }

        select option { background: var(--cool-blue); color: var(--macadamia); }

        /* Location tools */
        .loc-tools { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; }

        .btn-tool {
            display: flex;
            align-items: center;
            gap: 6px;
            background: rgba(115, 154, 185, 0.14);
            border: 1px solid rgba(115, 154, 185, 0.3);
            color: var(--macadamia);
            font-size: 12px;
            font-weight: 600;
            padding: 8px 12px;
            border-radius: 6px;
            cursor: pointer;
            transition: background 0.15s;
        }

        .btn-tool:hover { background: rgba(115, 154, 185, 0.24); }
        .btn-tool svg { width: 14px; height: 14px; }

        #map {
            height: 220px;
            border-radius: 8px;
            margin-bottom: 12px;
            border: 1px solid rgba(115, 154, 185, 0.3);
        }

        .coords-readout {
            font-size: 11.5px;
            color: var(--light-grayish);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .coords-readout strong { color: var(--macadamia); font-family: monospace; }

        /* Incident type buttons */
        .incident-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 4px;
        }

        .incident-option {
            position: relative;
        }

        .incident-option input { position: absolute; opacity: 0; }

        .incident-option label {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
            padding: 14px 8px;
            border: 1px solid rgba(115, 154, 185, 0.28);
            border-radius: 8px;
            text-align: center;
            font-size: 11.5px;
            font-weight: 600;
            cursor: pointer;
            margin: 0;
            transition: border-color 0.15s, background 0.15s;
        }

        .incident-option label svg { width: 20px; height: 20px; }

        .incident-option input:checked + label {
            border-color: var(--burnt-umber);
            background: rgba(109, 18, 11, 0.28);
        }

        /* Severity radios */
        .severity-wrap {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px dashed rgba(115, 154, 185, 0.25);
            display: none;
        }

        .severity-wrap.show { display: block; }

        .severity-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }

        .sev-option input { position: absolute; opacity: 0; }

        .sev-option label {
            display: block;
            text-align: center;
            padding: 10px 6px;
            border-radius: 7px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            border: 1.5px solid transparent;
            opacity: 0.55;
            transition: opacity 0.15s, border-color 0.15s;
        }

        .sev-option input:checked + label { opacity: 1; border-color: currentColor; }

        .sev-critical label { background: rgba(176,32,41,0.18); color: var(--critical); }
        .sev-high label { background: rgba(217,117,43,0.18); color: var(--high); }
        .sev-moderate label { background: rgba(212,171,43,0.18); color: var(--moderate); }
        .sev-low label { background: rgba(63,122,92,0.18); color: var(--low); }

        /* Resources checkboxes */
        .resource-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 14px; }

        .resource-option {
            display: flex;
            align-items: center;
            gap: 8px;
            border: 1px solid rgba(115, 154, 185, 0.28);
            border-radius: 7px;
            padding: 9px 11px;
            font-size: 12.5px;
            cursor: pointer;
        }

        .resource-option.suggested { border-color: var(--redwood); background: rgba(176,32,41,0.08); }

        .resource-option input { accent-color: var(--burnt-umber); width: 15px; height: 15px; }

        .suggest-tag {
            font-size: 9.5px;
            font-weight: 700;
            color: var(--redwood);
            margin-left: auto;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        .submit-row { display: flex; justify-content: flex-end; gap: 10px; margin-top: 4px; }

        button[type="submit"] {
            background: var(--burnt-umber);
            color: var(--macadamia);
            border: 0;
            padding: 11px 26px;
            border-radius: 50px;
            font-weight: 700;
            font-size: 13.5px;
            cursor: pointer;
            transition: background 0.15s;
        }

        button[type="submit"]:hover { background: var(--redwood); }
        button[type="submit"]:disabled { opacity: 0.4; cursor: not-allowed; }

        /* Live summary sidebar */
        .summary-card {
            position: sticky;
            top: 20px;
            background: rgba(251, 240, 216, 0.04);
            border: 1px solid rgba(115, 154, 185, 0.18);
            border-radius: 10px;
            padding: 18px;
        }

        .summary-card h3 {
            font-size: 12.5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--light-grayish);
            margin-bottom: 14px;
        }

        .summary-row { margin-bottom: 12px; }
        .summary-row .label { font-size: 10.5px; color: var(--light-grayish); text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 2px; }
        .summary-row .value { font-size: 13px; font-weight: 600; color: var(--macadamia); }
        .summary-row .value.empty { color: rgba(115,154,185,0.5); font-weight: 400; font-style: italic; }

        .summary-severity {
            display: inline-block;
            padding: 2px 9px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
        }

        .checklist { list-style: none; margin-top: 16px; padding-top: 16px; border-top: 1px dashed rgba(115,154,185,0.25); }
        .checklist li { font-size: 11.5px; color: var(--light-grayish); display: flex; align-items: center; gap: 7px; margin-bottom: 7px; }
        .checklist li svg { width: 13px; height: 13px; flex-shrink: 0; }
        .checklist li.done { color: #b7ecd1; }
        .checklist li.done svg { color: #3f7a5c; }

        @media (max-width: 900px) {
            .layout { grid-template-columns: 1fr; }
            .field-row, .incident-grid, .resource-grid, .severity-grid { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>

<?php require_once __DIR__ . '/../../assets/layouts/manager/manager_sidebar.php'; ?>

<main class="main">
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

                    <input type="hidden" id="latitude" name="latitude">
                    <input type="hidden" id="longitude" name="longitude">

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

            // reset suggestion styling
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
        else { el.textContent = 'Not filled yet'; el.classList.add('empty'); }
    }

    function toggleCheck(id, done) {
        const el = document.getElementById(id);
        el.classList.toggle('done', done);
    }

    // Wire up live updates
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