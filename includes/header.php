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

// ── Sidebar brand (name + logo) ───────────────────────────────────────────────
// Priority for the logo: 1) Settings → Branding upload, 2) an entity logo,
// 3) the bundled MagDyn SVG fallback. Brand name comes from Branding settings.
$_sbBrandName = (string) setting_get('brand_name', 'HRMS');
$_sbLogoUrl   = null;
try {
    $brandLogo = (string) setting_get('brand_logo', '');
    if ($brandLogo && file_exists(BASE_PATH . '/storage/branding/' . $brandLogo)) {
        $_sbLogoUrl = BASE_URL . '/storage/branding/' . $brandLogo;
    } else {
        $stmt = db()->prepare(
            "SELECT logo FROM entities
             WHERE logo IS NOT NULL AND logo != ''
             ORDER BY id LIMIT 1"
        );
        $stmt->execute();
        $logoFile = $stmt->fetchColumn();
        if ($logoFile && file_exists(BASE_PATH . '/storage/entities/' . $logoFile)) {
            $_sbLogoUrl = BASE_URL . '/storage/entities/' . $logoFile;
        }
    }
} catch (Exception $_e) { /* non-fatal */ }

// No branding/entity logo → use the stored default (storage/branding/default_brand.png).
if (!$_sbLogoUrl && file_exists(BASE_PATH . '/storage/branding/default_brand.png')) {
    $_sbLogoUrl = BASE_URL . '/storage/branding/default_brand.png';
}

// ── Pre-compute collapsed/open state for each submenu group ──────────────────
// history_report.php lives under /modules/employee/ URL-wise but belongs to the Reports nav
// section — exclude it so the Employees accordion doesn't also open/highlight on that page.
$_empActive  = (_sb_active('/modules/employee/', '/modules/letters/') && !_sb_active('/employee/history_report')) ? 'active' : '';
$_assetActive = _sb_active('/modules/assets/');
// salary_components.php lives under /payroll/ URL-wise but belongs to the Settings nav section.
// Keep $_payActive narrow so it does NOT match salary_components.
$_payActive  = _sb_active('/payroll/calculate', '/payroll/index', '/payroll/slip',
                           '/payroll/generate_slip', '/payroll/finalize',
                           '/payroll/history', '/payroll/process', '/payroll/salary_structure',
                           '/modules/benefits/', '/modules/bonuses/', '/modules/increments/',
                           '/modules/promotions/', '/modules/loans/');
$_salCompActive = _sb_active('/payroll/salary_components');
$_genSlipActive = _sb_active('/payroll/generate_slip');
$_calcActive    = _sb_active('/payroll/calculate');
$_attActive  = _sb_active('/modules/attendance/', '/modules/holidays/');
$_repActive  = _sb_active('/payroll/benefits_report', '/payroll/bonus_report',
                           '/employee/history_report', '/payroll/payroll_impact_report');
