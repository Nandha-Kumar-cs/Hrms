<?php
$page_title = 'Add Bonus / Incentive';
require_once __DIR__ . '/../../includes/header.php';
require_permission('bonuses', 'create');

$db     = db();
$emp_id = (int)($_GET['emp_id'] ?? 0);

// Edit mode: ?id=<record> loads an existing bonus and locks its employee.
$id  = (int)($_GET['id'] ?? 0);
$rec = null;
if ($id) {
    $rs = $db->prepare('SELECT * FROM employee_bonuses WHERE id = ?');
    $rs->execute([$id]);
    $rec = $rs->fetch();
    if ($rec) { $emp_id = (int)$rec['employee_id']; } else { $id = 0; }
}
$isEdit  = $id > 0;
$fromEmp = !$isEdit && $emp_id > 0;

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

// Bonus types (mirrors EmployeeBonus::TYPES in the reference project).
$bonusTypes = [
    'monthly_bonus' => 'Monthly Bonus',
    'performance'   => 'Performance Incentive',
    'festival'      => 'Festival Bonus',
    'overtime'      => 'Overtime Incentive',
    'one_time'      => 'One-time Reward',
];

$backUrl = $fromEmp
    ? BASE_URL . '/modules/employee/view.php?id=' . $emp_id . '#bonuses'
    : BASE_URL . '/modules/bonuses/index.php';

$old = $_SESSION['form_old'] ?? ($rec ?: []);
unset($_SESSION['form_old']);

$heading = $isEdit ? 'Edit Bonus / Incentive' : 'Add Bonus / Incentive';
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

        <form method="POST" action="<?= BASE_URL ?>/modules/bonuses/save.php">
            <?= csrf_field() ?>
            <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
            <?php if ($fromEmp): ?><input type="hidden" name="return" value="profile"><?php endif; ?>
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
                    <label class="form-label fw-semibold">Type <span class="text-danger">*</span></label>
                    <select name="type" class="form-select" required>
                        <?php foreach ($bonusTypes as $tk => $tl): ?>
                        <option value="<?= $tk ?>" <?= ($old['type'] ?? 'monthly_bonus') === $tk ? 'selected' : '' ?>><?= $tl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Amount (<?= PAYROLL_CURRENCY_SYMBOL ?>) <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><?= PAYROLL_CURRENCY_SYMBOL ?></span>
                        <input type="number" name="amount" class="form-control" step="0.01" min="0" value="<?= h($old['amount'] ?? '') ?>" placeholder="0.00" required>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Payroll Month <span class="text-danger">*</span></label>
                    <select name="payroll_month" class="form-select" required>
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= ($old['payroll_month'] ?? (int)date('n')) == $m ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Payroll Year <span class="text-danger">*</span></label>
                    <select name="payroll_year" class="form-select" required>
                        <?php for ($y = (int)date('Y') + 1; $y >= (int)date('Y') - 4; $y--): ?>
                        <option value="<?= $y ?>" <?= ($old['payroll_year'] ?? (int)date('Y')) == $y ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Reason <span class="text-danger">*</span></label>
                    <input type="text" name="reason" class="form-control" maxlength="255" value="<?= h($old['reason'] ?? '') ?>" required>
                    <div class="form-text">e.g. "Diwali Bonus", "Q4 Performance Excellence", "Project Delivery"</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Status <span class="text-danger">*</span></label>
                    <select name="status" class="form-select" required>
                        <option value="approved" <?= ($old['status'] ?? 'approved') === 'approved' ? 'selected' : '' ?>>Approved (apply to payslip)</option>
                        <option value="pending"  <?= ($old['status'] ?? '')         === 'pending'  ? 'selected' : '' ?>>Pending</option>
                        <option value="rejected" <?= ($old['status'] ?? '')         === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Remarks</label>
                    <textarea name="remarks" class="form-control" rows="2" maxlength="1000" placeholder="Optional remarks…"><?= h($old['remarks'] ?? '') ?></textarea>
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
