<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';
requireRole('admin');

$view = ($_GET['view'] ?? '') === 'archived' ? 'archived' : 'active';
$where  = $view === 'archived' ? "WHERE c.archived_at IS NOT NULL" : "WHERE c.archived_at IS NULL";
$params = [];
foreach (['status' => 'c.status', 'severity' => 'c.severity', 'barangay' => 'c.barangay'] as $k => $col) {
    if (($_GET[$k] ?? '') !== '') { $where .= " AND $col = ?"; $params[] = $_GET[$k]; }
}
if (($_GET['q'] ?? '') !== '') { $where .= " AND (c.clip_ref LIKE ? OR c.caller_name LIKE ?)"; $params[] = '%'.$_GET['q'].'%'; $params[] = '%'.$_GET['q'].'%'; }
if (($_GET['from'] ?? '') !== '') { $where .= " AND c.created_at >= ?"; $params[] = $_GET['from'].' 00:00:00'; }
if (($_GET['to'] ?? '') !== '')   { $where .= " AND c.created_at <= ?"; $params[] = $_GET['to'].' 23:59:59'; }

$stmt = $pdo->prepare("
    SELECT c.clip_ref, c.created_at, c.caller_name, c.caller_contact, c.barangay, c.sitio_purok, c.landmark,
           c.incident_type, c.severity, c.status, c.problem_resources,
           u.unit_name, u.plate_no,
           d.dispatched_at, d.departed_at, d.arrived_at, d.returned_at,
           TIMESTAMPDIFF(SECOND, d.departed_at, d.arrived_at) AS travel_s,
           TIMESTAMPDIFF(SECOND, c.created_at, d.arrived_at)  AS response_s,
           d.patient_name, d.patient_age_group, d.patient_sex, d.victim_count, d.vital_signs, d.alcohol_breath,
           d.closeout_remarks, d.incident_details, d.incident_photo
    FROM clip_reports c
    LEFT JOIN dispatch d ON d.clip_report_id = c.id
    LEFT JOIN ptv_units u ON u.id = d.unit_id
    $where
    ORDER BY c.created_at DESC
");
$stmt->execute($params);

function csvSafe($v) { $v = (string)$v; return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v; }
function mins($s) { return $s === null ? '' : round($s / 60, 1); }

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="hopeline-case-report-' . date('Ymd-His') . '.csv"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads it correctly
fputcsv($out, ['CLIP Ref','Date Reported','Caller','Contact','Barangay','Sitio/Purok','Landmark','Incident Type','Severity','Status',
    'Resources','Unit','Plate','Dispatched','Departed','Arrived','Returned','Travel (min)','Total Response (min)',
    'Patient Name','Age Group','Sex','# Victims','Vital Signs','Alcohol Breath','Remarks','Incident Details','Photo URL'], ',', '"', '');

$base = rtrim(BASE_URL, '/') . '/';
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($out, array_map('csvSafe', [
        $r['clip_ref'], $r['created_at'], $r['caller_name'], $r['caller_contact'], $r['barangay'], $r['sitio_purok'], $r['landmark'],
        $r['incident_type'], $r['severity'], $r['status'], $r['problem_resources'], $r['unit_name'], $r['plate_no'],
        $r['dispatched_at'], $r['departed_at'], $r['arrived_at'], $r['returned_at'],
        mins($r['travel_s']), mins($r['response_s']),
        $r['patient_name'] ?: $r['caller_name'], $r['patient_age_group'], $r['patient_sex'], $r['victim_count'],
        $r['vital_signs'], $r['alcohol_breath'], $r['closeout_remarks'], $r['incident_details'],
        $r['incident_photo'] ? $base . $r['incident_photo'] : ''
    ]), ',', '"', '');
}
fclose($out);