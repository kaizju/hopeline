<?php
require_once __DIR__ . '/../../config/alerts.php';
requireRole(['admin', 'manager']);

$alertRole = $_SESSION['role'];
$userId = (int)currentUserId();
$isAdminAlerts = $alertRole === 'admin';
$activeFilter = 'all';
$expandedKeys = [];
$alerts = [];
$loadError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $alerts = getAlerts($pdo, $alertRole, $userId);
        $validKeys = array_fill_keys(array_column($alerts, 'key'), true);
        $action = $_POST['action'] ?? '';
        $keys = [];

        if ($action === 'mark_all_read') {
            $keys = array_keys($validKeys);
        } elseif ($action === 'mark_selected_read') {
            $submittedKeys = $_POST['keys'] ?? [];
            if (!is_array($submittedKeys)) $submittedKeys = [];
            foreach ($submittedKeys as $key) {
                if (!is_string($key) || !isset($validKeys[$key])) {
                    http_response_code(400);
                    exit('Invalid alert key.');
                }
                $keys[] = $key;
            }
            $keys = array_values(array_unique($keys));
        } elseif (in_array($action, ['mark_read', 'mark_unread'], true)) {
            $key = $_POST['key'] ?? '';
            if (!is_string($key) || !isset($validKeys[$key])) {
                http_response_code(400);
                exit('Invalid alert key.');
            }
            $keys = [$key];
        } else {
            http_response_code(400);
            exit('Invalid alert action.');
        }

        if ($action === 'mark_unread') {
            $delete = $pdo->prepare('DELETE FROM alert_reads WHERE user_id = ? AND alert_key = ?');
            $delete->execute([$userId, $keys[0]]);
        } else {
            $insert = $pdo->prepare('INSERT IGNORE INTO alert_reads (user_id, alert_key) VALUES (?, ?)');
            foreach ($keys as $key) $insert->execute([$userId, $key]);
        }

        if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => true, 'unread' => countUnreadAlerts($pdo, $alertRole, $userId)]);
            exit;
        }
        redirectAfterPost('alerts.php', 'Alert read state updated.');
    } catch (PDOException $e) {
        error_log('HopeLine alert read state could not be updated: ' . $e->getMessage());
        http_response_code(500);
        exit('Alert state could not be updated. Refresh and try again.');
    }
}

try {
    $alerts = getAlerts($pdo, $alertRole, $userId);
} catch (PDOException $e) {
    error_log('HopeLine inbox could not be loaded: ' . $e->getMessage());
    $loadError = 'Alerts could not be loaded. Refresh the page or contact an administrator.';
}

