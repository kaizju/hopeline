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
  
}

$activeDelayCount = count(array_filter($delays, fn($d) => $d['resolved_at'] === null));

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Delay Alerts — HopeLine</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/manager.css">
</head>
<body>

<?php require_once __DIR__ . '/../../assets/layouts/manager/manager_sidebar.php'; ?>

<main class="main main-1100">
    <div class="page-head page-head--flex">
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