<?php
// api/delay-factors.php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/functions.php';
require_once __DIR__ . '/../config/delay_factors.php';

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

$lat = filter_input(INPUT_GET, 'lat', FILTER_VALIDATE_FLOAT);
$lng = filter_input(INPUT_GET, 'lng', FILTER_VALIDATE_FLOAT);

if ($lat === false || $lng === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid coordinates']);
    exit;
}

echo json_encode(calculateDelayFactors($lat, $lng));
