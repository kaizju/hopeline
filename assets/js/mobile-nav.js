/* =====================================================================
   assets/js/mobile-nav.js
   Mobile drawer navigation for the shared HopeLine sidebar shell
   (Admin, Manager, Responder). No dependencies, no markup changes needed.

   - Injects a fixed top bar (hamburger + brand) and a backdrop
   - Opens/closes the sidebar as a drawer at <= 900px
   - Closes on backdrop tap, Escape, nav link tap, or swipe-left
   - Wraps every <table> in .table-scroll so wide tables scroll sideways

   Include once, at the end of sidebar_shell.php (or every page footer):
     <script src="../../assets/js/mobile-nav.js" defer></script>
   ===================================================================== */
(function () {
    'use strict';

    var MOBILE_QUERY = '(max-width: 900px)';
    var mq = window.matchMedia(MOBILE_QUERY);

    function wrapTables() {
        var tables = document.querySelectorAll('table');
        for (var i = 0; i < tables.length; i++) {
            var t = tables[i];
            if (t.parentNode && t.parentNode.classList.contains('table-scroll')) continue;
            var wrap = document.createElement('div');
            wrap.className = 'table-scroll';
            t.parentNode.insertBefore(wrap, t);
            wrap.appendChild(t);
        }
    }

    function init() {
        wrapTables();

        var sidebar = document.querySelector('.sidebar');
        if (!sidebar) return;

        var body = document.body;
        if (!sidebar.id) sidebar.id = 'appSidebar';

        /* ---- Build top bar ---- */
        var nameEl = sidebar.querySelector('.brand-name');
        var roleEl = sidebar.querySelector('.brand-role');
        var markEl = sidebar.querySelector('.brand-mark');

        var bar = document.createElement('header');
        bar.className = 'm-topbar';

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'm-menu-btn';
        btn.setAttribute('aria-label', 'Open menu');
        btn.setAttribute('aria-controls', sidebar.id);
        btn.setAttribute('aria-expanded', 'false');
        btn.innerHTML =
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" ' +
            'stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>';

        var brand = document.createElement('div');
        brand.className = 'm-brand';
        if (markEl) brand.appendChild(markEl.cloneNode(true));

        var title = document.createElement('div');
        title.className = 'm-title';
        title.appendChild(document.createTextNode(nameEl ? nameEl.textContent.trim() : 'HopeLine'));
        if (roleEl && roleEl.textContent.trim()) {
            var small = document.createElement('small');
            small.textContent = roleEl.textContent.trim();
            title.appendChild(small);
        }
        brand.appendChild(title);

        bar.appendChild(btn);
        bar.appendChild(brand);

        var backdrop = document.createElement('div');
        backdrop.className = 'm-backdrop';
        backdrop.setAttribute('aria-hidden', 'true');

        body.insertBefore(bar, body.firstChild);
        body.appendChild(backdrop);

        /* ---- Open / close ---- */
        function isOpen() { return body.classList.contains('nav-open'); }

        function setOpen(open) {
            body.classList.toggle('nav-open', open);
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            btn.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
            if (open) {
                var first = sidebar.querySelector('.nav-item');
                if (first) first.focus({ preventScroll: true });
            }
        }

        btn.addEventListener('click', function () { setOpen(!isOpen()); });
        backdrop.addEventListener('click', function () { setOpen(false); btn.focus(); });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isOpen()) { setOpen(false); btn.focus(); }
        });

        sidebar.addEventListener('click', function (e) {
            var link = e.target.closest ? e.target.closest('.nav a') : null;
            if (link) setOpen(false);
        });

        /* Leaving mobile width (rotate / resize) resets the drawer */
        var onChange = function () { if (!mq.matches && isOpen()) setOpen(false); };
        if (mq.addEventListener) mq.addEventListener('change', onChange);
        else if (mq.addListener) mq.addListener(onChange);

        /* Swipe left on the drawer to close */
        var sx = null, sy = null;
        sidebar.addEventListener('touchstart', function (e) {
            var t = e.touches[0];
            sx = t.clientX; sy = t.clientY;
        }, { passive: true });
        sidebar.addEventListener('touchend', function (e) {
            if (sx === null) return;
            var t = e.changedTouches[0];
            var dx = t.clientX - sx, dy = t.clientY - sy;
            sx = sy = null;
            if (dx < -60 && Math.abs(dx) > Math.abs(dy) * 1.5) setOpen(false);
        }, { passive: true });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();