<?php
/**
 * Generate Individual Salary Slip
 *
 * GET  → render form (employee selector, month, year)
 * POST → validate, compute payroll, store slip; redirect to slip view
 *        POST with force_regenerate=1 → regenerate existing slip
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/PayrollCalculator.php';
require_login();
require_permission('payroll', 'process');

$db   = db();
$user = current_user();

// ─── POST — Generate or Regenerate ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        flash('error', 'Invalid request. Please try again.');
        redirect(BASE_URL . '/modules/payroll/generate_slip.php');
    }

    $empId    = (int)($_POST['employee_id'] ?? 0);
    $month    = (int)($_POST['month'] ?? 0);
    $year     = (int)($_POST['year'] ?? 0);
    $forceReg = !empty($_POST['force_regenerate']);
    $paidLeaves = max(0, (int)($_POST['paid_leaves'] ?? 0));   // admin-entered paid leave days (reduce absent)
    // OT hours: blank → auto-calculate from attendance; a value → override.
    $otInput    = trim((string)($_POST['ot_hours'] ?? ''));
    $manualOt   = ($otInput === '') ? null : max(0.0, (float)$otInput);

    // ── Validate inputs ──────────────────────────────────────────────────────
    $errors = [];
    if (!$empId)               $errors[] = 'Please select an employee.';
    if ($month < 1 || $month > 12) $errors[] = 'Please select a valid month.';
    if ($year < 2000 || $year > 2099) $errors[] = 'Please select a valid year.';
    // Block future months — payroll cannot be run before the month has started
    // (otherwise e.g. a loan EMI gets deducted for a month that hasn't happened).
    if ($month >= 1 && $month <= 12 && $year >= 2000
        && mktime(0, 0, 0, $month, 1, $year) > mktime(0, 0, 0, (int)date('n'), 1, (int)date('Y'))) {
        $errors[] = 'Cannot generate a salary slip for a future month ('
                  . date('F Y', mktime(0, 0, 0, $month, 1, $year))
                  . '). Payroll is only allowed for the current or a past month.';
    }

    if ($errors) {
        flash('error', implode(' ', $errors));
        redirect(BASE_URL . '/modules/payroll/generate_slip.php?emp=' . $empId . '&month=' . $month . '&year=' . $year);
    }

    // ── Load employee ────────────────────────────────────────────────────────
    $empSt = $db->prepare(
        'SELECT e.*, d.name AS dept_name, des.name AS desig_name
         FROM employees e
         LEFT JOIN departments d ON d.id = e.department_id
         LEFT JOIN designations des ON des.id = e.designation_id
         WHERE e.id = ? AND e.status = "Active" LIMIT 1'
    );
    $empSt->execute([$empId]);
    $employee = $empSt->fetch();
    if (!$employee) {
        flash('error', 'Employee not found or inactive.');
        redirect(BASE_URL . '/modules/payroll/generate_slip.php');
    }

    // ── Resolve effective salary ─────────────────────────────────────────────
    $calc          = new PayrollCalculator($db);
    $salaryForMonth = $calc->getSalaryForMonth($empId, $month, $year);
    $fixedSalary   = $salaryForMonth['fixed'];
    $variableSalary = $salaryForMonth['variable'];

    if ($fixedSalary <= 0) {
        flash('error', 'No salary structure found for ' . h($employee['name']) . '. Please set up a salary structure first.');
        redirect(BASE_URL . '/modules/payroll/generate_slip.php?emp=' . $empId . '&month=' . $month . '&year=' . $year);
    }

    // ── Load salary components ───────────────────────────────────────────────
    $components = $db->query('SELECT * FROM salary_components ORDER BY type, name')->fetchAll();

    // ── Check for existing slip ──────────────────────────────────────────────
    $payrollMonth = sprintf('%04d-%02d', $year, $month);
    $existSt      = $db->prepare('SELECT * FROM salary_slips WHERE employee_id = ? AND payroll_month = ? LIMIT 1');
    $existSt->execute([$empId, $payrollMonth]);
    $existing     = $existSt->fetch();

    if ($existing && !$forceReg) {
        // Store slip exists info in session and redirect back to form
        $_SESSION['slip_exists'] = [
            'slip_id'    => $existing['id'],
            'net_pay'    => $existing['net_pay'],
            'month'      => $month,
            'year'       => $year,
            'emp_id'     => $empId,
            'emp_name'   => $employee['name'],
            'paid_leaves'=> $paidLeaves,
            'ot_hours'   => $otInput,
        ];
        redirect(BASE_URL . '/modules/payroll/generate_slip.php?emp=' . $empId . '&month=' . $month . '&year=' . $year);
    }

    // ── Run payroll calculation ──────────────────────────────────────────────
    // persist=true → store per-day worked hours + deduction on attendance rows (audit).
    // $paidLeaves → admin-entered paid leave days that convert that many absent days to paid.
    $result = $calc->computePayroll($employee, $components, $month, $year, $fixedSalary, $variableSalary, true, $paidLeaves, $manualOt);

    // Fold in the employee's active Benefits & approved Bonuses (earnings) and
    // active Loan/Advance EMIs (deduction). Core formulas are untouched — gross,
    // total deductions and net pay are simply re-summed to include these items.
    require_once __DIR__ . '/../../includes/payroll_extras.php';
    $result = payroll_apply_extras($db, $result, $empId, $month, $year);

    // ── Persist slip ─────────────────────────────────────────────────────────
    $allowancesJson    = json_encode($result['allowances'],    JSON_UNESCAPED_UNICODE);
    $deductionsJson    = json_encode($result['deductions'],    JSON_UNESCAPED_UNICODE);
    $attendanceJson    = json_encode($result['attendance_summary'], JSON_UNESCAPED_UNICODE);

    if ($existing && $forceReg) {
        // Update existing slip
        $upd = $db->prepare(
            'UPDATE salary_slips SET
                fixed_salary=?, variable_salary=?,
                allowances=?, deductions_json=?, attendance_summary=?,
                working_days=?, present_days=?, lop_days=?,
                basic=?, hra=?, conveyance=?, medical=?, special_allow=?, other_allow=?,
                gross_earnings=?, pf_employee=?, pf_employer=?,
                esi_employee=?, esi_employer=?,
                total_deductions=?, net_pay=?,
                slip_type="individual", status="Generated"
             WHERE id=?'
        );
        $upd->execute([
            $fixedSalary, $variableSalary,
            $allowancesJson, $deductionsJson, $attendanceJson,
            $result['working_days'], $result['present_days'], $result['lop_days'],
            $result['basic'], $result['hra'], $result['conveyance'], $result['medical'],
            $result['special_allow'], $result['other_allow'],
            $result['gross_earnings'], $result['pf_employee'], $result['pf_employer'],
            $result['esi_employee'], $result['esi_employer'],
            $result['total_deductions'], $result['net_pay'],
            $existing['id'],
        ]);
        $slipId = $existing['id'];
        flash('success', 'Salary slip regenerated for ' . h($employee['name']) . ' — ' . date('F Y', mktime(0, 0, 0, $month, 1, $year)));
    } else {
        // Insert new slip
        $ins = $db->prepare(
            'INSERT INTO salary_slips
             (employee_id, payroll_month, fixed_salary, variable_salary,
              allowances, deductions_json, attendance_summary,
              working_days, present_days, lop_days,
              basic, hra, conveyance, medical, special_allow, other_allow,
              gross_earnings, pf_employee, pf_employer,
              esi_employee, esi_employer,
              total_deductions, net_pay,
              slip_type, status)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,"individual","Generated")'
        );
        $ins->execute([
            $empId, $payrollMonth, $fixedSalary, $variableSalary,
            $allowancesJson, $deductionsJson, $attendanceJson,
            $result['working_days'], $result['present_days'], $result['lop_days'],
            $result['basic'], $result['hra'], $result['conveyance'], $result['medical'],
            $result['special_allow'], $result['other_allow'],
            $result['gross_earnings'], $result['pf_employee'], $result['pf_employer'],
            $result['esi_employee'], $result['esi_employer'],
            $result['total_deductions'], $result['net_pay'],
        ]);
        $slipId = $db->lastInsertId();
        flash('success', 'Salary slip generated for ' . h($employee['name']) . ' — ' . date('F Y', mktime(0, 0, 0, $month, 1, $year)));
    }

    unset($_SESSION['slip_exists']);
    redirect(BASE_URL . '/modules/payroll/slip.php?id=' . $slipId);
}

// ─── GET — Show form ─────────────────────────────────────────────────────────
$page_title  = 'Generate Salary Slip';
$preEmpId    = (int)($_GET['emp']   ?? 0);
$preMonth    = (int)($_GET['month'] ?? date('n'));
$preYear     = (int)($_GET['year']  ?? date('Y'));
$slipExists  = $_SESSION['slip_exists'] ?? null;
unset($_SESSION['slip_exists']);

// Opened from the slip view's "Regenerate" (?emp&month&year) for a month that
// already has a slip → show the regenerate prompt directly, pre-filled with the
// existing OT hours so the admin can review/adjust before regenerating.
if (!$slipExists && $preEmpId) {
    $pm   = sprintf('%04d-%02d', $preYear, $preMonth);
    $exSt = $db->prepare(
        'SELECT s.id, s.net_pay, s.attendance_summary, e.name AS emp_name
           FROM salary_slips s JOIN employees e ON e.id = s.employee_id
          WHERE s.employee_id = ? AND s.payroll_month = ? LIMIT 1'
    );
    $exSt->execute([$preEmpId, $pm]);
    if ($ex = $exSt->fetch()) {
        $sum = json_decode((string)($ex['attendance_summary'] ?? ''), true) ?: [];
        $slipExists = [
            'slip_id'    => $ex['id'],
            'net_pay'    => $ex['net_pay'],
            'month'      => $preMonth,
            'year'       => $preYear,
            'emp_id'     => $preEmpId,
            'emp_name'   => $ex['emp_name'],
            'paid_leaves'=> 0,
            'ot_hours'   => isset($sum['ot_hours']) ? (string)$sum['ot_hours'] : '',
        ];
    }
}

// Active employees with salary structures
$employees = $db->query(
    'SELECT e.id, e.name, e.employee_id AS emp_code, e.department_id,
            d.name AS dept_name,
            COALESCE(ss.gross, 0) AS salary,
            COALESCE(ss.gross, 0) AS fixed_salary
     FROM employees e
     LEFT JOIN departments d ON d.id = e.department_id
     LEFT JOIN salary_structures ss ON ss.employee_id = e.id AND ss.is_current = 1
     WHERE e.status = "Active"
     ORDER BY e.name'
)->fetchAll();

// Month/Year ranges
$currentYear = (int)date('Y');
$years       = range($currentYear - 3, $currentYear + 1);

require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-head">
    <div>
        <h1>Generate Salary Slip</h1>
        <p class="muted">Create an individual salary slip for any employee and month</p>
    </div>
    <div class="head-actions">
        <a href="<?= BASE_URL ?>/modules/payroll/index.php" class="btn btn-ghost">All Slips</a>
        <a href="<?= BASE_URL ?>/modules/payroll/calculate.php" class="btn btn-ghost">Salary Calculation</a>
    </div>
</div>

<?= render_flash() ?>

<?php if ($slipExists): ?>
<!-- Slip Already Exists Warning -->
<div class="alert" style="background:#fff3cd;border:1px solid #ffc107;border-radius:var(--radius);padding:18px 20px;margin-bottom:20px">
    <div style="display:flex;gap:12px;align-items:flex-start">
        <i class="fa fa-exclamation-triangle fa-lg" style="color:#e6a817;margin-top:2px"></i>
        <div style="flex:1">
            <strong>Salary Slip Already Exists</strong>
            <p style="margin:6px 0 12px">
                A salary slip for <strong><?= h($slipExists['emp_name']) ?></strong> for
                <strong><?= date('F Y', mktime(0,0,0,$slipExists['month'],1,$slipExists['year'])) ?></strong>
                already exists. Net Pay: <strong style="color:var(--success)"><?= money($slipExists['net_pay']) ?></strong>
            </p>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                <a href="<?= BASE_URL ?>/modules/payroll/slip.php?id=<?= $slipExists['slip_id'] ?>" class="btn btn-sm btn-outline-info">
                    <i class="fa fa-eye"></i> View Existing Payslip
                </a>
                <form method="POST" class="d-flex align-items-end gap-2" onsubmit="return confirm('Regenerate salary slip?\n\nThis will overwrite the existing slip with a fresh calculation.')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="employee_id" value="<?= $slipExists['emp_id'] ?>">
                    <input type="hidden" name="month" value="<?= $slipExists['month'] ?>">
                    <input type="hidden" name="year" value="<?= $slipExists['year'] ?>">
                    <input type="hidden" name="force_regenerate" value="1">
                    <div>
                        <label class="form-label small mb-1">Paid Leaves (days)</label>
                        <input type="number" name="paid_leaves" class="form-control form-control-sm" min="0" step="1"
                               value="<?= (int)($slipExists['paid_leaves'] ?? 0) ?>" style="max-width:120px">
                    </div>
                    <div>
                        <label class="form-label small mb-1">OT Hours</label>
                        <input type="number" name="ot_hours" class="form-control form-control-sm" min="0" step="0.5"
                               placeholder="auto" value="<?= h((string)($slipExists['ot_hours'] ?? '')) ?>" style="max-width:120px">
                    </div>
                    <button type="submit" class="btn btn-sm btn-warning">
                        <i class="fa fa-refresh"></i> Regenerate Payslip
                    </button>
                </form>
                <a href="<?= BASE_URL ?>/modules/payroll/generate_slip.php" class="btn btn-sm btn-ghost">Dismiss</a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start">

    <!-- Form -->
    <div class="card">
        <div class="card-head"><h3>Slip Details</h3></div>
        <div style="padding:20px">
            <form method="POST" id="genForm">
                <?= csrf_field() ?>

                <div class="mb-3">
                    <label class="form-label">Employee <span class="text-danger">*</span></label>
                    <select name="employee_id" id="employeeSelect" class="form-select" required onchange="checkSalary(this)">
                        <option value="">— Select Employee —</option>
                        <?php foreach ($employees as $e): ?>
                        <option value="<?= $e['id'] ?>"
                            data-salary="<?= (float)$e['salary'] ?>"
                            data-fixed="<?= (float)$e['fixed_salary'] ?>"
                            data-name="<?= h($e['name']) ?>"
                            data-edit="<?= BASE_URL . '/modules/employee/edit.php?id=' . $e['id'] ?>"
                            <?= $preEmpId == $e['id'] ? 'selected' : '' ?>>
                            <?= h($e['name']) ?> (<?= h($e['emp_code']) ?>)
                            <?= $e['dept_name'] ? ' — ' . h($e['dept_name']) : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- No salary warning -->
                <div id="noSalaryWarn" class="alert" style="background:#fff3cd;border:1px solid #ffc107;border-radius:var(--radius);padding:12px 14px;display:none;margin-bottom:12px;font-size:13px">
                    <i class="fa fa-warning"></i>
                    This employee has no salary structure configured.
                    <a id="empEditLink" href="#" style="font-weight:600">Set up salary structure →</a>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div>
                        <label class="form-label">Month <span class="text-danger">*</span></label>
                        <select name="month" class="form-select" required>
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $preMonth === $m ? 'selected' : '' ?>>
                                <?= date('F', mktime(0,0,0,$m,1)) ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Year <span class="text-danger">*</span></label>
                        <select name="year" class="form-select" required>
                            <?php foreach ($years as $y): ?>
                            <option value="<?= $y ?>" <?= $preYear === $y ? 'selected' : '' ?>>
                                <?= $y ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div style="margin-top:12px">
                    <label class="form-label">Paid Leaves (days)</label>
                    <input type="number" name="paid_leaves" class="form-control" min="0" step="1" value="0" style="max-width:160px">
                    <div class="form-text">
                        Number of absent days to treat as <strong>paid leave</strong> this month — those days are
                        <strong>not deducted</strong>. e.g. 4 absent &amp; 2 paid leaves entered → only 2 days deducted.
                    </div>
                </div>

                <div style="margin-top:12px">
                    <label class="form-label">OT Hours</label>
                    <input type="number" name="ot_hours" class="form-control" min="0" step="0.5" placeholder="auto" style="max-width:160px">
                    <div class="form-text">
                        <strong>Leave blank</strong> to auto-calculate overtime from attendance (as now).
                        Enter a value to <strong>override</strong> the OT hours used on this slip.
                    </div>
                </div>

                <div style="margin-top:20px;display:flex;gap:8px">
                    <button type="submit" class="btn btn-primary" id="genBtn">
                        <i class="fa fa-bolt"></i> Generate Salary Slip
                    </button>
                    <a href="<?= BASE_URL ?>/modules/payroll/index.php" class="btn btn-ghost">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Salary Preview -->
    <div>
        <div id="salaryPreview" style="display:none">
            <div class="card" style="margin-bottom:12px;border:1px solid var(--success)">
                <div style="padding:16px">
                    <div style="font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:var(--muted);margin-bottom:10px">
                        Salary Preview — <span id="prevEmpName"></span>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px">
                        <div>
                            <div style="font-size:11px;color:var(--muted)">Fixed CTC / Month</div>
                            <div style="font-size:18px;font-weight:700;color:var(--success)" id="prevFixed">—</div>
                        </div>
                        <div>
                            <div style="font-size:11px;color:var(--muted)">Variable Pay</div>
                            <div style="font-size:18px;font-weight:700;color:var(--info)" id="prevVariable">—</div>
                        </div>
                        <div>
                            <div style="font-size:11px;color:var(--muted)">Total Package</div>
                            <div style="font-size:18px;font-weight:700;color:var(--primary)" id="prevTotal">—</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-head"><h3>Active Salary Components</h3></div>
            <?php
            $components = $db->query('SELECT * FROM salary_components ORDER BY type, name')->fetchAll();
            ?>
            <?php if ($components): ?>
            <div style="padding:0 0 8px">
                <?php foreach ($components as $comp): ?>
                <div style="display:flex;justify-content:space-between;padding:8px 16px;border-bottom:1px solid var(--border);font-size:13px">
                    <span><?= h($comp['name']) ?></span>
                    <span>
                        <span class="badge <?= $comp['type']==='allowance' ? 'bg-success' : 'bg-danger' ?>" style="font-size:10px;margin-right:6px"><?= ucfirst($comp['type']) ?></span>
                        <?= $comp['calculation_type']==='percentage' ? h($comp['value']).'%' : '₹'.number_format((float)$comp['value'],2) ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div style="padding:16px;color:var(--muted);font-size:13px">
                No salary components defined yet.
                <a href="<?= BASE_URL ?>/modules/payroll/salary_components.php">Add components →</a>
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<script>
function checkSalary(sel) {
    const opt      = sel.selectedOptions[0];
    const salary   = opt ? parseFloat(opt.dataset.salary) : 0;
    const fixed    = opt ? parseFloat(opt.dataset.fixed)  : 0;
    const name     = opt ? opt.dataset.name               : '';
    const editLink = opt ? opt.dataset.edit               : '#';

    document.getElementById('noSalaryWarn').style.display  = salary <= 0 && opt && opt.value ? '' : 'none';
    document.getElementById('empEditLink').href             = editLink;
    document.getElementById('salaryPreview').style.display = salary > 0 ? '' : 'none';
    document.getElementById('prevEmpName').textContent      = name;

    const fmt = v => '₹' + v.toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2});
    document.getElementById('prevFixed').textContent    = fmt(fixed);
    document.getElementById('prevVariable').textContent = '₹0.00';
    document.getElementById('prevTotal').textContent    = fmt(fixed);
}

// Trigger on pre-selected employee
document.addEventListener('DOMContentLoaded', function () {
    const sel = document.getElementById('employeeSelect');
    if (sel.value) checkSalary(sel);
});
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
