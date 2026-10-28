<?php
/* EXIF GPS -> ['lat','lng','taken'] or null */
function exifGps(string $file): ?array {
    if (!function_exists('exif_read_data')) return null;
    $e = @exif_read_data($file, 'ANY_TAG', true);
    $g = $e['GPS'] ?? null;
    if (!$g || empty($g['GPSLatitude']) || empty($g['GPSLongitude'])) return null;
    $num = function ($v) { $p = explode('/', (string)$v); return (count($p) === 2 && $p[1] != 0) ? $p[0] / $p[1] : (float)$v; };
    $dms = function ($a, $ref) use ($num) {
        $d = $num($a[0]) + $num($a[1]) / 60 + $num($a[2]) / 3600;
        return in_array($ref, ['S', 'W'], true) ? -$d : $d;
    };
    $t  = $e['EXIF']['DateTimeOriginal'] ?? null;
    $dt = $t ? DateTime::createFromFormat('Y:m:d H:i:s', $t) : false;
    return [
        'lat'   => $dms($g['GPSLatitude'],  $g['GPSLatitudeRef']  ?? 'N'),
        'lng'   => $dms($g['GPSLongitude'], $g['GPSLongitudeRef'] ?? 'E'),
        'taken' => $dt ? $dt->format('Y-m-d H:i:s') : null,
    ];
}
function statusLabel($s) {
    $map = [
        'pending'    => 'Pending',
        'dispatched' => 'Dispatched',
        'resolved'   => 'Completed',
        'cancelled'  => 'Cancelled',
    ];
    return $map[$s] ?? ucfirst(str_replace('_', ' ', (string)$s));
}
/* Coordinates -> readable location (OSM Nominatim) */

function reverseGeocode(float $lat, float $lng): ?array {
    $url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=18&addressdetails=1&lat=' . $lat . '&lon=' . $lng;
    $ctx = stream_context_create(['http' => ['timeout' => 6, 'header' => "User-Agent: HopeLine-LDRRMO/1.0 (you@yourdomain.com)\r\n"]]);
    $raw = @file_get_contents($url, false, $ctx);
    $j = $raw ? json_decode($raw, true) : null;
    if (empty($j['address'])) return null;
    $a = $j['address'];
    $purok = '';
    foreach ($a as $v) {                       // prefer a value that matches your known puroks
        foreach (purokList() as $n) if (is_string($v) && strcasecmp(trim($v), $n) === 0) $purok = $n;
    }
    if ($purok === '') $purok = $a['neighbourhood'] ?? $a['quarter'] ?? $a['hamlet'] ?? '';
    return [
        'barangay' => $a['village'] ?? $a['suburb'] ?? $a['city_district'] ?? '',
        'purok'    => $purok,
        'address'  => mb_substr($j['display_name'] ?? '', 0, 255),
    ];
}

/* Fill + cache geo columns of a clip_reports row (needs id, latitude, longitude) */
function fillGeo(PDO $pdo, array $r): array {
    if (!empty($r['geo_address']) || $r['latitude'] === null || $r['latitude'] === '') return $r;
    $g = reverseGeocode((float)$r['latitude'], (float)$r['longitude']);
    usleep(1100000); // Nominatim: max 1 request/second
    if ($g) {
        $pdo->prepare("UPDATE clip_reports SET geo_barangay=?, geo_purok=?, geo_address=? WHERE id=?")
            ->execute([$g['barangay'], $g['purok'], $g['address'], $r['id']]);
        $r['geo_barangay'] = $g['barangay']; $r['geo_purok'] = $g['purok']; $r['geo_address'] = $g['address'];
    }
    return $r;
}

/* Weekly / monthly / quarterly / yearly range containing $ref */
function reportPeriod(string $period, string $ref): ?array {
    $d = DateTime::createFromFormat('Y-m-d', $ref) ?: new DateTime('today');
    switch ($period) {
        case 'weekly':
            $f = (clone $d)->modify('monday this week'); $t = (clone $f)->modify('+6 days');
            $label = 'Week_' . $f->format('Y-m-d'); $name = 'Weekly'; break;
        case 'monthly':
            $f = (clone $d)->modify('first day of this month'); $t = (clone $d)->modify('last day of this month');
            $label = 'Month_' . $f->format('Y-m'); $name = 'Monthly'; break;
        case 'quarterly':
            $q = (int)ceil($d->format('n') / 3);
            $f = new DateTime($d->format('Y') . '-' . (($q - 1) * 3 + 1) . '-01');
            $t = (clone $f)->modify('last day of +2 months');
            $label = 'Q' . $q . '_' . $d->format('Y'); $name = 'Quarterly'; break;
        case 'yearly':
            $f = new DateTime($d->format('Y') . '-01-01'); $t = new DateTime($d->format('Y') . '-12-31');
            $label = 'Year_' . $d->format('Y'); $name = 'Yearly'; break;
        default: return null;
    }
    return ['from' => $f->format('Y-m-d'), 'to' => $t->format('Y-m-d'), 'label' => $label, 'name' => $name];
}

