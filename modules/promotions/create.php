<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('promotions', 'create');

$db     = db();
$emp_id = (int)($_GET['emp_id'] ?? 0);
$id     = (int)($_GET['id'] ?? 0);

// Single creation workflow: new promotions are created ONLY through the
// promotion LETTER flow (Employees → Promotion → New Letter). This page now
// edits an existing promotion record; a create request redirects to the letter
// form so there is no duplicate creation path.
if (!$id) {
    redirect(BASE_URL . '/modules/letters/create.php?type=Promotion' . ($emp_id ? '&emp_id=' . $emp_id : ''));
}

$page_title = 'Edit Promotion';
require_once __DIR__ . '/../../includes/header.php';

// Edit mode: ?id=<record> loads an existing promotion and locks its employee.
$rec = null;
if ($id) {
    $rs = $db->prepare('SELECT * FROM employee_promotions WHERE id = ?');
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
    $employees = $db->query('SELECT id, name, employee_id AS emp_code, designation_id, department_id FROM employees ORDER BY name')->fetchAll();
}

$designations = $db->query('SELECT id, name FROM designations ORDER BY name')->fetchAll();
$departments  = $db->query('SELECT id, name FROM departments ORDER BY name')->fetchAll();

$backUrl = $fromEmp
    ? BASE_URL . '/modules/employee/view.php?id=' . $emp_id . '#promotions'
    : BASE_URL . '/modules/promotions/index.php';

$old = $_SESSION['form_old'] ?? ($rec ?: []);
unset($_SESSION['form_old']);

$heading = $isEdit ? 'Edit Promotion' : 'Add Promotion';
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

        <form method="POST" action="<?= BASE_URL ?>/modules/promotions/save.php">
            <?= csrf_field() ?>
            <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
            <?php if ($fromEmp): ?><input type="hidden" name="return" value="profile"><?php endif; ?>
            <div class="row g-3">

                <?php if ($emp_id): ?>
                <input type="hidden" name="emp_id" value="<?= $emp_id ?>">
                <?php else: ?>
                <div class="col-md-6">
                    <label class="form-label">Employee <span class="text-danger">*</span></label>
                    <select name="emp_id" id="empSelect" class="form-select" required>
                        <option value="">Select Employee</option>
                        <?php foreach ($employees as $emp): ?>
                        <option value="<?= $emp['id'] ?>"
                                data-designation="<?= (int)($emp['designation_id'] ?? 0) ?>"
                                data-department="<?= (int)($emp['department_id'] ?? 0) ?>"
                                <?= ($old['emp_id'] ?? '') == $emp['id'] ? 'selected' : '' ?>>
                            <?= h($emp['name']) ?> (<?= h($emp['emp_code']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="col-md-6">
                    <label class="form-label">Effective Date <span class="text-danger">*</span></label>
                    <input type="date" name="effective_date" class="form-control" value="<?= h($old['effective_date'] ?? date('Y-m-d')) ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Previous Designation</label>
                    <select name="previous_designation_id" id="prevDesig" class="form-select">
                        <option value="">— <?= $e ? 'Current: ' . h($e['desig_name'] ?? 'N/A') : 'Select' ?> —</option>
                        <?php foreach ($designations as $d): ?>
                        <option value="<?= $d['id'] ?>"
                            <?= ($old['previous_designation_id'] ?? ($e['designation_id'] ?? '')) == $d['id'] ? 'selected' : '' ?>>
                            <?= h($d['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">New Designation <span class="text-danger">*</span></label>
                    <select name="new_designation_id" class="form-select" required>
                        <option value="">Select designation</option>
                        <?php foreach ($designations as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= ($old['new_designation_id'] ?? '') == $d['id'] ? 'selected' : '' ?>>
                            <?= h($d['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Department (if changed)</label>
                    <select name="department_id" id="deptSelect" class="form-select">
                        <option value="">Same / No Change</option>
                        <?php foreach ($departments as $dept): ?>
                        <option value="<?= $dept['id'] ?>"
                            <?= ($old['department_id'] ?? ($e['department_id'] ?? '')) == $dept['id'] ? 'selected' : '' ?>>
                            <?= h($dept['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Remarks</label>
                    <textarea name="remarks" class="form-control" rows="2" placeholder="Optional remarks…"><?= h($old['remarks'] ?? '') ?></textarea>
                </div>

                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Save Promotion</button>
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
    // When an employee is picked, pre-fill Previous Designation + Department
    // with their current designation/department (admin can still override).
    $('#empSelect').on('change', function () {
        var desig = $(this).find(':selected').data('designation');
        var dept  = $(this).find(':selected').data('department');
        if (desig) $('#prevDesig').val(desig);
        if (dept)  $('#deptSelect').val(dept);
    });
});
</script>
JS;
include __DIR__ . '/../../includes/footer.php'; ?>
