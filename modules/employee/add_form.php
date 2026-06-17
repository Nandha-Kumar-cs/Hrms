<?php
$page_title = 'Add Employee';
require_once __DIR__ . '/../../includes/header.php';
require_permission('employee', 'create');

$db = db();

// ── Dropdown data ─────────────────────────────────────────────────────────────
$entities = $db->query(
    'SELECT id, name FROM entities ORDER BY name'
)->fetchAll(PDO::FETCH_ASSOC);

$depts = $db->query(
    "SELECT id, name FROM departments ORDER BY name"
)->fetchAll(PDO::FETCH_ASSOC);

$designs = $db->query(
    "SELECT id, name, department_id FROM designations ORDER BY name"
)->fetchAll(PDO::FETCH_ASSOC);

$managers = $db->query(
    "SELECT id, name, employee_id FROM employees
     WHERE status = 'Active' ORDER BY name"
)->fetchAll(PDO::FETCH_ASSOC);

// ── Repopulate on validation failure ─────────────────────────────────────────
$old = $_SESSION['form_old'] ?? [];
unset($_SESSION['form_old']);

$v   = fn(string $key, string $default = '') => h($old[$key] ?? $default);
$sel = fn(string $key, $match) => (string)($old[$key] ?? '') === (string)$match ? 'selected' : '';
?>

