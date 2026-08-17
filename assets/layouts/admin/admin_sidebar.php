<?php


$currentPage = basename($_SERVER['PHP_SELF'], '.php');

function navActive($page, $current) {
    return $page === $current ? 'active' : '';
}

$unreadAlerts = $unreadAlerts ?? 0;
$adminEmail   = $_SESSION['email'] ?? 'admin@hopeline.local';
?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/sidebar.css">

<div class="sidebar">
    <div class="sidebar-header">
        <div class="brand">
            <div class="brand-mark">
                <svg viewBox="0 0 24 24" fill="none" stroke="#fbf0d8" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 21s-7-4.35-9.5-9C.5 8 2 4 6 4c2.2 0 3.5 1.2 4 2 1-1.5 2.5-2 4-2 4 0 5.5 4 3.5 8-2.5 4.65-5.5 9-5.5 9z"/>
                </svg>
            </div>
            <div>
                <div class="brand-name">HopeLine</div>
                <div class="brand-role">Admin Panel</div>
            </div>
        </div>
        <a class="header-icon" href="/app/admin/alerts.php" title="Alerts">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/>
                <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
            </svg>
            <?php if ($unreadAlerts > 0): ?>
                <div class="notif-dot"></div>
            <?php endif; ?>
        </a>
    </div>

    <div class="search-wrap">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>
        </svg>
        <input class="search-input" type="text" placeholder="Search records, units, users...">
        <div class="kbd"><span>⌘</span><span>K</span></div>
    </div>

    <nav class="nav">
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
    </nav>

  <div class="sidebar-footer">
        <div class="status-card">
            <div class="status-dot"></div>
            <div class="status-text">
                <div class="t1"><?php echo htmlspecialchars($adminEmail); ?></div>
                <div class="t2">All systems operational</div>
            </div>
        </div>
        <a class="logout-btn" href="<?php echo BASE_URL; ?>/logout.php" title="Log out" onclick="return confirm('Log out of HopeLine?');">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                <path d="M16 17l5-5-5-5"/>
                <path d="M21 12H9"/>
            </svg>
        </a>
    </div>
</div>