<?php
$page_title = 'Process Payroll';
require_once __DIR__ . '/../../includes/header.php';
require_permission('payroll', 'process');

$db    = db();
$month = sanitize($_GET['month'] ?? date('Y-m'));
$user  = current_user();

// Prevent double processing
$existing = $db->prepare('SELECT * FROM payroll_runs WHERE payroll_month=?');
$existing->execute([$month]);
$run = $existing->fetch();
if ($run && $run['status'] === 'Finalized') {
    flash('warn', 'Payroll for ' . $month . ' is finalized and cannot be reprocessed.');
    redirect(BASE_URL . '/modules/payroll/index.php?month=' . $month);
}

$monthStart = $month . '-01';
$monthEnd   = date('Y-m-t', strtotime($monthStart));
$workingDays = PAYROLL_WORKING_DAYS;

// Holidays in month
$hols = $db->prepare('SELECT COUNT(*) FROM holidays WHERE h_date BETWEEN ? AND ?');
$hols->execute([$monthStart, $monthEnd]);
$holiday_count = (int)$hols->fetchColumn();
$effectiveDays = $workingDays - $holiday_count;

// Active employees with current salary structure
$employees = $db->query(
    'SELECT e.*, ss.basic, ss.hra, ss.conveyance, ss.medical, ss.special_allow, ss.other_allow,
            ss.gross, ss.lop_per_day
     FROM employees e
     JOIN salary_structures ss ON ss.employee_id = e.id AND ss.is_current = 1
     WHERE e.status = "Active"
     ORDER BY e.name'
)->fetchAll();

$calculations = [];
foreach ($employees as $emp) {
    $emp_id = $emp['id'];
    // Count attendance
    $attStat = $db->prepare(
        'SELECT status, COUNT(*) cnt FROM attendance WHERE employee_id=? AND att_date BETWEEN ? AND ? GROUP BY status'
    );
    $attStat->execute([$emp_id, $monthStart, $monthEnd]);
    $attMap = [];
    foreach ($attStat->fetchAll() as $r) $attMap[$r['status']] = (int)$r['cnt'];

    $present  = ($attMap['On Time'] ?? 0) + ($attMap['Late'] ?? 0) + ($attMap['OD'] ?? 0) + ($attMap['Half Day'] ?? 0) * 0.5 + ($attMap['Comp Off'] ?? 0);
    $lop_days = max(0, $effectiveDays - $present);

    // Earnings after LOP
    $lop_deduct  = round($lop_days * (float)$emp['lop_per_day'], 2);
    $gross       = (float)$emp['gross'];
    $gross_after = max(0, $gross - $lop_deduct);

    // Proportional components
    $ratio = $gross > 0 ? $gross_after / $gross : 0;
    $basic   = round($emp['basic'] * $ratio, 2);
    $hra     = round($emp['hra'] * $ratio, 2);
    $conv    = round($emp['conveyance'] * $ratio, 2);
    $med     = round($emp['medical'] * $ratio, 2);
    $spec    = round($emp['special_allow'] * $ratio, 2);
    $other   = round($emp['other_allow'] * $ratio, 2);
    $gross_e = $basic + $hra + $conv + $med + $spec + $other;

    // Deductions
    $pf_emp = min(round($basic * PAYROLL_PF_EMPLOYEE, 2), round(15000 * PAYROLL_PF_EMPLOYEE, 2)); // PF on capped basic
    $pf_er  = $pf_emp; // Employer PF match
    $esi_emp = 0; $esi_er = 0;
    if ($gross_e <= PAYROLL_ESI_WAGE_LIMIT) {
        $esi_emp = round($gross_e * PAYROLL_ESI_EMPLOYEE, 2);
        $esi_er  = round($gross_e * PAYROLL_ESI_EMPLOYER, 2);
    }
    $total_ded = $pf_emp + $esi_emp;
    $net_pay   = max(0, $gross_e - $total_ded);

    $calculations[$emp_id] = [
        'employee'        => $emp,
        'present_days'    => (int)$present,
        'lop_days'        => (float)$lop_days,
        'working_days'    => $effectiveDays,
        'basic'           => $basic,
        'hra'             => $hra,
        'conveyance'      => $conv,
        'medical'         => $med,
        'special_allow'   => $spec,
        'other_allow'     => $other,
        'gross_earnings'  => $gross_e,
        'pf_employee'     => $pf_emp,
        'pf_employer'     => $pf_er,
        'esi_employee'    => $esi_emp,
        'esi_employer'    => $esi_er,
        'total_deductions'=> $total_ded,
        'net_pay'         => $net_pay,
    ];
}

