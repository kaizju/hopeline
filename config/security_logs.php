<?php
function slh($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** Which rows a role may see. $a = table alias. */
function slScope(string $role, int $uid, string $a): array {
    if ($role === 'admin')   return ['1=1', []];
    if ($role === 'manager') return ["($a.user_id = ? OR $a.role = 'user')", [$uid]];   // self + responders
    return ["$a.user_id = ?", [$uid]];                                                    // self only
}

function slAgo($secs): string {
    $s = max(0, (int)$secs);
    if ($s < 60)    return $s . 's ago';
    if ($s < 3600)  return floor($s / 60) . 'm ago';
    if ($s < 86400) { $h = floor($s / 3600); $m = floor(($s % 3600) / 60); return $h . 'h' . ($m ? " {$m}m" : '') . ' ago'; }
    return floor($s / 86400) . 'd ago';
}
function slDur($secs): string {
    $s = max(0, (int)$secs); $h = floor($s / 3600); $m = floor(($s % 3600) / 60);
    return $h ? "{$h}h {$m}m" : ($m ? "{$m}m" : "{$s}s");
}
function slDevice(?string $ua): string {
    $ua = (string)$ua;
    $b = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/OPR\//', $ua) ? 'Opera' : (preg_match('/Firefox\//', $ua) ? 'Firefox'
       : (preg_match('/Chrome\//', $ua) ? 'Chrome' : (preg_match('/Safari\//', $ua) ? 'Safari' : 'Unknown'))));
    $o = preg_match('/Android/', $ua) ? 'Android' : (preg_match('/iPhone|iPad/', $ua) ? 'iOS' : (preg_match('/Windows/', $ua) ? 'Windows'
       : (preg_match('/Mac OS/', $ua) ? 'macOS' : (preg_match('/Linux/', $ua) ? 'Linux' : ''))));
    return $b . ($o ? ' · ' . $o : '');
}
function slUserCell(array $r): string {
    $name = $r['name'] ?: ($r['email'] ?: 'Unknown');
    return '<strong>' . slh($name) . '</strong><div class="audit-meta">' . slh($r['email'] ?: '—') . '</div>';
}
function slRoleBadge(?string $role): string {
    if (!$role) return '<span class="status-badge status-inactive">Guest</span>';
    $label = ['admin' => 'Admin', 'manager' => 'Manager', 'user' => 'Responder'][$role] ?? ucfirst($role);
    return '<span class="role-badge role-' . slh($role) . '">' . slh($label) . '</span>';
}