$counts = ['delay' => 0, 'overdue' => 0, 'gps' => 0, 'unassigned' => 0, 'logins' => 0];
$unreadCount = 0;
$readCount = 0;
foreach ($alerts as $alert) {
    if (isset($counts[$alert['type']])) $counts[$alert['type']]++;
    if ($alert['is_read']) $readCount++;
    else $unreadCount++;
}
$unreadAlerts = $unreadCount;
$unreadAlertsCounted = true;
$flash = consumeFlash();
$flashType = consumeFlashType();
$escape = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$typeLabels = [
    'delay' => 'Reported delays',
    'overdue' => 'Overdue en route',
    'gps' => 'GPS silent',
    'unassigned' => 'Unassigned',
    'logins' => 'Failed logins',
];
$sidebar = $isAdminAlerts
    ? __DIR__ . '/../../assets/layouts/admin/admin_sidebar.php'
    : __DIR__ . '/../../assets/layouts/manager/manager_sidebar.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo $isAdminAlerts ? 'System Alerts' : 'Alerts'; ?> - HopeLine</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/app.css">
    <style>
        .inbox-tabs { flex-wrap:wrap; }
        .inbox-tabs .tab { background:transparent; font-family:inherit; }
        .inbox-toolbar { display:flex; flex-wrap:wrap; gap:8px; margin:0 0 14px; }
        .inbox-toolbar button, .alert-row-action { border:1px solid rgba(var(--border-rgb),.28); border-radius:7px; background:rgba(var(--surface-rgb),.06); color:var(--macadamia); padding:8px 12px; font:inherit; font-size:12px; cursor:pointer; }
        .inbox-toolbar button:disabled { opacity:.45; cursor:not-allowed; }
        .inbox-rows { border:1px solid rgba(var(--border-rgb),.18); border-radius:10px; overflow:hidden; }
        .inbox-row { display:grid; grid-template-columns:8px 24px minmax(0,1fr) auto; gap:12px; align-items:center; padding:13px 16px; border-bottom:1px solid rgba(var(--border-rgb),.12); background:rgba(var(--surface-rgb),.025); }
        .inbox-row:last-child { border-bottom:0; }
        .inbox-row.unread { background:rgba(var(--surface-rgb),.06); }
        .inbox-row.read { opacity:.72; }
        .inbox-unread-dot { width:8px; height:8px; border-radius:50%; background:var(--burnt-umber); }
        .inbox-row.read .inbox-unread-dot { visibility:hidden; }
        .inbox-row input[type=checkbox] { width:15px; height:15px; accent-color:var(--burnt-umber); }
        .inbox-row-main { min-width:0; }
        .inbox-row-head { display:flex; align-items:center; gap:10px; min-width:0; }
        .inbox-open { border:0; background:none; color:var(--macadamia); text-align:left; font:inherit; font-size:13px; cursor:pointer; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .inbox-row.unread .inbox-open { font-weight:700; }
        .inbox-meta, .inbox-details { color:var(--light-grayish); font-size:11.5px; margin-top:4px; }
        .inbox-time { color:var(--light-grayish); font-size:11px; white-space:nowrap; }
        .inbox-actions { display:flex; align-items:center; gap:8px; }
        .inbox-details { padding:10px 0 2px; }
        .inbox-details[hidden] { display:none; }
        .inbox-detail-link { display:inline-block; margin-top:8px; color:var(--burnt-umber); font-weight:600; text-decoration:none; }
        .inbox-empty { padding:24px; color:var(--light-grayish); text-align:center; }
        .inbox-error { margin-bottom:14px; }
        @media (max-width:600px) {
            .inbox-row { grid-template-columns:8px 20px minmax(0,1fr); padding:12px 10px; gap:8px; }
            .inbox-actions { grid-column:3; justify-content:space-between; }
            .inbox-time { margin-left:auto; }
        }
    </style>
</head>
<body>
<?php require $sidebar; ?>
<main class="main main-1100">
    <div class="page-head page-head--flex">
        <div>
            <h1><?php echo $isAdminAlerts ? 'System Alerts' : 'Alerts'; ?></h1>
            <p><?php echo (int)$unreadCount; ?> unread alert<?php echo $unreadCount === 1 ? '' : 's'; ?>. Refreshes every 30 seconds.</p>
        </div>
        <div id="inboxStatus" role="status" aria-live="polite"></div>
    </div>
    <?php if ($flash): ?><div class="flash"><?php echo $escape($flash); ?></div><?php endif; ?>
    <?php if ($loadError): ?><div class="alert-banner alert-error inbox-error"><?php echo $escape($loadError); ?></div><?php endif; ?>

    <div id="alertList" data-csrf="<?php echo $escape(csrfToken()); ?>">
        <div class="tabs inbox-tabs" role="tablist" aria-label="Alert filters">
            <button type="button" class="tab active" data-filter="all">All (<?php echo count($alerts); ?>)</button>
            <button type="button" class="tab" data-filter="unread">Unread (<?php echo $unreadCount; ?>)</button>
            <button type="button" class="tab" data-filter="read">Read (<?php echo $readCount; ?>)</button>
            <?php foreach ($typeLabels as $type => $label): if ($type === 'logins' && !$isAdminAlerts) continue; ?>
                <button type="button" class="tab" data-filter="<?php echo $escape($type); ?>"><?php echo $escape($label); ?> (<?php echo (int)$counts[$type]; ?>)</button>
            <?php endforeach; ?>
        </div>
        <div class="inbox-toolbar">
            <button type="button" data-bulk-action="mark_all_read" <?php echo $unreadCount === 0 ? 'disabled' : ''; ?>>Mark all as read</button>
            <button type="button" data-bulk-action="mark_selected_read" disabled>Mark selected as read</button>
        </div>
        <div class="inbox-rows">
            <?php if (!$alerts): ?>
                <div class="inbox-empty">All clear - no alerts at the moment.</div>
            <?php else: foreach ($alerts as $alert): $key = $alert['key']; ?>
                <article class="inbox-row <?php echo $alert['is_read'] ? 'read' : 'unread'; ?>" data-alert-key="<?php echo $escape($key); ?>" data-type="<?php echo $escape($alert['type']); ?>">
                    <span class="inbox-unread-dot" aria-hidden="true"></span>
                    <input class="alert-select" type="checkbox" value="<?php echo $escape($key); ?>" aria-label="Select alert">
                    <div class="inbox-row-main">
                        <div class="inbox-row-head">
                            <button type="button" class="inbox-open" aria-expanded="false"><?php echo $escape($alert['title']); ?></button>
                            <span class="delay-tag <?php echo $alert['severity'] === 'Critical' || $alert['severity'] === 'High' ? 'tag-active' : 'tag-manual'; ?>"><?php echo $escape($typeLabels[$alert['type']]); ?></span>
                        </div>
                        <div class="inbox-meta"><?php echo $escape($alert['meta']); ?></div>
                        <div class="inbox-details" hidden>
                            <div><strong>Severity:</strong> <?php echo $escape($alert['severity']); ?></div>
                            <div><strong>Time:</strong> <?php echo $alert['time'] ? $escape(date('M j, Y H:i', strtotime($alert['time']))) : 'No location received'; ?></div>
                            <?php if ($alert['mins'] !== null): ?><div><strong>Age:</strong> <?php echo (int)$alert['mins']; ?> min</div><?php endif; ?>
                            <?php if ($alert['is_manual'] ?? false): ?><div>Manually reported delay</div><?php endif; ?>
                            <?php if ($alert['notes'] !== ''): ?><div><?php echo nl2br($escape($alert['notes'])); ?></div><?php endif; ?>
                            <a class="inbox-detail-link" href="<?php echo $escape(BASE_URL . $alert['link']); ?>">Open related page</a>
                        </div>
                    </div>
                    <div class="inbox-actions">
                        <span class="inbox-time"><?php echo $alert['time'] ? $escape(date('M j H:i', strtotime($alert['time']))) : ''; ?></span>
                        <button type="button" class="alert-row-action" data-row-action="<?php echo $alert['is_read'] ? 'mark_unread' : 'mark_read'; ?>"><?php echo $alert['is_read'] ? 'Mark as unread' : 'Mark as read'; ?></button>
                    </div>
                </article>
            <?php endforeach; endif; ?>
        </div>
    </div>
</main>
<script>
(() => {
    const list = document.getElementById('alertList');
    const status = document.getElementById('inboxStatus');
    let activeFilter = 'all';
    const expandedKeys = new Set();
    const selectedKeys = new Set();
    let refreshing = false;

    function applyInboxState() {
        list.querySelectorAll('.inbox-row').forEach(row => {
            const key = row.dataset.alertKey;
            const read = row.classList.contains('read');
            row.hidden = activeFilter === 'unread' ? read
                : activeFilter === 'read' ? !read
                : !['all', 'unread', 'read'].includes(activeFilter) && row.dataset.type !== activeFilter;
            const isExpanded = expandedKeys.has(key);
            row.querySelector('.inbox-details').hidden = !isExpanded;
            row.querySelector('.inbox-open').setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
            row.querySelector('.alert-select').checked = selectedKeys.has(key);
        });
        list.querySelectorAll('.inbox-tabs .tab').forEach(tab => tab.classList.toggle('active', tab.dataset.filter === activeFilter));
        const selectedRead = [...selectedKeys].some(key => {
            const row = list.querySelector('.inbox-row[data-alert-key="' + CSS.escape(key) + '"]');
            return row && row.classList.contains('unread');
        });
        const selectedButton = list.querySelector('[data-bulk-action="mark_selected_read"]');
        if (selectedButton) selectedButton.disabled = !selectedRead;
    }

    function syncBadges(doc) {
        const fresh = doc.querySelector('[data-alert-badge]');
        if (!fresh) return;
        document.querySelectorAll('[data-alert-badge]').forEach(badge => {
            badge.textContent = fresh.textContent;
            badge.hidden = fresh.hidden;
        });
    }

    async function postAction(action, values = {}) {
        const body = new URLSearchParams({ action, csrf: list.dataset.csrf || '' });
        if (values.key) body.set('key', values.key);
        (values.keys || []).forEach(key => body.append('keys[]', key));
        const response = await fetch(location.href, {
            method: 'POST', credentials: 'same-origin', cache: 'no-store', body,
            headers: { 'X-Requested-With': 'fetch' }
        });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.error || 'Could not update alert.');
        status.textContent = '';
        await refreshInbox();
    }

    async function refreshInbox() {
        if (refreshing || document.hidden) return;
        refreshing = true;
        try {
            const response = await fetch(location.href, {
                credentials: 'same-origin', cache: 'no-store',
                headers: { 'X-Requested-With': 'fetch' }
            });
            if (!response.ok) return;
            const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
            const fresh = doc.getElementById('alertList');
            if (fresh) {
                const freshKeys = new Set([...fresh.querySelectorAll('.inbox-row')].map(row => row.dataset.alertKey));
                [...expandedKeys].forEach(key => { if (!freshKeys.has(key)) expandedKeys.delete(key); });
                [...selectedKeys].forEach(key => { if (!freshKeys.has(key)) selectedKeys.delete(key); });
                list.innerHTML = fresh.innerHTML;
                list.dataset.csrf = fresh.dataset.csrf || list.dataset.csrf;
                syncBadges(doc);
                applyInboxState();
            }
        } catch (error) {
            console.warn('Could not refresh alerts.', error);
        } finally {
            refreshing = false;
        }
    }

    list.addEventListener('click', async event => {
        const filter = event.target.closest('[data-filter]');
        if (filter) {
            activeFilter = filter.dataset.filter;
            applyInboxState();
            return;
        }
        const row = event.target.closest('.inbox-row');
        if (!row) return;
        const key = row.dataset.alertKey;
        const rowAction = event.target.closest('[data-row-action]');
        if (rowAction) {
            event.stopPropagation();
            try { await postAction(rowAction.dataset.rowAction, { key }); }
            catch (error) { status.textContent = error.message; }
            return;
        }
        if (event.target.closest('.alert-select, a')) return;
        const opening = !expandedKeys.has(key);
        if (opening) expandedKeys.add(key);
        else expandedKeys.delete(key);
        applyInboxState();
        if (opening && row.classList.contains('unread')) {
            try { await postAction('mark_read', { key }); }
            catch (error) { status.textContent = error.message; }
        }
    });

    list.addEventListener('change', event => {
        if (!event.target.matches('.alert-select')) return;
        const key = event.target.value;
        if (event.target.checked) selectedKeys.add(key);
        else selectedKeys.delete(key);
        applyInboxState();
    });

    list.addEventListener('click', async event => {
        const button = event.target.closest('[data-bulk-action]');
        if (!button) return;
        const action = button.dataset.bulkAction;
        const keys = [...selectedKeys];
        if (action === 'mark_selected_read') selectedKeys.clear();
        try { await postAction(action, action === 'mark_selected_read' ? { keys } : {}); }
        catch (error) { status.textContent = error.message; }
        applyInboxState();
    });

    applyInboxState();
    setInterval(refreshInbox, 30000);
})();
</script>
</body>
</html>
