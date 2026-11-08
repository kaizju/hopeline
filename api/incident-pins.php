<?php
// Live incident markers for the manager's map.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/functions.php';

header('Content-Type: application/json; charset=utf-8');
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
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

sessionTouch($pdo);
session_write_close();

try {
    $incidents = $pdo->query("
        SELECT c.id, c.clip_ref, c.caller_name, c.caller_contact,
               c.barangay, c.sitio_purok, c.landmark,
               c.latitude, c.longitude, c.status, c.severity,
               c.incident_type, c.problem_resources, c.problem_notes, c.created_at,
               d.status AS dispatch_status, d.departed_at, d.arrived_at,
               d.incident_photo AS photo_path, u.unit_name
        FROM clip_reports c
        LEFT JOIN dispatch d ON d.id = (
            SELECT MAX(d2.id) FROM dispatch d2
            WHERE d2.clip_report_id = c.id
              AND d2.status IN ('assigned','en_route','on_site','returning')
        )
        LEFT JOIN ptv_units u ON u.id = d.unit_id
        WHERE c.archived_at IS NULL
          AND c.status IN ('pending','dispatched')
          AND c.latitude IS NOT NULL AND c.longitude IS NOT NULL
        ORDER BY FIELD(c.severity,'Critical','High','Moderate','Low'), c.created_at ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['ok' => true, 'incidents' => $incidents], JSON_INVALID_UTF8_SUBSTITUTE);
} catch (PDOException $e) {
    error_log('HopeLine live incident pins could not be loaded: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db']);
}
