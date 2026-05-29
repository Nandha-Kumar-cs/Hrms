<?php
$page_title = 'New Employee';
require_once __DIR__ . '/../../includes/header.php';
require_permission('employee', 'create');

$db      = db();
$depts   = $db->query('SELECT * FROM departments ORDER BY name')->fetchAll();
$designs = $db->query('SELECT * FROM designations ORDER BY name')->fetchAll();
$managers= $db->query('SELECT id, name, employee_id FROM employees WHERE status="Active" ORDER BY name')->fetchAll();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { $errors[] = 'Invalid request.'; }
    else {
        $emp_id  = generate_employee_id();
        $name    = sanitize($_POST['name'] ?? '');
        $email   = sanitize($_POST['email'] ?? '');
        $phone   = sanitize($_POST['phone'] ?? '');
        $dob     = sanitize($_POST['dob'] ?? '');
        $gender  = sanitize($_POST['gender'] ?? '');
        $dept_id = (int)($_POST['department_id'] ?? 0);
        $des_id  = (int)($_POST['designation_id'] ?? 0);
        $mgr_id  = (int)($_POST['manager_id'] ?? 0) ?: null;
        $jdate   = sanitize($_POST['join_date'] ?? '');
        $etype   = sanitize($_POST['employment_type'] ?? 'Full Time');
        $addr    = sanitize($_POST['address'] ?? '');
        $city    = sanitize($_POST['city'] ?? '');
        $state   = sanitize($_POST['state'] ?? '');
        $pin     = sanitize($_POST['pincode'] ?? '');
        $bank    = sanitize($_POST['bank_name'] ?? '');
        $bacc    = sanitize($_POST['bank_account'] ?? '');
        $bifsc   = sanitize($_POST['bank_ifsc'] ?? '');
        $pan     = strtoupper(sanitize($_POST['pan_number'] ?? ''));
        $aadh    = sanitize($_POST['aadhaar_number'] ?? '');
        $uan     = sanitize($_POST['uan_number'] ?? '');
        $ename   = sanitize($_POST['emergency_name'] ?? '');
        $ephone  = sanitize($_POST['emergency_phone'] ?? '');

        if (!$name)  $errors[] = 'Name is required.';
        if (!$email) $errors[] = 'Email is required.';
        if (!$jdate) $errors[] = 'Join date is required.';

        if (!$errors) {
            // Handle photo upload
            $photo = null;
            if (!empty($_FILES['photo']['name'])) {
                $photo = upload_file($_FILES['photo'], UPLOAD_PATH . '/photos', 'emp_');
            }
            $stmt = $db->prepare(
                'INSERT INTO employees (employee_id,name,email,phone,dob,gender,address,city,state,pincode,
                 department_id,designation_id,manager_id,join_date,employment_type,
                 bank_name,bank_account,bank_ifsc,pan_number,aadhaar_number,uan_number,
                 emergency_name,emergency_phone,photo,status)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,"Active")'
            );
            $stmt->execute([
                $emp_id,$name,$email,$phone,$dob,$gender,$addr,$city,$state,$pin,
                $dept_id,$des_id,$mgr_id,$jdate,$etype,
                $bank,$bacc,$bifsc,$pan,$aadh,$uan,
                $ename,$ephone,$photo
            ]);
            $new_id = $db->lastInsertId();

            // Create user account
            $tmp_pass = password_hash('Hrms@' . substr($emp_id,-4), PASSWORD_DEFAULT);
            $ucheck   = $db->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
            $ucheck->execute([$email]);
            if (!$ucheck->fetchColumn()) {
                $db->prepare('INSERT INTO users (email,name,password_hash,role_id,employee_id) VALUES(?,?,?,6,?)')
                   ->execute([$email,$name,$tmp_pass,$new_id]);
            }

            flash('success', 'Employee ' . $name . ' (' . $emp_id . ') created successfully.');
            redirect(BASE_URL . '/modules/employee/view.php?id=' . $new_id);
        }
    }
}
?>

<div class="page-head">
    <div>
        <h1>New Employee</h1>
        <p class="muted">Add a new employee record</p>
    </div>
    <div class="head-actions">
        <a href="index.php" class="btn btn-ghost" accesskey="b" data-shortcut data-key="B"><u>B</u>ack</a>
    </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-error"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data">
