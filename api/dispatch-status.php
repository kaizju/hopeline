<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/functions.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isLoggedIn() || !hasRole('user')) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit;
}
$uid = (int)$_SESSION['user_id'];
session_write_close();

$s = $pdo->prepare("
    SELECT d.id, d.status FROM dispatch d
    JOIN ptv_units u ON u.id = d.unit_id
    WHERE u.responder_id = ? AND d.status IN ('assigned','en_route','on_site','returning')
    ORDER BY d.dispatched_at DESC, d.id DESC LIMIT 1
");
$s->execute([$uid]);
$r = $s->fetch(PDO::FETCH_ASSOC);

echo json_encode(['ok' => true, 'key' => $r ? $r['id'] . ':' . $r['status'] : 'none']);