<?php
$page_title = 'Add Loan / Advance';
require_once __DIR__ . '/../../includes/header.php';
require_permission('loans', 'create');

$db     = db();
$emp_id = (int)($_GET['emp_id'] ?? 0);

// Edit mode: ?id=<record> loads an existing loan and locks it to its employee.
$id  = (int)($_GET['id'] ?? 0);
$rec = null;
if ($id) {
    $rs = $db->prepare('SELECT * FROM employee_loans WHERE id = ?');
    $rs->execute([$id]);
    $rec = $rs->fetch();
    if ($rec) { $emp_id = (int)$rec['employee_id']; } else { $id = 0; }
}
$isEdit  = $id > 0;
$fromEmp = !$isEdit && $emp_id > 0;   // launched from an employee profile

$e         = null;
$employees = [];

if ($emp_id) {
    $st = $db->prepare('SELECT e.*, d.name AS dept_name, des.name AS desig_name FROM employees e LEFT JOIN departments d ON d.id=e.department_id LEFT JOIN designations des ON des.id=e.designation_id WHERE e.id=?');
    $st->execute([$emp_id]);
    $e = $st->fetch();
    if (!$e) $emp_id = 0;
}
if (!$emp_id) {
    $employees = $db->query('SELECT id, name, employee_id AS emp_code FROM employees ORDER BY name')->fetchAll();
}

$backUrl = $fromEmp
    ? BASE_URL . '/modules/employee/view.php?id=' . $emp_id . '#loans'
    : BASE_URL . '/modules/loans/index.php';

// Prefill: prior failed-submit values win, else the record being edited.
$old = $_SESSION['form_old'] ?? ($rec ?: []);
unset($_SESSION['form_old']);

$heading = $isEdit ? 'Edit Loan / Advance' : 'Add Loan / Advance';
?>

