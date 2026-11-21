<?php

function getAlerts(PDO $pdo, string $role, int $userId): array {
    if (!in_array($role, ['admin', 'manager'], true)) return [];

    $delayThreshold = 15;
    try {
        $settings = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $settings->execute(['delay_threshold_minutes']);
        $configuredThreshold = $settings->fetchColumn();
        if ($configuredThreshold !== false && (int)$configuredThreshold > 0) $delayThreshold = (int)$configuredThreshold;
    } catch (PDOException $e) {
        error_log('HopeLine alert threshold could not be loaded: ' . $e->getMessage());
    }

    $gpsStaleMinutes = 5;
    $unassignedMinutes = 10;
    $prefix = '/app/manager';
    $alerts = [];

    $delayQuery = $pdo->prepare(" 
        SELECT dl.id, dl.reason, dl.notes, dl.is_manual, dl.started_at,
               TIMESTAMPDIFF(MINUTE, dl.started_at, NOW()) AS mins,
               u.unit_name, c.clip_ref, c.barangay, c.severity
        FROM delay_logs dl
        JOIN ptv_units u ON u.id = dl.unit_id
        JOIN dispatch d ON d.id = dl.dispatch_id
        JOIN clip_reports c ON c.id = d.clip_report_id
        WHERE dl.resolved_at IS NULL
        ORDER BY dl.started_at DESC
    ");
    $delayQuery->execute();
    foreach ($delayQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $alerts[] = [
            'key' => 'delay:' . (int)$row['id'],
            'type' => 'delay',
            'title' => $row['unit_name'] . ' - ' . $row['reason'],
            'meta' => $row['clip_ref'] . ' | ' . $row['barangay'] . ' | ' . $row['severity'],
            'notes' => $row['notes'] ?? '',
            'severity' => $row['severity'],
            'time' => $row['started_at'],
            'mins' => (int)$row['mins'],
            'link' => $prefix . '/delay-alerts.php',
            'is_read' => false,
            'is_manual' => (bool)$row['is_manual'],
        ];
    }

    $overdueQuery = $pdo->prepare("
        SELECT d.id, u.unit_name, d.departed_at, d.predicted_eta_minutes,
               TIMESTAMPDIFF(MINUTE, d.departed_at, NOW()) AS mins,
               c.clip_ref, c.barangay, c.severity
        FROM dispatch d
        JOIN ptv_units u ON u.id = d.unit_id
        JOIN clip_reports c ON c.id = d.clip_report_id
        WHERE d.status = 'en_route' AND d.departed_at < NOW() - INTERVAL ? MINUTE
        ORDER BY d.departed_at DESC
    ");
    $overdueQuery->execute([$delayThreshold]);
    foreach ($overdueQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $alerts[] = [
            'key' => 'overdue:' . (int)$row['id'],
            'type' => 'overdue',
            'title' => $row['unit_name'] . ' - not arrived after ' . (int)$row['mins'] . ' min',
            'meta' => $row['clip_ref'] . ' | ' . $row['barangay'] . ' | ' . $row['severity'],
            'notes' => $row['predicted_eta_minutes'] !== null ? 'Predicted ETA was ' . round((float)$row['predicted_eta_minutes']) . ' min.' : '',
            'severity' => $row['severity'],
            'time' => $row['departed_at'],
            'mins' => (int)$row['mins'],
            'link' => $prefix . '/active-incidents.php',
            'is_read' => false,
        ];
    }

    $gpsQuery = $pdo->prepare("
        SELECT u.id, u.unit_name, u.plate_no, u.status, u.last_location_at,
               DATE(u.last_location_at) AS location_date,
               TIMESTAMPDIFF(MINUTE, u.last_location_at, NOW()) AS mins
        FROM ptv_units u
        JOIN dispatch d ON d.unit_id = u.id AND d.status IN ('en_route','on_site','returning')
        WHERE u.archived_at IS NULL
          AND d.departed_at < NOW() - INTERVAL ? MINUTE
          AND (u.last_location_at IS NULL OR u.last_location_at < NOW() - INTERVAL ? MINUTE)
        GROUP BY u.id
        ORDER BY u.unit_name
    ");
    $gpsQuery->execute([$gpsStaleMinutes, $gpsStaleMinutes]);
    foreach ($gpsQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $alerts[] = [
            'key' => 'gps:' . (int)$row['id'] . ':' . ($row['location_date'] ?: 'none'),
            'type' => 'gps',
            'title' => $row['unit_name'] . ' - GPS not reporting',
            'meta' => $row['plate_no'] ?: 'No plate',
            'notes' => "Check the responder's phone: location permission, GPS and connection.",
            'severity' => 'High',
            'time' => $row['last_location_at'],
            'mins' => $row['mins'] === null ? null : (int)$row['mins'],
            'link' => $prefix . '/live-map.php',
            'is_read' => false,
        ];
    }

    $unassignedQuery = $pdo->prepare("
        SELECT c.id, c.clip_ref, c.incident_type, c.barangay, c.severity, c.created_at,
               TIMESTAMPDIFF(MINUTE, c.created_at, NOW()) AS mins
        FROM clip_reports c
        WHERE c.status = 'pending' AND c.archived_at IS NULL
          AND c.created_at < NOW() - INTERVAL ? MINUTE
        ORDER BY FIELD(c.severity,'Critical','High','Moderate','Low'), c.created_at DESC
    ");
    $unassignedQuery->execute([$unassignedMinutes]);
    foreach ($unassignedQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $alerts[] = [
            'key' => 'unassigned:' . (int)$row['id'],
            'type' => 'unassigned',
            'title' => $row['incident_type'] . ' - ' . $row['barangay'],
            'meta' => $row['clip_ref'] . ' | waiting for a unit',
            'notes' => 'This incident has not yet been assigned to a response team.',
            'severity' => $row['severity'],
            'time' => $row['created_at'],
            'mins' => (int)$row['mins'],
            'link' => $prefix . '/active-incidents.php',
            'is_read' => false,
        ];
    }

    if ($role === 'admin') {
        $loginQuery = $pdo->prepare("
            SELECT COALESCE(email, 'unknown') AS email, COUNT(*) AS attempts, MAX(created_at) AS last_at
            FROM user_activity_logs
            WHERE action = 'login' AND status = 'failed' AND created_at > NOW() - INTERVAL 24 HOUR
            GROUP BY email
            ORDER BY attempts DESC, last_at DESC
            LIMIT 10
        ");
        $loginQuery->execute();
        foreach ($loginQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $alerts[] = [
                'key' => 'logins:' . md5($row['email']) . ':' . date('Y-m-d', strtotime($row['last_at'])),
                'type' => 'logins',
                'title' => $row['email'],
                'meta' => (int)$row['attempts'] . ' failed login attempt' . ((int)$row['attempts'] === 1 ? '' : 's') . ' in the last 24 hours',
                'notes' => 'Review the failed login activity and confirm whether the account is at risk.',
                'severity' => (int)$row['attempts'] >= 5 ? 'High' : 'Moderate',
                'time' => $row['last_at'],
                'mins' => max(0, (int)floor((time() - strtotime($row['last_at'])) / 60)),
                'link' => '/app/admin/audit-trail.php?action_type=login&status=failed&q=' . urlencode($row['email']),
                'is_read' => false,
            ];
        }
    }

    if ($alerts) {
        $keys = array_column($alerts, 'key');
        $placeholders = implode(',', array_fill(0, count($keys), '?'));
        $readQuery = $pdo->prepare("SELECT alert_key FROM alert_reads WHERE user_id = ? AND alert_key IN ($placeholders)");
        $readQuery->execute(array_merge([$userId], $keys));
        $readKeys = array_fill_keys($readQuery->fetchAll(PDO::FETCH_COLUMN), true);
        foreach ($alerts as &$alert) $alert['is_read'] = isset($readKeys[$alert['key']]);
        unset($alert);
    }

    usort($alerts, static function ($a, $b) {
        $timeOrder = (strtotime((string)$b['time']) ?: 0) <=> (strtotime((string)$a['time']) ?: 0);
        return $timeOrder ?: strcmp($a['key'], $b['key']);
    });

    return $alerts;
}

function countUnreadAlerts(PDO $pdo, string $role, int $userId): int {
    try {
        $unread = 0;
        foreach (getAlerts($pdo, $role, $userId) as $alert) {
            if (!$alert['is_read']) $unread++;
        }
        return $unread;
    } catch (PDOException $e) {
        error_log('HopeLine unread alert count could not be loaded: ' . $e->getMessage());
        return 0;
    }
}
