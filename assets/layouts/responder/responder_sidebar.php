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
        <a class="logout-btn" href="<?php echo BASE_URL; ?>/logout.php" title="Log out" onclick="return confirm('Log out of HopeLine?');">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                <path d="M16 17l5-5-5-5"/>
                <path d="M21 12H9"/>
            </svg>
        </a>
    </div>
</div>