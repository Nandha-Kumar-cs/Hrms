<?php
$page_title = 'Add Bonus / Incentive';
require_once __DIR__ . '/../../includes/header.php';
require_permission('employee');

$db     = db();
$emp_id = (int)($_GET['emp_id'] ?? 0);

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

$backUrl = $emp_id
    ? BASE_URL . '/modules/employee/view.php?id=' . $emp_id . '#bonuses'
    : BASE_URL . '/modules/bonuses/index.php';

$old = $_SESSION['form_old'] ?? [];
unset($_SESSION['form_old']);
?>

<div class="row justify-content-center">
<div class="col-xl-7 col-lg-9">
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2">
        <a href="<?= $backUrl ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left"></i></a>
        <div>
            <h5 class="mb-0 fw-semibold">Add Bonus / Incentive</h5>
            <?php if ($e): ?>
            <small class="text-muted"><?= h($e['name']) ?> &bull; <?= h($e['desig_name'] ?? '') ?></small>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">

        <?php if (!empty($_SESSION['errors'])): ?>
        <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($_SESSION['errors'] as $err): ?><li><?= h($err) ?></li><?php endforeach; ?></ul></div>
        <?php unset($_SESSION['errors']); endif; ?>

        <form method="POST" action="<?= BASE_URL ?>/modules/bonuses/save.php">
            <?= csrf_field() ?>
            <div class="row g-3">

                <?php if ($emp_id): ?>
                <input type="hidden" name="emp_id" value="<?= $emp_id ?>">
                <?php else: ?>
                <div class="col-12">
                    <label class="form-label">Employee <span class="text-danger">*</span></label>
                    <select name="emp_id" class="form-select" required>
                        <option value="">Select employee…</option>
                        <?php foreach ($employees as $emp): ?>
                        <option value="<?= $emp['id'] ?>" <?= ($old['emp_id'] ?? '') == $emp['id'] ? 'selected' : '' ?>>
                            <?= h($emp['name']) ?> (<?= h($emp['emp_code']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="col-md-6">
                    <label class="form-label">Type <span class="text-danger">*</span></label>
                    <select name="type" class="form-select" required>
                        <option value="bonus"      <?= ($old['type'] ?? 'bonus') === 'bonus'      ? 'selected' : '' ?>>Bonus</option>
                        <option value="incentive"  <?= ($old['type'] ?? '')       === 'incentive'  ? 'selected' : '' ?>>Incentive</option>
                        <option value="commission" <?= ($old['type'] ?? '')       === 'commission' ? 'selected' : '' ?>>Commission</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Amount <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><?= PAYROLL_CURRENCY_SYMBOL ?></span>
                        <input type="number" name="amount" class="form-control" step="0.01" min="0" value="<?= h($old['amount'] ?? '') ?>" placeholder="0.00" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Payroll Month <span class="text-danger">*</span></label>
                    <select name="payroll_month" class="form-select" required>
                        <?php foreach (['January','February','March','April','May','June','July','August','September','October','November','December'] as $mi => $mn): ?>
                        <option value="<?= $mi+1 ?>" <?= ($old['payroll_month'] ?? (int)date('n')) == $mi+1 ? 'selected' : '' ?>><?= $mn ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Payroll Year <span class="text-danger">*</span></label>
                    <input type="number" name="payroll_year" class="form-control" min="2000" max="2100" value="<?= h($old['payroll_year'] ?? date('Y')) ?>" required>
                </div>
                <div class="col-12">
                    <label class="form-label">Reason <span class="text-danger">*</span></label>
                    <textarea name="reason" class="form-control" rows="2" required placeholder="Reason for this bonus / incentive…"><?= h($old['reason'] ?? '') ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="pending"  <?= ($old['status'] ?? 'pending') === 'pending'  ? 'selected' : '' ?>>Pending</option>
                        <option value="approved" <?= ($old['status'] ?? '')        === 'approved' ? 'selected' : '' ?>>Approved</option>
                        <option value="rejected" <?= ($old['status'] ?? '')        === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                    </select>
                </div>

                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Save Bonus</button>
                    <a href="<?= $backUrl ?>" class="btn btn-light">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
