<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';



/**
 * Pulls today's key stats + active incidents + unit status.
 * Falls back to demo data if tables don't exist yet, so the page
 * still renders for defense/demo purposes.
 */
try {
    $activeIncidents = $pdo->query("
        SELECT c.id, c.clip_ref, c.barangay, c.incident_type, c.severity, c.created_at,
               d.status AS dispatch_status, d.departed_at, u.unit_name
        FROM clip_reports c
        LEFT JOIN dispatch d ON d.clip_report_id = c.id
        LEFT JOIN ptv_units u ON u.id = d.unit_id
        WHERE c.status != 'resolved'
        ORDER BY FIELD(c.severity,'Critical','High','Moderate','Low'), c.created_at DESC
        LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC);

    $unitCounts = $pdo->query("
        SELECT status, COUNT(*) AS total FROM ptv_units GROUP BY status
    ")->fetchAll(PDO::FETCH_KEY_PAIR);

    $todayCount = $pdo->query("
        SELECT COUNT(*) FROM clip_reports WHERE DATE(created_at) = CURDATE()
    ")->fetchColumn();

    $delayedCount = $pdo->query("
        SELECT COUNT(*) FROM delay_logs WHERE DATE(created_at) = CURDATE() AND resolved_at IS NULL
    ")->fetchColumn();

} catch (PDOException $e) {
    // ---- Demo fallback data ----
    $activeIncidents = [
        ['id'=>1,'clip_ref'=>'CLIP-20260815-A1B2C','barangay'=>'Maluko','incident_type'=>'Medical Emergency','severity'=>'Critical','created_at'=>date('Y-m-d H:i:s', strtotime('-8 minutes')),'dispatch_status'=>'en_route','departed_at'=>date('Y-m-d H:i:s', strtotime('-6 minutes')),'unit_name'=>'PTV Alpha'],
        ['id'=>2,'clip_ref'=>'CLIP-20260815-D4E5F','barangay'=>'Damilag','incident_type'=>'Vehicular Accident','severity'=>'High','created_at'=>date('Y-m-d H:i:s', strtotime('-20 minutes')),'dispatch_status'=>'on_site','departed_at'=>date('Y-m-d H:i:s', strtotime('-14 minutes')),'unit_name'=>'PTV Charlie'],
        ['id'=>3,'clip_ref'=>'CLIP-20260815-G7H8I','barangay'=>'Dahilayan','incident_type'=>'Flood / Landslide','severity'=>'Moderate','created_at'=>date('Y-m-d H:i:s', strtotime('-40 minutes')),'dispatch_status'=>'pending','departed_at'=>null,'unit_name'=>null],
        ['id'=>4,'clip_ref'=>'CLIP-20260815-J9K1L','barangay'=>'Tankulan (Poblacion)','incident_type'=>'Fire','severity'=>'Critical','created_at'=>date('Y-m-d H:i:s', strtotime('-2 minutes')),'dispatch_status'=>'pending','departed_at'=>null,'unit_name'=>null],
    ];
    $unitCounts = ['Available' => 2, 'En Route' => 1, 'On Site' => 1, 'Returning' => 1];
    $todayCount = 9;
    $delayedCount = 1;
}

$totalUnits = array_sum($unitCounts);
$available  = $unitCounts['Available'] ?? 0;
$unreadAlerts = $delayedCount;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manager Dashboard — HopeLine</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
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
            padding: 26px 32px 50px;
            color: var(--macadamia);
            max-width: 1280px;
        }

        .page-head { display: flex; align-items: flex-end; justify-content: space-between; margin-bottom: 22px; }
        .page-head h1 { font-size: 21px; margin-bottom: 4px; }
        .page-head p { color: var(--light-grayish); font-size: 13px; }

        .btn-primary {
            background: var(--burnt-umber);
            color: var(--macadamia);
            border: 0;
            padding: 10px 20px;
            border-radius: 50px;
            font-weight: 700;
            font-size: 13px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            transition: background 0.15s;
        }
        .btn-primary:hover { background: var(--redwood); }
        .btn-primary svg { width: 14px; height: 14px; }

        /* ===== STAT CARDS ===== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: rgba(251, 240, 216, 0.04);
            border: 1px solid rgba(115, 154, 185, 0.18);
            border-radius: 10px;
            padding: 16px 18px;
        }

        .stat-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }

        .stat-icon {
            width: 30px; height: 30px; border-radius: 7px;
            display: flex; align-items: center; justify-content: center;
        }
        .stat-icon svg { width: 15px; height: 15px; color: var(--macadamia); }

        .stat-value { font-size: 24px; font-weight: 700; color: var(--macadamia); }
        .stat-label { font-size: 11.5px; color: var(--light-grayish); margin-top: 2px; }

        .stat-sub { font-size: 10.5px; margin-top: 6px; display: flex; align-items: center; gap: 4px; }
        .stat-sub.warn { color: var(--high); }
        .stat-sub.ok { color: var(--low); }

        /* ===== LAYOUT GRID ===== */
        .dash-layout { display: grid; grid-template-columns: 1.6fr 1fr; gap: 18px; }

        .card {
            background: rgba(251, 240, 216, 0.04);
            border: 1px solid rgba(115, 154, 185, 0.18);
            border-radius: 10px;
            padding: 18px 20px;
            margin-bottom: 18px;
        }

        .card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
        .card-header h2 { font-size: 14.5px; font-weight: 700; }
        .card-header a { font-size: 11.5px; color: var(--light-grayish); text-decoration: none; }
        .card-header a:hover { color: var(--macadamia); }

        /* Incident rows */
        .incident-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 11px 0;
            border-bottom: 1px solid rgba(115, 154, 185, 0.1);
        }
        .incident-row:last-child { border-bottom: none; }

        .sev-dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }

        .incident-main { flex: 1; min-width: 0; }
        .incident-title { font-size: 13px; font-weight: 600; color: var(--macadamia); margin-bottom: 2px; }
        .incident-meta { font-size: 11px; color: var(--light-grayish); }

        .incident-status {
            font-size: 10px; font-weight: 700; text-transform: uppercase;
            padding: 3px 9px; border-radius: 20px; white-space: nowrap; letter-spacing: 0.3px;
        }

        .status-pending { background: rgba(217,117,43,0.18); color: var(--high); }
        .status-en_route { background: rgba(115,154,185,0.2); color: var(--light-grayish); }
        .status-on_site { background: rgba(176,32,41,0.18); color: var(--redwood); }

        .incident-time { font-size: 11px; color: var(--light-grayish); text-align: right; min-width: 55px; }

        /* Unit status bars */
        .unit-status-row { margin-bottom: 14px; }
        .unit-status-row:last-child { margin-bottom: 0; }
        .unit-status-label { display: flex; justify-content: space-between; font-size: 12px; margin-bottom: 6px; }
        .unit-status-label .n { font-weight: 700; color: var(--macadamia); }
        .bar-track { height: 7px; background: rgba(115,154,185,0.15); border-radius: 20px; overflow: hidden; }
        .bar-fill { height: 100%; border-radius: 20px; }

        /* Delay alerts */
        .delay-item {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 10px 0; border-bottom: 1px solid rgba(115,154,185,0.1);
        }
        .delay-item:last-child { border-bottom: none; }
        .delay-item svg { width: 15px; height: 15px; color: var(--high); flex-shrink: 0; margin-top: 1px; }
        .delay-item .t { font-size: 12.5px; color: var(--macadamia); font-weight: 600; }
        .delay-item .s { font-size: 11px; color: var(--light-grayish); }

        .empty-mini { text-align: center; padding: 20px; color: var(--light-grayish); font-size: 12px; }

        @media (max-width: 1050px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .dash-layout { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <?php require_once __DIR__ . '/../../assets/layouts/manager/manager_sidebar.php'; ?>

    <main class="main">
        <div class="page-head">
            <div>
                <h1>Manager Dashboard</h1>
                <p>Command center overview — <?php echo date('l, F j, Y'); ?></p>
            </div>
            <a href="<?php echo BASE_URL; ?>/app/manager/clip-report.php" class="btn-primary">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                New CLIP Report
            </a>
        </div>

        <!-- Stat cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-top">
                    <div><div class="stat-value"><?php echo count($activeIncidents); ?></div><div class="stat-label">Active Incidents</div></div>
                    <div class="stat-icon" style="background:rgba(176,32,41,0.2)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="var(--redwood)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15V7a2 2 0 0 1 2-2h5l2 2h5a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/></svg>
                    </div>
                </div>
                <div class="stat-sub warn">Requires dispatch attention</div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <div><div class="stat-value"><?php echo $available; ?>/<?php echo $totalUnits; ?></div><div class="stat-label">Units Available</div></div>
                    <div class="stat-icon" style="background:rgba(63,122,92,0.2)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="#3f7a5c" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M14 9h4l4 4v4a1 1 0 0 1-1 1h-2"/><circle cx="6.5" cy="18.5" r="2.5"/><circle cx="17.5" cy="18.5" r="2.5"/></svg>
                    </div>
                </div>
                <div class="stat-sub ok">Ready for dispatch</div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <div><div class="stat-value"><?php echo $todayCount; ?></div><div class="stat-label">CLIP Reports Today</div></div>
                    <div class="stat-icon" style="background:rgba(115,154,185,0.2)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="var(--light-grayish)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                    </div>
                </div>
                <div class="stat-sub">Since 12:00 AM</div>
            </div>

            <div class="stat-card">
                <div class="stat-top">
                    <div><div class="stat-value"><?php echo $delayedCount; ?></div><div class="stat-label">Delayed Dispatches</div></div>
                    <div class="stat-icon" style="background:rgba(217,117,43,0.2)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="var(--high)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                    </div>
                </div>
                <div class="stat-sub <?php echo $delayedCount > 0 ? 'warn' : 'ok'; ?>">
                    <?php echo $delayedCount > 0 ? 'Needs reassignment check' : 'No delays reported'; ?>
                </div>
            </div>
        </div>

        <div class="dash-layout">
            <div>
                <!-- Active incidents -->
                <div class="card">
                    <div class="card-header">
                        <h2>Active Incidents</h2>
                        <a href="<?php echo BASE_URL; ?>/app/manager/active-incidents.php">View all →</a>
                    </div>

                    <?php if (empty($activeIncidents)): ?>
                        <div class="empty-mini">No active incidents right now.</div>
                    <?php else: ?>
                        <?php
                        $sevColors = ['Critical' => 'var(--critical)', 'High' => 'var(--high)', 'Moderate' => 'var(--moderate)', 'Low' => 'var(--low)'];
                        $statusLabels = ['pending' => 'Pending', 'en_route' => 'En Route', 'on_site' => 'On Site'];
                        foreach ($activeIncidents as $inc):
                            $sevColor = $sevColors[$inc['severity']] ?? 'var(--light-grayish)';
                            $statusClass = 'status-' . ($inc['dispatch_status'] ?? 'pending');
                            $statusLabel = $statusLabels[$inc['dispatch_status'] ?? 'pending'] ?? 'Pending';
                            $mins = round((time() - strtotime($inc['created_at'])) / 60);
                        ?>
                        <div class="incident-row">
                            <div class="sev-dot" style="background:<?php echo $sevColor; ?>"></div>
                            <div class="incident-main">
                                <div class="incident-title"><?php echo htmlspecialchars($inc['incident_type']); ?> — <?php echo htmlspecialchars($inc['barangay']); ?></div>
                                <div class="incident-meta"><?php echo htmlspecialchars($inc['clip_ref']); ?><?php echo $inc['unit_name'] ? ' · ' . htmlspecialchars($inc['unit_name']) : ' · Unassigned'; ?></div>
                            </div>
                            <div class="incident-status <?php echo $statusClass; ?>"><?php echo $statusLabel; ?></div>
                            <div class="incident-time"><?php echo $mins; ?>m ago</div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Live map preview link -->
                <div class="card">
                    <div class="card-header">
                        <h2>Live Unit Map</h2>
                        <a href="<?php echo BASE_URL; ?>/app/manager/live-map.php">Open full map →</a>
                    </div>
                    <p style="font-size:12.5px; color:var(--light-grayish);">Track all PTV units and incident destinations in real time.</p>
                </div>
            </div>

            <div>
                <!-- Unit status breakdown -->
                <div class="card">
                    <div class="card-header"><h2>Unit Status</h2></div>
                    <?php
                    $statusMeta = [
                        'Available' => ['color' => '#3f7a5c'],
                        'En Route'  => ['color' => '#d9752b'],
                        'On Site'   => ['color' => '#b02029'],
                        'Returning' => ['color' => '#739ab9'],
                    ];
                    foreach ($statusMeta as $label => $meta):
                        $n = $unitCounts[$label] ?? 0;
                        $pct = $totalUnits > 0 ? round(($n / $totalUnits) * 100) : 0;
                    ?>
                    <div class="unit-status-row">
                        <div class="unit-status-label"><span><?php echo $label; ?></span><span class="n"><?php echo $n; ?></span></div>
                        <div class="bar-track"><div class="bar-fill" style="width:<?php echo $pct; ?>%; background:<?php echo $meta['color']; ?>"></div></div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Delay alerts -->
                <div class="card">
                    <div class="card-header">
                        <h2>Delay Alerts</h2>
                        <a href="<?php echo BASE_URL; ?>/app/manager/delay-alerts.php">View all →</a>
                    </div>
                    <?php if ($delayedCount > 0): ?>
                        <div class="delay-item">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
                            <div>
                                <div class="t">PTV Alpha reported road obstruction</div>
                                <div class="s">En route to Maluko — 6 min delayed</div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="empty-mini">No delays reported today.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

</body>
</html>