<?php
/**
 * Delete a salary slip (POST only).
 *
 * A slip is not just a document — it IS the record of what was paid, and it is
 * the only place a loan repayment is recorded (see loan_actual_deductions() in
 * includes/loan_history.php, which reconstructs a loan's whole history from
 * salary_slips.deductions_json). Deleting one therefore has to be guarded and
 * fully described in the audit trail (security audit M-20).
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/loan_history.php';
require_login();
require_permission('payroll', 'process');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    flash('error', 'Invalid request.');
    redirect(BASE_URL . '/modules/payroll/index.php');
}

$slipId = (int)($_POST['slip_id'] ?? 0);
if (!$slipId) {
    flash('error', 'Invalid slip ID.');
    redirect(BASE_URL . '/modules/payroll/index.php');
}

$db   = db();
$slip = $db->prepare('SELECT * FROM salary_slips WHERE id = ? LIMIT 1');
$slip->execute([$slipId]);
$s = $slip->fetch();

if (!$s) {
    flash('error', 'Salary slip not found.');
    redirect(BASE_URL . '/modules/payroll/index.php');
}

$month    = (string)($s['payroll_month'] ?? '');
$back     = BASE_URL . '/modules/payroll/index.php?month=' . ($month ?: date('Y-m'));
$empLabel = activity_emp_label((int)$s['employee_id']);

/* ── A finalized month is closed ──────────────────────────────────────────────
 * generate_slip.php already refuses to create or rewrite a slip in a finalized
 * month (M-3), and process.php refuses to reprocess one — but deletion was left
 * open, which is the same thing by another route: removing a slip changes the
 * signed-off totals exactly as much as rewriting one does. */
$runSt = $db->prepare('SELECT status FROM payroll_runs WHERE payroll_month = ? LIMIT 1');
$runSt->execute([$month]);
if (($runSt->fetchColumn() ?: '') === 'Finalized') {
    activity_log('deleted', 'Payroll',
        "REFUSED deletion of salary slip #$slipId for $empLabel — $month is finalized.");
    flash('error', 'Payroll for ' . $month . ' is finalized. Slips for a finalized month cannot be deleted.');
    redirect($back);
}

/* ── A slip already sent to the employee needs override authority ─────────────
 * status 'Sent' means the holder has it in hand. Removing the employer's copy of
 * a document the employee is holding should not be an ordinary payroll action. */
if ((string)($s['status'] ?? '') === 'Sent' && !can('payroll', 'override')) {
    activity_log('deleted', 'Payroll',
        "REFUSED deletion of salary slip #$slipId for $empLabel — slip was already Sent and the actor lacks payroll.override.");
    flash('error', 'This slip has already been sent to the employee. Deleting it requires the Payroll Override permission.');
    redirect($back);
}

/* ── What does this slip repay? ───────────────────────────────────────────────
 * Loan repayments live ONLY in deductions_json. Deleting the slip erases that
 * instalment from the loan's history, so the outstanding balance rises again and
 * the same EMI is charged a second time in a later month. That may genuinely be
 * what the operator wants (delete, correct, regenerate) — but it must never
 * happen without being written down. */
$loanLines = [];
foreach ((array)json_decode((string)($s['deductions_json'] ?? '[]'), true) as $k => $v) {
    if (preg_match('/Deduction #\d+$/', (string)$k) && (float)$v > 0) {
        $loanLines[(string)$k] = round((float)$v, 2);
    }
}

$changes = [
    ['field' => 'Payroll month',    'from' => $month,                                     'to' => '(slip deleted)'],
    ['field' => 'Net pay',          'from' => money((float)$s['net_pay']),                'to' => '—'],
    ['field' => 'Gross earnings',   'from' => money((float)($s['gross_earnings'] ?? 0)),  'to' => '—'],
    ['field' => 'Total deductions', 'from' => money((float)($s['total_deductions'] ?? 0)),'to' => '—'],
    ['field' => 'Slip status',      'from' => (string)($s['status'] ?? ''),               'to' => '—'],
];
foreach ($loanLines as $k => $amt) {
    $changes[] = ['field' => 'Loan repayment reversed', 'from' => $k . ' — ' . money($amt), 'to' => 'no longer recorded'];
}

$db->prepare('DELETE FROM salary_slips WHERE id = ?')->execute([$slipId]);

$desc = "Deleted salary slip #$slipId for $empLabel — $month. Net Pay was " . money((float)$s['net_pay']) . '.';
if ($loanLines) {
    $desc .= ' This slip recorded ' . count($loanLines) . ' loan/advance repayment('
           . implode(', ', array_map(fn($k, $v) => $k . ' ' . money($v), array_keys($loanLines), $loanLines))
           . ') — those instalments are no longer counted against the loan, so the balance has increased'
           . ' and the EMI will be charged again in a future month.';
}
activity_log('deleted', 'Payroll', $desc, $changes);

if ($loanLines) {
    flash('warn', 'Salary slip deleted. NOTE: it recorded '
        . implode(' and ', array_map(fn($k, $v) => $k . ' of ' . money($v), array_keys($loanLines), $loanLines))
        . '. That repayment is no longer counted — the loan balance has gone back up and the instalment'
        . ' will be deducted again unless you regenerate this slip.');
} else {
    flash('success', 'Salary slip deleted.');
}
redirect($back);
