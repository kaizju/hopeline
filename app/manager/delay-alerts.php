<?php
session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/functions.php';


$flash = '';

// ---- Handle: resolve delay / log manual delay ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (($_POST['action'] ?? '') === 'resolve_delay') {
            $delayId = (int)($_POST['delay_id'] ?? 0);
            $pdo->prepare("UPDATE delay_logs SET resolved_at = NOW() WHERE id = ?")->execute([$delayId]);
            $flash = 'Delay marked as resolved.';
        }

        if (($_POST['action'] ?? '') === 'log_manual_delay') {
            $dispatchId = (int)($_POST['dispatch_id'] ?? 0);
            $unitId     = (int)($_POST['unit_id'] ?? 0);
            $reason     = trim($_POST['reason'] ?? '');
            $notes      = trim($_POST['notes'] ?? '');

            $stmt = $pdo->prepare("INSERT INTO delay_logs (dispatch_id, unit_id, reason, notes, logged_by, is_manual) VALUES (?, ?, ?, ?, ?, 1)");
            $stmt->execute([$dispatchId, $unitId, $reason, $notes, $_SESSION['user_id']]);
            $flash = 'Manual delay logged.';
        }
    } catch (PDOException $e) {
        $flash = 'Action failed: ' . $e->getMessage();
    }
}

