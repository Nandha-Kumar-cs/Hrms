<?php
$page_title = 'Add Employee';
require_once __DIR__ . '/../../includes/header.php';
require_permission('employee', 'create');

$db = db();

// ── Dropdown data (IDs as values — the FK fix) ───────────────────────────────
$depts = $db->query(
    'SELECT id, name FROM departments ORDER BY name'
)->fetchAll();

$designs = $db->query(
    'SELECT id, name FROM designations ORDER BY name'
)->fetchAll();

$managers = $db->query(
    "SELECT id, name, employee_id FROM employees
     WHERE status = 'Active' ORDER BY name"
)->fetchAll();

// ── Repopulate on validation failure ─────────────────────────────────────────
$old = $_SESSION['form_old'] ?? [];
unset($_SESSION['form_old']);

// Helper: old value with fallback
$v = fn(string $key, string $default = '') => h($old[$key] ?? $default);
$sel = fn(string $key, $match) => ($old[$key] ?? '') == $match ? 'selected' : '';
?>

<div class="d-flex align-items-center gap-2 mb-4">
    <a href="<?= BASE_URL ?>/modules/employee/index.php"
       class="btn btn-sm btn-outline-secondary">
        <i class="fa fa-arrow-left"></i>
    </a>
    <div>
        <h4 class="mb-0 fw-semibold">Add New Employee</h4>
        <p class="text-muted small mb-0">Fill in the details below to create an employee record</p>
    </div>
</div>

<?php if (!empty($_SESSION['errors'])): ?>
<div class="alert alert-danger alert-dismissible fade show mb-4">
    <i class="fa fa-triangle-exclamation me-2"></i>
    <strong>Please fix the following errors:</strong>
    <ul class="mb-0 mt-2">
        <?php foreach ($_SESSION['errors'] as $err): ?>
        <li><?= h($err) ?></li>
        <?php endforeach; ?>
    </ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php unset($_SESSION['errors']); ?>
<?php endif; ?>

