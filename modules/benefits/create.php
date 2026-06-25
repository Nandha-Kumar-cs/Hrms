<?php
$page_title = 'Assign Benefit';
require_once __DIR__ . '/../../includes/header.php';
require_permission('benefits', 'create');

$db     = db();
$emp_id = (int)($_GET['emp_id'] ?? 0);

// Edit mode: ?id=<record> loads an existing benefit and locks its employee.
$id  = (int)($_GET['id'] ?? 0);
$rec = null;
if ($id) {
    $rs = $db->prepare('SELECT * FROM employee_benefits WHERE id = ?');
    $rs->execute([$id]);
    $rec = $rs->fetch();
    if ($rec) {
        $emp_id = (int)$rec['employee_id'];
        // DATE (YYYY-MM-01) → month input value (YYYY-MM)
        $rec['effective_month'] = substr((string)$rec['effective_month'], 0, 7);
    } else { $id = 0; }
}
$isEdit  = $id > 0;
$fromEmp = !$isEdit && $emp_id > 0;

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

// Benefit fund types for the dropdown.
$fundTypes = $db->query("SELECT id, name FROM benefit_fund_types WHERE status = 'active' ORDER BY name")->fetchAll();

$frequencies = [
    'weekly'      => 'Weekly',
    'fortnightly' => 'Fortnightly (Every 2 weeks)',
    'monthly'     => 'Monthly',
    'quarterly'   => 'Quarterly (Every 3 months)',
    'half_yearly' => 'Half-Yearly (Every 6 months)',
    'annual'      => 'Annual (Yearly)',
];

$backUrl = $fromEmp
    ? BASE_URL . '/modules/employee/view.php?id=' . $emp_id . '#benefits'
    : BASE_URL . '/modules/benefits/index.php';

$old = $_SESSION['form_old'] ?? ($rec ?: []);
unset($_SESSION['form_old']);

$heading = $isEdit ? 'Edit Benefit Fund' : 'Assign Benefit Fund';
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

        <div class="alert alert-info small mb-3">
            <i class="fa fa-info-circle me-1"></i>
            <strong>Recurring Benefits:</strong> Enter a start date and frequency to create a recurring benefit (applies automatically to applicable months).
            <strong>Legacy Mode:</strong> Leave start date empty and set effective month for a one-time benefit (monthly assignments).
        </div>

        <form method="POST" action="<?= BASE_URL ?>/modules/benefits/save.php">
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
                    <label class="form-label fw-semibold">Benefit Fund Type <span class="text-danger">*</span></label>
                    <select name="benefit_fund_type_id" class="form-select" required>
                        <option value="">Select Fund Type</option>
                        <?php foreach ($fundTypes as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= ($old['benefit_fund_type_id'] ?? '') == $t['id'] ? 'selected' : '' ?>>
                            <?= h($t['name']) ?>
                        </option>
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
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Payment Mode <span class="text-danger">*</span></label>
                    <select name="payment_mode" class="form-select" required>
                        <option value="cash"     <?= ($old['payment_mode'] ?? 'cash') === 'cash'     ? 'selected' : '' ?>>Cash</option>
                        <option value="cashless" <?= ($old['payment_mode'] ?? '')     === 'cashless' ? 'selected' : '' ?>>Cashless</option>
                    </select>
                    <div class="form-text">How is this benefit paid out?</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Benefit Name (Optional)</label>
                    <input type="text" name="benefit_name" class="form-control" maxlength="255" value="<?= h($old['benefit_name'] ?? '') ?>" placeholder="e.g. 'Education Fund 2026'">
                    <div class="form-text">Custom name for this benefit. If empty, uses fund type name.</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Status <span class="text-danger">*</span></label>
                    <select name="status" class="form-select" required>
                        <option value="active"   <?= ($old['status'] ?? 'active') === 'active'   ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= ($old['status'] ?? '')        === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>

                <!-- Recurring Benefit (optional) -->
                <div class="col-12">
                    <div class="card bg-light border-0 mb-1">
                        <div class="card-header bg-transparent fw-semibold text-muted py-2"><i class="fa fa-repeat me-1"></i>Recurring Benefit (Optional)</div>
                        <div class="card-body pt-3">
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">Start Date</label>
                                    <input type="date" name="start_date" class="form-control" value="<?= h($old['start_date'] ?? '') ?>">
                                    <div class="form-text">When does this recurring benefit start?</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">End Date (Optional)</label>
                                    <input type="date" name="end_date" class="form-control" value="<?= h($old['end_date'] ?? '') ?>">
                                    <div class="form-text">Leave empty for indefinite/ongoing benefit.</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Frequency</label>
                                    <select name="frequency" class="form-select">
                                        <option value="">Select Frequency…</option>
                                        <?php foreach ($frequencies as $fk => $fl): ?>
                                        <option value="<?= $fk ?>" <?= ($old['frequency'] ?? '') === $fk ? 'selected' : '' ?>><?= $fl ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">How often does this benefit recur?</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Legacy Mode -->
                <div class="col-12">
                    <div class="card bg-light border-0 mb-1">
                        <div class="card-header bg-transparent fw-semibold text-muted py-2"><i class="fa fa-calendar me-1"></i>Legacy Mode (For backward compatibility)</div>
                        <div class="card-body pt-3">
                            <div class="col-md-4">
                                <label class="form-label">Effective Month</label>
                                <input type="month" name="effective_month" class="form-control" value="<?= h($old['effective_month'] ?? date('Y-m')) ?>">
                                <div class="form-text">Used if no start date is provided.</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12">
                    <label class="form-label fw-semibold">Description / Notes</label>
                    <textarea name="description" class="form-control" rows="2" maxlength="1000" placeholder="Optional notes…"><?= h($old['description'] ?? '') ?></textarea>
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
