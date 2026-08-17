<?php
/**
 * assets/layouts/manager/manager_sidebar.php
 *
 * Reusable sidebar for all LDRRMO Personnel/Manager (Dispatcher) pages.
 * Include this at the top of every app/manager/*.php file, e.g.:
 *
 *   <?php require_once __DIR__ . '/../../assets/layouts/manager/manager_sidebar.php'; ?>
 *
 * Expects (optional, falls back gracefully if not set):
 *   $_SESSION['email']       - logged-in manager's email, shown in footer
 *   $unreadAlerts (int)      - unread delay/alert count for the notification dot
 *
 * Active link is detected automatically from the current filename,
 * so no manual "active" flag needs to be passed per page.
 */

$currentPage = basename($_SERVER['PHP_SELF'], '.php');

function navActive($page, $current) {
    return $page === $current ? 'active' : '';
}

$unreadAlerts  = $unreadAlerts ?? 0;
$managerEmail  = $_SESSION['email'] ?? 'manager@hopeline.local';
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
                <div class="brand-role">Manager Panel</div>
            </div>
        </div>
        <a class="header-icon" href="<?php echo BASE_URL; ?>/app/manager/alerts.php" title="Delay Alerts">
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
        <input class="search-input" type="text" placeholder="Search incidents, units...">
        <div class="kbd"><span>⌘</span><span>K</span></div>
    </div>

    <nav class="nav">
        <div class="nav-section-label">Overview</div>
        <a class="nav-item <?php echo navActive('dashboard', $currentPage); ?>" href="<?php echo BASE_URL; ?>/app/manager/dashboard.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9.5 12 3l9 6.5V21a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/></svg>
            Dashboard
        </a>
        <a class="nav-item <?php echo navActive('live-map', $currentPage); ?>" href="<?php echo BASE_URL; ?>/app/manager/live-map.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 6v16l7-4 8 4 7-4V2l-7 4-8-4z"/><path d="M8 2v16M16 6v16"/></svg>
            Live Unit Map
        </a>

        <div class="nav-section-label">Dispatch</div>
        <a class="nav-item <?php echo navActive('clip-report', $currentPage); ?>" href="<?php echo BASE_URL; ?>/app/manager/clip-report.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
            New CLIP Report
        </a>
        <a class="nav-item <?php echo navActive('active-incidents', $currentPage); ?>" href="<?php echo BASE_URL; ?>/app/manager/active-incidents.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15V7a2 2 0 0 1 2-2h5l2 2h5a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/><path d="M12 11v4M12 8h.01"/></svg>
            Active Incidents
            <?php if ($unreadAlerts > 0): ?><span class="badge"><?php echo (int)$unreadAlerts; ?></span><?php endif; ?>
        </a>
        <a class="nav-item <?php echo navActive('delay-alerts', $currentPage); ?>" href="<?php echo BASE_URL; ?>/app/manager/delay-alerts.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
            Delay Alerts
        </a>

        <div class="nav-section-label">Records</div>
        <a class="nav-item <?php echo navActive('incident-history', $currentPage); ?>" href="<?php echo BASE_URL; ?>/app/manager/incident-history.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M18 17V9M13 17V5M8 17v-3"/></svg>
            Incident History
        </a>
    </nav>

  <div class="sidebar-footer">
        <div class="status-card">
            <div class="status-dot"></div>
            <div class="status-text">
                <div class="t1"><?php echo htmlspecialchars($managerEmail); ?></div>
                <div class="t2">On duty — Command Center</div>
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