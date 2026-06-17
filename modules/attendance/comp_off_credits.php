<?php
/**
 * Comp Off — Credits & Balance.
 *
 * Ports the reference comp-off credit/balance/working-days subsystem into a
 * single HRMS screen:
 *   • Balances   — per-employee credited credits, availed comp-offs, balance.
 *   • Credits    — comp-off credits earned by working a non-working day.
 *   • Working Days — days the company declared as working.
 *
 * Credits are auto-created from attendance (see includes/comp_off.php, called
 * by modules/attendance/mark.php). "Sync from Attendance" re-scans the year.
 */
require_once __DIR__ . '/../../includes/comp_off.php';
require_login();
require_permission('attendance', 'view');

$canEdit = can('attendance', 'edit') || can('holidays', 'edit');

// Employee self-service: an Employee only sees their own credits / balance.
$user       = current_user();
$isEmployee = (($user['role_name'] ?? '') === 'Employee');
$selfEmpId  = (int)($user['employee_id'] ?? 0);

// ── Sync action ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sync') {
    verify_csrf($_POST['csrf_token'] ?? '');
    if (!$canEdit) { http_response_code(403); exit('Forbidden'); }
    $year = (int)($_POST['year'] ?? date('Y'));
    $n = comp_off_sync_credits_for_range("$year-01-01", "$year-12-31");
    flash('success', "Synced comp-off credits from {$n} attendance record(s) for {$year}.");
    redirect(BASE_URL . '/modules/attendance/comp_off_credits.php?year=' . $year);
}

$year = (int)($_GET['year'] ?? date('Y'));

// Balances per active employee.
$balWhere = "e.status = 'Active'"; $balParams = [];
if ($isEmployee) { $balWhere .= ' AND e.id = ?'; $balParams[] = $selfEmpId; }
$balances = db()->prepare(
    "SELECT e.id, e.employee_id AS emp_code, e.name,
            (SELECT COUNT(*) FROM comp_off_credits c WHERE c.employee_id = e.id AND c.status = 'credited') AS credited,
            (SELECT COALESCE(SUM(lr.days_requested),0) FROM leave_requests lr
                JOIN leave_types lt ON lt.id = lr.leave_type_id
                WHERE lr.employee_id = e.id AND lt.is_comp_off = 1 AND lr.status = 'approved') AS used
     FROM employees e WHERE $balWhere ORDER BY e.name"
);
$balances->execute($balParams);
$balances = $balances->fetchAll();

// Credits for the year.
$creWhere = "YEAR(c.work_date) = ?"; $creParams = [$year];
if ($isEmployee) { $creWhere .= ' AND c.employee_id = ?'; $creParams[] = $selfEmpId; }
$credits = db()->prepare(
    "SELECT c.*, e.name, e.employee_id AS emp_code FROM comp_off_credits c
     JOIN employees e ON e.id = c.employee_id WHERE $creWhere
     ORDER BY c.work_date DESC, e.name"
);
$credits->execute($creParams);
$credits = $credits->fetchAll();

// Declared working days for the year.
$workingDays = db()->prepare(
    "SELECT w.*, u.name AS declared_by_name FROM comp_off_working_days w
     LEFT JOIN users u ON u.id = w.declared_by WHERE YEAR(w.work_date) = ? ORDER BY w.work_date"
);
$workingDays->execute([$year]);
$workingDays = $workingDays->fetchAll();

$labels = WorkCalendar::$dayTypeLabels;
$colors = WorkCalendar::$dayTypeColors;

$page_title = 'Comp Off Credits';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Comp Off — Credits &amp; Balance</h1>
        <p class="page-subtitle">Comp-off earned by working non-working days — <?= $year ?></p>
    </div>
    <div class="page-actions d-flex gap-2 align-items-center">
        <form method="GET" class="d-inline-flex">
            <select name="year" onchange="this.form.submit()" class="form-select form-select-sm" style="width:auto">
                <?php for ($y = (int)date('Y') + 1; $y >= (int)date('Y') - 2; $y--): ?>
                <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </form>
        <a href="<?= BASE_URL ?>/modules/attendance/comp_off.php?year=<?= $year ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-calendar-plus me-1"></i>Comp Offs</a>
        <?php if ($canEdit): ?>
        <form method="POST" onsubmit="return confirm('Re-scan <?= $year ?> attendance and sync comp-off credits?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="sync">
            <input type="hidden" name="year" value="<?= $year ?>">
            <button class="btn btn-sm btn-primary"><i class="fa fa-rotate me-1"></i>Sync from Attendance</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php render_flash(); ?>

