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

$empStmt = $db->prepare(
    'SELECT e.*, d.name AS dept_name, des.name AS des_name
     FROM employees e
     LEFT JOIN departments d   ON d.id   = e.department_id
     LEFT JOIN designations des ON des.id = e.designation_id
     WHERE e.id = ?'
);
$empStmt->execute([$id]);
$emp = $empStmt->fetch();

if (!$emp) {
    flash('error', 'Employee not found.');
    redirect(BASE_URL . '/modules/employee/index.php');
}

$departments  = $db->query('SELECT id, name FROM departments  ORDER BY name')->fetchAll();
$designations = $db->query('SELECT id, name FROM designations ORDER BY name')->fetchAll();
$roles        = $db->query("SELECT id, name FROM roles WHERE name != 'Super Admin' ORDER BY name")->fetchAll();

$errors = [];

// ── POST handler ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf($_POST['csrf_token'] ?? '');

    $name   = sanitize($_POST['name']   ?? '');
    $email  = sanitize($_POST['email']  ?? '');
    $phone  = sanitize($_POST['phone']  ?? '');
    $gender = sanitize($_POST['gender'] ?? '');
    $dob    = sanitize($_POST['dob']    ?? '');
    $addr   = sanitize($_POST['address']  ?? '');
    $city   = sanitize($_POST['city']     ?? '');
    $state  = sanitize($_POST['state']    ?? '');
    $pin    = sanitize($_POST['pincode']  ?? '');
    $status = sanitize($_POST['status']   ?? 'Active');
    $ename  = sanitize($_POST['emergency_name']  ?? '');
    $ephone = sanitize($_POST['emergency_phone'] ?? '');

    $jdate   = sanitize($_POST['join_date']        ?? '');
    $etype   = sanitize($_POST['employment_type']  ?? 'Full Time');
    $dept_id = (int)($_POST['department_id']  ?? 0) ?: null;
    $des_id  = (int)($_POST['designation_id'] ?? 0) ?: null;
    $mgr_id  = (int)($_POST['manager_id']     ?? 0) ?: null;

    $bank  = sanitize($_POST['bank_name']      ?? '');
    $bacc  = sanitize($_POST['bank_account']   ?? '');
    $bifsc = sanitize($_POST['bank_ifsc']      ?? '');
    $pan   = strtoupper(sanitize($_POST['pan_number']     ?? ''));
    $aadh  = sanitize($_POST['aadhaar_number'] ?? '');
    $uan   = sanitize($_POST['uan_number']     ?? '');
    $esic  = sanitize($_POST['esic_number']    ?? '');

    if ($name === '')  $errors[] = 'Full name is required.';
    if ($email === '') $errors[] = 'Email address is required.';

    if ($email) {
        $dupEmail = $db->prepare('SELECT 1 FROM employees WHERE email = ? AND id != ? LIMIT 1');
        $dupEmail->execute([$email, $id]);
        if ($dupEmail->fetchColumn()) {
            $errors[] = "Email '$email' is already used by another employee.";
        }
    }

    $validStatuses = ['Active', 'On Leave', 'Resigned', 'Terminated'];
    if (!in_array($status, $validStatuses, true)) $status = 'Active';

    $validTypes = ['Full Time', 'Part Time', 'Contract', 'Intern'];
    if (!in_array($etype, $validTypes, true)) $etype = 'Full Time';

    if (empty($errors)) {
        $photo = $emp['photo'];
        if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $uploaded = upload_file($_FILES['photo'], UPLOAD_PATH . '/photos', 'emp_');
            if ($uploaded !== null) {
                $photo = $uploaded;
            } else {
                flash('warn', 'Photo could not be saved. Other changes were saved.');
            }
        }

        $stmt = $db->prepare(
            "UPDATE employees SET
                name=:name, email=:email, phone=:phone, gender=:gender,
                dob=:dob, address=:address, city=:city, state=:state, pincode=:pincode,
                status=:status,
                emergency_name=:ename, emergency_phone=:ephone,
                join_date=:jdate, employment_type=:etype,
                department_id=:dept_id, designation_id=:des_id, manager_id=:mgr_id,
                bank_name=:bank, bank_account=:bacc, bank_ifsc=:bifsc,
                pan_number=:pan, aadhaar_number=:aadh, uan_number=:uan, esic_number=:esic,
                photo=:photo, updated_at=NOW()
             WHERE id=:id"
        );
        $stmt->execute([
            ':name'    => $name,
            ':email'   => $email,
            ':phone'   => $phone   ?: null,
            ':gender'  => $gender  ?: null,
            ':dob'     => $dob     ?: null,
            ':address' => $addr    ?: null,
            ':city'    => $city    ?: null,
            ':state'   => $state   ?: null,
            ':pincode' => $pin     ?: null,
            ':status'  => $status,
            ':ename'   => $ename   ?: null,
            ':ephone'  => $ephone  ?: null,
            ':jdate'   => $jdate   ?: null,
            ':etype'   => $etype,
            ':dept_id' => $dept_id,
            ':des_id'  => $des_id,
            ':mgr_id'  => $mgr_id,
            ':bank'    => $bank    ?: null,
            ':bacc'    => $bacc    ?: null,
            ':bifsc'   => $bifsc   ?: null,
            ':pan'     => $pan     ?: null,
            ':aadh'    => $aadh    ?: null,
            ':uan'     => $uan     ?: null,
            ':esic'    => $esic    ?: null,
            ':photo'   => $photo,
            ':id'      => $id,
        ]);

        $db->prepare("UPDATE users SET email = :email, name = :name WHERE employee_id = :eid")
           ->execute([':email' => $email, ':name' => $name, ':eid' => $id]);

        if (!empty($_POST['role_id'])) {
            $rid = (int)$_POST['role_id'];
            $uidStmt = $db->prepare('SELECT id FROM users WHERE employee_id = ?');
            $uidStmt->execute([$id]);
            $userId = $uidStmt->fetchColumn();
            if ($userId) {
                $db->prepare('UPDATE users SET role_id = ? WHERE id = ?')
                   ->execute([$rid, $userId]);
            }
        }

        flash('success', 'Employee updated successfully.');
        redirect(BASE_URL . "/modules/employee/view.php?id=$id");
    }

    $emp = array_merge($emp, [
        'name' => $name, 'email' => $email, 'phone' => $phone,
        'gender' => $gender, 'dob' => $dob, 'address' => $addr,
        'city' => $city, 'state' => $state, 'pincode' => $pin,
        'status' => $status, 'emergency_name' => $ename, 'emergency_phone' => $ephone,
        'join_date' => $jdate, 'employment_type' => $etype,
        'department_id' => $dept_id, 'designation_id' => $des_id, 'manager_id' => $mgr_id,
        'bank_name' => $bank, 'bank_account' => $bacc, 'bank_ifsc' => $bifsc,
        'pan_number' => $pan, 'aadhaar_number' => $aadh, 'uan_number' => $uan, 'esic_number' => $esic,
    ]);
}

