<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';


// Self-contained: creates the settings table if it doesn't exist yet
$pdo->exec("
    CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value VARCHAR(255) NOT NULL,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$defaults = [
    'default_avg_speed_kmh' => '40',
    'delay_threshold_minutes' => '15',
    'barangay_list' => "Agusan Canyon\nAlae\nDahilayan\nDamilag\nDalirig\nDiclum\nGuilang-guilang\nKalugmanan\nLindaban\nLingion\nLunocan\nMaluko\nMambatangan\nMampayag\nMinsuro\nSan Miguel\nSankanan\nSantiago\nTankulan (Poblacion)\nTicala",
];

// Seed defaults on first run only
foreach ($defaults as $key => $val) {
    $exists = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ?");
    $exists->execute([$key]);
    if (!$exists->fetchColumn()) {
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)")->execute([$key, $val]);
    }
}

$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['default_avg_speed_kmh', 'delay_threshold_minutes', 'barangay_list'] as $key) {
        if (isset($_POST[$key])) {
            $value = trim($_POST[$key]);
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmt->execute([$key, $value]);
        }
    }
    if (function_exists('logActivity')) {
        logActivity($pdo, $_SESSION['user_id'], $_SESSION['email'], 'settings_updated', 'success');
    }
    $flash = 'Settings saved.';
}

$rows = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$unreadAlerts = 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>System Settings — HopeLine</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/sidebar.css">
<style>
    :root { --burnt-umber:#6d120b; --redwood:#b02029; --macadamia:#fbf0d8; --cool-blue:#113047; --light-grayish:#739ab9; }
    * { box-sizing:border-box; margin:0; padding:0; }
    body { font-family:'Segoe UI',-apple-system,BlinkMacSystemFont,sans-serif; background:#0c2334; display:flex; min-height:100vh; }
    .main { flex:1; padding:26px 32px 50px; color:var(--macadamia); max-width:720px; }
    .page-head { margin-bottom:20px; }
    .page-head h1 { font-size:21px; margin-bottom:4px; }
    .page-head p { color:var(--light-grayish); font-size:13px; }

    .flash { background:rgba(63,122,92,0.18); border:1px solid #3f7a5c; color:#b7ecd1; padding:10px 14px; border-radius:6px; font-size:13px; margin-bottom:16px; }

    .card { background:rgba(251,240,216,0.04); border:1px solid rgba(115,154,185,0.18); border-radius:10px; padding:20px 22px; margin-bottom:16px; }
    .card h2 { font-size:14px; font-weight:700; margin-bottom:4px; }
    .card .desc { font-size:11.5px; color:var(--light-grayish); margin-bottom:14px; }

    label { display:block; font-size:12px; font-weight:600; margin-bottom:6px; }
    input, textarea {
        width:100%; background:rgba(251,240,216,0.06); border:1px solid rgba(115,154,185,0.28);
        border-radius:6px; padding:9px 11px; color:var(--macadamia); font-size:13px; outline:none; font-family:inherit;
    }
    input:focus, textarea:focus { border-color:var(--redwood); }
    textarea { min-height:180px; resize:vertical; font-family:monospace; font-size:12px; line-height:1.6; }

    .input-with-suffix { position:relative; }
    .input-with-suffix .suffix { position:absolute; right:12px; top:50%; transform:translateY(-50%); font-size:12px; color:var(--light-grayish); }

    .btn-save { background:var(--burnt-umber); color:var(--macadamia); border:0; padding:12px 26px; border-radius:50px; font-weight:700; font-size:13.5px; cursor:pointer; margin-top:6px; }
    .btn-save:hover { background:var(--redwood); }
</style>
</head>
<body>

<?php require_once __DIR__ . '/../../assets/layouts/admin/admin_sidebar.php'; ?>

<main class="main">
    <div class="page-head">
        <h1>System Settings</h1>
        <p>Configuration used across ETA prediction and CLIP intake.</p>
    </div>

    <?php if ($flash): ?><div class="flash"><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>

    <form method="POST">
        <div class="card">
            <h2>ETA Prediction</h2>
            <div class="desc">Used in the formula: ETA = (Distance ÷ Average Speed) + Delays</div>
            <label for="default_avg_speed_kmh">Default Average Speed</label>
            <div class="input-with-suffix">
                <input type="number" step="0.1" name="default_avg_speed_kmh" id="default_avg_speed_kmh" value="<?php echo htmlspecialchars($rows['default_avg_speed_kmh']); ?>">
                <span class="suffix">km/h</span>
            </div>
        </div>

        <div class="card">
            <h2>Delay Threshold</h2>
            <div class="desc">A dispatch is auto-flagged as "delayed" on the dashboard once it exceeds this time en route without arriving.</div>
            <label for="delay_threshold_minutes">Threshold</label>
            <div class="input-with-suffix">
                <input type="number" step="1" name="delay_threshold_minutes" id="delay_threshold_minutes" value="<?php echo htmlspecialchars($rows['delay_threshold_minutes']); ?>">
                <span class="suffix">minutes</span>
            </div>
        </div>

        <div class="card">
            <h2>Barangay List</h2>
            <div class="desc">One barangay per line — populates the dropdown on the Manager's CLIP Report form.</div>
            <label for="barangay_list">Barangays</label>
            <textarea name="barangay_list" id="barangay_list"><?php echo htmlspecialchars($rows['barangay_list']); ?></textarea>
        </div>

        <button type="submit" class="btn-save">Save Settings</button>
    </form>
</main>

</body>
</html>