// SAVE payroll
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_process'])) {
    if (!csrf_verify()) { flash('error','Invalid request'); redirect('.'); }

    // Upsert payroll run
    if ($run) {
        $db->prepare('UPDATE payroll_runs SET status="Processed",processed_by=?,processed_at=NOW() WHERE id=?')
           ->execute([$user['id'], $run['id']]);
        $run_id = $run['id'];
    } else {
        $db->prepare('INSERT INTO payroll_runs (payroll_month,status,processed_by,processed_at) VALUES(?,?,?,NOW())')
           ->execute([$month, 'Processed', $user['id']]);
        $run_id = $db->lastInsertId();
    }

    // Delete and re-insert slips
    $db->prepare('DELETE FROM salary_slips WHERE payroll_run_id=?')->execute([$run_id]);

    $ins = $db->prepare(
        'INSERT INTO salary_slips
         (payroll_run_id,employee_id,payroll_month,working_days,present_days,lop_days,
          basic,hra,conveyance,medical,special_allow,other_allow,gross_earnings,
          pf_employee,pf_employer,esi_employee,esi_employer,total_deductions,net_pay,status)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,"Generated")'
    );
    foreach ($calculations as $emp_id => $c) {
        $ins->execute([
            $run_id, $emp_id, $month, $c['working_days'], $c['present_days'], $c['lop_days'],
            $c['basic'], $c['hra'], $c['conveyance'], $c['medical'], $c['special_allow'], $c['other_allow'],
            $c['gross_earnings'], $c['pf_employee'], $c['pf_employer'],
            $c['esi_employee'], $c['esi_employer'], $c['total_deductions'], $c['net_pay']
        ]);
    }

    flash('success', 'Payroll processed for ' . date('F Y', strtotime($monthStart)) . ' — ' . count($calculations) . ' employees.');
    redirect(BASE_URL . '/modules/payroll/index.php?month=' . $month);
}
?>

<div class="page-head">
    <div>
        <h1>Process Payroll</h1>
        <p class="muted"><?= date('F Y', strtotime($monthStart)) ?> · Effective working days: <?= $effectiveDays ?></p>
    </div>
    <div class="head-actions">
        <a href="index.php?month=<?= h($month) ?>" class="btn btn-ghost" accesskey="b" data-shortcut data-key="B"><u>B</u>ack</a>
    </div>
</div>

<?php if (!$employees): ?>
<div class="alert alert-warn">No employees with salary structures found. Please <a href="salary_structure.php">set up salary structures</a> first.</div>
<?php else: ?>

<!-- Summary preview -->
<div class="stat-grid">
    <div class="stat-card"><div class="stat-label">Employees</div><div class="stat-value"><?= count($calculations) ?></div></div>
    <div class="stat-card stat-success"><div class="stat-label">Total Gross</div><div class="stat-value"><?= money(array_sum(array_column($calculations,'gross_earnings'))) ?></div></div>
    <div class="stat-card stat-danger"><div class="stat-label">Total Deductions</div><div class="stat-value"><?= money(array_sum(array_column($calculations,'total_deductions'))) ?></div></div>
    <div class="stat-card stat-info"><div class="stat-label">Net Payable</div><div class="stat-value"><?= money(array_sum(array_column($calculations,'net_pay'))) ?></div></div>
    <div class="stat-card"><div class="stat-label">Employer PF</div><div class="stat-value"><?= money(array_sum(array_column($calculations,'pf_employer'))) ?></div></div>
    <div class="stat-card"><div class="stat-label">Employer ESI</div><div class="stat-value"><?= money(array_sum(array_column($calculations,'esi_employer'))) ?></div></div>
</div>

<div class="card">
    <div class="card-head"><h3>Salary Calculation Preview</h3></div>
    <div style="overflow-x:auto">
    <table class="data-table datatable" data-page-length="50">
        <thead><tr>
            <th>Employee</th>
            <th class="r">Gross CTC</th>
            <th class="r">Present</th>
            <th class="r">LOP</th>
            <th class="r">Earned Gross</th>
            <th class="r">PF (Emp)</th>
            <th class="r">ESI (Emp)</th>
            <th class="r">Total Ded.</th>
            <th class="r">Net Pay</th>
        </tr></thead>
        <tbody>
        <?php foreach ($calculations as $emp_id => $c):
            $emp = $c['employee'];
        ?>
        <tr>
            <td>
                <div style="font-weight:500"><?= h($emp['name']) ?></div>
                <div class="small muted"><?= h($emp['employee_id']) ?></div>
            </td>
            <td class="r"><?= money($emp['gross']) ?></td>
            <td class="r"><?= $c['present_days'] ?>/<?= $c['working_days'] ?></td>
            <td class="r"><?= $c['lop_days'] > 0 ? '<span class="text-danger">'.$c['lop_days'].'</span>' : '0' ?></td>
            <td class="r"><?= money($c['gross_earnings']) ?></td>
            <td class="r"><?= money($c['pf_employee']) ?></td>
            <td class="r"><?= money($c['esi_employee']) ?></td>
            <td class="r text-danger"><?= money($c['total_deductions']) ?></td>
            <td class="r" style="font-weight:700;color:var(--success)"><?= money($c['net_pay']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<form method="POST">
<?= csrf_field() ?>
<div style="margin-top:14px;display:flex;gap:8px;align-items:center">
    <button type="submit" name="confirm_process" value="1" class="btn btn-primary" accesskey="s" data-shortcut data-key="S"
            onclick="return confirm('Process payroll for <?= date('F Y', strtotime($monthStart)) ?>? This will generate salary slips.')">
        ✓ <u>S</u>ave & Process
    </button>
    <a href="index.php?month=<?= h($month) ?>" class="btn btn-ghost">Cancel</a>
    <span class="small muted">PF: 12% (employee + employer) · ESI: 0.75% + 3.25% (applicable below <?= money(PAYROLL_ESI_WAGE_LIMIT,false) ?>)</span>
</div>
</form>
<?php endif; ?>

<script>
window.BASE_URL = '<?= BASE_URL ?>';
window.PAGE_SHORTCUTS = {
    'b': () => window.location.href = '<?= BASE_URL ?>/modules/payroll/index.php?month=<?= h($month) ?>'
};
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
