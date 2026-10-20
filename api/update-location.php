<?php
// api/update-location.php
// Called by assets/js/gps-tracker.js from the responder's phone.
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/functions.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isLoggedIn() || !hasRole('user')) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
session_write_close(); // don't block other requests from the same session

$in  = json_decode(file_get_contents('php://input'), true) ?: [];
$lat = isset($in['lat']) && is_numeric($in['lat']) ? (float)$in['lat'] : null;
$lng = isset($in['lng']) && is_numeric($in['lng']) ? (float)$in['lng'] : null;

if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'bad_coordinates']);
    exit;
}

$acc     = isset($in['accuracy']) && is_numeric($in['accuracy']) ? (float)$in['accuracy'] : null;
$heading = isset($in['heading'])  && is_numeric($in['heading'])  ? (float)$in['heading']  : null;
$speed   = isset($in['speed'])    && is_numeric($in['speed'])    ? (float)$in['speed']    : null;

try {
    // The team leader's phone positions every vehicle on that team's moving dispatch.
    $st = $pdo->prepare("
        UPDATE ptv_units u
        JOIN dispatch d ON d.unit_id = u.id AND d.status IN ('en_route','on_site','returning')
        JOIN users usr  ON usr.id = ? AND usr.team_id = d.team_id
        SET u.current_lat = ?, u.current_lng = ?, u.gps_accuracy = ?, u.gps_heading = ?, u.gps_speed = ?,
            u.last_location_at = NOW()
        WHERE u.archived_at IS NULL
    ");
    $st->execute([$userId, $lat, $lng, $acc, $heading, $speed]);
    echo json_encode(['ok' => true, 'updated' => $st->rowCount()]);
} catch (PDOException $e) {
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db']);
}