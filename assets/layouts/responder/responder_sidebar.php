<?php
/**
 * assets/layouts/responder/responder_sidebar.php
 *
 * Reusable sidebar for all Barangay Responder (PTV Driver/Field Unit) pages.
 * Include this at the top of every app/responder/*.php file, e.g.:
 *
 *   <?php require_once __DIR__ . '/../../assets/layouts/responder/responder_sidebar.php'; ?>
 *
 * Expects (optional, falls back gracefully if not set):
 *   $_SESSION['email']       - logged-in responder's email, shown in footer
 *   $unitStatus (string)     - 'Available' | 'En Route' | 'On Site' | 'Returning'
 *
 * Active link is detected automatically from the current filename,
 * so no manual "active" flag needs to be passed per page.
 */

$currentPage = basename($_SERVER['PHP_SELF'], '.php');

function navActive($page, $current) {
    return $page === $current ? 'active' : '';
}

$unitStatus     = $unitStatus ?? 'Available';
$responderEmail = $_SESSION['email'] ?? 'responder@hopeline.local';

$statusColors = [
    'Available' => '#4caf7d',
    'En Route'  => '#e0a526',
    'On Site'   => '#b02029',
    'Returning' => '#739ab9',
];
$statusColor = $statusColors[$unitStatus] ?? '#4caf7d';
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

    .sidebar-footer { padding: 12px; border-top: 1px solid rgba(115, 154, 185, 0.15); }

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
                <div class="brand-role">Responder App</div>
            </div>
        </div>
        <a class="header-icon" href="<?php echo BASE_URL; ?>/app/responder/profile.php" title="Profile">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
            </svg>
        </a>
    </div>

    <div class="search-wrap">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>
        </svg>
        <input class="search-input" type="text" placeholder="Search past dispatches...">
        <div class="kbd"><span>⌘</span><span>K</span></div>
    </div>

    <nav class="nav">
        <div class="nav-section-label">Overview</div>
        <a class="nav-item <?php echo navActive('dashboard', $currentPage); ?>" href="<?php echo BASE_URL; ?>/app/responder/dashboard.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9.5 12 3l9 6.5V21a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/></svg>
            Dashboard
        </a>

        <div class="nav-section-label">Current Dispatch</div>
        <a class="nav-item <?php echo navActive('assignment', $currentPage); ?>" href="<?php echo BASE_URL; ?>/app/responder/assignment.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 15V7a2 2 0 0 1 2-2h5l2 2h5a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/><path d="M12 11v4M12 8h.01"/></svg>
            Assigned Incident
        </a>
        <a class="nav-item <?php echo navActive('eta-log', $currentPage); ?>" href="<?php echo BASE_URL; ?>/app/responder/eta-log.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
            Depart / Arrive Log
        </a>
        <a class="nav-item <?php echo navActive('report-delay', $currentPage); ?>" href="<?php echo BASE_URL; ?>/app/responder/report-delay.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
            Report Delay
        </a>

        <div class="nav-section-label">Records</div>
        <a class="nav-item <?php echo navActive('my-history', $currentPage); ?>" href="<?php echo BASE_URL; ?>/app/responder/my-history.php">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="M18 17V9M13 17V5M8 17v-3"/></svg>
            My Dispatch History
        </a>
    </nav>

    <div class="sidebar-footer">
        <div class="status-card">
            <div class="status-dot" style="background: <?php echo $statusColor; ?>; box-shadow: 0 0 0 3px <?php echo $statusColor; ?>2e;"></div>
            <div class="status-text">
                <div class="t1"><?php echo htmlspecialchars($responderEmail); ?></div>
                <div class="t2">Status: <?php echo htmlspecialchars($unitStatus); ?></div>
            </div>
        </div>
    </div>
</div>