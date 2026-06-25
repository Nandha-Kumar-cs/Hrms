<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('employee', 'edit');

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    flash('error', 'No employee specified.');
    redirect(BASE_URL . '/modules/employee/index.php');
}

$db = db();

// ── Load employee ─────────────────────────────────────────────────────────────
$empStmt = $db->prepare('SELECT * FROM employees WHERE id = ?');
$empStmt->execute([$id]);
$emp = $empStmt->fetch(PDO::FETCH_ASSOC);

if (!$emp) {
    flash('error', 'Employee not found.');
    redirect(BASE_URL . '/modules/employee/index.php');
}

// ── Dropdown data ─────────────────────────────────────────────────────────────
$entities = $db->query(
    'SELECT id, name FROM entities ORDER BY name'
)->fetchAll(PDO::FETCH_ASSOC);

$depts = $db->query(
    'SELECT id, name FROM departments ORDER BY name'
)->fetchAll(PDO::FETCH_ASSOC);

$designs = $db->query(
    'SELECT id, name, department_id FROM designations ORDER BY name'
)->fetchAll(PDO::FETCH_ASSOC);

$lunchBatches = [];
try {
    $lunchBatches = $db->query("SELECT id, name, start_time, end_time FROM lunch_batches ORDER BY start_time")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* table absent */ }

$managersStmt = $db->prepare(
    "SELECT id, name, employee_id FROM employees
     WHERE status = 'Active' AND id != ? ORDER BY name"
);
$managersStmt->execute([$id]);
$managers = $managersStmt->fetchAll(PDO::FETCH_ASSOC);

$errors = [];