function renderSecurityLogs(PDO $pdo, string $role, int $uid): void {
    $g = function (string $k): string { return (isset($_GET[$k]) && is_string($_GET[$k])) ? trim($_GET[$k]) : ''; };
    $tab  = $g('tab') === 'sessions' ? 'sessions' : 'activity';
    $page = max(1, (int)$g('page'));
    $per  = 20;
    $off  = ($page - 1) * $per;
    $f = [
        'q'      => mb_substr($g('q'), 0, 100),
        'module' => $g('module'),
        'status' => in_array($g('status'), ['success', 'failed'], true) ? $g('status') : '',
        'role'   => ($role !== 'user' && in_array($g('role'), ['admin', 'manager', 'user'], true)) ? $g('role') : '',
        'mine'   => $role !== 'user' && $g('mine') === '1',
    ];
    $intro = [
        'admin'   => 'Every action by admins, managers and responders.',
        'manager' => 'Your own activity and your responders\' activity.',
        'user'    => 'Your own activity and sign-in sessions.',
    ][$role] ?? '';

    $a = $tab === 'sessions' ? 's' : 'l';
    [$scope, $params] = slScope($role, $uid, $a);
    $where = "WHERE $scope";
    if ($f['q'] !== '') {
        $like = '%' . $f['q'] . '%';
        if ($tab === 'sessions') { $where .= " AND (s.email LIKE ? OR u.name LIKE ? OR s.ip_address LIKE ?)"; array_push($params, $like, $like, $like); }
        else { $where .= " AND (l.email LIKE ? OR u.name LIKE ? OR l.description LIKE ? OR l.action LIKE ?)"; array_push($params, $like, $like, $like, $like); }
    }
    if ($f['role'] !== '') { $where .= " AND $a.role = ?";    $params[] = $f['role']; }
    if ($f['mine'])        { $where .= " AND $a.user_id = ?"; $params[] = $uid; }
    if ($tab === 'activity') {
        if ($f['module'] !== '') { $where .= " AND l.module = ?"; $params[] = $f['module']; }
        if ($f['status'] !== '') { $where .= " AND l.status = ?"; $params[] = $f['status']; }
    }

    if ($tab === 'sessions') {
        $from  = "FROM user_sessions s LEFT JOIN users u ON u.id = s.user_id";
        $cols  = "s.*, u.name,
                  GREATEST(0, TIMESTAMPDIFF(SECOND, s.login_at, COALESCE(s.logout_at, s.last_seen_at))) AS dur_s,
                  GREATEST(0, TIMESTAMPDIFF(SECOND, s.last_seen_at, NOW())) AS idle_s";
        $order = "s.login_at DESC, s.id DESC";
    } else {
        $from  = "FROM user_activity_logs l LEFT JOIN users u ON u.id = l.user_id";
        $cols  = "l.*, u.name, GREATEST(0, TIMESTAMPDIFF(SECOND, l.created_at, NOW())) AS age_s";
        $order = "l.id DESC";
    }
    $cnt = $pdo->prepare("SELECT COUNT(*) $from $where");
    $cnt->execute($params);
    $total = (int)$cnt->fetchColumn();
    $st = $pdo->prepare("SELECT $cols $from $where ORDER BY $order LIMIT $per OFFSET $off");
    $st->execute($params);
    $rows  = $st->fetchAll(PDO::FETCH_ASSOC);
    $pages = max(1, (int)ceil($total / $per));
    [$mScope, $mParams] = slScope($role, $uid, 'l');
$mq = $pdo->prepare("SELECT DISTINCT l.module FROM user_activity_logs l WHERE $mScope ORDER BY l.module");
$mq->execute($mParams);
$modules = $mq->fetchAll(PDO::FETCH_COLUMN);
    $cur = sessionRef();

    $qs = function (array $extra = []) use ($tab, $f) {
        return http_build_query(array_filter(
            array_merge(['tab' => $tab, 'q' => $f['q'], 'module' => $f['module'], 'status' => $f['status'],
                         'role' => $f['role'], 'mine' => $f['mine'] ? 1 : ''], $extra),
            function ($v) { return $v !== '' && $v !== null; }));
    };
    ?>
    <div class="page-head">
        <h1>Security Logs</h1>
        <p><?php echo slh($intro); ?> <?php echo $total; ?> record(s).</p>
    </div>

    <div class="tabs">
        <a href="?tab=activity" class="tab <?php echo $tab === 'activity' ? 'active' : ''; ?>" style="text-decoration:none;">Activity</a>
        <a href="?tab=sessions" class="tab <?php echo $tab === 'sessions' ? 'active' : ''; ?>" style="text-decoration:none;">Sessions</a>
    </div>

    <form method="GET" class="filter-bar">
        <input type="hidden" name="tab" value="<?php echo slh($tab); ?>">
        <input type="text" name="q" placeholder="Search email, name, action…" value="<?php echo slh($f['q']); ?>">
        <?php if ($role !== 'user'): ?>
        <select name="role">
            <option value="">All roles</option>
            <?php foreach (['admin' => 'Admin', 'manager' => 'Manager', 'user' => 'Responder'] as $k => $v):
                if ($role === 'manager' && $k === 'admin') continue; ?>
                <option value="<?php echo $k; ?>" <?php echo $f['role'] === $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
        <?php if ($tab === 'activity'): ?>
        <select name="module">
            <option value="">All modules</option>
            <?php foreach ($modules as $m): ?>
                <option value="<?php echo slh($m); ?>" <?php echo $f['module'] === $m ? 'selected' : ''; ?>><?php echo slh($m); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status">
            <option value="">All outcomes</option>
            <option value="success" <?php echo $f['status'] === 'success' ? 'selected' : ''; ?>>Success</option>
            <option value="failed"  <?php echo $f['status'] === 'failed' ? 'selected' : ''; ?>>Failed</option>
        </select>
        <?php endif; ?>
        <?php if ($role !== 'user'): ?>
        <label style="display:flex;align-items:center;gap:6px;margin:0;">
            <input type="checkbox" name="mine" value="1" <?php echo $f['mine'] ? 'checked' : ''; ?>> Only my activity
        </label>
        <?php endif; ?>
        <button type="submit" class="btn-filter">Apply</button>
    </form>

    <?php if (!$rows): ?>
        <div class="empty-state">No records found.</div>
    <?php elseif ($tab === 'activity'): ?>
    <div style="overflow-x:auto;"><table>
        <thead><tr><th>User</th><th>Role</th><th>Action</th><th>Module</th><th>Description</th><th>IP</th><th>When</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?php echo slUserCell($r); ?></td>
                <td><?php echo slRoleBadge($r['role']); ?></td>
                <td><span class="status-badge status-<?php echo slh($r['status']); ?>"><?php echo slh($r['action']); ?></span></td>
                <td><?php echo slh($r['module']); ?></td>
                <td style="white-space:normal;min-width:220px;"><?php echo slh($r['description']); ?></td>
                <td class="clip-ref-cell"><?php echo slh($r['ip_address']); ?></td>
                <td title="<?php echo slh($r['created_at']); ?>">
    <span class="js-ago" data-age="<?php echo (int)$r['age_s']; ?>"><?php echo slAgo($r['age_s']); ?></span>
    <div class="audit-meta"><?php echo slh(date('M j, H:i:s', strtotime($r['created_at']))); ?></div>
</td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php else: ?>
    <div style="overflow-x:auto;"><table>
        <thead><tr><th>User</th><th>Role</th><th>Signed in</th><th>Last seen</th><th>Duration</th><th>IP</th><th>Device</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
            if ($r['logout_at'])          { $lbl = 'Signed out'; $cls = 'status-inactive'; }
            elseif ((int)$r['idle_s'] <= 1800) { $lbl = 'Active';     $cls = 'status-active'; }
            else                          { $lbl = 'Expired';    $cls = 'status-inactive'; } ?>
            <tr>
                <td><?php echo slUserCell($r); ?></td>
                <td><?php echo slRoleBadge($r['role']); ?></td>
                <td><?php echo slh(date('M j, H:i', strtotime($r['login_at']))); ?></td>
                <td><?php echo $r['logout_at'] ? slh(date('M j, H:i', strtotime($r['logout_at']))) : slAgo($r['idle_s']); ?></td>
                <td><?php echo slDur($r['dur_s']); ?></td>
                <td class="clip-ref-cell"><?php echo slh($r['ip_address']); ?></td>
                <td><?php echo slh(slDevice($r['user_agent'])); ?></td>
                <td><span class="status-badge <?php echo $cls; ?>"><?php echo $lbl; ?></span>
                    <?php if ($cur && $r['session_ref'] && hash_equals((string)$r['session_ref'], $cur)): ?><div class="audit-meta">This session</div><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>

    <div class="pagination">
        <?php for ($p = 1; $p <= $pages; $p++): ?>
            <a href="?<?php echo slh($qs(['page' => $p])); ?>" class="<?php echo $p === $page ? 'active' : ''; ?>"><?php echo $p; ?></a>
        <?php endfor; ?>
    </div>
    <?php
}

