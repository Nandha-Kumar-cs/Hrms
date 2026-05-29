<?php
$page_title = 'Salary Slip';
require_once __DIR__ . '/../../includes/header.php';
require_permission('payroll');

$id  = (int)($_GET['id'] ?? 0);
$db  = db();
$slip = $db->prepare(
    'SELECT ss.*, e.name, e.employee_id AS emp_code, e.designation_id, e.department_id,
            e.bank_account, e.bank_name, e.bank_ifsc, e.pan_number, e.uan_number,
            d.name AS dept_name, des.name AS desig_name
     FROM salary_slips ss
     JOIN employees e ON e.id = ss.employee_id
     LEFT JOIN departments d ON d.id = e.department_id
     LEFT JOIN designations des ON des.id = e.designation_id
     WHERE ss.id = ?'
);
$slip->execute([$id]);
$s = $slip->fetch();
if (!$s) { flash('error','Salary slip not found'); redirect(BASE_URL . '/modules/payroll/index.php'); }

// Auth check: employees can view their own slips only
$user = current_user();
if ($user['role_name'] === 'Employee') {
    $myEmp = $db->prepare('SELECT id FROM employees WHERE email=?');
    $myEmp->execute([$user['email']]);
    $myId = $myEmp->fetchColumn();
    if ($s['employee_id'] != $myId) {
        http_response_code(403); include BASE_PATH . '/includes/403.php'; exit;
    }
}
?>

<div class="page-head">
    <div>
        <h1>Salary Slip</h1>
        <p class="muted"><?= h($s['name']) ?> · <?= date('F Y', strtotime($s['payroll_month'].'-01')) ?></p>
    </div>
    <div class="head-actions">
        <button onclick="window.print()" class="btn btn-primary no-print" accesskey="p" data-shortcut data-key="P">
            ⎙ <u>P</u>rint
        </button>
        <a href="index.php?month=<?= h($s['payroll_month']) ?>" class="btn btn-ghost no-print" accesskey="b" data-shortcut data-key="B"><u>B</u>ack</a>
    </div>
</div>