// ---- Fetch data ----
try {
    $delays = $pdo->query("
        SELECT dl.*, u.unit_name, c.clip_ref, c.barangay, c.severity
        FROM delay_logs dl
        JOIN ptv_units u ON u.id = dl.unit_id
        JOIN dispatch d ON d.id = dl.dispatch_id
        JOIN clip_reports c ON c.id = d.clip_report_id
        ORDER BY dl.resolved_at IS NULL DESC, dl.started_at DESC
        LIMIT 50
    ")->fetchAll(PDO::FETCH_ASSOC);

    $activeDispatches = $pdo->query("
        SELECT d.id AS dispatch_id, d.unit_id, u.unit_name, c.clip_ref, c.barangay
        FROM dispatch d
        JOIN ptv_units u ON u.id = d.unit_id
        JOIN clip_reports c ON c.id = d.clip_report_id
        WHERE d.status IN ('assigned','en_route','on_site')
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $delays = [
        ['id'=>1,'unit_name'=>'PTV Alpha','clip_ref'=>'CLIP-20260815-A1B2C','barangay'=>'Maluko','severity'=>'Critical','reason'=>'Road obstruction/traffic','notes'=>'Fallen tree blocking the main road near Purok 2','is_manual'=>0,'started_at'=>date('Y-m-d H:i:s', strtotime('-6 minutes')),'resolved_at'=>null],
        ['id'=>2,'unit_name'=>'PTV Charlie','clip_ref'=>'CLIP-20260814-Z9Y8X','barangay'=>'Damilag','severity'=>'High','reason'=>'Vehicle breakdown/mechanical issue','notes'=>'Flat tire, backup requested','is_manual'=>0,'started_at'=>date('Y-m-d H:i:s', strtotime('-1 day -2 hours')),'resolved_at'=>date('Y-m-d H:i:s', strtotime('-1 day -1 hour -42 minutes'))],
        ['id'=>3,'unit_name'=>'PTV Echo','clip_ref'=>'CLIP-20260813-Q1W2E','barangay'=>'Dahilayan','severity'=>'Moderate','reason'=>'Unit unreachable','notes'=>'No response via radio, manually flagged for reassignment','is_manual'=>1,'started_at'=>date('Y-m-d H:i:s', strtotime('-2 days -3 hours')),'resolved_at'=>date('Y-m-d H:i:s', strtotime('-2 days -2 hours -50 minutes'))],
    ];
    $activeDispatches = [
        ['dispatch_id'=>10,'unit_id'=>3,'unit_name'=>'PTV Charlie','clip_ref'=>'CLIP-20260815-D4E5F','barangay'=>'Damilag'],
    ];
}

$activeDelayCount = count(array_filter($delays, fn($d) => $d['resolved_at'] === null));
$unreadAlerts = $activeDelayCount;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Delay Alerts — HopeLine</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    :root {
        --burnt-umber:#6d120b; --redwood:#b02029; --macadamia:#fbf0d8;
        --cool-blue:#113047; --light-grayish:#739ab9; --high:#d9752b;
    }
    * { box-sizing:border-box; margin:0; padding:0; }
    body { font-family:'Segoe UI',-apple-system,BlinkMacSystemFont,sans-serif; background:#0c2334; display:flex; min-height:100vh; }
    .main { flex:1; padding:26px 32px 50px; color:var(--macadamia); max-width:1100px; }
    .page-head { display:flex; align-items:flex-end; justify-content:space-between; margin-bottom:18px; flex-wrap:wrap; gap:10px; }
    .page-head h1 { font-size:21px; margin-bottom:4px; }
    .page-head p { color:var(--light-grayish); font-size:13px; }

    .flash { background:rgba(63,122,92,0.18); border:1px solid #3f7a5c; color:#b7ecd1; padding:10px 14px; border-radius:6px; font-size:13px; margin-bottom:16px; }

    .btn-primary {
        background:var(--burnt-umber); color:var(--macadamia); border:0; padding:10px 18px;
        border-radius:50px; font-weight:700; font-size:12.5px; cursor:pointer; display:inline-flex; align-items:center; gap:6px;
    }
    .btn-primary:hover { background:var(--redwood); }
    .btn-primary svg { width:13px; height:13px; }

    .tabs { display:flex; gap:8px; margin-bottom:16px; }
    .tab { font-size:12px; font-weight:600; padding:7px 14px; border-radius:20px; border:1px solid rgba(115,154,185,0.3); color:var(--light-grayish); cursor:pointer; }
    .tab.active { background:var(--burnt-umber); border-color:var(--burnt-umber); color:var(--macadamia); }

    .delay-card { background:rgba(251,240,216,0.04); border:1px solid rgba(115,154,185,0.18); border-radius:10px; padding:15px 18px; margin-bottom:10px; display:flex; gap:14px; align-items:flex-start; }
    .delay-card.active-delay { border-color: var(--high); background: rgba(217,117,43,0.06); }

    .delay-icon { width:34px; height:34px; border-radius:8px; background:rgba(217,117,43,0.2); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .delay-icon svg { width:17px; height:17px; color:var(--high); }

    .delay-main { flex:1; min-width:0; }
    .delay-top { display:flex; align-items:center; justify-content:space-between; gap:10px; margin-bottom:4px; flex-wrap:wrap; }
    .delay-title { font-size:13.5px; font-weight:700; }
    .delay-tag { font-size:9.5px; font-weight:700; text-transform:uppercase; padding:2px 8px; border-radius:20px; letter-spacing:0.3px; }
    .tag-active { background:rgba(217,117,43,0.2); color:var(--high); }
    .tag-resolved { background:rgba(63,122,92,0.2); color:#3f7a5c; }
    .tag-manual { background:rgba(115,154,185,0.2); color:var(--light-grayish); }

    .delay-meta { font-size:11.5px; color:var(--light-grayish); margin-bottom:4px; }
    .delay-notes { font-size:12px; color:var(--macadamia); opacity:0.85; }

    .delay-side { display:flex; flex-direction:column; align-items:flex-end; gap:8px; }
    .delay-duration { font-size:11px; color:var(--light-grayish); text-align:right; }
    .delay-duration strong { color:var(--macadamia); }

    .btn-resolve { background:rgba(63,122,92,0.2); color:#7fd6a5; border:1px solid #3f7a5c; border-radius:20px; padding:6px 13px; font-size:11px; font-weight:700; cursor:pointer; }
    .btn-resolve:hover { background:rgba(63,122,92,0.35); }

    .empty-state { text-align:center; padding:50px 20px; color:var(--light-grayish); font-size:13px; }

    /* Modal */
    .modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:1000; align-items:center; justify-content:center; }
    .modal-overlay.show { display:flex; }
    .modal { background:var(--cool-blue); border:1px solid rgba(115,154,185,0.3); border-radius:10px; padding:22px; width:100%; max-width:420px; }
    .modal h3 { font-size:15px; margin-bottom:14px; }
    .modal .field { margin-bottom:12px; }
    .modal label { display:block; font-size:12px; font-weight:600; margin-bottom:5px; }
    .modal select, .modal textarea {
        width:100%; background:rgba(251,240,216,0.06); border:1px solid rgba(115,154,185,0.28);
        border-radius:6px; padding:8px 10px; color:var(--macadamia); font-size:12.5px; outline:none; font-family:inherit;
    }
    .modal-actions { display:flex; justify-content:flex-end; gap:8px; margin-top:16px; }
    .btn-cancel { background:transparent; border:1px solid rgba(115,154,185,0.3); color:var(--light-grayish); border-radius:20px; padding:8px 16px; font-size:12px; cursor:pointer; }
</style>
</head>
<body>

<?php require_once __DIR__ . '/../../assets/layouts/manager/manager_sidebar.php'; ?>

<main class="main">
    <div class="page-head">
        <div>
            <h1>Delay Alerts</h1>
            <p><?php echo $activeDelayCount; ?> active delay<?php echo $activeDelayCount === 1 ? '' : 's'; ?> right now.</p>
        </div>
        <button class="btn-primary" onclick="document.getElementById('manualDelayModal').classList.add('show')">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
            Log Manual Delay
        </button>
    </div>

    <?php if ($flash): ?><div class="flash"><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>

    <div class="tabs">
        <div class="tab active" data-filter="all">All</div>
        <div class="tab" data-filter="active">Active</div>
        <div class="tab" data-filter="resolved">Resolved</div>
    </div>

    <div id="delayList">
        <?php if (empty($delays)): ?>
            <div class="empty-state">No delays logged.</div>
        <?php else: ?>
            <?php foreach ($delays as $d):
                $isActive = $d['resolved_at'] === null;
                $duration = $isActive
                    ? round((time() - strtotime($d['started_at'])) / 60) . 'm (ongoing)'
                    : round((strtotime($d['resolved_at']) - strtotime($d['started_at'])) / 60) . 'm total';
            ?>
            <div class="delay-card <?php echo $isActive ? 'active-delay' : ''; ?>" data-status="<?php echo $isActive ? 'active' : 'resolved'; ?>">
                <div class="delay-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
                </div>
                <div class="delay-main">
                    <div class="delay-top">
                        <div class="delay-title"><?php echo htmlspecialchars($d['unit_name']); ?> — <?php echo htmlspecialchars($d['reason']); ?></div>
                        <div style="display:flex; gap:6px;">
                            <?php if ($isActive): ?><span class="delay-tag tag-active">Active</span><?php else: ?><span class="delay-tag tag-resolved">Resolved</span><?php endif; ?>
                            <?php if (!empty($d['is_manual'])): ?><span class="delay-tag tag-manual">Manual</span><?php endif; ?>
                        </div>
                    </div>
                    <div class="delay-meta"><?php echo htmlspecialchars($d['clip_ref']); ?> · <?php echo htmlspecialchars($d['barangay']); ?> · <?php echo htmlspecialchars($d['severity']); ?></div>
                    <?php if (!empty($d['notes'])): ?><div class="delay-notes"><?php echo htmlspecialchars($d['notes']); ?></div><?php endif; ?>
                </div>
                <div class="delay-side">
                    <div class="delay-duration"><strong><?php echo $duration; ?></strong><br>started <?php echo date('g:i A', strtotime($d['started_at'])); ?></div>
                    <?php if ($isActive): ?>
                        <form method="POST">
                            <input type="hidden" name="action" value="resolve_delay">
                            <input type="hidden" name="delay_id" value="<?php echo $d['id']; ?>">
                            <button type="submit" class="btn-resolve">Mark Resolved</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</main>

<!-- Manual delay modal -->
<div class="modal-overlay" id="manualDelayModal">
    <div class="modal">
        <h3>Log Manual Delay</h3>
        <form method="POST">
            <input type="hidden" name="action" value="log_manual_delay">
            <div class="field">
                <label for="dispatch_id">Active Dispatch</label>
                <select name="dispatch_id" id="dispatch_id" required onchange="this.form.unit_id.value = this.options[this.selectedIndex].dataset.unit">
                    <option value="">Select a dispatch…</option>
                    <?php foreach ($activeDispatches as $ad): ?>
                        <option value="<?php echo $ad['dispatch_id']; ?>" data-unit="<?php echo $ad['unit_id']; ?>">
                            <?php echo htmlspecialchars($ad['unit_name']); ?> — <?php echo htmlspecialchars($ad['clip_ref']); ?> (<?php echo htmlspecialchars($ad['barangay']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="unit_id" id="unit_id">
            </div>
            <div class="field">
                <label for="reason">Reason</label>
                <select name="reason" id="reason" required>
                    <option value="Road obstruction/traffic">Road obstruction/traffic</option>
                    <option value="Vehicle breakdown/mechanical issue">Vehicle breakdown/mechanical issue</option>
                    <option value="Weather/flooding">Weather/flooding</option>
                    <option value="Wrong/unclear location">Wrong/unclear location</option>
                    <option value="Fuel issue">Fuel issue</option>
                    <option value="Waiting for backup unit">Waiting for backup unit</option>
                    <option value="Unit unreachable">Unit unreachable</option>
                    <option value="Other">Other</option>
                </select>
            </div>
            <div class="field">
                <label for="notes">Notes</label>
                <textarea name="notes" id="notes" rows="3" placeholder="Brief context for this delay"></textarea>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="document.getElementById('manualDelayModal').classList.remove('show')">Cancel</button>
                <button type="submit" class="btn-primary">Log Delay</button>
            </div>
        </form>
    </div>
</div>

<script>
    document.querySelectorAll('.tab').forEach(tab => {
        tab.addEventListener('click', () => {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            const filter = tab.dataset.filter;
            document.querySelectorAll('.delay-card').forEach(card => {
                card.style.display = (filter === 'all' || card.dataset.status === filter) ? '' : 'none';
            });
        });
    });
</script>

</body>
</html>