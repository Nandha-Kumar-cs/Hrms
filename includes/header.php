<?php
/**
 * MagDyn HRMS — Layout Header Partial
 * Usage: set $page_title (and optionally $extra_head) before including this file.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$flash       = render_flash();
$user        = current_user();
$notif_count = count(get_notifications($user['id'], true));

// ── Role shortcuts ────────────────────────────────────────────────────────────
$_sbRole  = strtolower($user['role_name'] ?? '');
$_sbAdmin = ($_sbRole === 'super admin' || $_sbRole === 'admin');
$_sbPriv  = $_sbAdmin || in_array($_sbRole, ['manager', 'hr manager']);

// ── Active-state helper ───────────────────────────────────────────────────────
function _sb_active(string ...$fragments): string {
    $uri = strtolower($_SERVER['REQUEST_URI'] ?? '');
    foreach ($fragments as $f) {
        if ($f !== '' && strpos($uri, strtolower($f)) !== false) return 'active';
    }
    return '';
}

// ── Entity logo lookup ────────────────────────────────────────────────────────
$_sbLogoUrl = null;
try {
    $stmt = db()->prepare(
        "SELECT logo FROM entities
         WHERE (name LIKE '%magneto%' OR name LIKE '%dynamics%')
           AND logo IS NOT NULL AND logo != ''
         LIMIT 1"
    );
    $stmt->execute();
    $logoFile = $stmt->fetchColumn();
    if ($logoFile && file_exists(BASE_PATH . '/storage/entities/' . $logoFile)) {
        $_sbLogoUrl = BASE_URL . '/storage/entities/' . $logoFile;
    }
} catch (Exception $_e) { /* non-fatal */ }

// ── Pre-compute collapsed/open state for each submenu group ──────────────────
$_empActive  = _sb_active('/modules/employee/', '/modules/letters/');
$_assetActive = _sb_active('/modules/assets/');
$_payActive  = _sb_active('/modules/payroll/');
$_attActive  = _sb_active('/modules/attendance/');
$_repActive  = _sb_active('report=');
$_settActive = _sb_active('/modules/settings/', '/modules/roles/', '/modules/pwa/');

