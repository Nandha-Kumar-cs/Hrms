<?php
/**
 * Loan / Advance — Loan History.
 * Shows the deduction schedule derived from the employee's salary slips:
 * how much was deducted, on which month/date, the running pending balance and
 * the interest. Replaces the old manual repayment screen.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/loan_history.php';
require_login();
require_permission('loans', 'view');

$db = db();
$id = (int)($_GET['id'] ?? 0);

$st = $db->prepare(
    'SELECT el.*, e.name AS emp_name, e.employee_id AS emp_code
     FROM employee_loans el JOIN employees e ON e.id = el.employee_id
     WHERE el.id = ?'
);
$st->execute([$id]);
$loan = $st->fetch();
if (!$loan) { flash('error', 'Loan record not found.'); redirect(BASE_URL . '/modules/loans/index.php'); }

// Self-scoped employees may only view their own loan.
require_own_employee((int)$loan['employee_id']);

$fig      = loan_figures($db, $loan);
$schedule = loan_schedule($loan, $fig);

$amount        = $fig['amount'];
$rate          = $fig['rate'];
$months        = $fig['months'];
$emi           = (float)$loan['monthly_deduction'];
$totalInterest = $fig['interest'];
$totalDue      = $fig['total_due'];
$returned      = $fig['returned'];
$pending       = $fig['pending'];
$paidMonths    = $fig['paid_months'];
$remaining     = max(0, $months - $paidMonths);
$status        = $fig['status'];
$progressPct   = $totalDue > 0 ? min(100.0, round(($returned / $totalDue) * 100, 1)) : 0.0;
$statusBadge   = ['active' => 'success', 'completed' => 'primary'][$status] ?? 'secondary';
$perInstInt    = ($months > 0 && $totalInterest > 0) ? round($totalInterest / $months, 2) : 0.0;

$page_title = 'Loan History';
require_once __DIR__ . '/../../includes/header.php';
render_flash();
?>
<div class="row g-3">

    <!-- ── Summary ─────────────────────────────────────────────────────── -->
    <div class="col-md-4">
        <div class="card page-card h-100">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-semibold"><i class="fa fa-file-invoice-dollar me-1 text-primary"></i>Loan Summary</h6>
                <div class="d-flex gap-1">
                    <?php if (can('loans', 'create')): ?>
                    <a href="<?= BASE_URL ?>/modules/loans/create.php?id=<?= (int)$loan['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa fa-edit"></i></a>
                    <?php endif; ?>
                    <a href="<?= BASE_URL ?>/modules/loans/index.php" class="btn btn-sm btn-outline-secondary" title="Back"><i class="fa fa-arrow-left"></i></a>
                </div>
            </div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr><th class="text-muted" style="width:42%">Employee</th><td class="fw-semibold"><?= h($loan['emp_name']) ?></td></tr>
                    <tr><th class="text-muted">Code</th><td><?= h($loan['emp_code']) ?></td></tr>
                    <tr><th class="text-muted">Type</th><td><span class="badge bg-<?= $loan['type'] === 'loan' ? 'primary' : 'info' ?>"><?= ucfirst($loan['type']) ?></span></td></tr>
                    <tr><th class="text-muted">Date Given</th><td><?= date('d M Y', strtotime($loan['date_given'])) ?></td></tr>
                    <tr><th class="text-muted">Total Months</th><td><?= $months ?></td></tr>
                    <tr><th class="text-muted">Deducted Months</th><td><?= $paidMonths ?></td></tr>
                    <tr><th class="text-muted">Remaining</th><td><?= $remaining ?> month(s)</td></tr>
                    <tr><th class="text-muted">Monthly EMI</th><td><?= money($emi) ?></td></tr>
                    <tr><th class="text-muted">Status</th><td><span class="badge bg-<?= $statusBadge ?>"><?= ucfirst($status) ?></span></td></tr>
                </table>

                <hr>

                <div class="table-responsive mb-3">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><th class="text-muted">Principal</th><td class="text-end fw-semibold"><?= money($amount) ?></td></tr>
                        <?php if ($rate > 0): ?>
                        <tr>
                            <th class="text-muted">Interest
                                <span class="text-muted fw-normal" style="font-size:.78rem">(<?= h($rate) ?>% p.a. × <?= $months ?> mo)</span>
                            </th>
                            <td class="text-end text-warning fw-semibold">+ <?= money($totalInterest) ?></td>
                        </tr>
                        <?php else: ?>
                        <tr><th class="text-muted">Interest</th><td class="text-end text-muted">Nil</td></tr>
                        <?php endif; ?>
                        <tr class="border-top"><th>Total Due</th><td class="text-end fw-bold text-primary"><?= money($totalDue) ?></td></tr>
                    </table>
                </div>

                <div class="row text-center g-2 mb-2">
                    <div class="col-4"><div class="text-muted" style="font-size:.72rem">Total Due</div><div class="fw-bold text-primary" style="font-size:.9rem"><?= money($totalDue) ?></div></div>
                    <div class="col-4"><div class="text-muted" style="font-size:.72rem">Returned</div><div class="fw-bold text-success" style="font-size:.9rem"><?= money($returned) ?></div></div>
                    <div class="col-4"><div class="text-muted" style="font-size:.72rem">Pending</div><div class="fw-bold text-danger" style="font-size:.9rem"><?= money($pending) ?></div></div>
                </div>

                <div class="progress mb-1" style="height:10px">
                    <div class="progress-bar bg-success" style="width:<?= min(100, $progressPct) ?>%" title="<?= $progressPct ?>% repaid"></div>
                </div>
                <div class="d-flex justify-content-between small text-muted">
                    <span><?= $progressPct ?>% repaid</span>
                    <span><?= money($pending) ?> left</span>
                </div>

                <?php if ($status === 'completed'): ?>
                <div class="alert alert-success py-2 mt-3 text-center small mb-0"><i class="fa fa-check-circle me-1"></i>Loan fully settled</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Loan History (deduction schedule) ───────────────────────────── -->
    <div class="col-md-8">
        <div class="card page-card">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-semibold"><i class="fa fa-history me-1 text-primary"></i>Loan History &mdash; Deduction Schedule</h6>
                <a href="<?= BASE_URL ?>/modules/loans/history_pdf.php?id=<?= (int)$loan['id'] ?>" target="_blank" class="btn btn-sm btn-danger">
                    <i class="fa fa-file-pdf me-1"></i>PDF
                </a>
            </div>
            <div class="card-body">
                <?php if (!$schedule): ?>
                <div class="text-center text-muted py-5"><i class="fa fa-inbox fa-2x mb-2 d-block"></i>No deduction schedule available.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover table-bordered align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>#</th>
                                <th>Month</th>
                                <th>Deduction Date</th>
                                <th class="text-end">Amount Deducted</th>
                                <?php if ($rate > 0): ?><th class="text-end">Interest</th><?php endif; ?>
                                <th class="text-end">Returned</th>
                                <th class="text-end">Pending</th>
                                <th class="text-center">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $schedN = count($schedule); $intSeq = 0; $intSum = 0.0; ?>
                            <?php foreach ($schedule as $row): $intSeq++;
                                // Spread interest across instalments; the LAST row takes the rounding
                                // remainder so the Interest column sums exactly to the total.
                                $rowInt = ($intSeq === $schedN) ? round($totalInterest - $intSum, 2) : $perInstInt;
                                $intSum += $rowInt;
                            ?>
                            <tr class="<?= $row['deducted'] ? '' : 'table-light text-muted' ?>">
                                <td><?= $row['seq'] ?></td>
                                <td><?= date('M Y', strtotime($row['month'] . '-01')) ?></td>
                                <td><?= date('d M Y', strtotime($row['date'])) ?></td>
                                <td class="text-end fw-semibold <?= $row['deducted'] ? 'text-success' : '' ?>"><?= money($row['amount']) ?></td>
                                <?php if ($rate > 0): ?><td class="text-end text-warning"><?= money($rowInt) ?></td><?php endif; ?>
                                <td class="text-end"><?= money($row['returned']) ?></td>
                                <td class="text-end <?= $row['pending'] > 0 ? 'text-danger' : 'text-success' ?>"><?= money($row['pending']) ?></td>
                                <td class="text-center">
                                    <?php if ($row['deducted']): ?>
                                    <span class="badge bg-success">Deducted</span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary">Upcoming</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="3">Total</th>
                                <th class="text-end text-success"><?= money($returned) ?></th>
                                <?php if ($rate > 0): ?><th class="text-end text-warning"><?= money($totalInterest) ?></th><?php endif; ?>
                                <th class="text-end text-success"><?= money($returned) ?></th>
                                <th class="text-end <?= $pending > 0 ? 'text-danger' : 'text-success' ?> fw-bold"><?= money($pending) ?></th>
                                <th></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="d-flex gap-3 flex-wrap mt-3 small">
                    <span class="badge bg-light text-dark border py-2 px-3">Total Due: <strong><?= money($totalDue) ?></strong></span>
                    <span class="badge bg-success py-2 px-3">Returned: <?= money($returned) ?></span>
                    <span class="badge bg-<?= $pending > 0 ? 'danger' : 'success' ?> py-2 px-3"><?= $pending > 0 ? 'Pending: ' . money($pending) : '✓ Fully Paid' ?></span>
                    <?php if ($rate > 0): ?>
                    <span class="badge bg-warning text-dark py-2 px-3">Interest: <?= money($totalInterest) ?></span>
                    <?php endif; ?>
                </div>
                <p class="text-muted small mt-2 mb-0">Deductions are taken automatically from the employee's monthly salary slips. Upcoming rows are projected from the EMI and may change.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
