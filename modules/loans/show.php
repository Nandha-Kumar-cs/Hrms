<?php
/**
 * Loan / Advance detail + repayment history.
 * Ported from the Employee_Management loans.show screen.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
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

$canManage = can('loans', 'create');

// ── Repayments ───────────────────────────────────────────────────────────────
$rp = $db->prepare('SELECT * FROM loan_repayments WHERE employee_loan_id = ? ORDER BY payment_date ASC, id ASC');
$rp->execute([$id]);
$repayments = $rp->fetchAll();

// ── Computed figures (mirror EmployeeLoan model) ─────────────────────────────
$amount   = (float)$loan['amount'];
$rate     = (float)$loan['interest_rate'];
$months   = max(1, (int)$loan['total_months']);
$emi      = (float)$loan['monthly_deduction'];

$totalInterest = $rate > 0 ? round($amount * ($rate / 100) * ($months / 12), 2) : 0.0;
$totalDue      = round($amount + $totalInterest, 2);
$returned      = 0.0; foreach ($repayments as $r) $returned += (float)$r['amount_paid'];
$returned      = round($returned, 2);
$pending       = max(0.0, round($totalDue - $returned, 2));
$interestRatio = ($totalDue > 0 && $totalInterest > 0) ? $totalInterest / $totalDue : 0.0;
$interestPaid  = $totalInterest > 0 ? min($totalInterest, round($returned * $interestRatio, 2)) : 0.0;
$principalPaid = max(0.0, round($returned - $interestPaid, 2));
$progressPct   = $totalDue > 0 ? min(100.0, round(($returned / $totalDue) * 100, 1)) : 0.0;
$paidMonths    = (int)$loan['paid_months'];
$remaining     = max(0, $months - $paidMonths);
$isSettled     = in_array($loan['status'], ['closed', 'completed'], true);
$canRepay      = $canManage && $loan['status'] === 'active' && $pending > 0.01;
$statusBadge   = ['active' => 'success', 'completed' => 'primary'][$loan['status']] ?? 'secondary';

$page_title = 'Loan / Advance Details';
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
                    <?php if ($canManage): ?>
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
                    <tr><th class="text-muted">Paid Months</th><td><?= $paidMonths ?></td></tr>
                    <tr><th class="text-muted">Remaining</th><td><?= $remaining ?> month(s)</td></tr>
                    <tr><th class="text-muted">Monthly EMI</th><td><?= money($emi) ?></td></tr>
                    <tr><th class="text-muted">Status</th><td><span class="badge bg-<?= $statusBadge ?>"><?= ucfirst($loan['status']) ?></span></td></tr>
                </table>

                <hr>

                <!-- Interest breakdown -->
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><th class="text-muted">Principal</th><td class="text-end fw-semibold"><?= money($amount) ?></td></tr>
                        <?php if ($rate > 0): ?>
                        <tr>
                            <th class="text-muted">Interest Rate
                                <span class="text-muted fw-normal" style="font-size:.78rem">(<?= h($rate) ?>% p.a. × <?= $months ?> mo)</span>
                            </th>
                            <td class="text-end text-warning fw-semibold">+ <?= money($totalInterest) ?></td>
                        </tr>
                        <tr class="border-top"><th>Total Due</th><td class="text-end fw-bold text-primary"><?= money($totalDue) ?></td></tr>
                        <?php else: ?>
                        <tr><th class="text-muted">Interest</th><td class="text-end text-muted">Nil</td></tr>
                        <tr class="border-top"><th>Total Due</th><td class="text-end fw-bold text-primary"><?= money($totalDue) ?></td></tr>
                        <?php endif; ?>
                    </table>
                </div>

                <!-- Repayment progress -->
                <div class="row text-center g-2 mb-2">
                    <div class="col-4"><div class="text-muted" style="font-size:.72rem">Total Due</div><div class="fw-bold text-primary" style="font-size:.9rem"><?= money($totalDue) ?></div></div>
                    <div class="col-4"><div class="text-muted" style="font-size:.72rem">Returned</div><div class="fw-bold text-success" style="font-size:.9rem"><?= money($returned) ?></div></div>
                    <div class="col-4"><div class="text-muted" style="font-size:.72rem">Pending</div><div class="fw-bold text-danger" style="font-size:.9rem"><?= money($pending) ?></div></div>
                </div>

                <?php if ($rate > 0 && $returned > 0): ?>
                <div class="small text-muted d-flex justify-content-between mb-1">
                    <span>Principal paid: <strong><?= money($principalPaid) ?></strong></span>
                    <span>Interest paid: <strong><?= money($interestPaid) ?></strong></span>
                </div>
                <?php endif; ?>

                <div class="progress mb-1" style="height:10px">
                    <div class="progress-bar bg-success" style="width:<?= min(100, $progressPct) ?>%" title="<?= $progressPct ?>% repaid"></div>
                </div>
                <div class="d-flex justify-content-between small text-muted">
                    <span><?= $progressPct ?>% repaid</span>
                    <span><?= money($pending) ?> left</span>
                </div>

                <?php if ($canRepay): ?>
                <div class="mt-3">
                    <button class="btn btn-success btn-sm w-100" data-bs-toggle="modal" data-bs-target="#repayModal"><i class="fa fa-plus me-1"></i>Add Repayment</button>
                </div>
                <?php elseif ($isSettled): ?>
                <div class="alert alert-success py-2 mt-3 text-center small mb-0"><i class="fa fa-check-circle me-1"></i>Loan fully settled</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Repayment history ───────────────────────────────────────────── -->
    <div class="col-md-8">
        <div class="card page-card">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-semibold"><i class="fa fa-history me-1 text-primary"></i>Repayment History</h6>
                <?php if ($canRepay): ?>
                <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#repayModal"><i class="fa fa-plus me-1"></i>Add Repayment</button>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!$repayments): ?>
                <div class="text-center text-muted py-5"><i class="fa fa-inbox fa-2x mb-2 d-block"></i>No repayments recorded yet.</div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover table-bordered align-middle">
                        <thead class="table-dark">
                            <tr>
                                <th>#</th><th>Date</th><th class="text-end">Amount Paid</th>
                                <?php if ($rate > 0): ?>
                                <th class="text-end">Interest&nbsp;Portion</th>
                                <th class="text-end">Principal&nbsp;Portion</th>
                                <?php endif; ?>
                                <th class="text-end">Balance After</th><th>Source</th><th>Note</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $runningBalance = $totalDue; $seq = 0; foreach ($repayments as $rep):
                                $seq++;
                                $paid     = (float)$rep['amount_paid'];
                                $intPart  = round($paid * $interestRatio, 2);
                                $prinPart = round($paid - $intPart, 2);
                                $runningBalance = max(0, round($runningBalance - $paid, 2));
                            ?>
                            <tr>
                                <td class="text-muted"><?= $seq ?></td>
                                <td><?= date('d M Y', strtotime($rep['payment_date'])) ?></td>
                                <td class="text-end fw-semibold text-success"><?= money($paid) ?></td>
                                <?php if ($rate > 0): ?>
                                <td class="text-end text-warning"><?= money($intPart) ?></td>
                                <td class="text-end text-info"><?= money($prinPart) ?></td>
                                <?php endif; ?>
                                <td class="text-end fw-semibold <?= $runningBalance > 0 ? 'text-danger' : 'text-success' ?>"><?= money($runningBalance) ?></td>
                                <td>
                                    <?php if (!empty($rep['salary_slip_id'])): ?>
                                    <span class="badge bg-primary">Salary Slip</span>
                                    <?php else: ?>
                                    <span class="badge bg-secondary">Manual</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted small"><?= h($rep['note'] ?? '') ?: '—' ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="2">Total Repaid</th>
                                <th class="text-end text-success"><?= money($returned) ?></th>
                                <?php if ($rate > 0): ?>
                                <th class="text-end text-warning"><?= money($interestPaid) ?></th>
                                <th class="text-end text-info"><?= money($principalPaid) ?></th>
                                <?php endif; ?>
                                <th class="text-end <?= $pending > 0 ? 'text-danger' : 'text-success' ?> fw-bold"><?= money($pending) ?></th>
                                <th colspan="2"></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="d-flex gap-3 flex-wrap mt-3 small">
                    <span class="badge bg-light text-dark border py-2 px-3">Total Due: <strong><?= money($totalDue) ?></strong></span>
                    <span class="badge bg-success py-2 px-3">Returned: <?= money($returned) ?></span>
                    <span class="badge bg-<?= $pending > 0 ? 'danger' : 'success' ?> py-2 px-3"><?= $pending > 0 ? 'Pending: ' . money($pending) : '✓ Fully Paid' ?></span>
                    <?php if ($rate > 0): ?>
                    <span class="badge bg-warning text-dark py-2 px-3">Interest paid: <?= money($interestPaid) ?> / <?= money($totalInterest) ?></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($canRepay): $suggested = min($emi, $pending); ?>
<!-- ── Add Repayment modal ─────────────────────────────────────────────── -->
<div class="modal fade" id="repayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form action="<?= BASE_URL ?>/modules/loans/repay.php" method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="loan_id" value="<?= (int)$loan['id'] ?>">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fa fa-rupee-sign me-1"></i>Add Repayment</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info py-2 mb-3 small">
                        <div class="d-flex justify-content-between"><span>Total Due</span><strong><?= money($totalDue) ?></strong></div>
                        <div class="d-flex justify-content-between"><span>Already Paid</span><strong class="text-success"><?= money($returned) ?></strong></div>
                        <div class="d-flex justify-content-between border-top mt-1 pt-1"><span><strong>Pending Balance</strong></span><strong class="text-danger"><?= money($pending) ?></strong></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Amount Paid <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><?= PAYROLL_CURRENCY_SYMBOL ?></span>
                            <input type="number" name="amount_paid" step="0.01" min="0.01" max="<?= $pending ?>" class="form-control" value="<?= h(number_format($suggested, 2, '.', '')) ?>" required>
                        </div>
                        <div class="form-text text-muted">Max: <?= money($pending) ?> (pending balance)</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Payment Date <span class="text-danger">*</span></label>
                        <input type="date" name="payment_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Note</label>
                        <input type="text" name="note" class="form-control" placeholder="e.g. Monthly deduction <?= date('F Y') ?>">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success btn-sm"><i class="fa fa-save me-1"></i>Save Repayment</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
