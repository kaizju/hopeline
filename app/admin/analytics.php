<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';

requireRole('admin');
$purokNames = purokList();
$in = $purokNames
    ? implode(',', array_map([$pdo, 'quote'], $purokNames))
    : "''";   // keeps the SQL valid if the list is ever empty
// Overall response time
$lastArrived = "(SELECT MAX(d2.id) FROM dispatch d2 WHERE d2.clip_report_id = c.id AND d2.arrived_at IS NOT NULL)";

$avgResponse = $pdo->query("
    SELECT AVG(TIMESTAMPDIFF(SECOND, c.created_at, d.arrived_at))
    FROM clip_reports c JOIN dispatch d ON d.id = $lastArrived
")->fetchColumn();

$perIncident = "SELECT MAX(id) FROM dispatch WHERE departed_at IS NOT NULL AND arrived_at IS NOT NULL GROUP BY clip_report_id";
$avgTravel = $pdo->query("
    SELECT AVG(TIMESTAMPDIFF(SECOND, d.departed_at, d.arrived_at)) FROM dispatch d WHERE d.id IN ($perIncident)
")->fetchColumn();

$byBarangay = $pdo->query("
    SELECT c.barangay,
           AVG(TIMESTAMPDIFF(SECOND, c.created_at, d.arrived_at)) AS avg_response,
           COUNT(*) AS incident_count
    FROM clip_reports c JOIN dispatch d ON d.id = $lastArrived
    WHERE c.barangay IN ($in)
    GROUP BY c.barangay ORDER BY avg_response DESC LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

// Incidents by severity
$bySeverity = $pdo->query("SELECT severity, COUNT(*) AS total FROM clip_reports GROUP BY severity")->fetchAll(PDO::FETCH_KEY_PAIR);
$totalIncidents = array_sum($bySeverity);

// Incidents by type
$byType = $pdo->query("SELECT incident_type, COUNT(*) AS total FROM clip_reports GROUP BY incident_type ORDER BY total DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
$maxTypeCount = max(array_column($byType, 'total') ?: [1]);

// Delay frequency by reason
$delayByReason = $pdo->query("SELECT reason, COUNT(*) AS total FROM delay_logs GROUP BY reason ORDER BY total DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
$maxDelayCount = max(array_column($delayByReason, 'total') ?: [1]);

// Predicted vs actual ETA accuracy (CLIP report time to latest arrival)
$etaAccuracy = $pdo->query("SELECT AVG(ABS(TIMESTAMPDIFF(SECOND, c.created_at, d.arrived_at) / 60 - c.predicted_eta_minutes)) AS avg_abs_variance,
        AVG(TIMESTAMPDIFF(SECOND, c.created_at, d.arrived_at) / 60 - c.predicted_eta_minutes) AS avg_bias,
        COUNT(*) AS sample_size,
        AVG(CASE WHEN ABS(TIMESTAMPDIFF(SECOND, c.created_at, d.arrived_at) / 60 - c.predicted_eta_minutes) <= " . ETA_ON_TIME_TOLERANCE_MIN . " THEN 100.0 ELSE 0.0 END) AS pct_on_time
    FROM clip_reports c JOIN dispatch d ON d.id = $lastArrived
    WHERE c.predicted_eta_minutes IS NOT NULL")->fetch(PDO::FETCH_ASSOC);

$liveEtaAccuracy = $pdo->query("SELECT AVG(ABS(TIMESTAMPDIFF(SECOND, departed_at, arrived_at) / 60 - live_eta_minutes)) AS avg_abs_variance,
        AVG(TIMESTAMPDIFF(SECOND, departed_at, arrived_at) / 60 - live_eta_minutes) AS avg_bias,
        AVG(CASE WHEN ABS(TIMESTAMPDIFF(SECOND, departed_at, arrived_at) / 60 - live_eta_minutes) <= " . ETA_ON_TIME_TOLERANCE_MIN . " THEN 100.0 ELSE 0.0 END) AS pct_on_time,
        COUNT(*) AS sample_size
    FROM dispatch
    WHERE live_eta_minutes IS NOT NULL AND arrived_at IS NOT NULL AND departed_at IS NOT NULL")->fetch(PDO::FETCH_ASSOC);

// ---- Vehicle Utilization: how much each PTV unit is actually being used ----
$vehicleUsage = $pdo->query("
    SELECT
        u.id,
        u.unit_name,
        u.plate_no,
        u.status,
        COUNT(d.id) AS total_dispatches,
        SUM(CASE WHEN d.departed_at IS NOT NULL AND d.arrived_at IS NOT NULL
                 THEN TIMESTAMPDIFF(SECOND, d.departed_at, d.arrived_at) ELSE 0 END) AS total_travel_seconds,
        AVG(CASE WHEN d.departed_at IS NOT NULL AND d.arrived_at IS NOT NULL
                 THEN TIMESTAMPDIFF(SECOND, d.departed_at, d.arrived_at) ELSE NULL END) AS avg_travel_seconds,
        (SELECT COUNT(*) FROM delay_logs dl WHERE dl.unit_id = u.id) AS delay_count
    FROM ptv_units u
    LEFT JOIN dispatch d ON d.unit_id = u.id
    GROUP BY u.id
    ORDER BY total_dispatches DESC
")->fetchAll(PDO::FETCH_ASSOC);

$maxDispatchCount = max(array_column($vehicleUsage, 'total_dispatches') ?: [1]);
$totalFleetDispatches = array_sum(array_column($vehicleUsage, 'total_dispatches'));

$unreadAlerts = 0;

function fmtMin($seconds) {
    if (!$seconds) return '—';
    return round($seconds / 60, 1) . ' min';
}

function fmtHours($seconds) {
    if (!$seconds) return '0h 0m';
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    return $h . 'h ' . $m . 'm';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Analytics & Reports — HopeLine</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/app.css">

</head>
<body>

<?php require_once __DIR__ . '/../../assets/layouts/admin/admin_sidebar.php'; ?>

<main class="main">
    <div class="page-head">
        <div style="margin-bottom:16px;"><?php echo exportPeriodForm(BASE_URL . '/app/admin/export-analytics.php'); ?></div>
        <h1>Analytics &amp; Reports</h1>
        <p>System-wide response performance.</p>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo fmtMin($avgResponse); ?></div>
            <div class="stat-label">Avg. Total Response Time<br>(CLIP logged → arrival)</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo fmtMin($avgTravel); ?></div>
            <div class="stat-label">Avg. Travel Time<br>(departure → arrival)</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo isset($etaAccuracy['avg_abs_variance']) && $etaAccuracy['avg_abs_variance'] !== null ? round($etaAccuracy['avg_abs_variance'], 1) . ' min' : '—'; ?></div>
            <div class="stat-label">Avg. ETA Prediction Variance<br>(<?php echo (int)($etaAccuracy['sample_size'] ?? 0); ?> samples)</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo isset($etaAccuracy['avg_bias']) && $etaAccuracy['avg_bias'] !== null ? sprintf('%+.1f min', $etaAccuracy['avg_bias']) : '—'; ?></div>
            <div class="stat-label"><?php echo isset($etaAccuracy['avg_bias']) && $etaAccuracy['avg_bias'] !== null ? ($etaAccuracy['avg_bias'] >= 0 ? 'Underestimates' : 'Overestimates') : 'Avg Bias'; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo isset($etaAccuracy['pct_on_time']) && $etaAccuracy['pct_on_time'] !== null ? round($etaAccuracy['pct_on_time'], 1) . '%' : '—'; ?></div>
            <div class="stat-label">% On Time</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo isset($liveEtaAccuracy['avg_abs_variance']) && $liveEtaAccuracy['avg_abs_variance'] !== null ? round($liveEtaAccuracy['avg_abs_variance'], 1) . ' min' : '—'; ?></div>
            <div class="stat-label">Live ETA Avg Abs Variance (<?php echo (int)$liveEtaAccuracy['sample_size']; ?> samples)</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo isset($liveEtaAccuracy['avg_bias']) && $liveEtaAccuracy['avg_bias'] !== null ? sprintf('%+.1f min', $liveEtaAccuracy['avg_bias']) : '—'; ?></div>
            <div class="stat-label"><?php echo isset($liveEtaAccuracy['avg_bias']) && $liveEtaAccuracy['avg_bias'] !== null ? ($liveEtaAccuracy['avg_bias'] >= 0 ? 'Live ETA Underestimates' : 'Live ETA Overestimates') : 'Live ETA Avg Bias'; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo isset($liveEtaAccuracy['pct_on_time']) && $liveEtaAccuracy['pct_on_time'] !== null ? round($liveEtaAccuracy['pct_on_time'], 1) . '%' : '—'; ?></div>
            <div class="stat-label">Live ETA % On Time</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo (int)($liveEtaAccuracy['sample_size'] ?? 0); ?></div>
            <div class="stat-label">Live ETA Samples</div>
        </div>
    </div>

    <div class="layout">
        <div>
            <div class="card">
                <h2>Response Time by Purok (slowest first)</h2>
                <?php if (empty($byBarangay)): ?>
                    <div class="empty-mini">Not enough resolved data yet.</div>
                <?php else:
                    $maxResp = max(array_column($byBarangay, 'avg_response'));
                    foreach ($byBarangay as $b):
                        $pct = $maxResp > 0 ? round(($b['avg_response'] / $maxResp) * 100) : 0;
                ?>
                <div class="bar-row">
                    <div class="bar-label"><span><?php echo htmlspecialchars($b['barangay']); ?> (<?php echo $b['incident_count']; ?>)</span><span class="n"><?php echo fmtMin($b['avg_response']); ?></span></div>
                    <div class="bar-track"><div class="bar-fill" style="width:<?php echo $pct; ?>%; background:var(--redwood);"></div></div>
                </div>
                <?php endforeach; endif; ?>
            </div>

            <div class="card">
                <h2>Incidents by Severity</h2>
                <?php
                $sevMeta = ['Critical' => 'var(--critical)', 'High' => 'var(--high)', 'Moderate' => 'var(--moderate)', 'Low' => 'var(--low)'];
                foreach ($sevMeta as $sev => $color):
                    $n = $bySeverity[$sev] ?? 0;
                    $pct = $totalIncidents > 0 ? round(($n / $totalIncidents) * 100) : 0;
                ?>
                <div class="bar-row">
                    <div class="bar-label"><span><?php echo $sev; ?></span><span class="n"><?php echo $n; ?></span></div>
                    <div class="bar-track"><div class="bar-fill" style="width:<?php echo $pct; ?>%; background:<?php echo $color; ?>;"></div></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div>
            <div class="card">
                <h2>Most Common Incident Types</h2>
                <?php if (empty($byType)): ?>
                    <div class="empty-mini">No incidents logged yet.</div>
                <?php else: foreach ($byType as $t): $pct = round(($t['total'] / $maxTypeCount) * 100); ?>
                <div class="bar-row">
                    <div class="bar-label"><span><?php echo htmlspecialchars($t['incident_type']); ?></span><span class="n"><?php echo $t['total']; ?></span></div>
                    <div class="bar-track"><div class="bar-fill" style="width:<?php echo $pct; ?>%; background:var(--light-grayish);"></div></div>
                </div>
                <?php endforeach; endif; ?>
            </div>

            <div class="card">
                <h2>Delay Frequency by Reason</h2>
                <?php if (empty($delayByReason)): ?>
                    <div class="empty-mini">No delays logged yet.</div>
                <?php else: foreach ($delayByReason as $d): $pct = round(($d['total'] / $maxDelayCount) * 100); ?>
                <div class="bar-row">
                    <div class="bar-label"><span><?php echo htmlspecialchars($d['reason']); ?></span><span class="n"><?php echo $d['total']; ?></span></div>
                    <div class="bar-track"><div class="bar-fill" style="width:<?php echo $pct; ?>%; background:var(--high);"></div></div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <!-- ================= Vehicle Utilization ================= -->
    <div class="card">
        <div class="card-header">
            <h2>Vehicle Utilization</h2>
            <a href="<?php echo BASE_URL; ?>/app/admin/units.php">Manage units →</a>
        </div>

        <?php if (empty($vehicleUsage)): ?>
            <div class="empty-mini">No PTV units registered yet.</div>
        <?php else: ?>

        <!-- Share of fleet dispatches, at a glance -->
        <?php foreach ($vehicleUsage as $v):
            $pct = $maxDispatchCount > 0 ? round(($v['total_dispatches'] / $maxDispatchCount) * 100) : 0;
            $share = $totalFleetDispatches > 0 ? round(($v['total_dispatches'] / $totalFleetDispatches) * 100) : 0;
        ?>
        <div class="bar-row">
            <div class="bar-label">
                <span><?php echo htmlspecialchars($v['unit_name']); ?> (<?php echo htmlspecialchars($v['plate_no'] ?: 'no plate'); ?>)</span>
                <span class="n"><?php echo $v['total_dispatches']; ?> dispatch<?php echo $v['total_dispatches'] == 1 ? '' : 'es'; ?> · <?php echo $share; ?>% of fleet load</span>
            </div>
            <div class="bar-track"><div class="bar-fill" style="width:<?php echo $pct; ?>%; background:var(--burnt-umber);"></div></div>
        </div>
        <?php endforeach; ?>

        <!-- Detailed breakdown table -->
        <div style="overflow-x:auto; margin-top:18px;">
        <table>
            <thead><tr>
                <th>Unit</th><th>Plate</th><th>Current Status</th>
                <th>Total Dispatches</th><th>Total Time Active</th><th>Avg. Travel Time</th><th>Delays Logged</th>
            </tr></thead>
            <tbody>
                <?php foreach ($vehicleUsage as $v): ?>
                <tr>
                    <td><?php echo htmlspecialchars($v['unit_name']); ?></td>
                    <td class="clip-ref-cell"><?php echo htmlspecialchars($v['plate_no'] ?: '—'); ?></td>
                    <td><span class="status-badge status-<?php echo strtolower(str_replace(' ', '_', $v['status'])); ?>"><?php echo htmlspecialchars($v['status']); ?></span></td>
                    <td><?php echo $v['total_dispatches']; ?></td>
                    <td><?php echo fmtHours($v['total_travel_seconds']); ?></td>
                    <td><?php echo fmtMin($v['avg_travel_seconds']); ?></td>
                    <td class="<?php echo $v['delay_count'] > 0 ? 'delay-flag' : 'no-delay'; ?>"><?php echo $v['delay_count']; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</main>

</body>
</html>
