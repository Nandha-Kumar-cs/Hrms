<?php
$page_title = 'Add Increment';
require_once __DIR__ . '/../../includes/header.php';
require_permission('increments', 'create');

$db     = db();
$emp_id = (int)($_GET['emp_id'] ?? 0);

// Edit mode: ?id=<record> loads an existing increment and locks its employee.
$id  = (int)($_GET['id'] ?? 0);
$rec = null;
if ($id) {
    $rs = $db->prepare('SELECT * FROM employee_increments WHERE id = ?');
    $rs->execute([$id]);
    $rec = $rs->fetch();
    if ($rec) { $emp_id = (int)$rec['employee_id']; } else { $id = 0; }
}
$isEdit  = $id > 0;
$fromEmp = !$isEdit && $emp_id > 0;

$e         = null;
$employees = [];
$currentSalary = 0;

if ($emp_id) {
    $st = $db->prepare('SELECT e.*, d.name AS dept_name, des.name AS desig_name FROM employees e LEFT JOIN departments d ON d.id=e.department_id LEFT JOIN designations des ON des.id=e.designation_id WHERE e.id=?');
    $st->execute([$emp_id]);
    $e = $st->fetch();
    if (!$e) $emp_id = 0;
}
if ($emp_id) {
    $sal = $db->prepare('SELECT gross FROM salary_structures WHERE employee_id=? AND is_current=1 ORDER BY effective_from DESC LIMIT 1');
    $sal->execute([$emp_id]);
    $currentSalary = (float)($sal->fetchColumn() ?: 0);
}
if (!$emp_id) {
    $employees = $db->query('SELECT id, name, employee_id AS emp_code, fixed_salary FROM employees ORDER BY name')->fetchAll();
}

$backUrl = $fromEmp
    ? BASE_URL . '/modules/employee/view.php?id=' . $emp_id . '#increments'
    : BASE_URL . '/modules/increments/index.php';

$old = $_SESSION['form_old'] ?? ($rec ?: []);
unset($_SESSION['form_old']);

$heading = $isEdit ? 'Edit Increment' : 'Add Increment';
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

        <form method="POST" action="<?= BASE_URL ?>/modules/increments/save.php">
            <?= csrf_field() ?>
            <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
            <?php if ($fromEmp): ?><input type="hidden" name="return" value="profile"><?php endif; ?>
            <div class="row g-3">

                <?php if ($emp_id): ?>
                <input type="hidden" name="emp_id" value="<?= $emp_id ?>">
                <div class="col-md-6">
                    <label class="form-label">Employee <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" value="<?= h(($e['name'] ?? '') . (isset($e['employee_id']) ? ' (' . $e['employee_id'] . ')' : '')) ?>" readonly>
                </div>
                <?php else: ?>
                <div class="col-md-6">
                    <label class="form-label">Employee <span class="text-danger">*</span></label>
                    <select name="emp_id" id="empSelect" class="form-select" required>
                        <option value="">Select Employee</option>
                        <?php foreach ($employees as $emp): ?>
                        <option value="<?= $emp['id'] ?>"
                                data-salary="<?= h(number_format((float)($emp['fixed_salary'] ?? 0), 2, '.', '')) ?>"
                                <?= ($old['emp_id'] ?? '') == $emp['id'] ? 'selected' : '' ?>>
                            <?= h($emp['name']) ?> (<?= h($emp['emp_code']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <div class="col-md-3">
                    <label class="form-label">Previous Salary <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><?= PAYROLL_CURRENCY_SYMBOL ?></span>
                        <input type="number" name="previous_salary" id="prevSalary" class="form-control" step="0.01" min="0"
                               value="<?= h($old['previous_salary'] ?? ($e['fixed_salary'] ?? '')) ?>" required>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">New Salary <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text"><?= PAYROLL_CURRENCY_SYMBOL ?></span>
                        <input type="number" name="new_salary" id="newSalary" class="form-control" step="0.01" min="0"
                               value="<?= h($old['new_salary'] ?? '') ?>" required>
                    </div>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Effective Date <span class="text-danger">*</span></label>
                    <input type="date" name="effective_date" id="effectiveDate" class="form-control" value="<?= h($old['effective_date'] ?? date('Y-m-d')) ?>" required>
                    <div id="futureDateWarning" class="form-text text-warning d-none">
                        <i class="fa fa-clock me-1"></i>Future date — salary will NOT change today. It applies from this date during payroll generation.
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Increment Preview</label>
                    <div class="form-control bg-light" id="incrPreview" style="color:#16a34a;font-weight:600">—</div>
                </div>
                <div class="col-12">
                    <label class="form-label">Remarks</label>
                    <textarea name="remarks" class="form-control" rows="2" placeholder="Optional remarks…"><?= h($old['remarks'] ?? '') ?></textarea>
                </div>

                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Save &amp; Update Salary</button>
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
    // Auto-fill Previous Salary from the selected employee's current CTC.
    $('#empSelect').on('change', function () {
        var salary = $(this).find(':selected').data('salary');
        if (salary !== undefined && salary !== '') {
            $('#prevSalary').val(parseFloat(salary).toFixed(2));
            updatePreview();
        }
    });

    function updatePreview() {
        var prev = parseFloat($('#prevSalary').val()) || 0;
        var next = parseFloat($('#newSalary').val())  || 0;
        if (prev > 0 && next > 0) {
            var diff = next - prev;
            var pct  = ((diff / prev) * 100).toFixed(2);
            $('#incrPreview').text((diff >= 0 ? '+' : '') + '₹' + diff.toLocaleString('en-IN') + ' (' + pct + '%)')
                             .css('color', diff >= 0 ? '#16a34a' : '#dc2626');
        } else {
            $('#incrPreview').text('—').css('color', '#16a34a');
        }
    }

    function checkFutureDate() {
        var val = $('#effectiveDate').val();
        if (!val) return;
        var today = new Date(); today.setHours(0, 0, 0, 0);
        var picked = new Date(val);
        $('#futureDateWarning').toggleClass('d-none', !(picked > today));
    }

    $('#prevSalary, #newSalary').on('input', updatePreview);
    $('#effectiveDate').on('change', checkFutureDate);
    updatePreview();
    checkFutureDate();
});
</script>
JS;
include __DIR__ . '/../../includes/footer.php'; ?>
