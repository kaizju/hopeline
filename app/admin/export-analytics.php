<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';
requireRole('admin');

$per = reportPeriod($_GET['period'] ?? '', $_GET['ref'] ?? '');
if (!$per) { http_response_code(400); exit('Invalid period.'); }
$p  = [$per['from'] . ' 00:00:00', $per['to'] . ' 23:59:59'];
$in = implode(',', array_map([$pdo, 'quote'], purokList()) ?: ["''"]);

function csvSafe($v) { $v = (string)$v; return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v; }
function q(PDO $pdo, $sql, $params) { $s = $pdo->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); }
function mm($s) { return $s === null ? '' : round($s / 60, 1); }
function sec($out, $title, $head, $rows) {
    fputcsv($out, [$title], ',', '"', '');
    fputcsv($out, $head, ',', '"', '');
    foreach ($rows as $r) fputcsv($out, array_map('csvSafe', $r), ',', '"', '');
    fwrite($out, "\n");
}
$last = "(SELECT MAX(d2.id) FROM dispatch d2 WHERE d2.clip_report_id = c.id AND d2.arrived_at IS NOT NULL)";

$s = q($pdo, "SELECT COUNT(*) total,
        AVG(TIMESTAMPDIFF(SECOND, c.created_at, d.arrived_at)) resp,
        AVG(TIMESTAMPDIFF(SECOND, d.departed_at, d.arrived_at)) trav,
        AVG(ABS(d.predicted_eta_minutes - TIMESTAMPDIFF(SECOND, d.departed_at, d.arrived_at) / 60)) eta_var
     FROM clip_reports c LEFT JOIN dispatch d ON d.id = $last
     WHERE c.created_at BETWEEN ? AND ?", $p)[0];

$byPurok = q($pdo, "SELECT c.barangay, COUNT(*) n, AVG(TIMESTAMPDIFF(SECOND, c.created_at, d.arrived_at)) resp
     FROM clip_reports c LEFT JOIN dispatch d ON d.id = $last
     WHERE c.created_at BETWEEN ? AND ? AND c.barangay IN ($in) GROUP BY c.barangay ORDER BY n DESC", $p);
$bySev  = q($pdo, "SELECT severity, COUNT(*) n FROM clip_reports WHERE created_at BETWEEN ? AND ? GROUP BY severity ORDER BY FIELD(severity,'Critical','High','Moderate','Low')", $p);
$byType = q($pdo, "SELECT incident_type, COUNT(*) n FROM clip_reports WHERE created_at BETWEEN ? AND ? GROUP BY incident_type ORDER BY n DESC", $p);
$delays = q($pdo, "SELECT reason, COUNT(*) n FROM delay_logs WHERE started_at BETWEEN ? AND ? GROUP BY reason ORDER BY n DESC", $p);
$fleet  = q($pdo, "SELECT u.unit_name, u.plate_no, COUNT(d.id) n,
        SUM(CASE WHEN d.departed_at IS NOT NULL AND d.arrived_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, d.departed_at, d.arrived_at) ELSE 0 END) secs,
        AVG(CASE WHEN d.departed_at IS NOT NULL AND d.arrived_at IS NOT NULL THEN TIMESTAMPDIFF(SECOND, d.departed_at, d.arrived_at) END) avg_s,
        (SELECT COUNT(*) FROM delay_logs dl WHERE dl.unit_id = u.id AND dl.started_at BETWEEN ? AND ?) delays
     FROM ptv_units u LEFT JOIN dispatch d ON d.unit_id = u.id AND d.dispatched_at BETWEEN ? AND ?
     GROUP BY u.id ORDER BY n DESC", array_merge($p, $p));

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="hopeline-analytics-' . $per['label'] . '-' . date('Ymd-His') . '.csv"');
header('Cache-Control: no-store');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, ['HopeLine Analytics & Reports'], ',', '"', '');
fputcsv($out, [$per['name'] . ' report', $per['from'] . ' to ' . $per['to']], ',', '"', '');
fwrite($out, "\n");
sec($out, 'SUMMARY', ['Metric', 'Value'], [
    ['Incidents logged', $s['total']],
    ['Avg total response (min)', mm($s['resp'])],
    ['Avg travel time (min)', mm($s['trav'])],
    ['Avg ETA variance (min)', $s['eta_var'] !== null ? round($s['eta_var'], 1) : ''],
]);
sec($out, 'BY PUROK', ['Purok', 'Incidents', 'Avg response (min)'],
    array_map(fn($r) => [$r['barangay'], $r['n'], mm($r['resp'])], $byPurok));
sec($out, 'BY SEVERITY', ['Severity', 'Incidents'], array_map(fn($r) => [$r['severity'], $r['n']], $bySev));
sec($out, 'BY INCIDENT TYPE', ['Type', 'Incidents'], array_map(fn($r) => [$r['incident_type'], $r['n']], $byType));
sec($out, 'DELAYS BY REASON', ['Reason', 'Count'], array_map(fn($r) => [$r['reason'], $r['n']], $delays));
sec($out, 'VEHICLE UTILIZATION', ['Unit', 'Plate', 'Dispatches', 'Time active (min)', 'Avg travel (min)', 'Delays'],
    array_map(fn($r) => [$r['unit_name'], $r['plate_no'], $r['n'], mm($r['secs']), mm($r['avg_s']), $r['delays']], $fleet));
fclose($out);