/** Compact "last 5 events" card for the dashboards. */
function renderSecurityLogCard(PDO $pdo, string $role, int $uid, string $href): void {
    $rows = [];
    $loadError = false;
    try {
        [$scope, $params] = slScope($role, $uid, 'l');
        $st = $pdo->prepare("SELECT l.*, u.name, GREATEST(0, TIMESTAMPDIFF(SECOND, l.created_at, NOW())) AS age_s
                             FROM user_activity_logs l LEFT JOIN users u ON u.id = l.user_id
                             WHERE $scope ORDER BY l.id DESC LIMIT 5");
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('HopeLine security activity card could not be loaded: ' . $e->getMessage());
        $loadError = true;
    }
    ?>
    <div class="card">
        <div class="card-header"><h2>Security Activity</h2><a href="<?php echo slh($href); ?>">View all →</a></div>
        <?php if ($loadError): ?><div class="alert-banner alert-error">Security activity could not be loaded. Refresh the page or contact an administrator.</div>
        <?php elseif (!$rows): ?><div class="empty-mini">No activity recorded yet.</div>
        <?php else: foreach ($rows as $r): ?>
            <div class="audit-row">
                <div>
                    <div class="audit-action"><?php echo slh($r['description']); ?></div>
                    <div class="audit-meta"><?php echo slh($r['email'] ?: 'unknown'); ?> · <?php echo slh(ucfirst((string)$r['role']) ?: 'guest'); ?> · <?php echo slAgo($r['age_s']); ?></div>
                </div>
                <span class="audit-status status-<?php echo slh($r['status']); ?>"><?php echo slh($r['status']); ?></span>
            </div>
        <?php endforeach; endif; ?>
        <script>
(function () {
    var t0 = Date.now();
    function fmt(s) {
        if (s < 60) return s + 's ago';
        if (s < 3600) return Math.floor(s / 60) + 'm ago';
        if (s < 86400) { var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60); return h + 'h' + (m ? ' ' + m + 'm' : '') + ' ago'; }
        return Math.floor(s / 86400) + 'd ago';
    }
    setInterval(function () {
        var d = Math.floor((Date.now() - t0) / 1000);
        document.querySelectorAll('.js-ago').forEach(function (el) {
            el.textContent = fmt(parseInt(el.dataset.age, 10) + d);
        });
    }, 15000);
})();
</script>
    </div>
    <?php
}
