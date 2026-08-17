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
<style>
    :root {
        --burnt-umber: #6d120b;
        --redwood: #b02029;
        --macadamia: #fbf0d8;
        --cool-blue: #113047;
        --light-grayish: #739ab9;
    }

    .sidebar {
        width: 248px;
        background: var(--cool-blue);
        height: 100vh;
        display: flex;
        flex-direction: column;
        border-right: 1px solid rgba(115, 154, 185, 0.15);
        flex-shrink: 0;
        font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
        position: sticky;
        top: 0;
    }

    .sidebar-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 18px 16px 14px;
    }

    .brand { display: flex; align-items: center; gap: 8px; }

    .brand-mark {
        width: 26px;
        height: 26px;
        border-radius: 6px;
        background: var(--burnt-umber);
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .brand-mark svg { width: 15px; height: 15px; }

    .brand-name {
        color: var(--macadamia);
        font-weight: 700;
        font-size: 14.5px;
        letter-spacing: 0.2px;
    }

    .brand-role {
        color: var(--light-grayish);
        font-size: 10px;
        font-weight: 600;
        letter-spacing: 0.6px;
        text-transform: uppercase;
    }

    .header-icon {
        width: 28px;
        height: 28px;
        border-radius: 6px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--light-grayish);
        cursor: pointer;
        position: relative;
        text-decoration: none;
    }

    .header-icon:hover { background: rgba(115, 154, 185, 0.12); color: var(--macadamia); }
    .header-icon svg { width: 16px; height: 16px; }

    .notif-dot {
        position: absolute;
        top: 5px;
        right: 6px;
        width: 6px;
        height: 6px;
        border-radius: 50%;
        background: var(--redwood);
        border: 1.5px solid var(--cool-blue);
    }

    .search-wrap { margin: 4px 12px 14px; position: relative; }

    .search-wrap svg {
        position: absolute;
        left: 10px;
        top: 50%;
        transform: translateY(-50%);
        width: 14px;
        height: 14px;
        color: var(--light-grayish);
    }

    .search-input {
        width: 100%;
        background: rgba(251, 240, 216, 0.06);
        border: 1px solid rgba(115, 154, 185, 0.2);
        border-radius: 7px;
        padding: 8px 10px 8px 30px;
        color: var(--macadamia);
        font-size: 12.5px;
        outline: none;
    }

    .search-input::placeholder { color: var(--light-grayish); }
    .search-input:focus { border-color: var(--redwood); }

    .kbd { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); display: flex; gap: 3px; }

    .kbd span {
        background: rgba(115, 154, 185, 0.18);
        color: var(--light-grayish);
        font-size: 10px;
        padding: 1.5px 5px;
        border-radius: 4px;
        font-family: monospace;
    }

    .nav { flex: 1; padding: 2px 10px; overflow-y: auto; }

    .nav-section-label {
        color: var(--light-grayish);
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 0.7px;
        text-transform: uppercase;
        padding: 14px 8px 6px;
        opacity: 0.65;
    }

    .nav-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 8px 10px;
        border-radius: 7px;
        color: var(--light-grayish);
        font-size: 13px;
        font-weight: 500;
        text-decoration: none;
        cursor: pointer;
        margin-bottom: 2px;
        transition: background 0.12s ease, color 0.12s ease;
    }

    .nav-item svg { width: 16px; height: 16px; flex-shrink: 0; }

    .nav-item:hover { background: rgba(251, 240, 216, 0.06); color: var(--macadamia); }

    .nav-item.active { background: var(--burnt-umber); color: var(--macadamia); }

    .nav-item .badge {
        margin-left: auto;
        background: var(--redwood);
        color: var(--macadamia);
        font-size: 10px;
        font-weight: 700;
        padding: 1px 6px;
        border-radius: 20px;
    }

   .sidebar-footer { padding: 12px; border-top: 1px solid rgba(115, 154, 185, 0.15); display: flex; align-items: center; gap: 8px; }
.status-card { flex: 1; min-width: 0; }

.logout-btn {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    color: var(--light-grayish);
    background: rgba(176, 32, 41, 0.08);
    border: 1px solid rgba(176, 32, 41, 0.25);
    text-decoration: none;
    transition: background 0.15s, color 0.15s;
}
.logout-btn:hover { background: var(--redwood); color: var(--macadamia); }
.logout-btn svg { width: 16px; height: 16px; }

    .status-card {
        background: rgba(251, 240, 216, 0.05);
        border: 1px solid rgba(115, 154, 185, 0.18);
        border-radius: 8px;
        padding: 10px 12px;
        display: flex;
        align-items: center;
        gap: 9px;
    }

    .status-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #4caf7d;
        box-shadow: 0 0 0 3px rgba(76, 175, 125, 0.18);
        flex-shrink: 0;
    }

    .status-text .t1 { color: var(--macadamia); font-size: 12px; font-weight: 600; }
    .status-text .t2 { color: var(--light-grayish); font-size: 10.5px; margin-top: 1px; }
</style>

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