<div class="card page-card">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2">
        <a href="<?= BASE_URL ?>/modules/employee/index.php"
           class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-arrow-left"></i>
        </a>
        <h5 class="mb-0 fw-semibold">Employee Details</h5>
    </div>

    <div class="card-body">

        <?php if (!empty($_SESSION['errors'])): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($_SESSION['errors'] as $err): ?>
                <li><?= h($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php unset($_SESSION['errors']); ?>
        <?php endif; ?>

        <form method="POST"
              action="<?= BASE_URL ?>/modules/employee/create.php"
              enctype="multipart/form-data"
              novalidate
              id="addEmployeeForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">

            <!-- ── Personal Information ───────────────────────────────────── -->
            <h6 class="text-primary fw-semibold mb-3 border-bottom pb-2">Personal Information</h6>
            <div class="row g-3 mb-4">

                <div class="col-md-4">
                    <label class="form-label">Employee Code <span class="text-danger">*</span></label>
                    <input type="text" name="employee_code"
                           class="form-control"
                           value="<?= $v('employee_code') ?>"
                           placeholder="e.g. EMP0001"
                           required>
                    <div class="form-text">Enter a unique employee code.</div>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="full_name"
                           class="form-control"
                           value="<?= $v('full_name') ?>"
                           placeholder="e.g. Ravi Kumar"
                           required>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Email <span class="text-danger">*</span></label>
                    <input type="email" name="email"
                           class="form-control"
                           value="<?= $v('email') ?>"
                           placeholder="ravi@company.com"
                           required>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Phone <span class="text-danger">*</span></label>
                    <input type="text" name="phone"
                           class="form-control"
                           value="<?= $v('phone') ?>"
                           placeholder="+91 98765 43210"
                           required>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Date of Birth</label>
                    <input type="date" name="dob"
                           class="form-control"
                           value="<?= $v('dob') ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Gender</label>
                    <select name="gender" class="form-select">
                        <option value="">Select Gender</option>
                        <option value="male"   <?= $sel('gender', 'male') ?>>Male</option>
                        <option value="female" <?= $sel('gender', 'female') ?>>Female</option>
                        <option value="other"  <?= $sel('gender', 'other') ?>>Other</option>
                    </select>
                </div>

            </div>

            <!-- ── Employment Information ─────────────────────────────────── -->
            <h6 class="text-primary fw-semibold mb-3 border-bottom pb-2">Employment Information</h6>
            <div class="row g-3 mb-4">

                <div class="col-md-4">
                    <label class="form-label">Entity (Company)</label>
                    <select name="entity_id" class="form-select">
                        <option value="">Select Entity</option>
                        <?php foreach ($entities as $ent): ?>
                        <option value="<?= $ent['id'] ?>" <?= $sel('entity_id', $ent['id']) ?>>
                            <?= h($ent['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Department</label>
                    <select name="department_id" id="department_id" class="form-select">
                        <option value="">Select Department</option>
                        <?php foreach ($depts as $dept): ?>
                        <option value="<?= $dept['id'] ?>" <?= $sel('department_id', $dept['id']) ?>>
                            <?= h($dept['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Designation</label>
                    <select name="designation_id" id="designation_id" class="form-select">
                        <option value="">Select Designation</option>
                        <?php foreach ($designs as $d): ?>
                        <option value="<?= $d['id'] ?>"
                            data-dept="<?= (int)$d['department_id'] ?>"
                            <?= $sel('designation_id', $d['id']) ?>>
                            <?= h($d['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="active"    <?= $sel('status', 'active') ?>>Active</option>
                        <option value="inactive"  <?= $sel('status', 'inactive') ?>>Inactive</option>
                        <option value="on_leave"  <?= $sel('status', 'on_leave') ?>>On Leave</option>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Joining Date</label>
                    <input type="date" name="joining_date"
                           class="form-control"
                           value="<?= $v('joining_date') ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Probation End Date</label>
                    <input type="date" name="probation_end"
                           class="form-control"
                           value="<?= $v('probation_end') ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Reporting Manager</label>
                    <select name="reporting_manager_id" class="form-select">
                        <option value="">Select Manager</option>
                        <?php foreach ($managers as $mgr): ?>
                        <option value="<?= $mgr['id'] ?>" <?= $sel('reporting_manager_id', $mgr['id']) ?>>
                            <?= h($mgr['name']) ?> (<?= h($mgr['employee_id']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

            </div>

            <!-- ── Photo ──────────────────────────────────────────────────── -->
            <h6 class="text-primary fw-semibold mb-3 border-bottom pb-2">Photo</h6>
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="form-label">Employee Photo</label>
                    <input type="file" name="photo" id="photoInput"
                           accept="image/jpeg,image/png,image/webp"
                           class="form-control"
                           onchange="previewPhoto(this)">
                    <div class="form-text">JPG / PNG / WebP — max 2 MB</div>
                    <div id="photoPreviewWrap" class="mt-2 d-none d-flex align-items-center gap-2">
                        <img id="photoPreview" src="" alt="Preview"
                             class="rounded"
                             style="width:80px;height:80px;object-fit:cover;border:2px solid #e2e8f0">
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="removePhoto()">
                            <i class="fa fa-times me-1"></i>Remove
                        </button>
                    </div>
                </div>
            </div>

            <!-- ── Salary Structure ───────────────────────────────────────── -->
            <h6 class="text-primary fw-semibold mb-3 border-bottom pb-2">Salary Structure</h6>
            <div class="alert alert-info py-2 small mb-3">
                <i class="fa fa-info-circle me-1"></i>
                <strong>CTC per Month</strong> is the total monthly package. Basic, HRA, TA and other
                allowances are auto-calculated from this value when generating payslips.
            </div>
            <div class="row g-3 mb-4">

                <div class="col-md-4">
                    <label class="form-label">CTC per Month <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text">₹</span>
                        <input type="number" name="fixed_salary"
                               step="0.01" min="0"
                               class="form-control"
                               value="<?= $v('fixed_salary', '0') ?>"
                               required>
                    </div>
                    <div class="form-text">Total monthly cost to company (Basic + HRA + TA + allowances)</div>
                </div>

                <div class="col-md-4 d-flex align-items-end pb-1">
                    <div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox"
                                   name="ot_enabled" value="1" id="otEnabled"
                                   <?= !empty($old['ot_enabled']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="otEnabled">
                                <i class="fa fa-clock me-1 text-warning"></i>OT Enabled
                            </label>
                        </div>
                        <div class="form-text">
                            Auto-calculate overtime when check-out exceeds 8:30 PM.<br>
                            OT rate = (Basic ÷ 30 ÷ 8) × 2 per OT hour.
                        </div>
                    </div>
                </div>

            </div>

            <!-- ── Bank & Statutory Details ──────────────────────────────── -->
            <h6 class="text-primary fw-semibold mb-3 border-bottom pb-2">Bank &amp; Statutory Details</h6>
            <div class="row g-3 mb-4">

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

                <div class="col-md-4">
                    <label class="form-label">ESIC Number</label>
                    <input type="text" name="esic_number" class="form-control"
                           value="<?= $v('esic_number') ?>">
                </div>

            </div>

            <!-- ── Actions ────────────────────────────────────────────────── -->
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-save me-1"></i> Create Employee
                </button>
                <a href="<?= BASE_URL ?>/modules/employee/index.php" class="btn btn-light">
                    Cancel
                </a>
            </div>

        </form>
    </div>
</div>

<?php
$_desig_repop = (int)($old['designation_id'] ?? 0);
ob_start(); ?>
<script>
window.BASE_URL = '<?= BASE_URL ?>';

// ── Photo preview ─────────────────────────────────────────────────────────────
function previewPhoto(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            $('#photoPreview').attr('src', e.target.result);
            $('#photoPreviewWrap').removeClass('d-none');
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function removePhoto() {
    document.getElementById('photoInput').value = '';
    document.getElementById('photoPreview').src = '';
    $('#photoPreviewWrap').addClass('d-none');
}

// ── Department → Designation AJAX ────────────────────────────────────────────
(function () {
    var $dept  = $('#department_id');
    var $desig = $('#designation_id');

    function filterDesignations(deptId, keepSelected) {
        var selected = keepSelected || $desig.val();
        $desig.html('<option value="">Select Designation</option>');
        if (!deptId) {
            return;
        }
        $.getJSON(window.BASE_URL + '/api/designations_by_dept.php', { dept_id: deptId }, function (data) {
            if (data.length === 0) {
                // No dept-linked designations — show all for unblocked UX
                $('#designation_id').data('all').each(function () {
                    $desig.append($(this).clone());
                });
            } else {
                $.each(data, function (i, d) {
                    var sel = (d.id == selected) ? ' selected' : '';
                    $desig.append('<option value="' + d.id + '"' + sel + '>' + d.name + '</option>');
                });
            }
        }).fail(function () {
            // AJAX failed — restore full list so user is not blocked
            $('#designation_id').data('all').each(function () {
                $desig.append($(this).clone());
            });
        });
    }

    // Cache the full option list before any filtering
    $desig.data('all', $desig.find('option').clone());

    $dept.on('change', function () {
        filterDesignations($(this).val());
    });

    // Repopulate on validation-error reload
    var initDept = $dept.val();
    if (initDept) {
        filterDesignations(initDept, <?= (int)($_desig_repop) ?>);
    }
})();
</script>
<?php $page_scripts = ob_get_clean(); ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
