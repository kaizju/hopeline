<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/functions.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isLoggedIn() || !(hasRole('manager') || hasRole('admin'))) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit;
}
session_write_close();

$where = "c.archived_at IS NULL AND c.latitude IS NOT NULL AND c.longitude IS NOT NULL";
$where .= ($_GET['scope'] ?? '') === 'all'
    ? " AND c.created_at >= NOW() - INTERVAL 30 DAY"
    : " AND c.status NOT IN ('resolved','cancelled')";

try {
    $rows = $pdo->query("
        SELECT c.id, c.clip_ref, c.incident_type, c.severity, c.status, c.caller_name, c.caller_contact,
               c.barangay, c.sitio_purok, c.landmark, c.latitude, c.longitude,
               c.problem_resources, c.problem_notes, c.created_at,
               d.status AS dispatch_status, d.departed_at, d.arrived_at, u.unit_name
        FROM clip_reports c
        LEFT JOIN dispatch d ON d.id = (SELECT MAX(d2.id) FROM dispatch d2 WHERE d2.clip_report_id = c.id)
        LEFT JOIN ptv_units u ON u.id = d.unit_id
        WHERE $where
        ORDER BY c.created_at DESC LIMIT 300
    ")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['ok' => true, 'incidents' => $rows]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false]);
}