// Include salary_components in settings group so the Settings accordion opens on that page.
$_settActive = _sb_active('/modules/settings/', '/modules/roles/', '/modules/pwa/',
                           '/payroll/salary_components', '/settings/office.php', '/settings/ot.php', '/settings/grace.php', '/settings/breaks.php');

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
    <!-- App is light-themed only; opt out of browser/OS forced dark mode -->
    <meta name="color-scheme" content="only light">
    <link rel="manifest" href="<?= BASE_URL ?>/manifest.json">
    <title><?= h($page_title ?? 'Dashboard') ?> — <?= h(APP_NAME) ?></title>

    <!-- Bootstrap 5 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <!-- Font Awesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.7/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
    <!-- HRMS custom styles (filemtime cache-buster so edits propagate past browser cache) -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/magdyn-base.css?v=<?= @filemtime(BASE_PATH . '/assets/css/magdyn-base.css') ?: APP_VERSION ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/hrms.css?v=<?= @filemtime(BASE_PATH . '/assets/css/hrms.css') ?: APP_VERSION ?>">

    <style>
    /* ── Layout shell ─────────────────────────────────────────────────── */
    :root { color-scheme: only light; }
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
    /* When collapsed, never show open submenus (their text would overflow the rail). */
    #sidebar.collapsed .sidebar-nav .collapse,
    #sidebar.collapsed .sidebar-submenu { display: none !important; }
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
        width: 40px; height: 40px;
        background: transparent;
        border-radius: 8px;
        display: flex; align-items: center; justify-content: center;
        color: white; font-size: 18px; font-weight: bold;
        flex-shrink: 0;
    }
    .sidebar-brand-icon img { width: 40px; height: 40px; object-fit: contain; }
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
                     style="width:40px;height:40px;object-fit:contain;border-radius:6px;">
            <?php else: ?>
                <img src="<?= BASE_URL ?>/storage/branding/default_brand.png" alt="Logo">
            <?php endif; ?>
        </div>
        <div class="sidebar-brand-text"><?= h($_sbBrandName) ?></div>
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
        <?php if (can_any('employee') || can_any('letters') || can_any('documents') || can_any('assets')): ?>
        <div class="sidebar-section">People</div>
        <?php endif; ?>

        <!-- Employees submenu -->
        <?php if (can('employee', 'view') || can_any('letters') || can('documents', 'view')): ?>
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
                    <?php if (can('employee', 'view')): ?>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/employee/index.php"
                           class="nav-link <?= (_sb_active('/modules/employee/') && !_sb_active('/employee/history_report')) ? 'active' : '' ?>">
                            Employees List
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (can('letters', 'offer')): ?>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/letters/index.php?type=offer"
                           class="nav-link <?= _sb_active('type=offer') ?>">
                            Offer Letters
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (can('letters', 'confirmation')): ?>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/letters/index.php?type=confirmation"
                           class="nav-link <?= _sb_active('type=confirmation') ?>">
                            Confirmation Letters
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (can('letters', 'increment')): ?>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/letters/index.php?type=increment"
                           class="nav-link <?= _sb_active('type=increment') ?>">
                            Increment Letters
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (can('letters', 'promotion')): ?>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/letters/index.php?type=promotion"
                           class="nav-link <?= _sb_active('type=promotion') ?>">
                            Promotion
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (can('documents', 'view')): ?>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/documents/index.php"
                           class="nav-link <?= _sb_active('/modules/documents/') ?>">
                            Documents
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
        </li>
        <?php endif; ?>

        <!-- Assets submenu -->
        <?php if (can('assets', 'view')): ?>
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
        <?php endif; ?>

        <!-- ── PAYROLL ─────────────────────────────────────────────────── -->
        <?php if (can('payroll', 'calculate') || can('payroll', 'view') || can_any('loans') || can_any('increments') || can_any('promotions') || can_any('benefits') || can_any('bonuses')): ?>
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
                    <?php if (can('payroll', 'calculate')): ?>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/payroll/calculate.php"
                           class="nav-link <?= $_calcActive ?>">
                            Salary Calculation
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (can('payroll', 'view')): ?>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/payroll/index.php"
                           class="nav-link <?= _sb_active('/payroll/index', '/payroll/slip') ?>">
                            Salary Slips
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (can('loans', 'view')): ?>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/loans/index.php"
                           class="nav-link <?= _sb_active('/modules/loans/') ?>">
                            Loans &amp; Advances
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (can('increments', 'view')): ?>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/increments/index.php"
                           class="nav-link <?= _sb_active('/modules/increments/') ?>">
                            Increments
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (can('promotions', 'view')): ?>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/promotions/index.php"
                           class="nav-link <?= _sb_active('/modules/promotions/') ?>">
                            Promotions
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (can('benefits', 'view')): ?>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/benefits/index.php"
                           class="nav-link <?= _sb_active('/modules/benefits/') ?>">
                            Benefits
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (can('bonuses', 'view')): ?>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/bonuses/index.php"
                           class="nav-link <?= _sb_active('/modules/bonuses/') ?>">
                            Bonuses &amp; Incentives
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
        </li>
        <?php endif; ?>

        <!-- ── ATTENDANCE ──────────────────────────────────────────────── -->
        <?php if (can('attendance', 'view') || can('attendance', 'report') || can('attendance', 'mark') || can('leaves', 'view') || can('leave_history', 'view') || can('od', 'view') || can('holidays', 'view') || can('compoff', 'view') || can('compoff_credits', 'view')): ?>
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
                    <?php if (can('attendance', 'mark')): ?>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/attendance/mark.php"
                           class="nav-link <?= _sb_active('/attendance/mark') ?>">
                            Mark Attendance
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (can('attendance', 'report')): ?>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/attendance/report.php"
                           class="nav-link <?= _sb_active('/attendance/report') ?>">
                            Attendance Report
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (can('leaves', 'view')): ?>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/attendance/leaves.php"
                           class="nav-link <?= _sb_active('/attendance/leaves') ?>">
                            Leave Requests
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (can('leave_history', 'view')): ?>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/attendance/leave_history.php"
                           class="nav-link <?= _sb_active('/attendance/leave_history') ?>">
                            Leave History
                        </a>
                    </li>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/attendance/leave_status.php"
                           class="nav-link <?= _sb_active('/attendance/leave_status') ?>">
                            Leave Status
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (can('holidays', 'view')): ?>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/holidays/index.php"
                           class="nav-link <?= _sb_active('/modules/holidays/') ?>">
                            Holidays
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (can('compoff', 'view')): ?>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/attendance/comp_off.php"
                           class="nav-link <?= _sb_active('/attendance/comp_off.php') ?>">
                            Comp Offs
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (can('compoff_credits', 'view')): ?>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/attendance/comp_off_credits.php"
                           class="nav-link <?= _sb_active('/attendance/comp_off_credits') ?>">
                            Comp Off Credits
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (can('od', 'view')): ?>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/attendance/od_requests.php"
                           class="nav-link <?= _sb_active('/attendance/od_requests') ?>">
                            On Duty (OD)
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
        </li>
        <?php endif; ?>

        <!-- ── REPORTS ─────────────────────────────────────────────────── -->
        <?php if (can('report_benefits', 'view') || can('report_bonus', 'view') || can('report_history', 'view') || can('report_impact', 'view')): ?>
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
                    <?php if (can('report_benefits', 'view')): ?>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/payroll/benefits_report.php"
                           class="nav-link <?= _sb_active('/payroll/benefits_report') ?>">
                            Monthly Benefits
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (can('report_bonus', 'view')): ?>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/payroll/bonus_report.php"
                           class="nav-link <?= _sb_active('/payroll/bonus_report') ?>">
                            Bonus Report
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (can('report_history', 'view')): ?>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/employee/history_report.php"
                           class="nav-link <?= _sb_active('/employee/history_report') ?>">
                            Employee History
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (can('report_impact', 'view')): ?>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/payroll/payroll_impact_report.php"
                           class="nav-link <?= _sb_active('/payroll/payroll_impact_report') ?>">
                            Payroll Impact
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </div>
        </li>
        <?php endif; ?>

        <!-- ── LEARNING ─────────────────────────────────────────────────── -->
        <?php if (can('training', 'view')): ?>
        <div class="sidebar-section">Learning</div>

        <li>
            <a href="<?= BASE_URL ?>/modules/training/index.php"
               class="nav-link <?= _sb_active('/modules/training/') ?>"
               title="Training">
                <i class="fa fa-graduation-cap nav-icon"></i>
                <span class="nav-label">Training</span>
            </a>
        </li>
        <?php endif; ?>

        <!-- ── AUDIT (Admin only) ─────────────────────────────────────── -->
        <?php if ($_sbAdmin): ?>
        <div class="sidebar-section">Audit</div>
        <li>
            <a href="<?= BASE_URL ?>/modules/settings/activity_log.php"
               class="nav-link <?= _sb_active('/settings/activity_log') ?>">
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
                    <?php if (can('users', 'view')): ?>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/settings/users.php"
                           class="nav-link <?= _sb_active('/settings/users') ?>">
                            User Management
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if ($_sbAdmin): ?>
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
                        <a href="<?= BASE_URL ?>/modules/payroll/salary_components.php"
                           class="nav-link <?= $_salCompActive ?>">
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
                        <a href="<?= BASE_URL ?>/modules/settings/index.php?tab=asset-categories"
                           class="nav-link <?= _sb_active('tab=asset-categories') ?>">
                            Asset Categories
                        </a>
                    </li>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/settings/office.php"
                           class="nav-link <?= _sb_active('/settings/office.php', '/settings/ot.php', '/settings/grace.php', '/settings/breaks.php') ?>">
                            Office Settings
                        </a>
                    </li>
                    <li>
                        <a href="<?= BASE_URL ?>/modules/settings/branding.php"
                           class="nav-link <?= _sb_active('/settings/branding.php') ?>">
                            Branding
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
            <!-- Mobile hamburger (opens the off-canvas sidebar on small screens only) -->
            <button id="sidebarToggleMobile"
                    class="btn btn-sm btn-outline-secondary d-md-none"
                    title="Toggle menu">
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
