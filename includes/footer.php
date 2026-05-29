
    </main><!-- /#mainContent -->
</div><!-- /.main-wrapper -->
</div><!-- /.hrms-layout -->

<!-- Bootstrap 5 bundle (includes Popper — needed for dropdowns) -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- jQuery + DataTables -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
<!-- HRMS core JS -->
<script src="<?= BASE_URL ?>/assets/js/hrms-core.js"></script>

<script>
(function () {
    'use strict';

    const sidebar        = document.getElementById('sidebar');
    const overlay        = document.getElementById('sidebarOverlay');
    const collapseBtn    = document.getElementById('sidebarCollapseBtn');
    const collapseIcon   = document.getElementById('sidebarCollapseIcon');
    const toggleMobile   = document.getElementById('sidebarToggleMobile');
    const toggleDesktop  = document.getElementById('sidebarToggleDesktop');
    const STORAGE_KEY    = 'hrms_sidebar_collapsed';

    // ── Restore collapsed state from localStorage ─────────────────────
    if (localStorage.getItem(STORAGE_KEY) === '1') {
        sidebar.classList.add('collapsed');
        if (collapseIcon) collapseIcon.style.transform = 'rotate(180deg)';
    }

    // ── Desktop: collapse / expand sidebar ───────────────────────────
    function toggleCollapse() {
        const isNowCollapsed = sidebar.classList.toggle('collapsed');
        localStorage.setItem(STORAGE_KEY, isNowCollapsed ? '1' : '0');
        if (collapseIcon) {
            collapseIcon.style.transform = isNowCollapsed ? 'rotate(180deg)' : '';
        }
    }
    if (collapseBtn)   collapseBtn.addEventListener('click', toggleCollapse);
    if (toggleDesktop) toggleDesktop.addEventListener('click', toggleCollapse);

    // ── Mobile: slide sidebar in/out ──────────────────────────────────
    function openMobile() {
        sidebar.classList.add('mobile-open');
        overlay.classList.add('active');
    }
    function closeMobile() {
        sidebar.classList.remove('mobile-open');
        overlay.classList.remove('active');
    }
    if (toggleMobile) toggleMobile.addEventListener('click', openMobile);
    if (overlay)      overlay.addEventListener('click', closeMobile);

    // ── Chevron rotation on Bootstrap collapse events ─────────────────
    document.querySelectorAll('#sidebar [data-bs-toggle="collapse"]').forEach(function (trigger) {
        const targetId = trigger.getAttribute('href') || trigger.getAttribute('data-bs-target');
        if (!targetId) return;
        const panel = document.querySelector(targetId);
        if (!panel) return;
        panel.addEventListener('show.bs.collapse', function () {
            trigger.setAttribute('aria-expanded', 'true');
        });
        panel.addEventListener('hide.bs.collapse', function () {
            trigger.setAttribute('aria-expanded', 'false');
        });
    });
})();
</script>

<!-- PWA Service Worker -->
<script>
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('<?= BASE_URL ?>/sw.js')
        .then(() => console.log('SW registered'))
        .catch(err => console.warn('SW registration failed', err));
}
</script>

<?php if (isset($page_scripts)) echo $page_scripts; ?>
</body>
</html>
