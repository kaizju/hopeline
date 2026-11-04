<?php
/** Activity + session logger. Tables: user_activity_logs, user_sessions */

function logClientIp(): string {
    // REMOTE_ADDR only. X-Forwarded-For is user-controlled, so it can be spoofed in a security log.
    return substr($_SERVER['REMOTE_ADDR'] ?? 'Unknown', 0, 45);
}

function sessionRef(): ?string {
    return (session_status() === PHP_SESSION_ACTIVE && session_id() !== '') ? hash('sha256', session_id()) : null;
}

function activityMeta(string $action): array {
    static $map = [
        'login'                      => ['auth', 'Signed in'],
        'logout'                     => ['auth', 'Signed out'],
        'page_view'                  => ['navigation', 'Visited a page'],
        'report_export'              => ['reports', 'Exported a report'],
        'clip_report_created'        => ['clip_report', 'Logged a new CLIP report'],
        'dispatch_assigned'          => ['dispatch', 'Dispatched a team'],
        'returned_to_command_center' => ['dispatch', 'Team returned to command center'],
        'delay_reported'             => ['delays', 'Reported a delay'],
        'incident_cancelled'         => ['incidents', 'Cancelled an incident'],
        'incident_archived'          => ['incidents', 'Archived an incident'],
        'incident_restored'          => ['incidents', 'Restored an incident'],
        'incident_closeout_saved'    => ['incidents', 'Saved case details'],
        'user_created'               => ['users', 'Created a user account'],
        'user_archived'              => ['users', 'Archived a user account'],
        'user_restored'              => ['users', 'Restored a user account'],
        'unit_archived'              => ['units', 'Archived a unit'],
        'unit_restored'              => ['units', 'Restored a unit'],
        'create_team'                => ['teams', 'Created a team'],
        'update_team'                => ['teams', 'Updated a team'],
        'team_archived'              => ['teams', 'Archived a team'],
        'team_restored'              => ['teams', 'Restored a team'],
        'settings_updated'           => ['settings', 'Updated system settings'],
    ];
    return $map[$action] ?? ['system', ucfirst(str_replace('_', ' ', $action))];
}

function logActivity($pdo, $user_id, $email, $action, $status = 'success', $description = null, $module = null) {
    $ip = logClientIp();
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 255);

    [$m, $d] = activityMeta($action);
    if ($action === 'login' && $status === 'failed') $d = 'Failed sign-in attempt';
    $module      = $module ?: $m;
    $description = mb_substr($description ?: $d, 0, 255);
    $role = ($user_id && (int)($_SESSION['user_id'] ?? 0) === (int)$user_id) ? ($_SESSION['role'] ?? null) : null;

    $ok = false;
    try {
        $pdo->prepare("INSERT INTO user_activity_logs
                (user_id, email, role, action, module, description, status, ip_address, user_agent, session_ref)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$user_id, $email, $role, $action, $module, $description, $status, $ip, $ua, sessionRef()]);
        $ok = true;
    } catch (PDOException $e) {
        error_log('Activity Log Error: ' . $e->getMessage());
    }

    // legacy table, kept so the old Audit Trail page keeps working
    try {
        $pdo->prepare("INSERT INTO activity_logs (user_id, email, action, status, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$user_id, $email, $action, $status, $ip, $ua]);
    } catch (PDOException $e) {
        error_log('Legacy Activity Log Error: ' . $e->getMessage());
    }
    return $ok;
}

/* ---------- sessions ---------- */
function sessionStart(PDO $pdo, int $uid, string $email, string $role): void {
    try {
        $pdo->prepare("INSERT INTO user_sessions (user_id, email, role, session_ref, ip_address, user_agent)
                       VALUES (?, ?, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE login_at = NOW(), last_seen_at = NOW(), logout_at = NULL")
            ->execute([$uid, $email, $role, sessionRef(), logClientIp(), substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
    } catch (PDOException $e) { error_log($e->getMessage()); }
}

function sessionTouch(PDO $pdo): void {            // at most once per 60 s
    if (empty($_SESSION['user_id']) || session_status() !== PHP_SESSION_ACTIVE) return;
    if (time() - ($_SESSION['_touch'] ?? 0) < 60) return;
    $_SESSION['_touch'] = time();
    try {
        $pdo->prepare("UPDATE user_sessions SET last_seen_at = NOW() WHERE session_ref = ? AND logout_at IS NULL")
            ->execute([sessionRef()]);
    } catch (PDOException $e) { error_log($e->getMessage()); }
}

function sessionEnd(PDO $pdo): void {
    try {
        $pdo->prepare("UPDATE user_sessions SET logout_at = NOW() WHERE session_ref = ? AND logout_at IS NULL")
            ->execute([sessionRef()]);
    } catch (PDOException $e) { error_log($e->getMessage()); }
}

/* Automatic "Visited: ..." entries + report exports */
function trackPageView(PDO $pdo): void {
    if (empty($_SESSION['user_id']) || session_status() !== PHP_SESSION_ACTIVE) return;
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') return;

    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $file   = basename($script, '.php');
    if (strpos($script, '/api/') !== false || in_array($file, ['index', 'logout'], true)) return;
    if (($_SERVER['HTTP_SEC_FETCH_DEST'] ?? '') === 'empty') return;   // background fetch()/polling, not a real visit

    sessionTouch($pdo);
    $uid   = (int)$_SESSION['user_id'];
    $email = $_SESSION['email'] ?? '';

    if (strpos($file, 'export-') === 0) {
        logActivity($pdo, $uid, $email, 'report_export', 'success',
            'Exported: ' . ucwords(str_replace(['export-', '-'], ['', ' '], $file)), 'reports');
        return;
    }
    if (time() - ($_SESSION['_pv'][$script] ?? 0) < 300) return;      // same page: log once per 5 min
    $_SESSION['_pv'][$script] = time();
    logActivity($pdo, $uid, $email, 'page_view', 'success',
        'Visited: ' . ucwords(str_replace(['-', '_'], ' ', $file)), 'navigation');
}