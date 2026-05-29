<?php
/**
 * MagDyn HRMS — Sidebar Partial
 * Usage: include this file inside your layout after bootstrap.php + require_login().
 * Expects: $user = current_user() to be available in scope.
 */

// ── Active-state helper ───────────────────────────────────────────────────────
// Returns 'active' if the current REQUEST_URI contains any of the given path
// fragments. Using REQUEST_URI so it works across nested sub-pages too.
if (!function_exists('_sb_active')) {
    function _sb_active(string ...$fragments): string {
        $uri = strtolower($_SERVER['REQUEST_URI'] ?? '');
        foreach ($fragments as $f) {
            if (strpos($uri, strtolower($f)) !== false) return 'active';
        }
        return '';
    }
}

// ── Role shortcuts ────────────────────────────────────────────────────────────
$_sbUser    = $user ?? current_user();
$_sbRole    = strtolower($_sbUser['role_name'] ?? '');
$_sbAdmin   = ($_sbRole === 'admin');
$_sbPriv    = in_array($_sbRole, ['admin', 'manager']);

// ── Logo lookup (entity branding) ─────────────────────────────────────────────
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
    if ($logoFile) {
        $logoPath = BASE_PATH . '/storage/entities/' . $logoFile;
        if (file_exists($logoPath)) {
            $_sbLogoUrl = BASE_URL . '/storage/entities/' . $logoFile;
        }
    }
} catch (Exception $_e) { /* non-fatal */ }

// ── Pre-compute active groups (collapse open/closed) ─────────────────────────
$_empActive  = _sb_active('/employee/', '/letters/');
$_assetActive = _sb_active('/assets/');
$_payActive  = _sb_active('/payroll/');
$_attActive  = _sb_active('/attendance/');
$_repActive  = _sb_active('/reports/');
$_settActive = _sb_active('/settings/', '/roles/', '/pwa/');
?>

<nav id="sidebar">

    {{-- Brand --}}
    <a href="<?= BASE_URL ?>/index.php" class="sidebar-brand">
        <div class="sidebar-brand-icon"<?= $_sbLogoUrl ? ' style="background:transparent;padding:0;"' : '' ?>>
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
               class="nav-link <?= _sb_active('/index.php') ?>"
               title="Alt+D — Dashboard">
                <i class="fa fa-gauge nav-icon"></i>
                <span><sc class="sc">D</sc>ashboard</span>
            </a>
        </li>

        <!-- ── PEOPLE ─────────────────────────────────────── -->
        <div class="sidebar-section">People</div>

        <!-- Employees (submenu) -->
        <li>
            <a class="nav-link <?= $_empActive ?>"
               data-bs-toggle="collapse" href="#empMenu" role="button"
               aria-expanded="<?= $_empActive ? 'true' : 'false' ?>"
               title="Alt+E — Employees">
                <i class="fa fa-users nav-icon"></i>
                <span><sc class="sc">E</sc>mployees</span>
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

        <!-- Assets (submenu) -->
        <li>
            <a class="nav-link <?= $_assetActive ?>"
               data-bs-toggle="collapse" href="#assetMenu" role="button"
               aria-expanded="<?= $_assetActive ? 'true' : 'false' ?>">
                <i class="fa fa-laptop nav-icon"></i>
                <span>Assets</span>
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

        <!-- ── PAYROLL ──────────────────────────────────── -->
        <div class="sidebar-section">Payroll</div>

        <!-- Payroll (submenu) -->
        <li>
            <a class="nav-link <?= $_payActive ?>"
               data-bs-toggle="collapse" href="#payMenu" role="button"
               aria-expanded="<?= $_payActive ? 'true' : 'false' ?>"
               title="Alt+P — Payroll">
                <i class="fa fa-money-bill-wave nav-icon"></i>
                <span><sc class="sc">P</sc>ayroll</span>
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

        <!-- ── ATTENDANCE ─────────────────────────────── -->
        <li>
            <a class="nav-link <?= $_attActive ?>"
               data-bs-toggle="collapse" href="#attMenu" role="button"
               aria-expanded="<?= $_attActive ? 'true' : 'false' ?>"
               title="Alt+A — Attendance">
                <i class="fa fa-calendar-check nav-icon"></i>
                <span><sc class="sc">A</sc>ttendance</span>
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
                        <a href="<?= BASE_URL ?>/modules/attendance/index.php"
                           class="nav-link <?= _sb_active('/attendance/index', '/attendance/export', '/attendance/calendar') ?>">
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

        <!-- ── REPORTS ──────────────────────────────────── -->
        <?php if ($_sbPriv): ?>
        <li>
            <a class="nav-link <?= $_repActive ?>"
               data-bs-toggle="collapse" href="#repMenu" role="button"
               aria-expanded="<?= $_repActive ? 'true' : 'false' ?>"
               title="Alt+R — Reports">
                <i class="fa fa-chart-bar nav-icon"></i>
                <span><sc class="sc">R</sc>eports</span>
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

        <!-- ── LEARNING ─────────────────────────────────── -->
        <div class="sidebar-section">Learning</div>

        <li>
            <a href="<?= BASE_URL ?>/modules/training/index.php"
               class="nav-link <?= _sb_active('/modules/training/') ?>"
               title="Alt+T — Training">
                <i class="fa fa-graduation-cap nav-icon"></i>
                <span><sc class="sc">T</sc>raining</span>
            </a>
        </li>

        <!-- ── AUDIT (Admin only) ───────────────────────── -->
        <?php if ($_sbAdmin): ?>
        <div class="sidebar-section">Audit</div>
        <li>
            <a href="<?= BASE_URL ?>/modules/settings/index.php?tab=activity-log"
               class="nav-link <?= _sb_active('tab=activity-log') ?>">
                <i class="fa fa-clock-rotate-left nav-icon"></i>
                <span>Activity Log</span>
            </a>
        </li>
        <?php endif; ?>

        <!-- ── SETTINGS (Admin/Manager only) ───────────── -->
        <?php if ($_sbPriv): ?>
        <div class="sidebar-section">Settings</div>

        <li>
            <a class="nav-link <?= $_settActive ?>"
               data-bs-toggle="collapse" href="#settMenu" role="button"
               aria-expanded="<?= $_settActive ? 'true' : 'false' ?>"
               title="Alt+S — Settings">
                <i class="fa fa-gear nav-icon"></i>
                <span><sc class="sc">S</sc>ettings</span>
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

    </ul>

    <!-- Bottom controls -->
    <div style="padding:10px 12px;border-top:1px solid #1e293b;margin-top:auto;display:flex;align-items:center;gap:8px;">
        <button id="sidebarCollapseBtn" title="Collapse sidebar"
                style="background:none;border:1px solid #334155;color:#64748b;font-size:12px;cursor:pointer;padding:5px 8px;border-radius:5px;flex-shrink:0;line-height:1;transition:all .18s">
            <i class="fa fa-angles-left" id="sidebarCollapseIcon"></i>
        </button>
        <button onclick="window.dispatchEvent(new KeyboardEvent('keydown',{key:'?',altKey:true}))"
                class="sidebar-bottom-text"
                style="background:none;border:none;color:#334155;font-size:11px;cursor:pointer;flex:1;text-align:left;padding:0;white-space:nowrap;overflow:hidden">
            <i class="fa fa-keyboard me-1"></i> <sc class="sc">?</sc> Keyboard shortcuts
        </button>
    </div>

</nav>