// ============================================================================
// POST handler
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf($_POST['csrf_token'] ?? '');

    // ── Personal ──────────────────────────────────────────────────────────────
    $full_name = sanitize($_POST['full_name'] ?? '');
    $email     = sanitize($_POST['email']     ?? '');
    $phone     = sanitize($_POST['phone']     ?? '');
    $dob       = sanitize($_POST['dob']       ?? '');
    $gender    = sanitize($_POST['gender']    ?? '');

    // ── Employment ────────────────────────────────────────────────────────────
    $entity_id     = (int)($_POST['entity_id']           ?? 0) ?: null;
    $lunch_batch_id = (int)($_POST['lunch_batch_id']     ?? 0) ?: null;
    $dept_id       = (int)($_POST['department_id']        ?? 0) ?: null;
    $des_id        = (int)($_POST['designation_id']       ?? 0) ?: null;
    $mgr_id        = (int)($_POST['reporting_manager_id'] ?? 0) ?: null;
    $joining_date  = sanitize($_POST['joining_date']  ?? '');
    $probation_end = sanitize($_POST['probation_end'] ?? '');
    $status        = sanitize($_POST['status']        ?? 'Active');

    // ── Salary ────────────────────────────────────────────────────────────────
    $fixed_salary = (float)($_POST['fixed_salary'] ?? 0);
    $ot_enabled   = isset($_POST['ot_enabled']) ? 1 : 0;

    // ── Bank & Statutory ──────────────────────────────────────────────────────
    $bank  = sanitize($_POST['bank_name']      ?? '');
    $bacc  = sanitize($_POST['bank_account']   ?? '');
    $bifsc = sanitize($_POST['bank_ifsc']      ?? '');
    $pan   = strtoupper(sanitize($_POST['pan_number']     ?? ''));
    $aadh  = sanitize($_POST['aadhaar_number'] ?? '');
    $uan   = sanitize($_POST['uan_number']     ?? '');
    $esic  = sanitize($_POST['esic_number']    ?? '');

    // ── Validation ────────────────────────────────────────────────────────────
    if ($full_name === '') $errors[] = 'Full name is required.';

    if ($email === '') {
        $errors[] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email address is not valid.';
    } else {
        $dupEmail = $db->prepare('SELECT 1 FROM employees WHERE email = ? AND id != ? LIMIT 1');
        $dupEmail->execute([$email, $id]);
        if ($dupEmail->fetchColumn()) {
            $errors[] = "Email '$email' is already used by another employee.";
        }
    }

    if ($fixed_salary < 0) $errors[] = 'CTC per month cannot be negative.';

    $validStatuses = ['Active', 'Inactive', 'On Leave', 'Resigned', 'Terminated'];
    if (!in_array($status, $validStatuses, true)) $status = 'Active';

    if ($entity_id !== null) {
        $entCheck = $db->prepare('SELECT 1 FROM entities WHERE id = ? LIMIT 1');
        $entCheck->execute([$entity_id]);
        if (!$entCheck->fetchColumn()) { $entity_id = null; }
    }

    if ($dept_id !== null) {
        $deptCheck = $db->prepare('SELECT 1 FROM departments WHERE id = ? LIMIT 1');
        $deptCheck->execute([$dept_id]);
        if (!$deptCheck->fetchColumn()) { $dept_id = null; }
    }

    if ($des_id !== null) {
        $desCheck = $db->prepare('SELECT 1 FROM designations WHERE id = ? LIMIT 1');
        $desCheck->execute([$des_id]);
        if (!$desCheck->fetchColumn()) { $des_id = null; }
    }

    if (empty($errors)) {
        // ── Photo upload / removal ────────────────────────────────────────────
        $photo = $emp['photo'];
        if (!empty($_POST['remove_photo']) && $_POST['remove_photo'] === '1') {
            $photo = null;
        }
        if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $allowed_image_types = ['image/jpeg', 'image/png', 'image/webp'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($_FILES['photo']['tmp_name']);
            if (!in_array($mime, $allowed_image_types)) {
                flash('warn', 'Photo must be JPG, PNG, or WebP. Other changes were saved.');
            } elseif ($_FILES['photo']['size'] > 2 * 1024 * 1024) {
                flash('warn', 'Photo exceeds 2 MB. Other changes were saved.');
            } else {
                $ext   = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                $dest  = UPLOAD_PATH . '/photos';
                $fname = 'emp_' . uniqid() . '.' . $ext;
                $fpath = rtrim($dest, '/') . '/' . $fname;
                if (!is_dir($dest)) mkdir($dest, 0755, true);
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $fpath)) {
                    $photo = $fname;
                }
            }
        }

        // ── UPDATE employees ──────────────────────────────────────────────────
        $stmt = $db->prepare(
            "UPDATE employees SET
                entity_id=:entity_id, lunch_batch_id=:lunch_batch_id,
                name=:name, email=:email, phone=:phone, gender=:gender, dob=:dob,
                department_id=:dept_id, designation_id=:des_id,
                ot_enabled=:ot_enabled, manager_id=:mgr_id,
                join_date=:join_date, probation_end=:probation_end, status=:status,
                fixed_salary=:fixed_salary,
                bank_name=:bank, bank_account=:bacc, bank_ifsc=:bifsc,
                pan_number=:pan, aadhaar_number=:aadh, uan_number=:uan, esic_number=:esic,
                photo=:photo, updated_at=NOW()
             WHERE id=:id"
        );
        $stmt->execute([
            ':entity_id'    => $entity_id,
            ':lunch_batch_id' => $lunch_batch_id,
            ':name'         => $full_name,
            ':email'        => $email,
            ':phone'        => $phone         ?: null,
            ':gender'       => $gender        ?: null,
            ':dob'          => $dob           ?: null,
            ':dept_id'      => $dept_id,
            ':des_id'       => $des_id,
            ':ot_enabled'   => $ot_enabled,
            ':mgr_id'       => $mgr_id,
            ':join_date'    => $joining_date  ?: null,
            ':probation_end'=> $probation_end ?: null,
            ':status'       => $status,
            ':fixed_salary' => $fixed_salary,
            ':bank'         => $bank          ?: null,
            ':bacc'         => $bacc          ?: null,
            ':bifsc'        => $bifsc         ?: null,
            ':pan'          => $pan           ?: null,
            ':aadh'         => $aadh          ?: null,
            ':uan'          => $uan           ?: null,
            ':esic'         => $esic          ?: null,
            ':photo'        => $photo,
            ':id'           => $id,
        ]);

        // ── Sync user account ─────────────────────────────────────────────────
        $db->prepare("UPDATE users SET email = :email, name = :name WHERE employee_id = :eid")
           ->execute([':email' => $email, ':name' => $full_name, ':eid' => $id]);

        // ── Salary structure: create new entry if CTC changed ─────────────────
        $prevSalary = (float)($emp['fixed_salary'] ?? 0);
        if (abs($fixed_salary - $prevSalary) > 0.005 && $fixed_salary > 0) {
            $db->prepare("UPDATE salary_structures SET is_current = 0 WHERE employee_id = ?")
               ->execute([$id]);
            $basic   = round($fixed_salary * 0.50, 2);
            $hra     = round($fixed_salary * 0.20, 2);
            $conv    = round($fixed_salary * 0.10, 2);
            $special = round($fixed_salary - $basic - $hra - $conv, 2);
            $db->prepare(
                "INSERT INTO salary_structures
                    (employee_id, basic, hra, conveyance, special_allow, effective_from, is_current, created_at)
                 VALUES (:eid, :basic, :hra, :conv, :special, CURDATE(), 1, NOW())"
            )->execute([
                ':eid'     => $id,
                ':basic'   => $basic,
                ':hra'     => $hra,
                ':conv'    => $conv,
                ':special' => $special,
            ]);
        }

        $changes = array_values(array_filter([
            activity_change('Name',   $emp['name'] ?? '',  $full_name),
            activity_change('Email',  $emp['email'] ?? '', $email),
            activity_change('Status', $emp['status'] ?? '', $status),
            activity_change('CTC/Month',
                '₹' . number_format((float)($emp['fixed_salary'] ?? 0), 2),
                '₹' . number_format((float)$fixed_salary, 2)),
        ]));
        activity_log('updated', 'Employee', 'Updated employee: ' . $full_name . ' (' . ($emp['employee_id'] ?? '') . ')', $changes);
        flash('success', 'Employee updated successfully.');
        redirect(BASE_URL . "/modules/employee/view.php?id=$id");
    }

    // ── Repopulate $emp on validation failure ─────────────────────────────────
    $emp = array_merge($emp, [
        'entity_id'      => $entity_id,
        'name'           => $full_name,
        'email'          => $email,
        'phone'          => $phone,
        'gender'         => $gender,
        'dob'            => $dob,
        'department_id'  => $dept_id,
        'designation_id' => $des_id,
        'manager_id'     => $mgr_id,
        'join_date'      => $joining_date,
        'probation_end'  => $probation_end,
        'status'         => $status,
        'fixed_salary'   => $fixed_salary,
        'ot_enabled'     => $ot_enabled,
        'bank_name'      => $bank,
        'bank_account'   => $bacc,
        'bank_ifsc'      => $bifsc,
        'pan_number'     => $pan,
        'aadhaar_number' => $aadh,
        'uan_number'     => $uan,
        'esic_number'    => $esic,
    ]);
}