$managersStmt = $db->prepare(
    "SELECT id, name, employee_id FROM employees WHERE status = 'Active' AND id != ? ORDER BY name"
);
$managersStmt->execute([$id]);
$managers = $managersStmt->fetchAll();

$userRoleStmt = $db->prepare('SELECT role_id FROM users WHERE employee_id = ?');
$userRoleStmt->execute([$id]);
$currentRoleId = $userRoleStmt->fetchColumn() ?: null;

$page_title = 'Edit Employee';
require_once __DIR__ . '/../../includes/header.php';

// sel() helper: returns 'selected' if value matches
$sel = fn($field, $val) => (string)($emp[$field] ?? '') === (string)$val ? 'selected' : '';
// v() helper: returns h()-escaped value
$v = fn($field) => h($emp[$field] ?? '');
?>

<div class="page-head">
    <div>
        <h1>Edit Employee</h1>
        <p class="muted"><?= $v('employee_id') ?> &mdash; <?= $v('name') ?></p>
    </div>
    <div class="head-actions">
        <a href="view.php?id=<?= $id ?>" class="btn btn-ghost">Back</a>
        <button type="submit" form="editEmpForm" class="btn btn-primary">Save Changes</button>
    </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-error">
    <strong>Please fix the following:</strong>
    <ul style="margin:.4em 0 0 1.2em;padding:0">
        <?php foreach ($errors as $err): ?>
        <li><?= h($err) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" id="editEmpForm">
    <?= csrf_field() ?>

    <!-- ── Personal Information ────────────────────────────────────────────── -->
    <div class="card">
        <div class="card-head"><h3>Personal Information</h3></div>
        <div class="card-body">

            <div class="form-grid">

                <div class="field span-2">
                    <label>Full Name <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="name" value="<?= $v('name') ?>" required
                           placeholder="e.g. Ravi Kumar">
                </div>

                <div class="field">
                    <label>Status</label>
                    <select name="status">
                        <?php foreach (['Active', 'On Leave', 'Resigned', 'Terminated'] as $s): ?>
                        <option value="<?= $s ?>" <?= $sel('status', $s) ?>><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field span-2">
                    <label>Email Address <span style="color:var(--danger)">*</span></label>
                    <input type="email" name="email" value="<?= $v('email') ?>" required>
                </div>

                <div class="field">
                    <label>Phone</label>
                    <input type="tel" name="phone" value="<?= $v('phone') ?>">
                </div>

                <div class="field">
                    <label>Date of Birth</label>
                    <input type="date" name="dob" value="<?= $v('dob') ?>">
                </div>

                <div class="field">
                    <label>Gender</label>
                    <select name="gender">
                        <option value="">— Select —</option>
                        <?php foreach (['Male', 'Female', 'Other', 'Prefer not to say'] as $g): ?>
                        <option value="<?= $g ?>" <?= $sel('gender', $g) ?>><?= $g ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field"><!-- spacer --></div>

                <div class="field span-3">
                    <label>Address</label>
                    <textarea name="address" rows="2"><?= $v('address') ?></textarea>
                </div>

                <div class="field">
                    <label>City</label>
                    <input type="text" name="city" value="<?= $v('city') ?>">
                </div>

                <div class="field">
                    <label>State</label>
                    <input type="text" name="state" value="<?= $v('state') ?>">
                </div>

                <div class="field">
                    <label>Pincode</label>
                    <input type="text" name="pincode" maxlength="10" value="<?= $v('pincode') ?>">
                </div>

                <div class="field span-2">
                    <label>Emergency Contact Name</label>
                    <input type="text" name="emergency_name" value="<?= $v('emergency_name') ?>">
                </div>

                <div class="field">
                    <label>Emergency Contact Phone</label>
                    <input type="tel" name="emergency_phone" value="<?= $v('emergency_phone') ?>">
                </div>

            </div><!-- /.form-grid -->

            <!-- Photo upload -->
            <div class="section-title">Profile Photo</div>
            <div style="display:flex;align-items:center;gap:14px;margin-top:8px">
                <?php if (!empty($emp['photo'])): ?>
                <img src="<?= BASE_URL ?>/uploads/photos/<?= $v('photo') ?>"
                     id="photoPreview"
                     style="width:56px;height:56px;border-radius:50%;object-fit:cover;border:2px solid var(--border)">
                <?php else: ?>
                <div id="photoPreview"
                     style="width:56px;height:56px;border-radius:50%;background:var(--primary);
                            display:flex;align-items:center;justify-content:center;
                            color:#fff;font-size:22px;font-weight:700">
                    <?= strtoupper(mb_substr($emp['name'],0,1)) ?>
                </div>
                <?php endif; ?>
                <div>
                    <input type="file" name="photo" id="photoInput"
                           accept="image/jpeg,image/png,image/webp"
                           style="display:block;padding:6px 10px;border:1px solid var(--border-strong);border-radius:var(--radius);font-size:13px;background:white;cursor:pointer">
                    <div style="font-size:12px;color:var(--text-muted);margin-top:4px">JPG, PNG, WebP &middot; max 2 MB</div>
                </div>
            </div>

        </div>
    </div>

    <!-- ── Employment Details ──────────────────────────────────────────────── -->
    <div class="card">
        <div class="card-head"><h3>Employment Details</h3></div>
        <div class="card-body">
            <div class="form-grid">

                <div class="field">
                    <label>Department</label>
                    <select name="department_id">
                        <option value="">— Select —</option>
                        <?php foreach ($departments as $d): ?>
                        <option value="<?= $d['id'] ?>"
                            <?= $sel('department_id', $d['id']) ?>><?= h($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label>Designation</label>
                    <select name="designation_id">
                        <option value="">— Select —</option>
                        <?php foreach ($designations as $d): ?>
                        <option value="<?= $d['id'] ?>"
                            <?= $sel('designation_id', $d['id']) ?>><?= h($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label>Reporting Manager</label>
                    <select name="manager_id">
                        <option value="">— None —</option>
                        <?php foreach ($managers as $m): ?>
                        <option value="<?= $m['id'] ?>"
                            <?= $sel('manager_id', $m['id']) ?>>
                            <?= h($m['name']) ?> (<?= h($m['employee_id']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label>Join Date</label>
                    <input type="date" name="join_date" value="<?= $v('join_date') ?>">
                </div>

                <div class="field">
                    <label>Employment Type</label>
                    <select name="employment_type">
                        <?php foreach (['Full Time', 'Part Time', 'Contract', 'Intern'] as $t): ?>
                        <option value="<?= $t ?>" <?= $sel('employment_type', $t) ?>><?= $t ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label>System Role</label>
                    <select name="role_id">
                        <option value="">— No Change —</option>
                        <?php foreach ($roles as $r): ?>
                        <option value="<?= $r['id'] ?>"
                            <?= $currentRoleId == $r['id'] ? 'selected' : '' ?>>
                            <?= h($r['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

            </div>
        </div>
    </div>

    <!-- ── Bank & Statutory Details ────────────────────────────────────────── -->
    <div class="card">
        <div class="card-head"><h3>Bank &amp; Statutory Details</h3></div>
        <div class="card-body">
            <div class="form-grid">

                <div class="field">
                    <label>Bank Name</label>
                    <input type="text" name="bank_name" value="<?= $v('bank_name') ?>">
                </div>

                <div class="field">
                    <label>Account Number</label>
                    <input type="text" name="bank_account" value="<?= $v('bank_account') ?>">
                </div>

                <div class="field">
                    <label>IFSC Code</label>
                    <input type="text" name="bank_ifsc" value="<?= $v('bank_ifsc') ?>"
                           style="text-transform:uppercase">
                </div>

                <div class="field">
                    <label>PAN Number</label>
                    <input type="text" name="pan_number" maxlength="10"
                           value="<?= $v('pan_number') ?>" style="text-transform:uppercase">
                </div>

                <div class="field">
                    <label>Aadhaar Number</label>
                    <input type="text" name="aadhaar_number" maxlength="12"
                           value="<?= $v('aadhaar_number') ?>">
                </div>

                <div class="field">
                    <label>UAN Number</label>
                    <input type="text" name="uan_number" value="<?= $v('uan_number') ?>">
                </div>

                <div class="field">
                    <label>ESIC Number</label>
                    <input type="text" name="esic_number" value="<?= $v('esic_number') ?>">
                </div>

            </div>
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save Changes</button>
        <a href="view.php?id=<?= $id ?>" class="btn btn-ghost">Cancel</a>
    </div>

</form>

<script>
document.getElementById('photoInput').addEventListener('change', function () {
    var file = this.files[0];
    if (!file || !file.type.startsWith('image/')) return;
    var reader = new FileReader();
    reader.onload = function (e) {
        var prev = document.getElementById('photoPreview');
        if (prev) { prev.src = e.target.result; prev.style.cssText += ';border-radius:50%;object-fit:cover'; }
    };
    reader.readAsDataURL(file);
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