<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-bal" type="button">Balances</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-cre" type="button">Credits <span class="badge bg-secondary"><?= count($credits) ?></span></button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-wd" type="button">Working Days <span class="badge bg-secondary"><?= count($workingDays) ?></span></button></li>
</ul>

<div class="tab-content">
    <!-- Balances -->
    <div class="tab-pane fade show active" id="tab-bal">
        <div class="card page-card"><div class="card-body table-responsive">
            <table class="table table-hover table-bordered align-middle mb-0">
                <thead class="table-dark"><tr><th>Employee</th><th class="text-center">Credited</th><th class="text-center">Used (Leave)</th><th class="text-center">Balance</th></tr></thead>
                <tbody>
                <?php foreach ($balances as $b): $bal = max(0, (int)$b['credited'] - (int)$b['used']); ?>
                    <tr>
                        <td><strong><?= h($b['emp_code']) ?></strong> <span class="text-muted">— <?= h($b['name']) ?></span></td>
                        <td class="text-center"><?= (int)$b['credited'] ?></td>
                        <td class="text-center"><?= (int)$b['used'] ?></td>
                        <td class="text-center"><span class="badge bg-<?= $bal > 0 ? 'success' : 'secondary' ?>"><?= $bal ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$balances): ?><tr><td colspan="4" class="text-center text-muted py-4">No active employees.</td></tr><?php endif; ?>
                </tbody>
            </table>
            <p class="small text-muted mt-2 mb-0">Balance = credited credits − approved Comp Off leave days.</p>
        </div></div>
    </div>

    <!-- Credits -->
    <div class="tab-pane fade" id="tab-cre">
        <div class="card page-card"><div class="card-body table-responsive">
            <table class="table table-hover table-bordered align-middle mb-0">
                <thead class="table-dark"><tr><th>Work Date</th><th>Employee</th><th>Day Type</th><th>Reason / Holiday</th><th class="text-center">Status</th></tr></thead>
                <tbody>
                <?php foreach ($credits as $c): ?>
                    <tr>
                        <td class="fw-semibold"><?= date_fmt($c['work_date']) ?></td>
                        <td><strong><?= h($c['emp_code']) ?></strong> <span class="text-muted">— <?= h($c['name']) ?></span></td>
                        <td><span class="badge bg-<?= $colors[$c['day_type']] ?? 'secondary' ?>"><?= h($labels[$c['day_type']] ?? $c['day_type']) ?></span></td>
                        <td><?= h($c['holiday_name'] ?? '—') ?></td>
                        <td class="text-center"><?= $c['status'] === 'credited' ? '<span class="badge bg-success">Credited</span>' : '<span class="badge bg-secondary">Cancelled</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$credits): ?><tr><td colspan="5" class="text-center text-muted py-4">No comp-off credits for <?= $year ?>. Mark attendance on a non-working day, then “Sync from Attendance”.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div></div>
    </div>

    <!-- Working Days -->
    <div class="tab-pane fade" id="tab-wd">
        <div class="card page-card"><div class="card-body table-responsive">
            <table class="table table-hover table-bordered align-middle mb-0">
                <thead class="table-dark"><tr><th>Date</th><th>Day Type</th><th>Holiday</th><th>Reason</th><th>Declared By</th></tr></thead>
                <tbody>
                <?php foreach ($workingDays as $w): ?>
                    <tr>
                        <td class="fw-semibold"><?= date_fmt($w['work_date']) ?></td>
                        <td><span class="badge bg-<?= $colors[$w['day_type']] ?? 'secondary' ?>"><?= h($labels[$w['day_type']] ?? $w['day_type']) ?></span></td>
                        <td><?= h($w['holiday_name'] ?? '—') ?></td>
                        <td><?= h($w['reason'] ?? '—') ?></td>
                        <td><?= h($w['declared_by_name'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$workingDays): ?><tr><td colspan="5" class="text-center text-muted py-4">No company working days declared for <?= $year ?>.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div></div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