$page_title = 'Edit Employee';
require_once __DIR__ . '/../../includes/header.php';

$sel = fn($field, $val) => (string)($emp[$field] ?? '') === (string)$val ? 'selected' : '';
$v   = fn($field)       => h($emp[$field] ?? '');
?>

<div class="card page-card">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2">
        <a href="<?= BASE_URL ?>/modules/employee/view.php?id=<?= $id ?>"
           class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-arrow-left"></i>
        </a>
        <h5 class="mb-0 fw-semibold">
            Employee Details
            <small class="text-muted fw-normal fs-6 ms-2"><?= $v('employee_id') ?> — <?= $v('name') ?></small>
        </h5>
    </div>

    <div class="card-body">

        <?php if ($errors): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $err): ?>
                <li><?= h($err) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" novalidate id="editEmpForm">
            <?= csrf_field() ?>

            <!-- ── Personal Information ───────────────────────────────────── -->
            <h6 class="text-primary fw-semibold mb-3 border-bottom pb-2">Personal Information</h6>
            <div class="row g-3 mb-4">

                <div class="col-md-4">
                    <label class="form-label">Employee Code</label>
                    <input type="text" class="form-control bg-light"
                           value="<?= $v('employee_id') ?>" disabled>
                    <div class="form-text">Employee code cannot be changed after creation.</div>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="full_name" class="form-control"
                           value="<?= $v('name') ?>"
                           placeholder="e.g. Ravi Kumar" required>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Email <span class="text-danger">*</span></label>
                    <input type="email" name="email" class="form-control"
                           value="<?= $v('email') ?>"
                           placeholder="ravi@company.com" required>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control"
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
                        <option value="">Select Gender</option>
                        <option value="Male"              <?= $sel('gender', 'Male') ?>>Male</option>
                        <option value="Female"            <?= $sel('gender', 'Female') ?>>Female</option>
                        <option value="Other"             <?= $sel('gender', 'Other') ?>>Other</option>
                        <option value="Prefer not to say" <?= $sel('gender', 'Prefer not to say') ?>>Prefer not to say</option>
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
                    <label class="form-label">Lunch Batch</label>
                    <select name="lunch_batch_id" class="form-select">
                        <option value="">Default lunch</option>
                        <?php foreach ($lunchBatches as $lb): ?>
                        <option value="<?= $lb['id'] ?>" <?= $sel('lunch_batch_id', $lb['id']) ?>>
                            <?= h($lb['name']) ?> (<?= h(date('h:i A', strtotime($lb['start_time']))) ?>–<?= h(date('h:i A', strtotime($lb['end_time']))) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Used in salary calculation.</div>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="Active"     <?= $sel('status', 'Active') ?>>Active</option>
                        <option value="Inactive"   <?= $sel('status', 'Inactive') ?>>Inactive</option>
                        <option value="On Leave"   <?= $sel('status', 'On Leave') ?>>On Leave</option>
                        <option value="Resigned"   <?= $sel('status', 'Resigned') ?>>Resigned</option>
                        <option value="Terminated" <?= $sel('status', 'Terminated') ?>>Terminated</option>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Joining Date</label>
                    <input type="date" name="joining_date" class="form-control"
                           value="<?= $v('join_date') ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Probation End Date</label>
                    <input type="date" name="probation_end" class="form-control"
                           value="<?= $v('probation_end') ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Reporting Manager</label>
                    <select name="reporting_manager_id" class="form-select">
                        <option value="">Select Manager</option>
                        <?php foreach ($managers as $mgr): ?>
                        <option value="<?= $mgr['id'] ?>" <?= $sel('manager_id', $mgr['id']) ?>>
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
                    <input type="hidden" name="remove_photo" id="removePhotoFlag" value="0">
                    <input type="file" name="photo" id="photoInput"
                           accept="image/jpeg,image/png,image/webp"
                           class="form-control"
                           onchange="previewPhoto(this)">
                    <div class="form-text">JPG / PNG / WebP — max 2 MB</div>
                    <div id="photoPreviewWrap" class="mt-2 d-flex align-items-center gap-2 <?= empty($emp['photo']) ? 'd-none' : '' ?>">
                        <img id="photoPreview"
                             src="<?= !empty($emp['photo']) ? BASE_URL . '/uploads/photos/' . h($emp['photo']) : '' ?>"
                             alt="Preview" class="rounded"
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
                <strong>CTC per Month</strong> is the total monthly package. Changing it creates a new
                salary structure entry automatically.
            </div>
            <div class="row g-3 mb-4">

                <div class="col-md-4">
                    <label class="form-label">CTC per Month <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text">₹</span>
                        <input type="number" name="fixed_salary" step="0.01" min="0"
                               class="form-control"
                               value="<?= h(number_format((float)($emp['fixed_salary'] ?? 0), 2, '.', '')) ?>"
                               required>
                    </div>
                    <div class="form-text">Total monthly cost to company (Basic + HRA + TA + allowances)</div>
                </div>

                <div class="col-md-4 d-flex align-items-end pb-1">
                    <div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox"
                                   name="ot_enabled" value="1" id="otEnabled"
                                   <?= !empty($emp['ot_enabled']) ? 'checked' : '' ?>>
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
                           value="<?= $v('bank_name') ?>" placeholder="HDFC Bank">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Account Number</label>
                    <input type="text" name="bank_account" class="form-control"
                           value="<?= $v('bank_account') ?>">
                </div>

                <div class="col-md-4">
                    <label class="form-label">IFSC Code</label>
                    <input type="text" name="bank_ifsc" class="form-control"
                           value="<?= $v('bank_ifsc') ?>" placeholder="HDFC0001234">
                </div>

                <div class="col-md-4">
                    <label class="form-label">PAN Number</label>
                    <input type="text" name="pan_number" class="form-control"
                           maxlength="10" style="text-transform:uppercase"
                           value="<?= $v('pan_number') ?>" placeholder="ABCDE1234F">
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
                    <i class="fa fa-save me-1"></i> Save Changes
                </button>
                <a href="<?= BASE_URL ?>/modules/employee/view.php?id=<?= $id ?>"
                   class="btn btn-light">Cancel</a>
            </div>

        </form>
    </div>
</div>

<?php
$_desig_repop = (int)($emp['designation_id'] ?? 0);
ob_start(); ?>
<script>
window.BASE_URL = '<?= BASE_URL ?>';

// ── Photo preview ─────────────────────────────────────────────────────────────
function previewPhoto(input) {
    if (input.files && input.files[0]) {
        document.getElementById('removePhotoFlag').value = '0';
        var reader = new FileReader();
        reader.onload = function(e) {
            $('#photoPreview').attr('src', e.target.result);
            $('#photoPreviewWrap').removeClass('d-none');
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function removePhoto() {
    document.getElementById('removePhotoFlag').value = '1';
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
        if (!deptId) { return; }
        $.getJSON(window.BASE_URL + '/api/designations_by_dept.php', { dept_id: deptId }, function (data) {
            if (data.length === 0) {
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
            $('#designation_id').data('all').each(function () {
                $desig.append($(this).clone());
            });
        });
    }

    $desig.data('all', $desig.find('option').clone());

    $dept.on('change', function () {
        filterDesignations($(this).val());
    });

    var initDept = $dept.val();
    if (initDept) {
        filterDesignations(initDept, <?= (int)$_desig_repop ?>);
    }
})();
</script>
<?php $page_scripts = ob_get_clean(); ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