<div class="card form-card" id="salarySlip" style="max-width:800px;margin:0 auto">
    <!-- Slip Header -->
    <div class="slip-header">
        <h2 style="font-size:18px;font-weight:700"><?= h(COMPANY_NAME) ?></h2>
        <p class="muted small"><?= h(COMPANY_ADDRESS) ?></p>
        <h3 style="margin-top:12px;font-size:15px;letter-spacing:.05em;text-transform:uppercase">
            Salary Slip — <?= date('F Y', strtotime($s['payroll_month'].'-01')) ?>
        </h3>
    </div>

    <!-- Employee Info -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0;margin-bottom:18px;border:1px solid var(--border);border-radius:var(--radius)">
        <?php
        $infoLeft = [
            'Employee Name'  => $s['name'],
            'Employee ID'    => $s['emp_code'],
            'Department'     => $s['dept_name'] ?? '—',
            'Designation'    => $s['desig_name'] ?? '—',
        ];
        $infoRight = [
            'PAN Number'     => $s['pan_number'] ?? '—',
            'UAN Number'     => $s['uan_number'] ?? '—',
            'Bank Account'   => $s['bank_account'] ? '****'.substr($s['bank_account'],-4) : '—',
            'Bank Name'      => $s['bank_name'] ?? '—',
        ];
        $leftItems = array_values($infoLeft);
        $rightItems = array_values($infoRight);
        $leftKeys = array_keys($infoLeft);
        $rightKeys = array_keys($infoRight);
        for ($i = 0; $i < 4; $i++): ?>
        <div style="padding:8px 12px;border-bottom:1px solid var(--border);font-size:13px;<?= $i===3?'border-bottom:none':'' ?>">
            <span class="muted"><?= $leftKeys[$i] ?>: </span><strong><?= h($leftItems[$i]) ?></strong>
        </div>
        <div style="padding:8px 12px;border-bottom:1px solid var(--border);border-left:1px solid var(--border);font-size:13px;<?= $i===3?'border-bottom:none':'' ?>">
            <span class="muted"><?= $rightKeys[$i] ?>: </span><strong><?= h($rightItems[$i]) ?></strong>
        </div>
        <?php endfor; ?>
        <div style="padding:8px 12px;font-size:13px;border-top:none"><span class="muted">Working Days: </span><strong><?= $s['working_days'] ?></strong></div>
        <div style="padding:8px 12px;font-size:13px;border-left:1px solid var(--border)"><span class="muted">Present Days: </span><strong><?= $s['present_days'] ?></strong></div>
        <?php if ($s['lop_days'] > 0): ?>
        <div style="padding:8px 12px;font-size:13px;border-top:1px solid var(--border)"><span class="muted">LOP Days: </span><strong class="text-danger"><?= $s['lop_days'] ?></strong></div>
        <div style="padding:8px 12px;border-left:1px solid var(--border);border-top:1px solid var(--border)"></div>
        <?php endif; ?>
    </div>

    <!-- Earnings & Deductions -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:18px">
        <!-- Earnings -->
        <div>
            <div class="slip-col head">Earnings</div>
            <?php
            $earnings = [
                'Basic Salary'      => $s['basic'],
                'HRA'               => $s['hra'],
                'Conveyance'        => $s['conveyance'],
                'Medical Allowance' => $s['medical'],
                'Special Allowance' => $s['special_allow'],
                'Other Allowance'   => $s['other_allow'],
            ];
            foreach ($earnings as $lbl => $amt):
                if (!$amt) continue;
            ?>
            <div class="slip-col" style="display:flex;justify-content:space-between">
                <span><?= h($lbl) ?></span>
                <span><?= money($amt) ?></span>
            </div>
            <?php endforeach; ?>
            <div class="slip-col slip-total-row" style="display:flex;justify-content:space-between">
                <strong>Total Earnings</strong>
                <strong><?= money($s['gross_earnings']) ?></strong>
            </div>
        </div>

        <!-- Deductions -->
        <div>
            <div class="slip-col head">Deductions</div>
            <?php
            $deductions = [
                'Provident Fund (Employee)' => $s['pf_employee'],
                'ESI (Employee)'            => $s['esi_employee'],
                'TDS'                       => $s['tds'],
                'Other Deductions'          => $s['other_deductions'],
            ];
            foreach ($deductions as $lbl => $amt):
                if (!$amt) continue;
            ?>
            <div class="slip-col" style="display:flex;justify-content:space-between">
                <span><?= h($lbl) ?></span>
                <span><?= money($amt) ?></span>
            </div>
            <?php endforeach; ?>
            <div class="slip-col slip-total-row" style="display:flex;justify-content:space-between">
                <strong>Total Deductions</strong>
                <strong><?= money($s['total_deductions']) ?></strong>
            </div>
        </div>
    </div>

    <!-- Net Pay -->
    <div style="background:var(--primary);color:white;border-radius:var(--radius);padding:16px 20px;display:flex;justify-content:space-between;align-items:center">
        <div>
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.08em;opacity:.8">NET PAY</div>
            <div style="font-size:24px;font-weight:800"><?= money($s['net_pay']) ?></div>
        </div>
        <div style="text-align:right">
            <div style="font-size:11px;opacity:.8">Employer's Contribution</div>
            <div style="font-size:13px">PF: <?= money($s['pf_employer']) ?> · ESI: <?= money($s['esi_employer']) ?></div>
            <div style="font-size:11px;margin-top:4px;opacity:.8">Total CTC this month: <?= money($s['gross_earnings'] + $s['pf_employer'] + $s['esi_employer']) ?></div>
        </div>
    </div>

    <p class="muted small" style="margin-top:12px;text-align:center">
        This is a computer-generated salary slip. No signature is required.
        For queries contact <?= h(COMPANY_EMAIL) ?>.
    </p>
</div>

<script>
window.BASE_URL = '<?= BASE_URL ?>';
window.PAGE_SHORTCUTS = {
    'p': () => window.print(),
    'b': () => window.location.href = '<?= BASE_URL ?>/modules/payroll/index.php?month=<?= h($s['payroll_month']) ?>'
};
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
