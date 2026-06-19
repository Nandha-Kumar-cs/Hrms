<?php
/**
 * Record a manual loan repayment.
 * Ported from Employee_Management LoanController::storeRepayment.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('loans', 'create');
verify_csrf($_POST['csrf_token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . '/modules/loans/index.php');

$db  = db();
$id  = (int)($_POST['loan_id'] ?? 0);
$st  = $db->prepare('SELECT * FROM employee_loans WHERE id = ?');
$st->execute([$id]);
$loan = $st->fetch();
if (!$loan) { flash('error', 'Loan record not found.'); redirect(BASE_URL . '/modules/loans/index.php'); }

$back = BASE_URL . '/modules/loans/show.php?id=' . $id;

// ── Current pending balance (total due − already repaid) ─────────────────────
$amount   = (float)$loan['amount'];
$rate     = (float)$loan['interest_rate'];
$months   = max(1, (int)$loan['total_months']);
$totalInterest = $rate > 0 ? round($amount * ($rate / 100) * ($months / 12), 2) : 0.0;
$totalDue = round($amount + $totalInterest, 2);

$sumStmt = $db->prepare('SELECT COALESCE(SUM(amount_paid),0) FROM loan_repayments WHERE employee_loan_id = ?');
$sumStmt->execute([$id]);
$returned = (float)$sumStmt->fetchColumn();
$pending  = max(0.0, round($totalDue - $returned, 2));

// ── Validate ─────────────────────────────────────────────────────────────────
$amountPaid  = (float)($_POST['amount_paid'] ?? 0);
$paymentDate = trim($_POST['payment_date'] ?? '');
$note        = trim($_POST['note'] ?? '') ?: null;

$errors = [];
$pd = DateTime::createFromFormat('Y-m-d', $paymentDate);
if ($amountPaid < 0.01)                  $errors[] = 'Amount paid must be greater than zero.';
if ($amountPaid > $pending + 0.01)       $errors[] = 'Amount paid (' . money($amountPaid) . ') exceeds the pending balance (' . money($pending) . ').';
if (!$paymentDate || !$pd || $pd->format('Y-m-d') !== $paymentDate) $errors[] = 'A valid payment date is required.';
if ($loan['status'] !== 'active')        $errors[] = 'Only active loans can take repayments.';

if ($errors) { flash('error', implode(' ', $errors)); redirect($back); }

// ── Record the repayment ─────────────────────────────────────────────────────
$db->prepare('INSERT INTO loan_repayments (employee_loan_id, amount_paid, payment_date, note) VALUES (?,?,?,?)')
   ->execute([$id, $amountPaid, $paymentDate, $note]);

// Bump the paid-months counter and refresh the returned/pending figures.
$newPaidMonths = (int)$loan['paid_months'] + 1;
$sumStmt->execute([$id]);
$returned = round((float)$sumStmt->fetchColumn(), 2);
$pending  = max(0.0, round($totalDue - $returned, 2));

// Auto-complete when fully settled (balance cleared OR all installments paid).
$status = $loan['status'];
if ($pending <= 0.01 || $newPaidMonths >= $months) $status = 'completed';

// Keep the cached returned_amount column in sync (read by the list & profile).
$db->prepare('UPDATE employee_loans SET paid_months = ?, returned_amount = ?, status = ? WHERE id = ?')
   ->execute([$newPaidMonths, $returned, $status, $id]);

if (function_exists('activity_log')) {
    $empLabel = function_exists('activity_emp_label') ? activity_emp_label((int)$loan['employee_id']) : ('employee #' . (int)$loan['employee_id']);
    activity_log('created', 'Loan', 'Repayment of ' . money($amountPaid) . ' recorded for ' . $empLabel
        . ($status === 'completed' ? ' — Loan fully settled ✓' : ' — Pending: ' . money($pending)));
}

$msg = 'Repayment of ' . money($amountPaid) . ' recorded successfully.';
$msg .= $status === 'completed' ? ' Loan fully settled ✓' : ' Pending balance: ' . money($pending) . '.';
flash('success', $msg);
redirect($back);
