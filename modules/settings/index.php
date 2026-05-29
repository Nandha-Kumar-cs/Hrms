<?php
require_once '../../includes/bootstrap.php';
require_login();
require_permission('settings', 'view');

$page_title = 'Settings';
include '../../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Settings</h1>
        <p class="page-subtitle">System configuration and preferences</p>
    </div>
</div>

<?php render_flash(); ?>

<div class="row">
    <div class="col-4">
        <div class="card mb-4">
            <div class="card-header"><h3 class="card-title">Application Info</h3></div>
            <div class="card-body">
                <table class="table table-sm">
                    <tr><td class="text-muted">App Name</td><td><strong><?= h(APP_NAME) ?></strong></td></tr>
                    <tr><td class="text-muted">Version</td><td>1.0.0</td></tr>
                    <tr><td class="text-muted">Company</td><td><?= h(COMPANY_NAME) ?></td></tr>
                    <tr><td class="text-muted">Environment</td><td><span class="pill pill-<?= APP_ENV==='production'?'success':'warn' ?>"><?= APP_ENV ?></span></td></tr>
                    <tr><td class="text-muted">PHP Version</td><td><?= PHP_VERSION ?></td></tr>
                    <tr><td class="text-muted">Base URL</td><td><?= h(APP_URL) ?></td></tr>
                </table>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><h3 class="card-title">Quick Links</h3></div>
            <div class="card-body">
                <div class="d-flex flex-column gap-2">
                    <a href="../roles/index.php" class="btn btn-secondary">Roles &amp; Permissions</a>
                    <a href="../pwa/index.php" class="btn btn-secondary">PWA / Mobile Settings</a>
                    <a href="../../install/schema.sql" class="btn btn-secondary">Database Schema</a>
                    <a href="../../config/app.php" class="btn btn-secondary text-muted" disabled style="opacity:.5">Edit Config (via file)</a>
                </div>
            </div>
        </div>
    </div>

    <div class="col-4">
        <div class="card mb-4">
            <div class="card-header"><h3 class="card-title">Payroll Constants</h3></div>
            <div class="card-body">
                <table class="table table-sm">
                    <tr><td class="text-muted">PF (Employee)</td><td><?= (PAYROLL_PF_EMPLOYEE * 100) ?>%</td></tr>
                    <tr><td class="text-muted">PF (Employer)</td><td><?= (PAYROLL_PF_EMPLOYER * 100) ?>%</td></tr>
                    <tr><td class="text-muted">ESI (Employee)</td><td><?= (PAYROLL_ESI_EMPLOYEE * 100) ?>%</td></tr>
                    <tr><td class="text-muted">ESI (Employer)</td><td><?= (PAYROLL_ESI_EMPLOYER * 100) ?>%</td></tr>
                    <tr><td class="text-muted">ESI Wage Limit</td><td>₹<?= number_format(PAYROLL_ESI_WAGE_LIMIT) ?></td></tr>
                    <tr><td class="text-muted">Working Days/Month</td><td><?= PAYROLL_WORKING_DAYS ?></td></tr>
                </table>
                <small class="text-muted">To change, edit <code>config/app.php</code></small>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><h3 class="card-title">Attendance Settings</h3></div>
            <div class="card-body">
                <table class="table table-sm">
                    <tr><td class="text-muted">Work Start</td><td><?= WORK_START_TIME ?></td></tr>
                    <tr><td class="text-muted">Work End</td><td><?= WORK_END_TIME ?></td></tr>
                    <tr><td class="text-muted">Grace Period</td><td><?= ATTENDANCE_GRACE_MINUTES ?> minutes</td></tr>
                </table>
            </div>
        </div>
    </div>

    <div class="col-4">
        <div class="card mb-4">
            <div class="card-header"><h3 class="card-title">Database Status</h3></div>
            <div class="card-body">
                <?php
                try {
                    $stats = [
                        'Employees'   => db()->query("SELECT COUNT(*) FROM employees")->fetchColumn(),
                        'Users'       => db()->query("SELECT COUNT(*) FROM users")->fetchColumn(),
                        'Attendance'  => db()->query("SELECT COUNT(*) FROM attendance")->fetchColumn(),
                        'Salary Slips'=> db()->query("SELECT COUNT(*) FROM salary_slips")->fetchColumn(),
                        'Letters'     => db()->query("SELECT COUNT(*) FROM letters")->fetchColumn(),
                        'Assets'      => db()->query("SELECT COUNT(*) FROM assets")->fetchColumn(),
                        'Training'    => db()->query("SELECT COUNT(*) FROM training_courses")->fetchColumn(),
                    ];
                ?>
                <table class="table table-sm">
                    <?php foreach ($stats as $label => $count): ?>
                    <tr><td class="text-muted"><?= $label ?></td><td><strong><?= number_format($count) ?></strong></td></tr>
                    <?php endforeach; ?>
                </table>
                <span class="pill pill-success">Connected</span>
                <?php } catch (Exception $e) { echo '<span class="pill pill-danger">Error</span> ' . h($e->getMessage()); } ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h3 class="card-title">SSO Configuration</h3></div>
            <div class="card-body">
                <table class="table table-sm">
                    <tr><td class="text-muted">SSO Enabled</td><td><?= SSO_ENABLED ? '<span class="pill pill-success">Yes</span>' : '<span class="pill pill-secondary">No</span>' ?></td></tr>
                    <?php if (SSO_ENABLED): ?>
                    <tr><td class="text-muted">Provider URL</td><td><?= h(SSO_PROVIDER_URL) ?></td></tr>
                    <?php endif; ?>
                </table>
                <?php if (SSO_ENABLED): ?>
                <a href="../sso/initiate.php" class="btn btn-sm btn-secondary">Test SSO Login</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Keyboard Shortcuts Reference -->