<div class="row justify-content-center">
<div class="col-xl-7 col-lg-9">
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2">
        <a href="<?= $backUrl ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left"></i></a>
        <div>
            <h5 class="mb-0 fw-semibold"><?= $heading ?></h5>
            <?php if ($e): ?>
            <small class="text-muted"><?= h($e['name']) ?> &bull; <?= h($e['desig_name'] ?? '') ?></small>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">

        <?php if (!empty($_SESSION['errors'])): ?>
        <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($_SESSION['errors'] as $err): ?><li><?= h($err) ?></li><?php endforeach; ?></ul></div>
        <?php unset($_SESSION['errors']); endif; ?>

        <form method="POST" action="<?= BASE_URL ?>/modules/loans/save.php">
            <?= csrf_field() ?>
            <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
            <?php if ($fromEmp): ?><input type="hidden" name="return" value="profile"><?php endif; ?>
            <div class="row g-3">

                <?php if ($emp_id): ?>
                <input type="hidden" name="emp_id" value="<?= $emp_id ?>">
                <div class="col-md-8">
                    <label class="form-label">Employee <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" value="<?= h(($e['name'] ?? '') . (isset($e['employee_id']) ? ' (' . $e['employee_id'] . ')' : '')) ?>" readonly>
                </div>
                <?php else: ?>
                <div class="col-md-8">
                    <label class="form-label">Employee <span class="text-danger">*</span></label>
                    <select name="emp_id" class="form-select" required>
                        <option value="">— Select Employee —</option>
                        <?php foreach ($employees as $emp): ?>
                        <option value="<?= $emp['id'] ?>" <?= ($old['emp_id'] ?? '') == $emp['id'] ? 'selected' : '' ?>>
                            <?= h($emp['name']) ?> (<?= h($emp['emp_code']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="col-md-4">
                    <label class="form-label">Type <span class="text-danger">*</span></label>
                    <select name="type" class="form-select" required>
                        <option value="loan"    <?= ($old['type'] ?? 'loan') === 'loan'    ? 'selected' : '' ?>>Loan</option>
                        <option value="advance" <?= ($old['type'] ?? '')     === 'advance' ? 'selected' : '' ?>>Advance</option>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Date Given <span class="text-danger">*</span></label>
                    <input type="date" name="date_given" class="form-control" value="<?= h($old['date_given'] ?? date('Y-m-d')) ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Principal Amount <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><?= PAYROLL_CURRENCY_SYMBOL ?></span>
                        <input type="number" name="amount" id="inpAmount" class="form-control" step="0.01" min="1" value="<?= h($old['amount'] ?? '') ?>" placeholder="0.00" required>
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Interest Rate (% per year)</label>
                    <div class="input-group">
                        <input type="number" name="interest_rate" id="inpRate" class="form-control" step="0.01" min="0" max="100" value="<?= h($old['interest_rate'] ?? '0') ?>">
                        <span class="input-group-text">%</span>
                    </div>
                    <div class="form-text text-muted">Enter 0 for no interest (advance).</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Total Months <span class="text-danger">*</span></label>
                    <input type="number" name="total_months" id="inpMonths" class="form-control" min="1" value="<?= h($old['total_months'] ?? '') ?>" required>
                </div>

                <!-- Auto-calculated summary -->
                <div class="col-12">
                    <div id="loanSummary" class="alert alert-info py-2 small d-none">
                        <div class="row g-1">
                            <div class="col-auto">Principal: <strong id="sumPrincipal">—</strong></div>
                            <div class="col-auto">+</div>
                            <div class="col-auto">Interest: <strong id="sumInterest" class="text-warning">—</strong></div>
                            <div class="col-auto">=</div>
                            <div class="col-auto">Total Due: <strong id="sumTotal" class="text-primary">—</strong></div>
                            <div class="col-auto ms-3 border-start ps-3">Suggested EMI: <strong id="sumEmi" class="text-success">—</strong></div>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Monthly Deduction (EMI) <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><?= PAYROLL_CURRENCY_SYMBOL ?></span>
                        <input type="number" name="monthly_deduction" id="inpEmi" class="form-control" step="0.01" min="0.01" value="<?= h($old['monthly_deduction'] ?? '') ?>" placeholder="0.00" required>
                    </div>
                    <div class="form-text text-muted"><i class="fa fa-magic me-1 text-success"></i>Auto-calculated from principal, rate &amp; tenure</div>
                </div>
                <?php if ($isEdit): ?>
                <div class="col-md-4">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="active"    <?= ($old['status'] ?? 'active') === 'active'    ? 'selected' : '' ?>>Active</option>
                        <option value="closed"    <?= ($old['status'] ?? '')       === 'closed'    ? 'selected' : '' ?>>Closed</option>
                        <option value="completed" <?= ($old['status'] ?? '')       === 'completed' ? 'selected' : '' ?>>Completed</option>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-12">
                    <label class="form-label">Remarks</label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="Optional remarks…"><?= h($old['notes'] ?? '') ?></textarea>
                </div>

                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Save Loan / Advance</button>
                    <a href="<?= $backUrl ?>" class="btn btn-light">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<?php $page_scripts = <<<'JS'
<script>
$(function () {
    function calcLoan(forceFill) {
        var principal = parseFloat($('#inpAmount').val()) || 0;
        var rate      = parseFloat($('#inpRate').val())   || 0;
        var months    = parseInt($('#inpMonths').val())   || 0;

        if (principal <= 0 || months <= 0) { $('#loanSummary').addClass('d-none'); return; }

        // Simple interest: P × R × (T / 12)
        var interest = principal * (rate / 100) * (months / 12);
        var total    = principal + interest;
        var emi       = months > 0 ? total / months : 0;

        $('#sumPrincipal').text('₹' + principal.toLocaleString('en-IN', {minimumFractionDigits:2}));
        $('#sumInterest').text('₹' + interest.toLocaleString('en-IN', {minimumFractionDigits:2}));
        $('#sumTotal').text('₹' + total.toLocaleString('en-IN', {minimumFractionDigits:2}));
        $('#sumEmi').text('₹' + emi.toFixed(2));
        $('#loanSummary').removeClass('d-none');

        // Auto-fill EMI on user input; on load only when empty (preserve a saved/overridden EMI).
        if (forceFill || !$('#inpEmi').val()) $('#inpEmi').val(emi.toFixed(2));
    }
    $('#inpAmount, #inpRate, #inpMonths').on('input', function () { calcLoan(true); });
    calcLoan(false);
});
</script>
JS;
include __DIR__ . '/../../includes/footer.php'; ?>
