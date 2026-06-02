<?php
$page_title = 'Assign Benefit';
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
    if (!$e) $emp_id = 0; // invalid id — fall through to dropdown
}
if (!$emp_id) {
    $employees = $db->query('SELECT id, name, employee_id AS emp_code FROM employees ORDER BY name')->fetchAll();
}

$backUrl = $emp_id
    ? BASE_URL . '/modules/employee/view.php?id=' . $emp_id . '#benefits'
    : BASE_URL . '/modules/benefits/index.php';

$old = $_SESSION['form_old'] ?? [];
unset($_SESSION['form_old']);
?>

<div class="row justify-content-center">
<div class="col-xl-7 col-lg-9">
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2">
        <a href="<?= $backUrl ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left"></i></a>
        <div>
            <h5 class="mb-0 fw-semibold">Assign Benefit Fund</h5>
            <?php if ($e): ?>
            <small class="text-muted"><?= h($e['name']) ?> &bull; <?= h($e['desig_name'] ?? '') ?></small>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">

        <?php if (!empty($_SESSION['errors'])): ?>
        <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($_SESSION['errors'] as $err): ?><li><?= h($err) ?></li><?php endforeach; ?></ul></div>
        <?php unset($_SESSION['errors']); endif; ?>

        <form method="POST" action="<?= BASE_URL ?>/modules/benefits/save.php">
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
                    <label class="form-label">Fund Type <span class="text-danger">*</span></label>
                    <input type="text" name="fund_type" class="form-control" value="<?= h($old['fund_type'] ?? '') ?>" placeholder="e.g. Provident Fund, Gratuity" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Amount <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><?= PAYROLL_CURRENCY_SYMBOL ?></span>
                        <input type="number" name="amount" class="form-control" step="0.01" min="0" value="<?= h($old['amount'] ?? '') ?>" placeholder="0.00" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Effective Month <span class="text-danger">*</span></label>
                    <input type="month" name="effective_month" class="form-control" value="<?= h($old['effective_month'] ?? date('Y-m')) ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="active"   <?= ($old['status'] ?? 'active') === 'active'   ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= ($old['status'] ?? '')        === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Notes / Description</label>
                    <textarea name="description" class="form-control" rows="2" placeholder="Optional notes…"><?= h($old['description'] ?? '') ?></textarea>
                </div>

                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Save Benefit</button>
                    <a href="<?= $backUrl ?>" class="btn btn-light">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>
</div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