<?= csrf_field() ?>
<div class="card form-card">
    <div class="section-title">Personal Information</div>
    <div class="form-grid">
        <div class="field span-2">
            <label>Full <u>N</u>ame *</label>
            <input type="text" name="name" accesskey="n" required value="<?= h($_POST['name']??'') ?>" placeholder="John Doe">
        </div>
        <div class="field">
            <label>Photo</label>
            <input type="file" name="photo" accept="image/*">
        </div>
        <div class="field">
            <label><u>E</u>mail *</label>
            <input type="email" name="email" accesskey="e" required value="<?= h($_POST['email']??'') ?>" placeholder="john@company.com">
        </div>
        <div class="field">
            <label><u>P</u>hone</label>
            <input type="tel" name="phone" accesskey="p" value="<?= h($_POST['phone']??'') ?>" placeholder="+91 98765 43210">
        </div>
        <div class="field">
            <label>Date of Birth</label>
            <input type="date" name="dob" value="<?= h($_POST['dob']??'') ?>">
        </div>
        <div class="field">
            <label>Gender</label>
            <select name="gender">
                <option value="">— Select —</option>
                <?php foreach (['Male','Female','Other','Prefer not to say'] as $g): ?>
                <option <?= ($_POST['gender']??'')===$g?'selected':'' ?>><?= $g ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field span-3">
            <label>Address</label>
            <textarea name="address" rows="2"><?= h($_POST['address']??'') ?></textarea>
        </div>
        <div class="field">
            <label>City</label>
            <input type="text" name="city" value="<?= h($_POST['city']??'') ?>">
        </div>
        <div class="field">
            <label>State</label>
            <input type="text" name="state" value="<?= h($_POST['state']??'') ?>">
        </div>
        <div class="field">
            <label>Pincode</label>
            <input type="text" name="pincode" maxlength="10" value="<?= h($_POST['pincode']??'') ?>">
        </div>
    </div>

    <div class="section-title">Employment Details</div>
    <div class="form-grid">
        <div class="field">
            <label><u>D</u>epartment</label>
            <select name="department_id" accesskey="d">
                <option value="">— Select —</option>
                <?php foreach ($depts as $d): ?>
                <option value="<?= $d['id'] ?>" <?= ($_POST['department_id']??'')==$d['id']?'selected':'' ?>><?= h($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Designation</label>
            <select name="designation_id">
                <option value="">— Select —</option>
                <?php foreach ($designs as $d): ?>
                <option value="<?= $d['id'] ?>" <?= ($_POST['designation_id']??'')==$d['id']?'selected':'' ?>><?= h($d['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Reporting Manager</label>
            <select name="manager_id">
                <option value="">— None —</option>
                <?php foreach ($managers as $m): ?>
                <option value="<?= $m['id'] ?>"><?= h($m['name']) ?> (<?= h($m['employee_id']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Join Date *</label>
            <input type="date" name="join_date" required value="<?= h($_POST['join_date']??'') ?>">
        </div>
        <div class="field">
            <label>Employment Type</label>
            <select name="employment_type">
                <?php foreach (['Full Time','Part Time','Contract','Intern'] as $t): ?>
                <option <?= ($_POST['employment_type']??'Full Time')===$t?'selected':'' ?>><?= $t ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>Emergency Contact</label>
            <input type="text" name="emergency_name" value="<?= h($_POST['emergency_name']??'') ?>" placeholder="Name">
        </div>
        <div class="field">
            <label>Emergency Phone</label>
            <input type="tel" name="emergency_phone" value="<?= h($_POST['emergency_phone']??'') ?>">
        </div>
    </div>

    <div class="section-title">Bank & Statutory Details</div>
    <div class="form-grid">
        <div class="field">
            <label>Bank Name</label>
            <input type="text" name="bank_name" value="<?= h($_POST['bank_name']??'') ?>">
        </div>
        <div class="field">
            <label>Account Number</label>
            <input type="text" name="bank_account" value="<?= h($_POST['bank_account']??'') ?>">
        </div>
        <div class="field">
            <label>IFSC Code</label>
            <input type="text" name="bank_ifsc" value="<?= h($_POST['bank_ifsc']??'') ?>">
        </div>
        <div class="field">
            <label>PAN Number</label>
            <input type="text" name="pan_number" maxlength="10" style="text-transform:uppercase" value="<?= h($_POST['pan_number']??'') ?>">
        </div>
        <div class="field">
            <label>Aadhaar Number</label>
            <input type="text" name="aadhaar_number" maxlength="12" value="<?= h($_POST['aadhaar_number']??'') ?>">
        </div>
        <div class="field">
            <label>UAN Number</label>
            <input type="text" name="uan_number" value="<?= h($_POST['uan_number']??'') ?>">
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary" accesskey="s" data-shortcut data-key="S">
            ✓ <u>S</u>ave Employee
        </button>
        <a href="index.php" class="btn btn-ghost">Cancel</a>
    </div>
</div>
</form>

<script>
window.BASE_URL = '<?= BASE_URL ?>';
window.PAGE_SHORTCUTS = {
    's': () => document.querySelector('form').submit(),
    'b': () => window.location.href = '<?= BASE_URL ?>/modules/employee/index.php'
};
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
