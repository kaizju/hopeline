<?php
// api/unit-locations.php
// Polled by assets/js/live-map-gps.js on the manager Live Unit Map.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/functions.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isLoggedIn() || !(hasRole('manager') || hasRole('admin'))) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}
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
        FROM ptv_units u
        LEFT JOIN dispatch d ON d.unit_id = u.id AND d.status IN ('en_route','returning')
        LEFT JOIN clip_reports c ON c.id = d.clip_report_id
        WHERE u.archived_at IS NULL
        ORDER BY u.unit_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok' => true, 'units' => $units]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db']);
}