/* Period picker form (used by Incident Records + Analytics) */
function exportPeriodForm(string $url, array $hidden = []): string {
    $h = '';
    foreach ($hidden as $k => $v) {
        if (is_string($v) && $v !== '') $h .= '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars($v) . '">';
    }
    return '<form method="GET" action="' . htmlspecialchars($url) . '" class="filter-bar" style="margin:0;">' . $h
        . '<div class="filter-field"><label>Report period</label><select name="period">'
        . '<option value="weekly">Weekly</option><option value="monthly" selected>Monthly</option>'
        . '<option value="quarterly">Quarterly</option><option value="yearly">Yearly</option></select></div>'
        . '<div class="filter-field"><label>Period containing</label><input type="date" name="ref" value="' . date('Y-m-d') . '"></div>'
        . '<button type="submit" class="btn-primary">Export (.csv)</button></form>';
}
function redirect($path) {
    header("Location: " . BASE_URL . $path);
    exit;
}
if (!defined('PTV_BASE_LAT')) {            // shared base coordinates (#7, #9)
    define('PTV_BASE_LAT', 8.371714652741774);
    define('PTV_BASE_LNG', 124.85717564826615);
}

function csrfToken(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function verifyCsrf(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
    $t = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');   // form field OR header (JSON APIs)
    if (!is_string($t) || !hash_equals(csrfToken(), $t)) {
        http_response_code(403);
        if (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') !== false) {
            header('Content-Type: application/json');
            exit(json_encode(['ok' => false, 'error' => 'csrf']));
        }
        exit('Invalid or expired form token. Go back, refresh the page, and try again.');
    }
}

// safe string input: arrays/missing keys can't crash trim()
function postStr(string $k, int $max = 255): string {
    $v = $_POST[$k] ?? '';
    return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
}
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function hasRole($role) {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

function currentUserId() {
    return $_SESSION['user_id'] ?? null;
}

function currentUserEmail() {
    return $_SESSION['email'] ?? null;
}

function requireLogin() {
    if (!isLoggedIn()) {
        redirect('/index.php');
    }
}

function requireRole($role) {
    requireLogin();
    if (!hasRole($role)) {
        die("Access denied. Required role: $role");
    }
}
function purokList() {
    return ['Kihare','Lower Kalanawan','Lower Pol-ton','Lower Sosohon','Mangima',
            'Saint Joseph','Upper Kalanawan','Upper Pol-ton','Upper Sosohon'];
}
function renderHeader($title) {
    $currentRole = $_SESSION['role'] ?? 'guest';
    $currentEmail = $_SESSION['email'] ?? '';
    $isLoggedIn = isset($_SESSION['user_id']);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?php echo htmlspecialchars($title); ?></title>
        <!-- Material Icons -->
        <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
        <!-- Google Fonts -->
        <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="http://localhost/php-pdo/assets/css/style.css">


    </head>
    <body>
        <?php if ($isLoggedIn): ?>
            <div class="main-content">
                <div class="container">
        <?php else: ?>
            <div class="container" style="margin: 0 auto; max-width: 500px; padding-top: 100px;">
        <?php endif; ?>
    <?php
}

function renderFooter() {
    $isLoggedIn = isset($_SESSION['user_id']);
    ?>
        <?php if ($isLoggedIn): ?>
                </div>
            </div>
        <?php else: ?>
            </div>
        <?php endif; ?>
    </body>
    </html>
    <?php
}
require_once __DIR__ . '/../includes/activity-logger.php';

// ---- CSRF, enforced once for every page/API that includes this file ----
csrfToken();
verifyCsrf();                                   // any POST without a valid token stops here

$csrfFieldHtml = csrfField();
$csrfSkip = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') !== false
         || basename($_SERVER['SCRIPT_NAME'] ?? '') === 'export-cases.php';
if (!$csrfSkip) {
    // auto-adds the hidden token to every <form method="POST"> in the page output
    ob_start(function ($html) use ($csrfFieldHtml) {
        if (stripos($html, '<form') === false) return $html;
        return preg_replace_callback('/<form\b[^>]*\bmethod\s*=\s*["\']?post["\']?[^>]*>/i',
            function ($m) use ($csrfFieldHtml) { return $m[0] . $csrfFieldHtml; }, $html);
    });
}