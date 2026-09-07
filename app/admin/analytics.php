<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';



// Overall response time
$avgResponse = $pdo->query("
    SELECT AVG(TIMESTAMPDIFF(SECOND, c.created_at, d.arrived_at))
    FROM clip_reports c JOIN dispatch d ON d.clip_report_id = c.id
    WHERE d.arrived_at IS NOT NULL
")->fetchColumn();

$avgTravel = $pdo->query("
    SELECT AVG(TIMESTAMPDIFF(SECOND, d.departed_at, d.arrived_at))
    FROM dispatch d WHERE d.departed_at IS NOT NULL AND d.arrived_at IS NOT NULL
")->fetchColumn();

// Response time by barangay (avg total response, worst first)
$byBarangay = $pdo->query("
    SELECT c.barangay,
           AVG(TIMESTAMPDIFF(SECOND, c.created_at, d.arrived_at)) AS avg_response,
           COUNT(*) AS incident_count
    FROM clip_reports c JOIN dispatch d ON d.clip_report_id = c.id
    WHERE d.arrived_at IS NOT NULL
    GROUP BY c.barangay
    ORDER BY avg_response DESC
    LIMIT 8
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

// Predicted vs actual ETA accuracy (only where both exist)
$etaAccuracy = $pdo->query("
    SELECT AVG(ABS(d.predicted_eta_minutes - (TIMESTAMPDIFF(SECOND, d.departed_at, d.arrived_at) / 60))) AS avg_variance,
           COUNT(*) AS sample_size
    FROM dispatch d
    WHERE d.predicted_eta_minutes IS NOT NULL AND d.departed_at IS NOT NULL AND d.arrived_at IS NOT NULL
")->fetch(PDO::FETCH_ASSOC);

$unreadAlerts = 0;

function fmtMin($seconds) {
    if (!$seconds) return '—';
    return round($seconds / 60, 1) . ' min';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Analytics & Reports — HopeLine</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/sidebar.css">
<style>
    :root { --burnt-umber:#6d120b; --redwood:#b02029; --macadamia:#fbf0d8; --cool-blue:#113047; --light-grayish:#739ab9;
            --critical:#b02029; --high:#d9752b; --moderate:#d4ab2b; --low:#3f7a5c; }
    * { box-sizing:border-box; margin:0; padding:0; }
    body { font-family:'Segoe UI',-apple-system,BlinkMacSystemFont,sans-serif; background:#0c2334; display:flex; min-height:100vh; }
    .main { flex:1; padding:26px 32px 50px; color:var(--macadamia); max-width:1200px; }
    .page-head { margin-bottom:20px; }
    .page-head h1 { font-size:21px; margin-bottom:4px; }
    .page-head p { color:var(--light-grayish); font-size:13px; }

    .stats-grid { display:grid; grid-template-columns:repeat(3, 1fr); gap:14px; margin-bottom:20px; }
    .stat-card { background:rgba(251,240,216,0.04); border:1px solid rgba(115,154,185,0.18); border-radius:10px; padding:16px 18px; }
    .stat-value { font-size:24px; font-weight:700; }
    .stat-label { font-size:11.5px; color:var(--light-grayish); margin-top:2px; }

    .layout { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
    .card { background:rgba(251,240,216,0.04); border:1px solid rgba(115,154,185,0.18); border-radius:10px; padding:18px 20px; margin-bottom:18px; }
    .card h2 { font-size:14px; font-weight:700; margin-bottom:14px; }

    .bar-row { margin-bottom:12px; }
    .bar-row:last-child { margin-bottom:0; }
    .bar-label { display:flex; justify-content:space-between; font-size:12px; margin-bottom:5px; }
    .bar-label .n { font-weight:700; color:var(--macadamia); }
    .bar-track { height:8px; background:rgba(115,154,185,0.15); border-radius:20px; overflow:hidden; }
    .bar-fill { height:100%; border-radius:20px; }

    .empty-mini { text-align:center; padding:20px; color:var(--light-grayish); font-size:12px; }

    @media (max-width: 1000px) { .stats-grid { grid-template-columns:1fr 1fr; } .layout { grid-template-columns:1fr; } }
</style>
</head>
<body>

<?php require_once __DIR__ . '/../../assets/layouts/admin/admin_sidebar.php'; ?>

<main class="main">
    <div class="page-head">
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
            <div class="stat-value"><?php echo $etaAccuracy['avg_variance'] ? round($etaAccuracy['avg_variance'], 1) . ' min' : '—'; ?></div>
            <div class="stat-label">Avg. ETA Prediction Variance<br>(<?php echo (int)($etaAccuracy['sample_size'] ?? 0); ?> samples)</div>
        </div>
    </div>

    <div class="layout">
        <div>
            <div class="card">
                <h2>Response Time by Barangay (slowest first)</h2>
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
</main>

</body>
</html>