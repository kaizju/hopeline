<?php
// api/unit-locations.php
// Polled by assets/js/live-map-gps.js on the manager Live Unit Map.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/functions.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isLoggedIn() || !revalidateSessionUser()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}
if (!hasRole('manager') && !hasRole('admin')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}
sessionTouch($pdo);
session_write_close();

try {
    $units = $pdo->query("
        SELECT u.id, u.unit_name, u.plate_no, u.driver_name, u.status,
               u.current_lat, u.current_lng,
               u.gps_accuracy, u.gps_heading, u.gps_speed, u.last_location_at,
               TIMESTAMPDIFF(SECOND, u.last_location_at, NOW()) AS gps_age,
               d.status AS dispatch_status, d.departed_at, d.resolved_at,
               c.clip_ref, c.barangay, c.severity,
               c.latitude AS dest_lat, c.longitude AS dest_lng
               , (SELECT dl.reason FROM delay_logs dl WHERE dl.dispatch_id=d.id AND dl.resolved_at IS NULL ORDER BY dl.started_at DESC LIMIT 1) AS delay_reason
               , (SELECT TIMESTAMPDIFF(MINUTE, dl.started_at, NOW()) FROM delay_logs dl WHERE dl.dispatch_id=d.id AND dl.resolved_at IS NULL ORDER BY dl.started_at DESC LIMIT 1) AS delay_duration_minutes
        FROM ptv_units u
        LEFT JOIN dispatch d ON d.id = (
            SELECT MAX(d2.id) FROM dispatch d2
            WHERE d2.unit_id=u.id AND d2.status IN ('assigned','en_route','on_site','returning'))
        LEFT JOIN clip_reports c ON c.id = d.clip_report_id
        WHERE u.archived_at IS NULL
        ORDER BY u.unit_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($units as &$unit) $unit['has_active_delay'] = $unit['delay_reason'] !== null;
    unset($unit);

    echo json_encode(['ok' => true, 'units' => $units]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db']);
}