// ── Navbar: role badge colour map ─────────────────────────────────────────────
$_roleColours = [
    'super admin' => 'danger',  'admin'      => 'danger',
    'hr manager'  => 'primary', 'manager'    => 'primary',
    'hr executive'=> 'info',    'finance'    => 'warning',
    'employee'    => 'secondary',
];
$_roleBadge = $_roleColours[$_sbRole] ?? 'secondary';
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="<?= PWA_THEME_COLOR ?>">
    <link rel="manifest" href="<?= BASE_URL ?>/manifest.json">
    <title><?= h($page_title ?? 'Dashboard') ?> — <?= h(APP_NAME) ?></title>

    <!-- Bootstrap 5 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
    <!-- HRMS custom styles -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/magdyn-base.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/hrms.css">

    <style>
    /* ── Layout shell ─────────────────────────────────────────────────── */
    *, *::before, *::after { box-sizing: border-box; }
    body { margin: 0; background: #f4f5f7; font-family: system-ui, -apple-system, sans-serif; }

    .hrms-layout { display: flex; min-height: 100vh; }

    /* ── Sidebar ──────────────────────────────────────────────────────── */
    #sidebar {
        width: 250px;
        min-height: 100vh;
        background: #0f172a;
        display: flex;
        flex-direction: column;
        flex-shrink: 0;
        transition: width .22s ease;
        overflow: hidden;
        position: sticky;
        top: 0;
        height: 100vh;
        z-index: 1040;
    }
    #sidebar.collapsed { width: 62px; }
    #sidebar.collapsed .sidebar-brand-text,
    #sidebar.collapsed .nav-label,
    #sidebar.collapsed .nav-chevron,
    #sidebar.collapsed .sidebar-section,
    #sidebar.collapsed .sidebar-bottom-text { display: none !important; }
    #sidebar.collapsed .sidebar-brand-icon { margin: 0 auto; }
    #sidebar.collapsed .sidebar-nav .nav-link { justify-content: center; padding: 10px 0; }
    #sidebar.collapsed #sidebarCollapseIcon { transform: rotate(180deg); }

    .sidebar-brand {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 16px 14px 14px;
        text-decoration: none;
        border-bottom: 1px solid #1e293b;
        flex-shrink: 0;
    }
    .sidebar-brand-icon {
        width: 36px; height: 36px;
        background: #1e3a8a;
        border-radius: 8px;
        display: flex; align-items: center; justify-content: center;
        color: white; font-size: 18px; font-weight: bold;
        flex-shrink: 0;
    }
    .sidebar-brand-text span { color: #93c5fd; font-weight: 700; font-size: 15px; }
    .sidebar-brand-text { color: #f1f5f9; font-weight: 700; font-size: 15px; }

    .sidebar-nav {
        list-style: none;
        margin: 0;
        padding: 8px 0;
        flex: 1;
        overflow-y: auto;
        overflow-x: hidden;
    }
    .sidebar-nav::-webkit-scrollbar { width: 4px; }
    .sidebar-nav::-webkit-scrollbar-track { background: transparent; }
    .sidebar-nav::-webkit-scrollbar-thumb { background: #334155; border-radius: 2px; }

    .sidebar-section {
        padding: 10px 16px 4px;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: .08em;
        color: #475569;
        text-transform: uppercase;
    }

    .sidebar-nav .nav-link {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 9px 16px;
        color: #94a3b8;
        font-size: 13.5px;
        text-decoration: none;
        border-radius: 0;
        transition: background .15s, color .15s;
        cursor: pointer;
        border: none;
        background: none;
        width: 100%;
        white-space: nowrap;
    }
    .sidebar-nav .nav-link:hover { background: #1e293b; color: #e2e8f0; }
    .sidebar-nav .nav-link.active { background: #1e293b; color: #fff; border-left: 3px solid #3b82f6; }

    .nav-icon { width: 18px; text-align: center; flex-shrink: 0; font-size: 14px; }
    .nav-label { flex: 1; }
    .nav-chevron { font-size: 10px; transition: transform .2s; margin-left: auto; flex-shrink: 0; }
    .sidebar-nav .nav-link[aria-expanded="true"] .nav-chevron { transform: rotate(180deg); }

    .sidebar-submenu {
        list-style: none;
        margin: 0;
        padding: 2px 0 4px 0;
        background: #070e1a;
    }
    .sidebar-submenu .nav-link {
        padding: 7px 16px 7px 44px;
        font-size: 13px;
        color: #64748b;
    }
    .sidebar-submenu .nav-link:hover { color: #cbd5e1; background: #0f172a; }
    .sidebar-submenu .nav-link.active { color: #93c5fd; background: #0f172a; border-left: 3px solid #3b82f6; }

    /* ── Main wrapper ─────────────────────────────────────────────────── */
    .main-wrapper {
        flex: 1;
        display: flex;
        flex-direction: column;
        min-width: 0;
    }

    /* ── Topbar ───────────────────────────────────────────────────────── */
    #topbar {
        background: #fff;
        border-bottom: 1px solid #e2e8f0;
        padding: 0 20px;
        height: 56px;
        flex-shrink: 0;
        position: sticky;
        top: 0;
        z-index: 1030;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    #topbar .breadcrumb { margin: 0; }
    #topbar .breadcrumb-item a { color: #3b82f6; text-decoration: none; }
    #topbar .breadcrumb-item.active { color: #64748b; }

    #topbar .user-avatar {
        width: 32px; height: 32px;
        border-radius: 50%;
        background: #1e3a8a;
        color: #fff;
        display: flex; align-items: center; justify-content: center;
        font-size: .8rem; font-weight: 600;
        flex-shrink: 0;
    }

    /* ── Page content area ────────────────────────────────────────────── */
    #mainContent { flex: 1; padding: 24px; overflow-y: auto; }

    /* ── Bottom collapse button ───────────────────────────────────────── */
    .sidebar-bottom {
        padding: 10px 12px;
        border-top: 1px solid #1e293b;
        display: flex;
        align-items: center;
        gap: 8px;
        flex-shrink: 0;
    }
    #sidebarCollapseBtn {
        background: none;
        border: 1px solid #334155;
        color: #64748b;
        font-size: 12px;
        cursor: pointer;
        padding: 5px 8px;
        border-radius: 5px;
        flex-shrink: 0;
        line-height: 1;
        transition: all .18s;
    }
    #sidebarCollapseBtn:hover { border-color: #64748b; color: #94a3b8; }
    #sidebarCollapseIcon { transition: transform .22s; display: inline-block; }

    .sidebar-bottom-text {
        background: none;
        border: none;
        color: #334155;
        font-size: 11px;
        cursor: pointer;
        flex: 1;
        text-align: left;
        padding: 0;
        white-space: nowrap;
        overflow: hidden;
    }
    .sidebar-bottom-text:hover { color: #64748b; }

    /* ── Mobile overlay ───────────────────────────────────────────────── */
    @media (max-width: 767px) {
        #sidebar { position: fixed; left: -250px; transition: left .22s ease; }
        #sidebar.mobile-open { left: 0; }
        #sidebar.collapsed { width: 250px; left: -250px; }
        #sidebar.collapsed.mobile-open { left: 0; }
        #mainContent { padding: 16px; }
        .sidebar-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(0,0,0,.4);
            z-index: 1039;
        }
        .sidebar-overlay.active { display: block; }
    }
    </style>

    <?php if (!empty($extra_head)) echo $extra_head; ?>
</head>
<body>
<div class="hrms-layout">

<!-- ═══════════════════════ SIDEBAR ═══════════════════════════════════════ -->
<nav id="sidebar">

    <!-- Brand -->
    <a href="<?= BASE_URL ?>/index.php" class="sidebar-brand">
        <div class="sidebar-brand-icon">
            <?php if ($_sbLogoUrl): ?>
                <img src="<?= h($_sbLogoUrl) ?>" alt="Logo"
                     style="width:36px;height:36px;object-fit:contain;border-radius:6px;">
            <?php else: ?>
                &#9878;
            <?php endif; ?>
        </div>
        <div class="sidebar-brand-text"><span>HR</span>MS</div>
    </a>

    <ul class="sidebar-nav">

        <!-- Dashboard -->
        <li>
            <a href="<?= BASE_URL ?>/index.php"
               class="nav-link <?= _sb_active('/hrms/index.php', '/hrms/') && !_sb_active('/modules/') ? 'active' : '' ?>"
               title="Dashboard">
                <i class="fa fa-gauge nav-icon"></i>
                <span class="nav-label">Dashboard</span>
            </a>
        </li>

        <!-- ── PEOPLE ──────────────────────────────────────────────────── -->
        <div class="sidebar-section">People</div>

        <!-- Employees submenu -->
        <li>
            <a class="nav-link <?= $_empActive ?>"
               data-bs-toggle="collapse" href="#empMenu" role="button"
               aria-expanded="<?= $_empActive ? 'true' : 'false' ?>"
               title="Employees">
                <i class="fa fa-users nav-icon"></i>
                <span class="nav-label">Employees</span>
                <i class="fa fa-chevron-down nav-chevron"></i>
            </a>
            <div class="collapse <?= $_empActive ? 'show' : '' ?>" id="empMenu">
                <ul class="sidebar-submenu">
                    <li>
                        <a href="<?= BASE_URL ?>/modules/employee/index.php"
                           class="nav-link <?= _sb_active('/modules/employee/') ?>">
                            Employees List
                        </a>
                    </li>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/letters/index.php?type=offer"
                           class="nav-link <?= _sb_active('type=offer') ?>">
                            Offer Letters
                        </a>
                    </li>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/letters/index.php?type=confirmation"
                           class="nav-link <?= _sb_active('type=confirmation') ?>">
                            Confirmation Letters
                        </a>
                    </li>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/letters/index.php?type=increment"
                           class="nav-link <?= _sb_active('type=increment') ?>">
                            Increment Letters
                        </a>
                    </li>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/employee/view.php?tab=documents"
                           class="nav-link <?= _sb_active('tab=documents') ?>">
                            Documents
                        </a>
                    </li>
                </ul>
            </div>
        </li>

        <!-- Assets submenu -->
        <li>
            <a class="nav-link <?= $_assetActive ?>"
               data-bs-toggle="collapse" href="#assetMenu" role="button"
               aria-expanded="<?= $_assetActive ? 'true' : 'false' ?>"
               title="Assets">
                <i class="fa fa-laptop nav-icon"></i>
                <span class="nav-label">Assets</span>
                <i class="fa fa-chevron-down nav-chevron"></i>
            </a>
            <div class="collapse <?= $_assetActive ? 'show' : '' ?>" id="assetMenu">
                <ul class="sidebar-submenu">
                    <li>
                        <a href="<?= BASE_URL ?>/modules/assets/index.php"
                           class="nav-link <?= _sb_active('/modules/assets/index', '/modules/assets/view', '/modules/assets/assign') ?>">
                            Company Assets
                        </a>
                    </li>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/assets/clearance.php"
                           class="nav-link <?= _sb_active('/modules/assets/clearance') ?>">
                            No Due Certificates
                        </a>
                    </li>
                </ul>
            </div>
        </li>

        <!-- ── PAYROLL ─────────────────────────────────────────────────── -->
        <div class="sidebar-section">Payroll</div>

        <!-- Payroll submenu -->
        <li>
            <a class="nav-link <?= $_payActive ?>"
               data-bs-toggle="collapse" href="#payMenu" role="button"
               aria-expanded="<?= $_payActive ? 'true' : 'false' ?>"
               title="Payroll">
                <i class="fa fa-money-bill-wave nav-icon"></i>
                <span class="nav-label">Payroll</span>
                <i class="fa fa-chevron-down nav-chevron"></i>
            </a>
            <div class="collapse <?= $_payActive ? 'show' : '' ?>" id="payMenu">
                <ul class="sidebar-submenu">
                    <li>
                        <a href="<?= BASE_URL ?>/modules/payroll/process.php"
                           class="nav-link <?= _sb_active('/payroll/process') ?>">
                            Salary Calculation
                        </a>
                    </li>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/payroll/index.php"
                           class="nav-link <?= _sb_active('/payroll/index', '/payroll/slip', '/payroll/history') ?>">
                            Salary Slips
                        </a>
                    </li>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/payroll/salary_structure.php"
                           class="nav-link <?= _sb_active('/payroll/salary_structure') ?>">
                            Salary Structure
                        </a>
                    </li>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/payroll/finalize.php"
                           class="nav-link <?= _sb_active('/payroll/finalize') ?>">
                            Increments
                        </a>
                    </li>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/payroll/history.php"
                           class="nav-link <?= _sb_active('/payroll/history') ?>">
                            Bonuses &amp; Incentives
                        </a>
                    </li>
                </ul>
            </div>
        </li>

        <!-- ── ATTENDANCE ──────────────────────────────────────────────── -->
        <li>
            <a class="nav-link <?= $_attActive ?>"
               data-bs-toggle="collapse" href="#attMenu" role="button"
               aria-expanded="<?= $_attActive ? 'true' : 'false' ?>"
               title="Attendance">
                <i class="fa fa-calendar-check nav-icon"></i>
                <span class="nav-label">Attendance</span>
                <i class="fa fa-chevron-down nav-chevron"></i>
            </a>
            <div class="collapse <?= $_attActive ? 'show' : '' ?>" id="attMenu">
                <ul class="sidebar-submenu">
                    <li>
                        <a href="<?= BASE_URL ?>/modules/attendance/mark.php"
                           class="nav-link <?= _sb_active('/attendance/mark') ?>">
                            Mark Attendance
                        </a>
                    </li>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/attendance/report.php"
                           class="nav-link <?= _sb_active('/attendance/report') ?>">
                            Attendance Report
                        </a>
                    </li>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/attendance/index.php?tab=leave"
                           class="nav-link <?= _sb_active('tab=leave') ?>">
                            Leave Requests
                        </a>
                    </li>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/attendance/index.php?tab=leave-history"
                           class="nav-link <?= _sb_active('tab=leave-history') ?>">
                            Leave History
                        </a>
                    </li>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/attendance/calendar.php"
                           class="nav-link <?= _sb_active('/attendance/calendar') ?>">
                            Holidays
                        </a>
                    </li>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/attendance/comp_off.php"
                           class="nav-link <?= _sb_active('/attendance/comp_off') ?>">
                            Comp Offs
                        </a>
                    </li>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/attendance/od_requests.php"
                           class="nav-link <?= _sb_active('/attendance/od_requests') ?>">
                            On Duty (OD)
                        </a>
                    </li>
                </ul>
            </div>
        </li>

        <!-- ── REPORTS ─────────────────────────────────────────────────── -->
        <?php if ($_sbPriv): ?>
        <li>
            <a class="nav-link <?= $_repActive ?>"
               data-bs-toggle="collapse" href="#repMenu" role="button"
               aria-expanded="<?= $_repActive ? 'true' : 'false' ?>"
               title="Reports">
                <i class="fa fa-chart-bar nav-icon"></i>
                <span class="nav-label">Reports</span>
                <i class="fa fa-chevron-down nav-chevron"></i>
            </a>
            <div class="collapse <?= $_repActive ? 'show' : '' ?>" id="repMenu">
                <ul class="sidebar-submenu">
                    <li>
                        <a href="<?= BASE_URL ?>/modules/payroll/export.php?report=benefits"
                           class="nav-link <?= _sb_active('report=benefits') ?>">
                            Monthly Benefits
                        </a>
                    </li>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/payroll/export.php?report=bonuses"
                           class="nav-link <?= _sb_active('report=bonuses') ?>">
                            Bonus Report
                        </a>
                    </li>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/employee/view.php?tab=history"
                           class="nav-link <?= _sb_active('tab=history') ?>">
                            Employee History
                        </a>
                    </li>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/payroll/export.php?report=payroll-impact"
                           class="nav-link <?= _sb_active('report=payroll-impact') ?>">
                            Payroll Impact
                        </a>
                    </li>
                </ul>
            </div>
        </li>
        <?php endif; ?>

        <!-- ── LEARNING ─────────────────────────────────────────────────── -->
        <div class="sidebar-section">Learning</div>

        <li>
            <a href="<?= BASE_URL ?>/modules/training/index.php"
               class="nav-link <?= _sb_active('/modules/training/') ?>"
               title="Training">
                <i class="fa fa-graduation-cap nav-icon"></i>
                <span class="nav-label">Training</span>
            </a>
        </li>

        <!-- ── AUDIT (Admin only) ─────────────────────────────────────── -->
        <?php if ($_sbAdmin): ?>
        <div class="sidebar-section">Audit</div>
        <li>
            <a href="<?= BASE_URL ?>/modules/settings/index.php?tab=activity-log"
               class="nav-link <?= _sb_active('tab=activity-log') ?>">
                <i class="fa fa-clock-rotate-left nav-icon"></i>
                <span class="nav-label">Activity Log</span>
            </a>
        </li>
        <?php endif; ?>

        <!-- ── SETTINGS (Admin / Manager) ────────────────────────────── -->
        <?php if ($_sbPriv): ?>
        <div class="sidebar-section">Settings</div>
        <li>
            <a class="nav-link <?= $_settActive ?>"
               data-bs-toggle="collapse" href="#settMenu" role="button"
               aria-expanded="<?= $_settActive ? 'true' : 'false' ?>"
               title="Settings">
                <i class="fa fa-gear nav-icon"></i>
                <span class="nav-label">Settings</span>
                <i class="fa fa-chevron-down nav-chevron"></i>
            </a>
            <div class="collapse <?= $_settActive ? 'show' : '' ?>" id="settMenu">
                <ul class="sidebar-submenu">
                    <?php if ($_sbAdmin): ?>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/settings/index.php?tab=users"
                           class="nav-link <?= _sb_active('tab=users') ?>">
                            User Management
                        </a>
                    </li>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/roles/index.php"
                           class="nav-link <?= _sb_active('/modules/roles/') ?>">
                            Roles &amp; Permissions
                        </a>
                    </li>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/pwa/index.php"
                           class="nav-link <?= _sb_active('/modules/pwa/') ?>">
                            Mobile Access
                        </a>
                    </li>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/settings/index.php?tab=entities"
                           class="nav-link <?= _sb_active('tab=entities') ?>">
                            Entities
                        </a>
                    </li>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/settings/index.php?tab=salary-components"
                           class="nav-link <?= _sb_active('tab=salary-components') ?>">
                            Salary Components
                        </a>
                    </li>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/settings/index.php?tab=departments"
                           class="nav-link <?= _sb_active('tab=departments') ?>">
                            Departments
                        </a>
                    </li>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/settings/index.php?tab=designations"
                           class="nav-link <?= _sb_active('tab=designations') ?>">
                            Designations
                        </a>
                    </li>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/settings/index.php?tab=leave-types"
                           class="nav-link <?= _sb_active('tab=leave-types') ?>">
                            Leave Types
                        </a>
                    </li>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/settings/index.php?tab=holiday-types"
                           class="nav-link <?= _sb_active('tab=holiday-types') ?>">
                            Holiday Types
                        </a>
                    </li>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/settings/index.php?tab=benefit-fund-types"
                           class="nav-link <?= _sb_active('tab=benefit-fund-types') ?>">
                            Benefit Fund Types
                        </a>
                    </li>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/settings/index.php?tab=ot"
                           class="nav-link <?= _sb_active('tab=ot') ?>">
                            OT Settings
                        </a>
                    </li>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/settings/index.php?tab=grace"
                           class="nav-link <?= _sb_active('tab=grace') ?>">
                            Grace Settings
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
        </li>
        <?php endif; ?>

    </ul><!-- /.sidebar-nav -->

    <!-- Bottom: collapse toggle + keyboard hint -->
    <div class="sidebar-bottom">
        <button id="sidebarCollapseBtn" title="Collapse sidebar">
            <i class="fa fa-angles-left" id="sidebarCollapseIcon"></i>
        </button>
        <button class="sidebar-bottom-text"
                onclick="window.dispatchEvent(new KeyboardEvent('keydown',{key:'?',altKey:true}))">
            <i class="fa fa-keyboard me-1"></i> Keyboard shortcuts
        </button>
    </div>

</nav><!-- /#sidebar -->

<!-- Mobile overlay -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ═══════════════════════ MAIN WRAPPER ══════════════════════════════════ -->
<div class="main-wrapper">

    <!-- ── Topbar / Navbar ──────────────────────────────────────────── -->
    <nav id="topbar">
        <!-- Left: hamburger + breadcrumb -->
        <div class="d-flex align-items-center gap-3">
            <!-- Mobile hamburger -->
            <button id="sidebarToggleMobile"
                    class="btn btn-sm btn-outline-secondary d-md-none"
                    title="Toggle menu">
                <i class="fa fa-bars"></i>
            </button>
            <!-- Desktop collapse toggle (mirrors sidebar bottom btn) -->
            <button id="sidebarToggleDesktop"
                    class="btn btn-sm btn-outline-secondary d-none d-md-inline-flex align-items-center"
                    title="Toggle sidebar">
                <i class="fa fa-bars"></i>
            </button>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0 small">
                    <li class="breadcrumb-item">
                        <a href="<?= BASE_URL ?>/index.php" class="text-decoration-none">Home</a>
                    </li>
                    <?php if (!empty($page_title) && $page_title !== 'Dashboard'): ?>
                    <li class="breadcrumb-item active"><?= h($page_title) ?></li>
                    <?php endif; ?>
                </ol>
            </nav>
        </div>

        <!-- Right: date + user dropdown -->
        <div class="d-flex align-items-center gap-3">
            <div class="text-muted small d-none d-sm-block">
                <i class="fa fa-calendar-days me-1"></i><?= date('d M Y') ?>
            </div>
            <?php if ($notif_count > 0): ?>
            <span class="badge bg-danger rounded-pill" title="<?= $notif_count ?> unread notifications">
                <?= $notif_count ?>
            </span>
            <?php endif; ?>
            <div class="dropdown">
                <button class="btn btn-sm btn-light dropdown-toggle d-flex align-items-center gap-2"
                        data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="user-avatar">
                        <?= strtoupper(mb_substr($user['name'], 0, 1)) ?>
                    </div>
                    <span class="d-none d-sm-inline"><?= h($user['name']) ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                    <li><h6 class="dropdown-header"><?= h($user['email'] ?? '') ?></h6></li>
                    <li>
                        <span class="dropdown-item-text small">
                            <span class="badge bg-<?= $_roleBadge ?>">
                                <?= h($user['role_name'] ?? 'User') ?>
                            </span>
                        </span>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <a href="<?= BASE_URL ?>/logout.php" class="dropdown-item text-danger">
                            <i class="fa fa-right-from-bracket me-2"></i>Logout
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav><!-- /#topbar -->

    <!-- ── Page content ─────────────────────────────────────────────── -->
    <main id="mainContent">
        <?php if ($flash): ?>
        <div class="alert-container"><?= $flash ?></div>
        <?php endif; ?>
