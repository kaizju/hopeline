<?php
require_once __DIR__ . '/../sidebar_shell.php';

$currentPage = basename($_SERVER['PHP_SELF'], '.php');

function navActive($page, $current) {
    return $page === $current ? 'active' : '';
}

$unreadAlerts = $unreadAlerts ?? 0;
$adminEmail   = $_SESSION['email'] ?? 'admin@hopeline.local';

$headerIcon = '
<a class="header-icon" href="/app/admin/alerts.php" title="Alerts">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/>
        <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
    </svg>
    ' . ($unreadAlerts > 0 ? '<div class="notif-dot"></div>' : '') . '
</a>';

ob_start();
?>
<div class="nav-section-label">Overview</div>
<a class="nav-item <?php echo navActive('dashboard', $currentPage); ?>" href="/app/admin/dashboard.php">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9.5 12 3l9 6.5V21a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/></svg>
    Dashboard
</a>
<a class="nav-item <?php echo navActive('incidents', $currentPage); ?>" href="/app/admin/incidents.php">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15V7a2 2 0 0 1 2-2h5l2 2h5a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/><path d="M12 11v4M12 8h.01"/></svg>
    Incident Records
</a>
<a class="nav-item <?php echo navActive('analytics', $currentPage); ?>" href="/app/admin/analytics.php">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2 2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
    Analytics &amp; Reports
</a>
<a class="nav-item <?php echo navActive('delays', $currentPage); ?>" href="/app/admin/delays.php">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
    Delay Reports
</a>

<div class="nav-section-label">Management</div>
<a class="nav-item <?php echo navActive('users', $currentPage); ?>" href="/app/admin/users.php">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>
    User Management
</a>
<a class="nav-item <?php echo navActive('units', $currentPage); ?>" href="/app/admin/units.php">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M14 9h4l4 4v4a1 1 0 0 1-1 1h-2"/><circle cx="6.5" cy="18.5" r="2.5"/><circle cx="17.5" cy="18.5" r="2.5"/></svg>
    PTV / Unit Management
</a>

<div class="nav-section-label">System</div>
<a class="nav-item <?php echo navActive('audit-trail', $currentPage); ?>" href="/app/admin/audit-trail.php">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 11V6a3 3 0 0 1 6 0v5"/><rect x="5" y="11" width="14" height="10" rx="2"/></svg>
    Audit Trail
</a>
<a class="nav-item <?php echo navActive('settings', $currentPage); ?>" href="/app/admin/settings.php">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
    System Settings
</a>
<?php
$navHtml = ob_get_clean();

render_sidebar_shell([
    'role_label'          => 'Admin Panel',
    'header_icon'         => $headerIcon,
    'search_placeholder'  => 'Search records, units, users...',
    'nav_html'            => $navHtml,
    'email'               => $adminEmail,
    'status_text'         => 'All systems operational',
    'status_color'        => '#4caf7d',
]);