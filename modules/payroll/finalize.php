<?php
require_once '../../includes/bootstrap.php';
require_login();
require_permission('payroll_edit');

$runId = (int)($_GET['run_id'] ?? 0);
if (!$runId) redirect(BASE_URL . '/modules/payroll/index.php');

$run = db()->query("SELECT * FROM payroll_runs WHERE id=$runId")->fetch(PDO::FETCH_ASSOC);
if (!$run) redirect(BASE_URL . '/modules/payroll/index.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf($_POST['csrf_token'] ?? '');
    db()->prepare("UPDATE payroll_runs SET status='Finalized', finalized_at=NOW(), finalized_by=:uid WHERE id=:id")
         ->execute([':uid'=>current_user()['id'],':id'=>$runId]);
    flash('success','Payroll finalized successfully. Salary slips are now available.');
    redirect(BASE_URL . '/modules/payroll/index.php');
}

$slips = db()->query("SELECT ss.*, CONCAT(e.first_name,' ',e.last_name) AS emp_name, e.employee_id AS emp_code,
    d.name AS dept_name
    FROM salary_slips ss
    JOIN employees e ON ss.employee_id = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    WHERE ss.payroll_run_id=$runId
    ORDER BY e.first_name")->fetchAll(PDO::FETCH_ASSOC);

$totals = ['gross'=>0,'deductions'=>0,'net'=>0,'pf'=>0,'esi'=>0];
foreach ($slips as $s) {
    $totals['gross']      += $s['gross_salary'];
    $totals['deductions'] += $s['total_deductions'];
    $totals['net']        += $s['net_salary'];
    $totals['pf']         += $s['pf_employee'];
    $totals['esi']        += $s['esi_employee'];
}

$page_title = 'Finalize Payroll';
include '../../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Finalize Payroll</h1>
        <p class="page-subtitle"><?= date('F Y', mktime(0,0,0,$run['month'],1,$run['year'])) ?></p>
    </div>
    <div class="page-actions">
        <a href="export.php?run_id=<?= $runId ?>" class="btn btn-secondary" data-key="E"><u>E</u>xport CSV</a>
        <a href="index.php" class="btn btn-secondary" data-key="B"><u>B</u>ack</a>
    </div>
</div>

<?php render_flash(); ?>

<?php if ($run['status'] === 'Draft'): ?>
<div class="alert alert-warn">
    <strong>⚠️ Review before finalizing.</strong> Once finalized, salary slips will be accessible to employees. This action cannot be undone.
</div>
<?php endif; ?>

<!-- Totals Summary -->
<div class="row mb-4">
    <div class="col"><div class="stat-card"><div class="stat-value"><?= count($slips) ?></div><div class="stat-label">Employees</div></div></div>
    <div class="col"><div class="stat-card"><div class="stat-value"><?= money($totals['gross']) ?></div><div class="stat-label">Total Gross</div></div></div>
    <div class="col"><div class="stat-card"><div class="stat-value"><?= money($totals['deductions']) ?></div><div class="stat-label">Total Deductions</div></div></div>
    <div class="col"><div class="stat-card"><div class="stat-value text-success"><?= money($totals['net']) ?></div><div class="stat-label">Net Payable</div></div></div>
    <div class="col"><div class="stat-card"><div class="stat-value"><?= money($totals['pf']) ?></div><div class="stat-label">PF (Employee)</div></div></div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <table class="table datatable">
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Department</th>
                    <th>LOP Days</th>
                    <th>Gross</th>
                    <th>PF</th>
                    <th>ESI</th>
                    <th>Deductions</th>
                    <th>Net Pay</th>
                    <th>Slip</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($slips as $s): ?>
                <tr>
                    <td><strong><?= h($s['emp_code']) ?></strong><br><small><?= h($s['emp_name']) ?></small></td>
                    <td><?= h($s['dept_name']) ?></td>
                    <td><?= $s['lop_days'] ?></td>
                    <td><?= money($s['gross_salary']) ?></td>
                    <td><?= money($s['pf_employee']) ?></td>
                    <td><?= money($s['esi_employee']) ?></td>
                    <td class="text-danger"><?= money($s['total_deductions']) ?></td>
                    <td><strong class="text-success"><?= money($s['net_salary']) ?></strong></td>
                    <td><a href="slip.php?id=<?= $s['id'] ?>" class="btn btn-xs btn-secondary">View</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="font-weight-bold">
                    <td colspan="3"><strong>TOTAL</strong></td>
                    <td><strong><?= money($totals['gross']) ?></strong></td>
                    <td><strong><?= money($totals['pf']) ?></strong></td>
                    <td><strong><?= money($totals['esi']) ?></strong></td>
                    <td><strong class="text-danger"><?= money($totals['deductions']) ?></strong></td>
                    <td><strong class="text-success"><?= money($totals['net']) ?></strong></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<?php if ($run['status'] === 'Draft'): ?>
<div class="card">
    <div class="card-body d-flex gap-3 align-items-center">
        <form method="POST" onsubmit="return confirm('Finalize payroll for <?= date('F Y', mktime(0,0,0,$run['month'],1,$run['year'])) ?>? This cannot be undone.')">
            <?= csrf_field() ?>
            <button type="submit" class="btn btn-primary" data-key="F"><u>F</u>inalize Payroll</button>
        </form>
        <a href="index.php" class="btn btn-secondary">Cancel</a>
        <span class="text-muted ml-3">Status: <strong><?= $run['status'] ?></strong></span>
    </div>
</div>
<?php else: ?>
<div class="alert alert-success">
    Payroll finalized on <?= date_fmt($run['finalized_at'], 'd M Y H:i') ?>.
</div>
<?php endif; ?>

<script>
addLocalShortcut('b', () => location.href='index.php');
</script>
<?php include '../../includes/footer.php'; ?>
