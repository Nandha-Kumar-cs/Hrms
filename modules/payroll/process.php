<?php
/**
 * Process Payroll — batch run for a month.
 *
 * This screen has ONE job: run the payroll engine over every eligible employee
 * and write the resulting slips. It used to carry its own inline formula, which
 * disagreed with PayrollCalculator (the engine behind the Salary Calculation
 * preview and individual slip generation) on nearly everything: working days,
 * half days, approved paid leave, loans, benefits, bonuses, overtime, the late
 * penalty, short hours and the ESI base. The operator reviewed one number on the
 * preview screen and "Process Payroll" then wrote a materially different one,
 * with allowances / deductions_json / attendance_summary / fixed_salary all left
 * NULL so the payslip renderer and the loan-repayment tracker had nothing to
 * read (security audit C-1).
 *
 * The inline formula is gone. Every figure below comes from
 * PayrollCalculator::computePayroll() + payroll_apply_extras() — the exact path
 * generate_slip.php takes — so the preview, the batch run and an individually
 * generated slip now agree by construction.
 *
 * Guards and the POST handler run BEFORE header.php so redirect() never has to
 * fall back to a client-side redirect.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/PayrollCalculator.php';
require_once __DIR__ . '/../../includes/payroll_extras.php';
require_login();
require_permission('payroll', 'process');

$db    = db();
$month = sanitize($_GET['month'] ?? date('Y-m'));
$user  = current_user();

if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');

// Block future months — payroll cannot be processed before the month begins.
if ($month > date('Y-m')) {
    flash('error', 'Cannot process payroll for a future month ('
        . date('F Y', strtotime($month . '-01'))
        . '). Payroll is only allowed for the current or a past month.');
    redirect(BASE_URL . '/modules/payroll/index.php');
}

$monthStart = $month . '-01';
$monthEnd   = date('Y-m-t', strtotime($monthStart));
$yearNum    = (int) substr($month, 0, 4);
$monthNum   = (int) substr($month, 5, 2);

// Prevent double processing
$existing = $db->prepare('SELECT * FROM payroll_runs WHERE payroll_month=?');
$existing->execute([$month]);
$run = $existing->fetch();
if ($run && $run['status'] === 'Finalized') {
    flash('warn', 'Payroll for ' . $month . ' is finalized and cannot be reprocessed.');
    redirect(BASE_URL . '/modules/payroll/index.php?month=' . $month);
}

$isSave = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_process']);
if ($isSave && !csrf_verify()) {
    flash('error', 'Invalid request. Please try again.');
    redirect(BASE_URL . '/modules/payroll/process.php?month=' . $month);
}

// ── Employees in scope ───────────────────────────────────────────────────────
// LEFT JOIN, not INNER: the effective salary is resolved by
// PayrollCalculator::getSalaryForMonth(), which also honours increments and the
// employees.fixed_salary fallback. Requiring a salary_structures row here would
// silently drop employees the individual-slip path pays perfectly well.
$empStmt = $db->prepare(
    'SELECT e.*, d.name AS dept_name, des.name AS desig_name
       FROM employees e
       LEFT JOIN departments  d   ON d.id   = e.department_id
       LEFT JOIN designations des ON des.id = e.designation_id
      WHERE e.status = "Active"
      ORDER BY e.name'
);
$empStmt->execute();
$employees = $empStmt->fetchAll();

// sort_order fixes the earnings/deductions row order frozen onto each slip.
$components = $db->query('SELECT * FROM salary_components ORDER BY sort_order, id')->fetchAll();

// Slips that already exist for this month — shown on the preview so the operator
// can see what a run will replace before pressing the button.
$slipMap = [];
$slipSt  = $db->prepare('SELECT employee_id, id, payroll_run_id, slip_type, net_pay FROM salary_slips WHERE payroll_month = ?');
$slipSt->execute([$month]);
foreach ($slipSt->fetchAll() as $s) $slipMap[(int)$s['employee_id']] = $s;

// ── Compute ──────────────────────────────────────────────────────────────────
// persist=true writes each day's worked hours + deduction back onto the
// attendance rows (the audit trail). That is a real write, so it happens only on
// the actual run — never while merely rendering the preview.
$calc         = new PayrollCalculator($db);
$calculations = [];
$skipped      = [];      // [name => reason] — reported, never silently dropped

foreach ($employees as $emp) {
    $empId = (int)$emp['id'];

    // Joined after this payroll month → nothing to pay.
    if (!empty($emp['join_date']) && substr((string)$emp['join_date'], 0, 7) > $month) {
        $skipped[] = ['name' => $emp['name'], 'reason' => 'joined after this month'];
        continue;
    }

    $salary        = $calc->getSalaryForMonth($empId, $monthNum, $yearNum);
    $fixedSalary   = (float)$salary['fixed'];
    $variableSalary = (float)$salary['variable'];
    if ($fixedSalary <= 0) {
        $skipped[] = ['name' => $emp['name'], 'reason' => 'no salary structure in effect'];
        continue;
    }

    $result = $calc->computePayroll(
        $emp, $components, $monthNum, $yearNum, $fixedSalary, $variableSalary, $isSave
    );
    // Benefits & approved bonuses (earnings) and active loan/advance EMIs
    // (deduction) — the same fold-in generate_slip.php and calculate.php apply.
    $result = payroll_apply_extras($db, $result, $empId, $monthNum, $yearNum);

    $result['employee']        = $emp;
    $result['fixed_salary']    = $fixedSalary;
    $result['variable_salary'] = $variableSalary;
    $result['existing_slip']   = $slipMap[$empId] ?? null;
    $calculations[$empId]      = $result;
}

// ── SAVE ─────────────────────────────────────────────────────────────────────
if ($isSave) {
    // Everything below is ONE unit of work. Without a transaction the sequence
    // is: mark the run Processed → delete out-of-scope slips → insert N slips in
    // a loop. A throw anywhere in that loop (a deadlock, a truncation, a dropped
    // connection — PDO is in ERRMODE_EXCEPTION, so any of them is fatal) left the
    // run flagged Processed with some slips written, some deleted and the rest
    // missing, and no way to tell which (security audit M-2). Both tables are
    // InnoDB, so a rollback restores the exact prior state.
    //
    // NOT included, deliberately: the per-day worked_hours / deduction_amount
    // write-back that computePayroll(persist: true) already did in the compute
    // loop above. Those are derived audit fields, recomputed and overwritten on
    // every run, so leaving them is not a corrupting partial state — and pulling
    // the whole compute phase into the transaction would hold row locks across
    // every employee's attendance for the duration of the run.
    $db->beginTransaction();

    try {
    // Upsert the run
    if ($run) {
        $db->prepare('UPDATE payroll_runs SET status="Processed",processed_by=?,processed_at=NOW() WHERE id=?')
           ->execute([$user['id'], $run['id']]);
        $run_id = (int)$run['id'];
    } else {
        $db->prepare('INSERT INTO payroll_runs (payroll_month,status,processed_by,processed_at) VALUES(?,?,?,NOW())')
           ->execute([$month, 'Processed', $user['id']]);
        $run_id = (int)$db->lastInsertId();
    }

    // Drop this run's own slips for employees who are no longer in scope. The
    // ones still in scope are upserted below, so they are never deleted and
    // re-inserted (which would burn autoincrement ids and break slip links).
    if ($calculations) {
        $keep = implode(',', array_map('intval', array_keys($calculations)));
        $db->prepare("DELETE FROM salary_slips WHERE payroll_run_id = ? AND employee_id NOT IN ($keep)")
           ->execute([$run_id]);
    } else {
        $db->prepare('DELETE FROM salary_slips WHERE payroll_run_id = ?')->execute([$run_id]);
    }

    // ON DUPLICATE KEY UPDATE, because salary_slips is unique on
    // (employee_id, payroll_month): an individually generated slip for the same
    // month would otherwise make the whole run fail on a key collision.
    $ins = $db->prepare(
        'INSERT INTO salary_slips
         (payroll_run_id, employee_id, payroll_month, fixed_salary, variable_salary,
          allowances, deductions_json, attendance_summary,
          working_days, present_days, lop_days,
          basic, hra, conveyance, medical, special_allow, other_allow,
          gross_earnings, pf_employee, pf_employer, esi_employee, esi_employer,
          total_deductions, net_pay, slip_type, status)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,"batch","Generated")
         ON DUPLICATE KEY UPDATE
            payroll_run_id     = VALUES(payroll_run_id),
            fixed_salary       = VALUES(fixed_salary),
            variable_salary    = VALUES(variable_salary),
            allowances         = VALUES(allowances),
            deductions_json    = VALUES(deductions_json),
            attendance_summary = VALUES(attendance_summary),
            working_days       = VALUES(working_days),
            present_days       = VALUES(present_days),
            lop_days           = VALUES(lop_days),
            basic              = VALUES(basic),
            hra                = VALUES(hra),
            conveyance         = VALUES(conveyance),
            medical            = VALUES(medical),
            special_allow      = VALUES(special_allow),
            other_allow        = VALUES(other_allow),
            gross_earnings     = VALUES(gross_earnings),
            pf_employee        = VALUES(pf_employee),
            pf_employer        = VALUES(pf_employer),
            esi_employee       = VALUES(esi_employee),
            esi_employer       = VALUES(esi_employer),
            total_deductions   = VALUES(total_deductions),
            net_pay            = VALUES(net_pay),
            slip_type          = "batch",
            status             = "Generated"'
    );

    foreach ($calculations as $emp_id => $c) {
        $ins->execute([
            $run_id, $emp_id, $month, $c['fixed_salary'], $c['variable_salary'],
            json_encode($c['allowances'],         JSON_UNESCAPED_UNICODE),
            json_encode($c['deductions'],         JSON_UNESCAPED_UNICODE),
            json_encode($c['attendance_summary'], JSON_UNESCAPED_UNICODE),
            $c['working_days'], $c['present_days'], $c['lop_days'],
            $c['basic'], $c['hra'], $c['conveyance'], $c['medical'],
            $c['special_allow'], $c['other_allow'],
            $c['gross_earnings'], $c['pf_employee'], $c['pf_employer'],
            $c['esi_employee'], $c['esi_employer'],
            $c['total_deductions'], $c['net_pay'],
        ]);
    }

        $db->commit();

    } catch (Throwable $e) {
        // Undo the whole run — the month is left exactly as it was before the
        // button was pressed, rather than half-processed with the run already
        // flagged Processed (security audit M-2).
        if ($db->inTransaction()) $db->rollBack();

        error_log('Payroll batch run failed for ' . $month . ': ' . $e->getMessage());
        activity_log('updated', 'Payroll',
            'Batch payroll run for ' . date('F Y', strtotime($monthStart))
            . ' FAILED and was rolled back — no slips were written. Error: ' . $e->getMessage()
        );
        flash('error',
            'Payroll could not be processed for ' . date('F Y', strtotime($monthStart))
            . '. The run was rolled back and nothing was changed — no slips were written and the '
            . 'month is not marked as processed. Please review the error and try again. ('
            . $e->getMessage() . ')'
        );
        redirect(BASE_URL . '/modules/payroll/process.php?month=' . $month);
    }

    // ── Committed — only now is it safe to report success ────────────────────
    $msg = 'Payroll processed for ' . date('F Y', strtotime($monthStart))
         . ' — ' . count($calculations) . ' employees.';
    if ($skipped) $msg .= ' ' . count($skipped) . ' skipped (see the preview screen for why).';
    flash('success', $msg);

    // One summary entry per run, not per employee — a batch run has no manual
    // overrides to call out individually, unlike generate_slip.php (security
    // audit H-11 — payroll previously had zero activity-log coverage at all).
    activity_log('updated', 'Payroll',
        'Batch-processed payroll for ' . date('F Y', strtotime($monthStart))
        . ' — ' . count($calculations) . ' employees, total gross '
        . money(array_sum(array_column($calculations, 'gross_earnings')))
        . ', total net ' . money(array_sum(array_column($calculations, 'net_pay')))
        . ($skipped ? ' (' . count($skipped) . ' skipped)' : '')
    );
    redirect(BASE_URL . '/modules/payroll/index.php?month=' . $month);
}

// ── Preview ──────────────────────────────────────────────────────────────────
// Working days are a property of the month (calendar − weekly offs − holidays),
// so every employee shares the same figure; take it from the first computed row.
$firstRow     = $calculations ? reset($calculations) : null;
$workingDays  = $firstRow ? (int)$firstRow['working_days'] : 0;
$replacing    = array_filter($calculations, fn($c) => !empty($c['existing_slip']));

$page_title = 'Process Payroll';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Process Payroll</h1>
        <p class="muted"><?= date('F Y', strtotime($monthStart)) ?> · Working days: <?= $workingDays ?></p>
    </div>
    <div class="head-actions">
        <a href="index.php?month=<?= h($month) ?>" class="btn btn-ghost" accesskey="b" data-shortcut data-key="B"><u>B</u>ack</a>
    </div>
</div>

<?= render_flash() ?>

<?php if (!$calculations): ?>
<div class="alert alert-warn">
    No employees can be paid for this month. Please <a href="salary_structure.php">set up salary structures</a> first.
</div>
<?php else: ?>

<?php if ($replacing): ?>
<div class="alert alert-warn">
    <strong><?= count($replacing) ?></strong> employee(s) already have a salary slip for
    <?= date('F Y', strtotime($monthStart)) ?>. Processing will <strong>replace</strong> those slips with the
    figures below — including any generated individually with manual paid-leave or OT overrides.
</div>
<?php endif; ?>

<?php if ($skipped): ?>
<div class="alert alert-info">
    <strong><?= count($skipped) ?></strong> active employee(s) are not included in this run:
    <?= h(implode(', ', array_map(fn($s) => $s['name'] . ' (' . $s['reason'] . ')', array_slice($skipped, 0, 8)))) ?><?php
    if (count($skipped) > 8) echo ' and ' . (count($skipped) - 8) . ' more'; ?>.
</div>
<?php endif; ?>

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
            <th class="r">Monthly CTC</th>
            <th class="r">Present</th>
            <th class="r">Paid Leave</th>
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
            $as  = $c['attendance_summary'];
        ?>
        <tr>
            <td>
                <div style="font-weight:500"><?= h($emp['name']) ?></div>
                <div class="small muted"><?= h($emp['employee_id']) ?><?php
                    if (!empty($c['existing_slip'])) echo ' · <span class="text-danger">slip exists</span>'; ?></div>
            </td>
            <td class="r"><?= money($c['fixed_salary']) ?></td>
            <td class="r"><?= $c['present_days'] ?>/<?= $c['working_days'] ?></td>
            <td class="r"><?= (float)($as['paid_leave_days'] ?? 0) ?></td>
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
        ✓ <u>S</u>ave &amp; Process
    </button>
    <a href="index.php?month=<?= h($month) ?>" class="btn btn-ghost">Cancel</a>
    <span class="small muted">PF: 12% of earned basic (capped) · ESI: 0.75% + 3.25% (CTC below <?= money(PAYROLL_ESI_WAGE_LIMIT,false) ?>)</span>
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
