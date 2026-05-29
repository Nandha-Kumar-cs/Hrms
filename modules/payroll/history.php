<?php
require_once '../../includes/bootstrap.php';
require_login();
require_permission('payroll_view');

$empId = (int)($_GET['employee_id'] ?? 0);
$employees = db()->query("SELECT id, CONCAT(first_name,' ',last_name,' (',employee_id,')') AS name FROM employees WHERE status='Active' ORDER BY first_name")->fetchAll(PDO::FETCH_ASSOC);

$slips = [];
$emp = null;
if ($empId) {
    $emp = db()->query("SELECT *, CONCAT(first_name,' ',last_name) AS full_name FROM employees WHERE id=$empId")->fetch(PDO::FETCH_ASSOC);
    $slips = db()->query("SELECT ss.*, pr.month, pr.year, pr.status AS run_status
        FROM salary_slips ss
        JOIN payroll_runs pr ON ss.payroll_run_id = pr.id
        WHERE ss.employee_id=$empId
        ORDER BY pr.year DESC, pr.month DESC")->fetchAll(PDO::FETCH_ASSOC);
}

$page_title = 'Payroll History';
include '../../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Payroll History</h1>
        <p class="page-subtitle"><?= $emp ? h($emp['full_name']) . ' (' . h($emp['employee_id']) . ')' : 'Select an employee' ?></p>
    </div>
    <div class="page-actions">
        <a href="index.php" class="btn btn-secondary" data-key="B"><u>B</u>ack</a>
    </div>
</div>

<?php render_flash(); ?>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="form-row align-items-end">
            <div class="form-group col-5">
                <label class="form-label">Employee</label>
                <select name="employee_id" class="form-control">
                    <option value="">Select Employee</option>
                    <?php foreach ($employees as $e): ?>
                        <option value="<?= $e['id'] ?>" <?= $empId==$e['id']?'selected':'' ?>><?= h($e['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <button type="submit" class="btn btn-primary">View History</button>
            </div>
        </form>
    </div>
</div>

<?php if ($empId && $slips): ?>
<div class="card">
    <div class="card-body">
        <table class="table datatable">
            <thead>
                <tr>
                    <th>Month</th>
                    <th>Working Days</th>
                    <th>LOP Days</th>
                    <th>Gross</th>
                    <th>PF</th>
                    <th>ESI</th>
                    <th>Deductions</th>
                    <th>Net Pay</th>
                    <th>Status</th>
                    <th>Slip</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($slips as $s): ?>
                <tr>
                    <td><?= date('M Y', mktime(0,0,0,$s['month'],1,$s['year'])) ?></td>
                    <td><?= $s['working_days'] ?></td>
                    <td><?= $s['lop_days'] ?></td>
                    <td><?= money($s['gross_salary']) ?></td>
                    <td><?= money($s['pf_employee']) ?></td>
                    <td><?= money($s['esi_employee']) ?></td>
                    <td class="text-danger"><?= money($s['total_deductions']) ?></td>
                    <td><strong class="text-success"><?= money($s['net_salary']) ?></strong></td>
                    <td><?= $s['run_status']==='Finalized' ? '<span class="pill pill-success">Finalized</span>' : '<span class="pill pill-warn">Draft</span>' ?></td>
                    <td><a href="slip.php?id=<?= $s['id'] ?>" class="btn btn-xs btn-secondary">View</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php elseif ($empId): ?>
<div class="card"><div class="card-body text-muted">No payroll records found for this employee.</div></div>
<?php endif; ?>

<script>
addLocalShortcut('b', () => location.href='index.php');
</script>
<?php include '../../includes/footer.php'; ?>
