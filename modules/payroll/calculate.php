<?php
/**
 * Salary Calculation — Monthly calculation view
 *
 * Shows every active employee's computed payroll for the selected month/year.
 * Read-only preview; click Generate to actually create the slip.
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/PayrollCalculator.php';
require_login();
require_permission('payroll', 'calculate');
// Self-scoped employees are not blocked — they see only their own calculation row.
$scopeEmp = scope_employee_id();

$db          = db();
$currentYear = (int)date('Y');
$month       = (int)($_GET['month'] ?? date('n'));
$year        = (int)($_GET['year']  ?? $currentYear);
if ($month < 1 || $month > 12) $month = (int)date('n');
if ($year < 2000 || $year > 2099) $year = $currentYear;

$monthStart   = sprintf('%04d-%02d-01', $year, $month);
$monthLabel   = date('F Y', mktime(0, 0, 0, $month, 1, $year));
$payrollMonth = sprintf('%04d-%02d', $year, $month);
$calDays      = (int)date('t', strtotime($monthStart));

// Future-month guard: payroll cannot be run before the month has started. The
// error is shown on THIS page (the Generate buttons are disabled) rather than
// letting the POST bounce to the Generate Salary Slip page.
$isFutureMonth = mktime(0, 0, 0, $month, 1, $year) > mktime(0, 0, 0, (int)date('n'), 1, $currentYear);
$futureMsg     = 'Cannot generate a salary slip for a future month (' . $monthLabel
               . '). Payroll is only allowed for the current or a past month.';

// ─── Load salary components ──────────────────────────────────────────────────
$components = $db->query('SELECT * FROM salary_components ORDER BY type, name')->fetchAll();

// ─── Load active employees with salary structures (own row only if self-scoped) ─
$empSql = 'SELECT e.*, d.name AS dept_name, des.name AS desig_name,
            ss.gross AS salary_gross, ss.effective_from
     FROM employees e
     LEFT JOIN departments d   ON d.id = e.department_id
     LEFT JOIN designations des ON des.id = e.designation_id
     LEFT JOIN salary_structures ss ON ss.employee_id = e.id AND ss.is_current = 1
     WHERE e.status = "Active"';
$empParams = [];
if ($scopeEmp) { $empSql .= ' AND e.id = ?'; $empParams[] = $scopeEmp; }
$empSql .= ' ORDER BY e.name';
$empStmt = $db->prepare($empSql);
$empStmt->execute($empParams);
$employees = $empStmt->fetchAll();

// ─── Load existing slips for this month ─────────────────────────────────────
$slipMap = [];
$slipSt  = $db->prepare('SELECT * FROM salary_slips WHERE payroll_month = ?');
$slipSt->execute([$payrollMonth]);
foreach ($slipSt->fetchAll() as $s) {
    $slipMap[(int)$s['employee_id']] = $s;
}

// ─── Compute payroll rows ────────────────────────────────────────────────────
$calc   = new PayrollCalculator($db);
$rows   = [];
$totals = ['gross' => 0, 'ot' => 0, 'pf_esi' => 0, 'absent_late' => 0, 'other_ded' => 0, 'net' => 0, 'pf_employer' => 0];

foreach ($employees as $emp) {
    $empId = (int)$emp['id'];

    // Skip employees who joined after this month
    $joiningDate = $emp['joining_date'] ?? null;
    $beforeJoining = false;
    if ($joiningDate) {
        $joinMonth = (int)date('Y', strtotime($joiningDate)) * 100 + (int)date('n', strtotime($joiningDate));
        $thisMonth = $year * 100 + $month;
        if ($joinMonth > $thisMonth) {
            $beforeJoining = true;
        }
    }

    $hasSalary  = $emp['salary_gross'] > 0;
    $existSlip  = $slipMap[$empId] ?? null;

    if ($beforeJoining || !$hasSalary) {
        $rows[] = [
            'employee'      => $emp,
            'before_joining'=> $beforeJoining,
            'no_salary'     => !$hasSalary && !$beforeJoining,
            'slip'          => $existSlip,
            'result'        => null,
        ];
        continue;
    }

    $salaryData    = $calc->getSalaryForMonth($empId, $month, $year);
    $fixedSalary   = $salaryData['fixed'];
    $variableSalary = $salaryData['variable'];

    if ($fixedSalary <= 0) {
        $rows[] = ['employee' => $emp, 'before_joining' => false, 'no_salary' => true, 'slip' => $existSlip, 'result' => null];
        continue;
    }

    $result = $calc->computePayroll($emp, $components, $month, $year, $fixedSalary, $variableSalary);
    // Fold in benefits / bonuses (earnings) and loan EMIs (deduction) so the
    // preview matches the generated slip. (See includes/payroll_extras.php.)
    require_once __DIR__ . '/../../includes/payroll_extras.php';
    $result = payroll_apply_extras($db, $result, $empId, $month, $year);
    $as     = $result['attendance_summary'];

    // Derive OT amount and absent+late from result
    $otAmount      = (float)($as['ot_amount']     ?? 0);
    $absentDed     = (float)($as['absent_deduction'] ?? 0);
    $lateDed       = (float)($as['late_deduction']   ?? 0);
    $pfEsiDed      = $result['pf_employee'] + $result['esi_employee'];
    $otherDed      = $result['total_deductions'] - $pfEsiDed - $absentDed - $lateDed;

    $rows[] = [
        'employee'      => $emp,
        'before_joining'=> false,
        'no_salary'     => false,
        'slip'          => $existSlip,
        'result'        => $result,
        'fixed_salary'  => $fixedSalary,
        'variable_salary' => $variableSalary,
        'ot_amount'     => $otAmount,
        'pf_esi_ded'    => $pfEsiDed,
        'absent_late_ded' => $absentDed + $lateDed,
        'other_ded'     => max(0, $otherDed),
    ];

    $totals['gross']      += $result['gross_earnings'];
    $totals['ot']         += $otAmount;
    $totals['pf_esi']     += $pfEsiDed;
    $totals['absent_late']+= $absentDed + $lateDed;
    $totals['other_ded']  += max(0, $otherDed);
    $totals['net']        += $result['net_pay'];
    $totals['pf_employer']+= $result['pf_employer'];
}

$employeesProcessed = count(array_filter($rows, fn($r) => $r['result'] !== null));

// ─── Working days for this month ─────────────────────────────────────────────
$monthEnd = date('Y-m-t', strtotime($monthStart));
$holSt    = $db->prepare('SELECT COUNT(*) FROM holidays WHERE h_date BETWEEN ? AND ?');
$holSt->execute([$monthStart, $monthEnd]);
$holidays    = (int)$holSt->fetchColumn();
$workingDays = PAYROLL_WORKING_DAYS - $holidays;

$page_title = 'Salary Calculation — ' . $monthLabel;
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Salary Calculation</h1>
        <p class="muted"><?= h($monthLabel) ?></p>
    </div>
    <div class="head-actions">
        <a href="<?= BASE_URL ?>/modules/payroll/index.php" class="btn btn-ghost">All Slips</a>
        <?php if (can('payroll','process')): ?>
        <a href="<?= BASE_URL ?>/modules/payroll/generate_slip.php" class="btn btn-primary">
            <i class="fa fa-plus"></i> Generate Slip
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Month / Year Picker -->
<form method="GET" style="margin-bottom:18px;display:flex;gap:10px;align-items:flex-end">
    <div>
        <label class="form-label" style="font-size:12px">Month</label>
        <select name="month" class="form-select form-select-sm" style="min-width:130px">
            <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
            <?php endfor; ?>
        </select>
    </div>
    <div>
        <label class="form-label" style="font-size:12px">Year</label>
        <select name="year" class="form-select form-select-sm" style="min-width:90px">
            <?php foreach (range($currentYear - 3, $currentYear + 1) as $y): ?>
            <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="btn btn-primary btn-sm"><i class="fa fa-search"></i> Calculate</button>
</form>

<?php if ($isFutureMonth): ?>
<div class="alert alert-danger" style="margin-bottom:18px">
    <i class="fa fa-triangle-exclamation me-1"></i><?= h($futureMsg) ?>
</div>
<?php endif; ?>

<!-- Summary cards -->
<div class="stat-grid" style="margin-bottom:20px">
    <div class="stat-card">
        <div class="stat-label">Employees</div>
        <div class="stat-value"><?= $employeesProcessed ?></div>
    </div>
    <div class="stat-card stat-success">
        <div class="stat-label">Total Gross</div>
        <div class="stat-value"><?= money($totals['gross']) ?></div>
    </div>
    <div class="stat-card stat-danger">
        <div class="stat-label">Total Deductions</div>
        <div class="stat-value"><?= money($totals['pf_esi'] + $totals['absent_late'] + $totals['other_ded']) ?></div>
    </div>
    <div class="stat-card stat-info">
        <div class="stat-label">Total Net Pay</div>
        <div class="stat-value"><?= money($totals['net']) ?></div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Employer PF</div>
        <div class="stat-value"><?= money($totals['pf_employer']) ?></div>
    </div>
</div>

<!-- Statutory badges -->
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;font-size:12px">
    <span class="badge bg-secondary">Working Days: <?= $workingDays ?></span>
    <span class="badge bg-secondary">Calendar Days: <?= $calDays ?></span>
    <span class="badge" style="background:#6c757d">PF: 12% employee + 12% employer (capped ₹1,800)</span>
    <span class="badge" style="background:#6c757d">ESI: 0.75% + 3.25% (below <?= money(PAYROLL_ESI_WAGE_LIMIT, false) ?>)</span>
</div>

<!-- Salary Calculation Table -->
<style>
#tbl-calc { border-collapse:collapse; font-size:12px; min-width:1100px; width:100% }
#tbl-calc thead tr:first-child th {
    padding:9px 10px;
    font-size:11px;
    font-weight:700;
    letter-spacing:.06em;
    text-transform:uppercase;
    border:none;
}
#tbl-calc thead tr:last-child th {
    padding:6px 8px;
    font-size:11px;
    font-weight:600;
    border-bottom:2px solid #c8d8e8;
}
#tbl-calc tbody td {
    padding:8px 10px;
    border-bottom:1px solid #f0f0f0;
    vertical-align:middle;
}
#tbl-calc tbody tr:hover td { background:rgba(0,0,0,.025) }
#tbl-calc tfoot td {
    padding:9px 10px;
    font-weight:700;
    font-size:12px;
    border-top:2px solid #c8d8e8;
    background:#f5f7fa;
}
/* Section header colours */
.th-emp   { background:#f5f7fa; color:#334155; min-width:180px; }
.th-att   { background:#1a6fb5; color:#fff; }
.th-att-s { background:#dbeafe; color:#1e40af; border-right:1px solid #bfd3f2; }
.th-earn  { background:#166534; color:#fff; }
.th-earn-s{ background:#dcfce7; color:#166534; border-right:1px solid #a7f3c0; }
.th-ded   { background:#991b1b; color:#fff; }
.th-ded-s { background:#fee2e2; color:#991b1b; }
.th-net   { background:#1e3a8a; color:#fff; min-width:100px; }
.th-pay   { background:#374151; color:#fff; min-width:140px; }
</style>

<div class="card" style="border:none;box-shadow:0 1px 4px rgba(0,0,0,.08)">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid #e8edf2">
        <input type="text" id="calc-search" class="form-control form-control-sm"
               placeholder="Search employee…" style="max-width:260px">
        <button onclick="window.print()" class="btn btn-sm btn-ghost no-print">⎙ Print</button>
    </div>
    <div style="overflow-x:auto">
        <table id="tbl-calc">
            <thead>
                <tr>
                    <th rowspan="2" class="th-emp" style="vertical-align:middle;padding:9px 12px">Employee</th>
                    <th colspan="6" class="th-att text-center">Attendance</th>
                    <th colspan="2" class="th-earn text-center">Earnings</th>
                    <th colspan="3" class="th-ded text-center">Deductions</th>
                    <th rowspan="2" class="th-net text-center" style="vertical-align:middle">Net Pay</th>
                    <th rowspan="2" class="th-pay text-center" style="vertical-align:middle">Payslip</th>
                </tr>
                <tr>
                    <th class="th-att-s text-center" title="Present (incl. Late)">P</th>
                    <th class="th-att-s text-center" title="Half Day">H</th>
                    <th class="th-att-s text-center" title="Absent (deducted)">A</th>
                    <th class="th-att-s text-center" title="On Leave">L</th>
                    <th class="th-att-s text-center" title="Late arrivals">Late</th>
                    <th class="th-att-s text-center" title="Overtime hours" style="border-right:2px solid #bfd3f2">OT Hrs</th>
                    <th class="th-earn-s text-end">Gross</th>
                    <th class="th-earn-s text-end" style="border-right:2px solid #a7f3c0">OT ₹</th>
                    <th class="th-ded-s text-end">PF+ESI</th>
                    <th class="th-ded-s text-end">Abs+Late</th>
                    <th class="th-ded-s text-end" style="border-right:2px solid #fca5a5">Other</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row):
                $emp    = $row['employee'];
                $result = $row['result'];
                $slip   = $row['slip'];
                $as     = $result['attendance_summary'] ?? [];
                $rowStyle = '';
                if ($row['before_joining']) $rowStyle = 'opacity:.45;background:#f1f5f9';
                elseif ($row['no_salary'])  $rowStyle = 'background:#fefce8';
            ?>
            <tr style="<?= $rowStyle ?>">
                <td>
                    <div style="font-weight:500"><?= h($emp['name']) ?></div>
                    <div class="small muted"><?= h($emp['employee_id']) ?></div>
                    <?php if ($emp['dept_name']): ?>
                    <span class="badge bg-secondary" style="font-size:10px;font-weight:400"><?= h($emp['dept_name']) ?></span>
                    <?php endif; ?>
                </td>
                <?php if ($result): ?>
                <td class="text-center" style="color:var(--success)"><?= $as['present_days'] ?? '—' ?></td>
                <td class="text-center"><?= $as['half_days'] ?? 0 ?></td>
                <td class="text-center" style="<?= ($as['absent_days'] ?? 0) > 0 ? 'color:var(--danger)' : '' ?>">
                    <?= number_format((float)($as['absent_days'] ?? 0), 1) ?>
                </td>
                <td class="text-center"><?= $as['leave_days'] ?? 0 ?></td>
                <td class="text-center">
                    <?php $lm = $as['late_minutes'] ?? 0; ?>
                    <?php if ($lm > 0): ?>
                    <span style="<?= $lm > 90 ? 'color:var(--danger);font-weight:600' : 'color:var(--success)' ?>" title="<?= $lm ?> min total">
                        <?= $lm ?>m
                    </span>
                    <?php else: ?>
                    <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td class="text-center">
                    <?php $ot = (float)($as['ot_hours'] ?? 0); ?>
                    <?= $ot > 0 ? '<span style="color:var(--success)">' . number_format($ot, 1) . '</span>' : '<span class="muted">—</span>' ?>
                </td>
                <td class="text-end"><?= money($result['gross_earnings']) ?></td>
                <td class="text-end">
                    <?= $row['ot_amount'] > 0 ? '<span style="color:var(--success)">' . money($row['ot_amount']) . '</span>' : '<span class="muted">—</span>' ?>
                </td>
                <td class="text-end"><?= money($row['pf_esi_ded']) ?></td>
                <td class="text-end">
                    <?= $row['absent_late_ded'] > 0 ? '<span style="color:var(--danger)">' . money($row['absent_late_ded']) . '</span>' : '<span class="muted">—</span>' ?>
                </td>
                <td class="text-end">
                    <?= $row['other_ded'] > 0 ? money($row['other_ded']) : '<span class="muted">—</span>' ?>
                </td>
                <td class="text-center" style="font-weight:700;font-size:14px;color:var(--primary)"><?= money($result['net_pay']) ?></td>
                <?php else: ?>
                <td colspan="11" class="text-center" style="color:var(--muted);font-size:12px">
                    <?= $row['before_joining'] ? 'Before joining date' : 'No salary structure' ?>
                </td>
                <?php endif; ?>
                <!-- PAYSLIP ACTIONS -->
                <td class="text-center" style="white-space:nowrap">
                    <?php if ($row['before_joining']): ?>
                        <span class="badge bg-secondary">Before Joining</span>
                    <?php elseif ($row['no_salary']): ?>
                        <span class="badge bg-warning text-dark">No Salary</span>
                    <?php elseif ($slip): ?>
                        <a href="<?= BASE_URL ?>/modules/payroll/slip.php?id=<?= $slip['id'] ?>" class="btn btn-xs" title="View" style="padding:3px 7px;font-size:11px">View</a>
                        <a href="<?= BASE_URL ?>/modules/payroll/slip_pdf.php?id=<?= $slip['id'] ?>" class="btn btn-xs" style="padding:3px 7px;font-size:11px;color:var(--danger);border-color:var(--danger)" target="_blank" title="PDF">PDF</a>
                        <?php if (can('payroll','process')): ?>
                        <a href="<?= $isFutureMonth ? '#' : BASE_URL . '/modules/payroll/generate_slip.php?emp=' . $emp['id'] . '&month=' . $month . '&year=' . $year ?>"
                           class="btn btn-xs" style="padding:3px 7px;font-size:11px;color:#e6a817;border-color:#e6a817<?= $isFutureMonth ? ';opacity:.5;pointer-events:none' : '' ?>"
                           title="<?= $isFutureMonth ? 'Future month — payroll not allowed' : 'Regenerate (set paid leaves on the form)' ?>">
                            <i class="fa fa-refresh"></i>
                        </a>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if (can('payroll','process')): ?>
                        <a href="<?= $isFutureMonth ? '#' : BASE_URL . '/modules/payroll/generate_slip.php?emp=' . $emp['id'] . '&month=' . $month . '&year=' . $year ?>"
                           class="btn btn-xs" style="padding:3px 7px;font-size:11px;background:var(--success);color:#fff;border-color:var(--success)<?= $isFutureMonth ? ';opacity:.5;pointer-events:none' : '' ?>"
                           title="<?= $isFutureMonth ? 'Future month — payroll not allowed' : 'Generate Slip (set paid leaves on the form)' ?>">
                            <i class="fa fa-bolt"></i> Generate
                        </a>
                        <?php else: ?>
                        <span class="muted small">Not generated</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if ($employeesProcessed > 0): ?>
            <tfoot>
                <tr>
                    <td style="color:#334155">Totals — <?= $employeesProcessed ?> employees</td>
                    <td colspan="6"></td>
                    <td class="text-end" style="color:#166534"><?= money($totals['gross']) ?></td>
                    <td class="text-end" style="color:#166534"><?= money($totals['ot']) ?></td>
                    <td class="text-end" style="color:#991b1b"><?= money($totals['pf_esi']) ?></td>
                    <td class="text-end" style="color:#991b1b"><?= money($totals['absent_late']) ?></td>
                    <td class="text-end" style="color:#991b1b"><?= money($totals['other_ded']) ?></td>
                    <td class="text-end" style="color:#1e3a8a;font-size:14px"><?= money($totals['net']) ?></td>
                    <td></td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<!-- Legend -->
<div style="margin-top:10px;font-size:11px;color:var(--muted);display:flex;gap:16px;flex-wrap:wrap">
    <span><strong>P</strong> = Present (incl. Late)</span>
    <span><strong>H</strong> = Half Day (0.5×)</span>
    <span><strong>A</strong> = Absent (deducted)</span>
    <span><strong>L</strong> = On Leave</span>
    <span><strong>Late</strong> = Late arrivals — penalty if &gt;90 min/month (2× rate)</span>
    <span><strong>OT</strong> = Overtime hours (2× hourly rate)</span>
</div>

<?php $page_scripts = <<<'JS'
<script>
// Simple row search — avoids DataTables column-count mismatch on complex thead
document.addEventListener('DOMContentLoaded', function () {
    var input = document.getElementById('calc-search');
    if (!input) return;
    input.addEventListener('input', function () {
        var q = this.value.toLowerCase();
        document.querySelectorAll('#tbl-calc tbody tr').forEach(function (tr) {
            var text = tr.querySelector('td:first-child') ? tr.querySelector('td:first-child').textContent.toLowerCase() : '';
            tr.style.display = (!q || text.includes(q)) ? '' : 'none';
        });
    });
});
</script>
JS; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
