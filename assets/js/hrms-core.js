/**
 * MagDyn HRMS — Core JavaScript
 * Keyboard shortcuts, sidebar, DataTables, notifications
 */
(function () {
    'use strict';

    /* ═══════════════════════════════════════════════════════
       SIDEBAR TOGGLE
    ═══════════════════════════════════════════════════════ */
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const stored = localStorage.getItem('hrms_sidebar');
    if (stored === 'collapsed' && sidebar) sidebar.classList.add('collapsed');

    if (sidebarToggle) {
        sidebarToggle.addEventListener('click', () => {
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('hrms_sidebar', sidebar.classList.contains('collapsed') ? 'collapsed' : 'open');
        });
    }

    /* Mobile toggle */
    const mobileBtn = document.createElement('button');
    mobileBtn.className = 'mobile-toggle';
    mobileBtn.textContent = '☰';
    mobileBtn.setAttribute('aria-label', 'Open menu');
    mobileBtn.addEventListener('click', () => {
        sidebar.classList.toggle('mobile-open');
    });
    document.body.prepend(mobileBtn);

    /* ═══════════════════════════════════════════════════════
       KEYBOARD SHORTCUTS
       Global: Alt+key — highlight shown on any page
       Local:  defined per page via window.PAGE_SHORTCUTS
    ═══════════════════════════════════════════════════════ */
    const GLOBAL_SHORTCUTS = {
        's': () => { if (sidebar) { sidebar.classList.toggle('collapsed'); } },   // Alt+S: toggle sidebar
        'h': () => navigate(BASE_URL + '/index.php'),                               // Alt+H: home/dashboard
        'e': () => navigate(BASE_URL + '/modules/employee/index.php'),              // Alt+E: employees
        'a': () => navigate(BASE_URL + '/modules/attendance/index.php'),            // Alt+A: attendance
        'p': () => navigate(BASE_URL + '/modules/payroll/index.php'),               // Alt+P: payroll
        'l': () => navigate(BASE_URL + '/modules/letters/index.php'),               // Alt+L: letters
        't': () => navigate(BASE_URL + '/modules/training/index.php'),              // Alt+T: training
        'q': () => { if (confirm('Logout?')) navigate(BASE_URL + '/logout.php'); }, // Alt+Q: logout
        'n': () => toggleNotifPanel(),                                               // Alt+N: notifications
        '/': () => { const s = document.querySelector('[data-search]'); if (s) s.focus(); } // Alt+/: search
    };

    function navigate(url) { window.location.href = url; }

    let altActive = false;

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Alt') {
            altActive = true;
            document.body.classList.add('alt-active');
            const bar = document.getElementById('shortcutBar');
            if (bar) bar.style.display = 'block';
            return;
        }
        if (e.altKey) {
            const key = e.key.toLowerCase();
            // Local shortcuts first
            const localShortcuts = window.PAGE_SHORTCUTS || {};
            if (localShortcuts[key]) {
                e.preventDefault();
                localShortcuts[key]();
                return;
            }
            // Global shortcuts
            if (GLOBAL_SHORTCUTS[key]) {
                e.preventDefault();
                GLOBAL_SHORTCUTS[key]();
            }
        }
        // Escape: close modals
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal.open').forEach(m => m.classList.remove('open'));
            const np = document.getElementById('notifPanel');
            if (np) np.style.display = 'none';
        }
    });

    document.addEventListener('keyup', function (e) {
        if (e.key === 'Alt') {
            altActive = false;
            document.body.classList.remove('alt-active');
            const bar = document.getElementById('shortcutBar');
            if (bar) bar.style.display = 'none';
        }
    });

    /* ═══════════════════════════════════════════════════════
       NOTIFICATIONS
    ═══════════════════════════════════════════════════════ */
    function toggleNotifPanel() {
        const panel = document.getElementById('notifPanel');
        if (!panel) return;
        if (panel.style.display === 'none' || !panel.style.display) {
            loadNotifications(panel);
            panel.style.display = 'block';
        } else {
            panel.style.display = 'none';
        }
    }

    function loadNotifications(panel) {
        fetch(BASE_URL + '/api/notifications.php')
            .then(r => r.json())
            .then(data => {
                if (!data.notifications || data.notifications.length === 0) {
                    panel.innerHTML = '<div style="padding:16px;text-align:center;color:#6b7280;font-size:13px;">No notifications</div>';
                    return;
                }
                panel.innerHTML = '<div style="padding:10px 14px;font-weight:600;font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;border-bottom:1px solid #e3e5ea">Notifications</div>' +
                    data.notifications.map(n => `
                        <div class="notif-item ${n.is_read == 0 ? 'unread' : ''}">
                            <div>${escHtml(n.title)}</div>
                            <div style="font-size:12px;color:#6b7280;margin-top:2px">${escHtml(n.body)}</div>
                            <div class="notif-time">${escHtml(n.created_at)}</div>
                        </div>`).join('');
            })
            .catch(() => {});
    }

    // Close panel when clicking outside
    document.addEventListener('click', function (e) {
        const panel = document.getElementById('notifPanel');
        const trigger = document.getElementById('userMenuTrigger');
        if (panel && !panel.contains(e.target) && trigger && !trigger.contains(e.target)) {
            panel.style.display = 'none';
        }
    });

    const userTrigger = document.getElementById('userMenuTrigger');
    if (userTrigger) {
        userTrigger.addEventListener('click', toggleNotifPanel);
    }

    /* ═══════════════════════════════════════════════════════
       DATATABLES AUTO-INIT
       Any <table class="datatable"> auto-initialised
    ═══════════════════════════════════════════════════════ */
    document.addEventListener('DOMContentLoaded', function () {
        if (typeof jQuery === 'undefined' || typeof jQuery.fn.DataTable === 'undefined') return;
        jQuery('table.datatable').each(function () {
            if (jQuery.fn.DataTable.isDataTable(this)) return; // skip already-initialized tables
            const $t = jQuery(this);
            const opts = {
                pageLength: parseInt($t.data('page-length') || 25),
                order: [],
                language: {
                    search: '',
                    searchPlaceholder: 'Search...',
                    lengthMenu: 'Show _MENU_ rows',
                    paginate: { previous: '‹', next: '›' }
                },
                dom: '<"dt-top"lBf>rt<"dt-bottom"ip>',
                buttons: [
                    { extend: 'excelHtml5', text: '↓ Excel', className: 'btn btn-sm' },
                    { extend: 'print',      text: '⎙ Print', className: 'btn btn-sm' }
                ]
            };
            $t.DataTable(opts);
        });
    });

    /* ═══════════════════════════════════════════════════════
       MODAL HELPERS (global)
    ═══════════════════════════════════════════════════════ */
    window.openModal = function (id) {
        const m = document.getElementById(id);
        if (m) m.classList.add('open');
    };
    window.closeModal = function (id) {
        const m = document.getElementById(id);
        if (m) m.classList.remove('open');
    };

    // Close on backdrop click
    document.addEventListener('click', function (e) {
        if (e.target.classList.contains('modal')) {
            e.target.classList.remove('open');
        }
    });

    /* ═══════════════════════════════════════════════════════
       UTILITY
    ═══════════════════════════════════════════════════════ */
    function escHtml(str) {
        return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    window.BASE_URL = window.BASE_URL || '';
    // Expose for inline use
    window.toggleNotifPanel = toggleNotifPanel;

})();
