<?php
$page_title = 'Payroll';
require_once __DIR__ . '/../../includes/header.php';
require_permission('payroll');

$db    = db();
$month = $_GET['month'] ?? date('Y-m');

// Get or create payroll run for month
$run = $db->prepare('SELECT * FROM payroll_runs WHERE payroll_month = ?');
$run->execute([$month]);
$run = $run->fetch();

// Salary slips for this month
$slips = $db->prepare(
    'SELECT ss.*, e.name, e.employee_id AS emp_code, d.name AS dept_name
     FROM salary_slips ss
     JOIN employees e ON e.id = ss.employee_id
     LEFT JOIN departments d ON d.id = e.department_id
     WHERE ss.payroll_month = ?
     ORDER BY e.name'
);
$slips->execute([$month]);
$slip_rows = $slips->fetchAll();

// Totals
$total_gross  = array_sum(array_column($slip_rows, 'gross_earnings'));
$total_net    = array_sum(array_column($slip_rows, 'net_pay'));
$total_deduct = array_sum(array_column($slip_rows, 'total_deductions'));
$total_pf     = array_sum(array_column($slip_rows, 'pf_employer'));
?>

<div class="page-head">
    <div>
        <h1>Payroll</h1>
        <p class="muted"><?= date('F Y', strtotime($month . '-01')) ?></p>
    </div>
    <div class="head-actions">
        <input type="month" id="monthPicker" value="<?= h($month) ?>"
               onchange="window.location='index.php?month='+this.value"
               style="padding:7px 10px;border:1px solid var(--border-strong);border-radius:var(--radius);font-size:13px">
        <?php if (can('payroll','process') && (!$run || $run['status'] === 'Draft')): ?>
        <a href="process.php?month=<?= h($month) ?>" class="btn btn-primary" accesskey="p" data-shortcut data-key="P">
            ⚡ <u>P</u>rocess Payroll
        </a>
        <?php endif; ?>
        <?php if ($run && $run['status'] !== 'Draft' && can('payroll','export')): ?>
        <a href="export.php?month=<?= h($month) ?>" class="btn" accesskey="x" data-shortcut data-key="X">↓ E<u>x</u>port</a>
        <?php endif; ?>
    </div>
</div>

<!-- Status bar -->
<?php if ($run): ?>
<div class="alert alert-<?= $run['status']==='Finalized'?'success':($run['status']==='Processed'?'info':'warn') ?>">
    Payroll for <?= date('F Y', strtotime($month.'-01')) ?> is
    <strong><?= h($run['status']) ?></strong>.
    <?php if ($run['status'] === 'Processed' && can('payroll','process')): ?>
    <a href="finalize.php?month=<?= h($month) ?>" class="btn btn-sm btn-success" style="margin-left:8px">Finalize & Lock</a>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="alert alert-info">Payroll not yet processed for this month.</div>
<?php endif; ?>

<!-- Summary stats -->
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">Employees Processed</div>
        <div class="stat-value"><?= count($slip_rows) ?></div>
    </div>
    <div class="stat-card stat-success">
        <div class="stat-label">Total Gross</div>
        <div class="stat-value"><?= money($total_gross) ?></div>
    </div>
    <div class="stat-card stat-danger">
        <div class="stat-label">Total Deductions</div>
        <div class="stat-value"><?= money($total_deduct) ?></div>
    </div>
    <div class="stat-card stat-info">
        <div class="stat-label">Net Payable</div>
        <div class="stat-value"><?= money($total_net) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Employer PF</div>
        <div class="stat-value"><?= money($total_pf) ?></div>
    </div>
</div>

<!-- Salary slips table -->
<div class="card">
    <div class="card-head">
        <h3>Salary Slips</h3>
        <div class="search">
            <input type="search" placeholder="Search..." data-search>
        </div>
    </div>
    <div style="overflow-x:auto">
    <table class="data-table" id="tbl-payroll">
        <thead><tr>
            <th>Employee</th>
            <th>Department</th>
            <th class="r">Gross</th>
            <th class="r">Deductions</th>
            <th class="r">Net Pay</th>
            <th>Days</th>
            <th>LOP</th>
            <th>Status</th>
            <th class="r">Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($slip_rows as $s): ?>
        <tr>
            <td>
                <div style="font-weight:500"><?= h($s['name']) ?></div>
                <div class="small muted"><?= h($s['emp_code']) ?></div>
            </td>
            <td><?= h($s['dept_name'] ?? '—') ?></td>
            <td class="r"><?= money($s['gross_earnings']) ?></td>
            <td class="r"><?= money($s['total_deductions']) ?></td>
            <td class="r" style="font-weight:700;color:var(--success)"><?= money($s['net_pay']) ?></td>
            <td><?= $s['present_days'] ?>/<?= $s['working_days'] ?></td>
            <td><?= $s['lop_days'] > 0 ? '<span class="text-danger">'.$s['lop_days'].'</span>' : '0' ?></td>
            <td><span class="pill pill-<?= strtolower($s['status']) ?>"><?= h($s['status']) ?></span></td>
            <td class="r nowrap">
                <a href="slip.php?id=<?= $s['id'] ?>" class="btn btn-sm">View Slip</a>
                <?php if ($run && $run['status'] !== 'Finalized'): ?>
                <a href="edit_slip.php?id=<?= $s['id'] ?>" class="btn btn-sm">Edit</a>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$slip_rows): ?>
        <!-- <tr><td colspan="9" class="empty">No payroll data. <a href="process.php?month=<?= h($month) ?>">Process payroll</a> to generate slips.</td></tr> -->
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap">
    <a href="salary_structure.php" class="btn">Salary Structures</a>
    <a href="history.php" class="btn">Payroll History</a>
</div>

<script>
window.BASE_URL = '<?= BASE_URL ?>';
window.PAGE_SHORTCUTS = {
    'p': () => window.location.href = '<?= BASE_URL ?>/modules/payroll/process.php?month=<?= h($month) ?>'
};
</script>
<?php $page_scripts = <<<'JS'
<script>
$(document).ready(function () {
    $('#tbl-payroll').DataTable({
        pageLength: 25,
        order: [],
        language: {
            search: '', searchPlaceholder: 'Search...',
            lengthMenu: 'Show _MENU_ rows',
            paginate: { previous: '‹', next: '›' }
        },
        dom: '<"dt-top"lBf>rt<"dt-bottom"ip>',
        buttons: [
            { extend: 'excelHtml5', text: '↓ Excel', className: 'btn btn-sm' },
            { extend: 'print',      text: '⎙ Print', className: 'btn btn-sm' }
        ],
        columnDefs: [
            { orderable: false, targets: [8] }
        ]
    });
});
</script>
JS; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