<div class="card mt-4">
    <div class="card-header"><h3 class="card-title">Keyboard Shortcuts Reference</h3></div>
    <div class="card-body">
        <div class="row">
            <div class="col-6">
                <strong>Global Shortcuts (Alt + Key)</strong>
                <table class="table table-sm mt-2">
                    <tr><td><kbd>Alt+H</kbd></td><td>Dashboard / Home</td></tr>
                    <tr><td><kbd>Alt+E</kbd></td><td>Employees</td></tr>
                    <tr><td><kbd>Alt+A</kbd></td><td>Attendance</td></tr>
                    <tr><td><kbd>Alt+P</kbd></td><td>Payroll</td></tr>
                    <tr><td><kbd>Alt+L</kbd></td><td>Letters</td></tr>
                    <tr><td><kbd>Alt+T</kbd></td><td>Training</td></tr>
                    <tr><td><kbd>Alt+N</kbd></td><td>Notifications</td></tr>
                    <tr><td><kbd>Alt+S</kbd></td><td>Toggle Sidebar</td></tr>
                    <tr><td><kbd>Alt+Q</kbd></td><td>Logout</td></tr>
                    <tr><td><kbd>Alt+/</kbd></td><td>Search</td></tr>
                </table>
            </div>
            <div class="col-6">
                <strong>Local Shortcuts (Alt + Key, context-dependent)</strong>
                <table class="table table-sm mt-2">
                    <tr><td><kbd>Alt+N</kbd></td><td>New (Employee / Course / Asset)</td></tr>
                    <tr><td><kbd>Alt+S</kbd></td><td>Save (in forms)</td></tr>
                    <tr><td><kbd>Alt+B</kbd></td><td>Back (in detail pages)</td></tr>
                    <tr><td><kbd>Alt+E</kbd></td><td>Edit (in view pages)</td></tr>
                    <tr><td><kbd>Alt+P</kbd></td><td>Print (salary slips, letters)</td></tr>
                    <tr><td><kbd>Alt+I</kbd></td><td>Issue letter</td></tr>
                    <tr><td><kbd>Alt+F</kbd></td><td>Finalize payroll</td></tr>
                </table>
                <small class="text-muted">Hold Alt to see highlighted shortcuts on buttons.</small>
            </div>
        </div>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>
