<?php
/**
 * assets/layouts/sidebar_shell.php
 *
 * Single shared shell for every HopeLine sidebar (Admin, Responder, etc).
 * Both role-specific sidebars call render_sidebar_shell() so the header,
 * search bar, and footer markup exists in exactly one place, and both
 * always pull the same assets/css/sidebar.css.
 *
 * Usage from a role sidebar file:
 *
 *   ob_start();
 *   ?>
 *   <div class="nav-section-label">Overview</div>
 *   <a class="nav-item ..." href="...">...</a>
 *   <?php
 *   $navHtml = ob_get_clean();
 *
 *   render_sidebar_shell([
 *       'role_label'   => 'Admin Panel',
 *       'header_icon'  => $headerIconHtml,   // optional, e.g. alerts bell
 *       'search_placeholder' => 'Search records, units, users...',
 *       'nav_html'     => $navHtml,
 *       'email'        => $adminEmail,
 *       'status_text'  => 'All systems operational',
 *       'status_color' => '#4caf7d',
 *   ]);
 */

function render_sidebar_shell(array $opts): void {
    $roleLabel   = $opts['role_label']   ?? '';
    $headerIcon  = $opts['header_icon']  ?? '';
    $placeholder = $opts['search_placeholder'] ?? 'Search...';
    $navHtml     = $opts['nav_html']     ?? '';
    $email       = $opts['email']        ?? '';
    $statusText  = $opts['status_text']  ?? '';
    $statusColor = $opts['status_color'] ?? '#4caf7d';
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
                    <div class="brand-role"><?php echo htmlspecialchars($roleLabel); ?></div>
                </div>
            </div>
            <?php echo $headerIcon; ?>
        </div>

        <div class="search-wrap">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>
            </svg>
            <input class="search-input" type="text" placeholder="<?php echo htmlspecialchars($placeholder); ?>">
            <div class="kbd"><span>⌘</span><span>K</span></div>
        </div>

        <nav class="nav">
            <?php echo $navHtml; ?>
        </nav>

        <div class="sidebar-footer">
            <div class="status-card">
                <div class="status-dot" style="background: <?php echo htmlspecialchars($statusColor); ?>; box-shadow: 0 0 0 3px <?php echo htmlspecialchars($statusColor); ?>2e;"></div>
                <div class="status-text">
                    <div class="t1"><?php echo htmlspecialchars($email); ?></div>
                    <div class="t2"><?php echo htmlspecialchars($statusText); ?></div>
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
    <?php
}