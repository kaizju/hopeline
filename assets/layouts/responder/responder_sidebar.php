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
 *
 * Shares its header/search/footer markup with the admin sidebar via
 * sidebar_shell.php — both pull the same assets/css/sidebar.css.
 */

require_once __DIR__ . '/../sidebar_shell.php';

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

$headerIcon = '
<a class="header-icon" href="' . BASE_URL . '/app/responder/profile.php" title="Profile">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
    </svg>
</a>';

ob_start();
?>
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
<?php
$navHtml = ob_get_clean();

render_sidebar_shell([
    'role_label'          => 'Responder App',
    'header_icon'         => $headerIcon,
    'search_placeholder'  => 'Search past dispatches...',
    'nav_html'            => $navHtml,
    'email'               => $responderEmail,
    'status_text'         => 'Status: ' . $unitStatus,
    'status_color'        => $statusColor,
]);