<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';

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
    SELECT COUNT(*) FROM delay_logs WHERE DATE(started_at) = CURDATE() AND resolved_at IS NULL
")->fetchColumn();

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
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/manager.css">
</head>
<body>

    <?php require_once __DIR__ . '/../../assets/layouts/manager/manager_sidebar.php'; ?>

    <main class="main">
        <div class="page-head page-head--flex">
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
                                <div class="t">Delayed dispatch(es) reported today</div>
                                <div class="s">View Delay Alerts for details</div>
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