<form method="POST"
      action="<?= BASE_URL ?>/modules/employee/create.php"
      enctype="multipart/form-data"
      id="addEmployeeForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">

    <!-- ── Personal Information ─────────────────────────────────────────── -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h6 class="mb-0 fw-semibold">
                <i class="fa fa-id-card me-2 text-primary"></i>Personal Information
            </h6>
        </div>
        <div class="card-body">
            <div class="row g-3">

                <div class="col-md-8">
                    <label class="form-label">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control"
                           value="<?= $v('name') ?>"
                           placeholder="e.g. Ravi Kumar" required>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Profile Photo</label>
                    <input type="file" name="photo" class="form-control"
                           accept="image/jpeg,image/png,image/webp"
                           id="photoInput">
                    <div id="photoPreviewWrap" class="mt-2 d-none">
                        <img id="photoPreview"
                             style="width:64px;height:64px;border-radius:50%;object-fit:cover;border:2px solid #e2e8f0">
                    </div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Email Address <span class="text-danger">*</span></label>
                    <input type="email" name="email" class="form-control"
                           value="<?= $v('email') ?>"
                           placeholder="ravi@company.com" required>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Phone</label>
                    <input type="tel" name="phone" class="form-control"
                           value="<?= $v('phone') ?>"
                           placeholder="+91 98765 43210">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Date of Birth</label>
                    <input type="date" name="dob" class="form-control"
                           value="<?= $v('dob') ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Gender</label>
                    <select name="gender" class="form-select">
                        <option value="">— Select —</option>
                        <?php foreach (['Male', 'Female', 'Other', 'Prefer not to say'] as $g): ?>
                        <option value="<?= $g ?>" <?= $sel('gender', $g) ?>><?= $g ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <!-- spacer -->
                </div>

                <div class="col-12">
                    <label class="form-label">Address</label>
                    <textarea name="address" class="form-control" rows="2"
                              placeholder="Street, area…"><?= $v('address') ?></textarea>
                </div>

                <div class="col-md-4">
                    <label class="form-label">City</label>
                    <input type="text" name="city" class="form-control"
                           value="<?= $v('city') ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">State</label>
                    <input type="text" name="state" class="form-control"
                           value="<?= $v('state') ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Pincode</label>
                    <input type="text" name="pincode" class="form-control"
                           maxlength="10" value="<?= $v('pincode') ?>">
                </div>

            </div>
        </div>
    </div>

    <!-- ── Employment Details ────────────────────────────────────────────── -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h6 class="mb-0 fw-semibold">
                <i class="fa fa-briefcase me-2 text-success"></i>Employment Details
            </h6>
        </div>
        <div class="card-body">
            <div class="row g-3">

                <!--
                    FK FIX: value="<?php echo $d['id']; ?>" passes the integer PK,
                    not the name string. This prevents the
                    SQLSTATE[23000] FK constraint error when dept_id=0 or a
                    text string is sent instead of a real departments.id value.
                -->
                <div class="col-md-6">
                    <label class="form-label">Department <span class="text-danger">*</span></label>
                    <select name="department_id" class="form-select" required>
                        <option value="">— Select Department —</option>
                        <?php foreach ($depts as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= $sel('department_id', $d['id']) ?>>
                            <?= h($d['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Must match an existing department in the system.</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Designation</label>
                    <select name="designation_id" class="form-select">
                        <option value="">— Select Designation —</option>
                        <?php foreach ($designs as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= $sel('designation_id', $d['id']) ?>>
                            <?= h($d['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Reporting Manager</label>
                    <select name="manager_id" class="form-select">
                        <option value="">— None —</option>
                        <?php foreach ($managers as $m): ?>
                        <option value="<?= $m['id'] ?>" <?= $sel('manager_id', $m['id']) ?>>
                            <?= h($m['name']) ?> (<?= h($m['employee_id']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Join Date <span class="text-danger">*</span></label>
                    <input type="date" name="join_date" class="form-control"
                           value="<?= $v('join_date') ?>" required>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Employment Type</label>
                    <select name="employment_type" class="form-select">
                        <?php foreach (['Full Time', 'Part Time', 'Contract', 'Intern'] as $t): ?>
                        <option value="<?= $t ?>"
                            <?= ($old['employment_type'] ?? 'Full Time') === $t ? 'selected' : '' ?>>
                            <?= $t ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Emergency Contact Name</label>
                    <input type="text" name="emergency_name" class="form-control"
                           value="<?= $v('emergency_name') ?>"
                           placeholder="Parent / Spouse name">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Emergency Contact Phone</label>
                    <input type="tel" name="emergency_phone" class="form-control"
                           value="<?= $v('emergency_phone') ?>">
                </div>

            </div>
        </div>
    </div>

    <!-- ── Bank & Statutory Details ─────────────────────────────────────── -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h6 class="mb-0 fw-semibold">
                <i class="fa fa-university me-2 text-warning"></i>Bank &amp; Statutory Details
            </h6>
        </div>
        <div class="card-body">
            <div class="row g-3">

                <div class="col-md-4">
                    <label class="form-label">Bank Name</label>
                    <input type="text" name="bank_name" class="form-control"
                           value="<?= $v('bank_name') ?>"
                           placeholder="HDFC Bank">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Account Number</label>
                    <input type="text" name="bank_account" class="form-control"
                           value="<?= $v('bank_account') ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">IFSC Code</label>
                    <input type="text" name="bank_ifsc" class="form-control"
                           value="<?= $v('bank_ifsc') ?>"
                           placeholder="HDFC0001234">
                </div>

                <div class="col-md-4">
                    <label class="form-label">PAN Number</label>
                    <input type="text" name="pan_number" class="form-control"
                           maxlength="10"
                           style="text-transform:uppercase"
                           value="<?= $v('pan_number') ?>"
                           placeholder="ABCDE1234F">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Aadhaar Number</label>
                    <input type="text" name="aadhaar_number" class="form-control"
                           maxlength="12" value="<?= $v('aadhaar_number') ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">UAN Number</label>
                    <input type="text" name="uan_number" class="form-control"
                           value="<?= $v('uan_number') ?>">
                </div>

            </div>
        </div>
    </div>

    <!-- ── Submit ────────────────────────────────────────────────────────── -->
    <div class="d-flex gap-2 mb-4">
        <button type="submit" class="btn btn-primary">
            <i class="fa fa-save me-1"></i> Save Employee
        </button>
        <a href="<?= BASE_URL ?>/modules/employee/index.php" class="btn btn-light">
            Cancel
        </a>
    </div>

</form>

<?php
$page_scripts = <<<'JS'
<script>
// Photo preview
document.getElementById('photoInput').addEventListener('change', function () {
    var file = this.files[0];
    if (file && file.type.startsWith('image/')) {
        var reader = new FileReader();
        reader.onload = function (e) {
            document.getElementById('photoPreview').src = e.target.result;
            document.getElementById('photoPreviewWrap').classList.remove('d-none');
        };
        reader.readAsDataURL(file);
    }
});
</script>
JS;
?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
