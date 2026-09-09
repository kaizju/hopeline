<?php
/**
 * assets/layouts/sidebar_shell.php
 *
 * Single shared shell for every HopeLine sidebar (Admin, Manager, Responder).
 * Every role-specific sidebar calls render_sidebar_shell() so the header,
 * search bar, theme toggle, and footer markup exists in exactly one place.
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
    <script>
    (function() {
        const saved = localStorage.getItem('hopeline-theme') || 'dark';
        document.documentElement.setAttribute('data-theme', saved);
    })();
    </script>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/app.css">

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
            <div style="display:flex; align-items:center; gap:4px;">
                <button class="header-icon" id="themeToggle" title="Toggle light/dark mode" type="button">
                    <svg id="iconMoon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                    </svg>
                    <svg id="iconSun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display:none;">
                        <circle cx="12" cy="12" r="4"/>
                        <path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>
                    </svg>
                </button>
                <?php echo $headerIcon; ?>
            </div>
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

    <script>
    (function() {
        const btn = document.getElementById('themeToggle');
        const iconMoon = document.getElementById('iconMoon');
        const iconSun = document.getElementById('iconSun');

        function syncIcons(theme) {
            iconMoon.style.display = theme === 'light' ? 'none' : 'block';
            iconSun.style.display = theme === 'light' ? 'block' : 'none';
        }

        syncIcons(document.documentElement.getAttribute('data-theme') || 'dark');

        btn.addEventListener('click', function() {
            const isLight = document.documentElement.getAttribute('data-theme') === 'light';
            const next = isLight ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', next);
            localStorage.setItem('hopeline-theme', next);
            syncIcons(next);
        });
    })();
    </script>
    <?php
}