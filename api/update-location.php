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
    $pdo->prepare("
        UPDATE ptv_units
        SET current_lat = ?, current_lng = ?, gps_accuracy = ?, gps_heading = ?, gps_speed = ?,
            last_location_at = NOW()
        WHERE responder_id = ? AND archived_at IS NULL
    ")->execute([$lat, $lng, $acc, $heading, $speed, $userId]);

    echo json_encode(['ok' => true]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'db']);
}