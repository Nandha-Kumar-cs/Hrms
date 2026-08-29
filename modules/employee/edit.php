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
    $lunchBatches = $db->query("SELECT id, name, start_time, end_time, shift_id FROM lunch_batches ORDER BY start_time")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* table absent */ }

$shifts = [];
try {
    $shifts = $db->query("SELECT id, name, lunch_start FROM shifts WHERE status='active' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { /* shifts table absent */ }

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
    // The employee CODE is editable here. It is a label, not a key: every other
    // table joins employees on the numeric id, so renaming the code re-labels the
    // employee everywhere (including past payslips) without moving any record.
    // Uppercased to match how create.php stores it, so 'emp001' and 'EMP001'
    // cannot both exist.
    $employee_code = strtoupper(sanitize($_POST['employee_code'] ?? ''));
    $full_name = sanitize($_POST['full_name'] ?? '');
    $email     = sanitize($_POST['email']     ?? '');
    $phone     = sanitize($_POST['phone']     ?? '');
    $dob       = sanitize($_POST['dob']       ?? '');
    $gender      = sanitize($_POST['gender'] ?? '');
    $blood_group = blood_group_clean($_POST['blood_group'] ?? null);

    // ── Address ───────────────────────────────────────────────────────────────
    $address = sanitize($_POST['address'] ?? '');
    $city    = sanitize($_POST['city']    ?? '');
    $state   = sanitize($_POST['state']   ?? '');
    $pincode = sanitize($_POST['pincode']  ?? '');

    // ── Employment ────────────────────────────────────────────────────────────
    $entity_id     = (int)($_POST['entity_id']           ?? 0) ?: null;
    $lunch_batch_id = (int)($_POST['lunch_batch_id']     ?? 0) ?: null;
    $shift_id      = (int)($_POST['shift_id']            ?? 0) ?: null;
    // A lunch batch must belong to the chosen shift; otherwise clear it.
    if ($lunch_batch_id !== null && $shift_id !== null) {
        try {
            $bChk = $db->prepare('SELECT 1 FROM lunch_batches WHERE id=? AND (shift_id IS NULL OR shift_id=?) LIMIT 1');
            $bChk->execute([$lunch_batch_id, $shift_id]);
            if (!$bChk->fetchColumn()) $lunch_batch_id = null;
        } catch (Throwable $e) { /* ignore */ }
    }
    $dept_id       = (int)($_POST['department_id']        ?? 0) ?: null;
    $des_id        = (int)($_POST['designation_id']       ?? 0) ?: null;
    $mgr_id        = (int)($_POST['reporting_manager_id'] ?? 0) ?: null;
    $joining_date  = sanitize($_POST['joining_date']  ?? '');
    $probation_end = sanitize($_POST['probation_end'] ?? '');
    $status        = sanitize($_POST['status']        ?? 'Active');

    // ── Salary ────────────────────────────────────────────────────────────────
    $fixed_salary = (float)($_POST['fixed_salary'] ?? 0);
    // An intern has no CTC. The form disables the input (includes/intern_ctc_guard.php),
    // but a disabled field simply is not POSTed — so the rule is enforced here too,
    // which also covers JavaScript being off or a hand-crafted request.
    if ($des_id) {
        $dn = db()->prepare('SELECT name FROM designations WHERE id=?');
        $dn->execute([$des_id]);
        if (designation_is_intern((string)$dn->fetchColumn())) {
            $fixed_salary    = 0.0;
        }
    }

    $ot_enabled   = isset($_POST['ot_enabled']) ? 1 : 0;
    $pf_enabled   = isset($_POST['pf_enabled']) ? 1 : 0;

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

    if ($employee_code === '') {
        $errors[] = 'Employee code is required.';
    } else {
        // employees.employee_id carries a UNIQUE index, so a clash would surface
        // as a raw SQL error. Check first and report it in the form instead.
        $dupCode = $db->prepare('SELECT name FROM employees WHERE employee_id = ? AND id != ? LIMIT 1');
        $dupCode->execute([$employee_code, $id]);
        if ($clashName = $dupCode->fetchColumn()) {
            $errors[] = "Employee code '$employee_code' is already used by " . $clashName . '.';
        }
    }

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
                employee_id=:employee_code,
                entity_id=:entity_id, lunch_batch_id=:lunch_batch_id, shift_id=:shift_id,
                name=:name, email=:email, phone=:phone, gender=:gender, dob=:dob,
                blood_group=:blood_group,
                address=:address, city=:city, state=:state, pincode=:pincode,
                department_id=:dept_id, designation_id=:des_id,
                ot_enabled=:ot_enabled, pf_enabled=:pf_enabled, manager_id=:mgr_id,
                join_date=:join_date, probation_end=:probation_end, status=:status,
                fixed_salary=:fixed_salary,
                bank_name=:bank, bank_account=:bacc, bank_ifsc=:bifsc,
                pan_number=:pan, aadhaar_number=:aadh, uan_number=:uan, esic_number=:esic,
                photo=:photo, updated_at=NOW()
             WHERE id=:id"
        );
        $stmt->execute([
            ':employee_code' => $employee_code,
            ':entity_id'    => $entity_id,
            ':lunch_batch_id' => $lunch_batch_id,
            ':shift_id'       => $shift_id,
            ':name'         => $full_name,
            ':email'        => $email,
            ':phone'        => $phone         ?: null,
            ':gender'       => $gender        ?: null,
            ':dob'          => $dob           ?: null,
            ':blood_group'  => $blood_group,
            ':address'      => $address       ?: null,
            ':city'         => $city          ?: null,
            ':state'        => $state         ?: null,
            ':pincode'      => $pincode       ?: null,
            ':dept_id'      => $dept_id,
            ':des_id'       => $des_id,
            ':ot_enabled'   => $ot_enabled,
            ':pf_enabled'   => $pf_enabled,
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
            activity_change('Employee Code', $emp['employee_id'] ?? '', $employee_code),
            activity_change('Name',   $emp['name'] ?? '',  $full_name),
            activity_change('Email',  $emp['email'] ?? '', $email),
            activity_change('Status', $emp['status'] ?? '', $status),
            activity_change('CTC/Month',
                '₹' . number_format((float)($emp['fixed_salary'] ?? 0), 2),
                '₹' . number_format((float)$fixed_salary, 2)),
            activity_change('PF & ESI Deduction',
                !empty($emp['pf_enabled']) ? 'Enabled' : 'Disabled',
                $pf_enabled ? 'Enabled' : 'Disabled'),
        ]));
        activity_log('updated', 'Employee', 'Updated employee: ' . $full_name . ' (' . $employee_code . ')', $changes);
        flash('success', 'Employee updated successfully.');
        redirect(BASE_URL . "/modules/employee/view.php?id=$id");
    }

    // ── Repopulate $emp on validation failure ─────────────────────────────────
    $emp = array_merge($emp, [
        'employee_id'    => $employee_code,
        'entity_id'      => $entity_id,
        'name'           => $full_name,
        'email'          => $email,
        'phone'          => $phone,
        'gender'         => $gender,
        'blood_group'    => $blood_group,
        'dob'            => $dob,
        'address'        => $address,
        'city'           => $city,
        'state'          => $state,
        'pincode'        => $pincode,
        'department_id'  => $dept_id,
        'designation_id' => $des_id,
        'shift_id'       => $shift_id,
        'manager_id'     => $mgr_id,
        'join_date'      => $joining_date,
        'probation_end'  => $probation_end,
        'status'         => $status,
        'fixed_salary'   => $fixed_salary,
        'ot_enabled'     => $ot_enabled,
        'pf_enabled'     => $pf_enabled,
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
                    <label class="form-label">Employee Code <span class="text-danger">*</span></label>
                    <input type="text" name="employee_code" class="form-control"
                           value="<?= $v('employee_id') ?>"
                           placeholder="e.g. EMP0001" required>
                    <div class="form-text">Must be unique. Changing it re-labels the employee
                        everywhere, including payslips already issued.</div>
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

                <div class="col-md-4">
                    <label class="form-label">Blood Group</label>
                    <select name="blood_group" class="form-select">
                        <option value="">Select Blood Group</option>
                        <?php foreach (blood_group_options() as $bg): ?>
                        <option value="<?= h($bg) ?>" <?= $sel('blood_group', $bg) ?>><?= h($bg) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Printed on the back of the ID card.</div>
                </div>

                <div class="col-md-12">
                    <label class="form-label">Address</label>
                    <textarea name="address" class="form-control" rows="2"
                              placeholder="House / street / area"><?= $v('address') ?></textarea>
                </div>

                <div class="col-md-4">
                    <label class="form-label">City</label>
                    <input type="text" name="city" class="form-control"
                           value="<?= $v('city') ?>" placeholder="Chennai">
                </div>

                <div class="col-md-4">
                    <label class="form-label">State</label>
                    <input type="text" name="state" class="form-control"
                           value="<?= $v('state') ?>" placeholder="Tamil Nadu">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Pincode</label>
                    <input type="text" name="pincode" class="form-control"
                           value="<?= $v('pincode') ?>" placeholder="600001" maxlength="10">
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

                <?php if ($shifts): ?>
                <div class="col-md-4">
                    <label class="form-label">Shift</label>
                    <select name="shift_id" id="shiftSelect" class="form-select">
                        <?php foreach ($shifts as $s): ?>
                        <option value="<?= $s['id'] ?>" data-haslunch="<?= !empty($s['lunch_start']) ? 1 : 0 ?>" <?= $sel('shift_id', $s['id']) ?>>
                            <?= h($s['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Drives attendance &amp; payroll timing.</div>
                </div>
                <?php endif; ?>

                <div class="col-md-4">
                    <label class="form-label">Lunch Batch</label>
                    <select name="lunch_batch_id" id="lunchBatchSelect" class="form-select">
                        <option value="">Default lunch</option>
                        <?php foreach ($lunchBatches as $lb): ?>
                        <option value="<?= $lb['id'] ?>" data-shift="<?= (int)($lb['shift_id'] ?? 0) ?>" <?= $sel('lunch_batch_id', $lb['id']) ?>>
                            <?= h($lb['name']) ?> (<?= h(date('h:i A', strtotime($lb['start_time']))) ?>–<?= h(date('h:i A', strtotime($lb['end_time']))) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Used in salary calculation.</div>
                </div>

                <script>
                (function () {
                    var shiftSel = document.getElementById('shiftSelect');
                    var lunchSel = document.getElementById('lunchBatchSelect');
                    if (!shiftSel || !lunchSel) return;
                    function apply() {
                        var opt = shiftSel.options[shiftSel.selectedIndex];
                        var sid = shiftSel.value;
                        var hasLunch = opt && opt.getAttribute('data-haslunch') === '1';
                        Array.prototype.forEach.call(lunchSel.options, function (o) {
                            if (o.value === '') { o.hidden = false; return; }
                            o.hidden = (o.getAttribute('data-shift') !== sid);
                        });
                        var cur = lunchSel.options[lunchSel.selectedIndex];
                        if (cur && cur.hidden) lunchSel.value = '';
                        if (!hasLunch) { lunchSel.value = ''; lunchSel.disabled = true; }
                        else { lunchSel.disabled = false; }
                    }
                    shiftSel.addEventListener('change', apply);
                    apply();
                })();
                </script>

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
                             src="<?= !empty($emp['photo']) ? h(file_url('uploads/photos/' . $emp['photo'])) : '' ?>"
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

                <div class="col-md-4 d-flex align-items-end pb-1">
                    <div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox"
                                   name="pf_enabled" value="1" id="pfEnabled"
                                   <?= !empty($emp['pf_enabled']) ? 'checked' : '' ?>>
                            <label class="form-check-label fw-semibold" for="pfEnabled">
                                <i class="fa fa-piggy-bank me-1 text-success"></i>PF &amp; ESI Deduction
                            </label>
                        </div>
                        <div class="form-text">
                            Deduct statutory contributions from this employee's salary.<br>
                            When off, <strong>both PF and ESI</strong> are skipped.
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

<?php /* Interns have no CTC — disables the CTC input for an intern designation. */ ?>
<?php include __DIR__ . '/../../includes/intern_ctc_guard.php'; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
