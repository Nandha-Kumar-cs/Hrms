<?php
/**
 * Employee write handler — add / delete
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('employee', 'create');

verify_csrf($_POST['csrf_token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL . '/modules/employee/index.php');
}

$action = sanitize($_POST['action'] ?? '');
$db     = db();

// ============================================================================
// DELETE
// ============================================================================
if ($action === 'delete') {
    require_permission('employee', 'delete');
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) {
        flash('error', 'Invalid employee ID.');
        redirect(BASE_URL . '/modules/employee/index.php');
    }

    $hasPayroll = $db->prepare(
        "SELECT 1 FROM salary_slips WHERE employee_id = ? LIMIT 1"
    );
    $hasPayroll->execute([$id]);
    if ($hasPayroll->fetchColumn()) {
        flash('error', 'Cannot delete an employee who has salary slips. Terminate them instead.');
        redirect(BASE_URL . '/modules/employee/index.php');
    }

    $db->prepare("DELETE FROM users WHERE employee_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM employees WHERE id = ?")->execute([$id]);

    flash('success', 'Employee deleted.');
    redirect(BASE_URL . '/modules/employee/index.php');
}

// ============================================================================
// ADD
// ============================================================================
if ($action !== 'add') {
    flash('error', 'Unknown action.');
    redirect(BASE_URL . '/modules/employee/index.php');
}

// ── Collect & sanitise POST values ───────────────────────────────────────────
$employee_code = strtoupper(sanitize($_POST['employee_code'] ?? ''));
$full_name     = sanitize($_POST['full_name']  ?? '');
$email         = sanitize($_POST['email']      ?? '');
$phone         = sanitize($_POST['phone']      ?? '');
$dob           = sanitize($_POST['dob']        ?? '');
$gender_raw    = sanitize($_POST['gender']     ?? '');

// Map Laravel lowercase values to HRMS ENUM (Male/Female/Other)
$gender_map = ['male' => 'Male', 'female' => 'Female', 'other' => 'Other'];
$gender     = $gender_map[$gender_raw] ?? null;

// Map Laravel status to HRMS ENUM
$status_map = ['active' => 'Active', 'inactive' => 'Inactive', 'on_leave' => 'On Leave'];
$status_raw = sanitize($_POST['status'] ?? 'active');
$status     = $status_map[$status_raw] ?? 'Active';

// FKs — cast to int, treat 0/empty as NULL
$entity_id  = (int)($_POST['entity_id']           ?? 0) ?: null;
$dept_id    = (int)($_POST['department_id']        ?? 0) ?: null;
$des_id     = (int)($_POST['designation_id']       ?? 0) ?: null;
$mgr_id     = (int)($_POST['reporting_manager_id'] ?? 0) ?: null;

$joining_date  = sanitize($_POST['joining_date']  ?? '');
$probation_end = sanitize($_POST['probation_end'] ?? '');

$fixed_salary    = (float)($_POST['fixed_salary']    ?? 0);
$variable_salary = (float)($_POST['variable_salary'] ?? 0);
$ot_enabled      = isset($_POST['ot_enabled']) ? 1 : 0;

$bank  = sanitize($_POST['bank_name']      ?? '');
$bacc  = sanitize($_POST['bank_account']   ?? '');
$bifsc = sanitize($_POST['bank_ifsc']      ?? '');
$pan   = strtoupper(sanitize($_POST['pan_number']     ?? ''));
$aadh  = sanitize($_POST['aadhaar_number'] ?? '');
$uan   = sanitize($_POST['uan_number']     ?? '');
$esic  = sanitize($_POST['esic_number']    ?? '');

// ── Validation ───────────────────────────────────────────────────────────────
$errors = [];

if ($employee_code === '') {
    $errors[] = 'Employee code is required.';
} else {
    $codeCheck = $db->prepare('SELECT 1 FROM employees WHERE employee_id = ? LIMIT 1');
    $codeCheck->execute([$employee_code]);
    if ($codeCheck->fetchColumn()) {
        $errors[] = "Employee code '$employee_code' is already in use.";
    }
}

if ($full_name === '') $errors[] = 'Full name is required.';

if ($email === '') {
    $errors[] = 'Email address is required.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Email address is not valid.';
} else {
    $emailCheck = $db->prepare('SELECT 1 FROM employees WHERE email = ? LIMIT 1');
    $emailCheck->execute([$email]);
    if ($emailCheck->fetchColumn()) {
        $errors[] = "An employee with email '$email' already exists.";
    }
}

if ($phone === '') $errors[] = 'Phone number is required.';

if ($fixed_salary < 0) $errors[] = 'CTC per month cannot be negative.';

// Verify entity if submitted
if ($entity_id !== null) {
    $entCheck = $db->prepare('SELECT 1 FROM entities WHERE id = ? LIMIT 1');
    $entCheck->execute([$entity_id]);
    if (!$entCheck->fetchColumn()) {
        $errors[] = 'Selected entity is invalid.';
        $entity_id = null;
    }
}

// Verify department if submitted
if ($dept_id !== null) {
    $deptCheck = $db->prepare('SELECT 1 FROM departments WHERE id = ? LIMIT 1');
    $deptCheck->execute([$dept_id]);
    if (!$deptCheck->fetchColumn()) {
        $errors[] = 'Selected department is invalid.';
        $dept_id  = null;
    }
}

// Verify designation if submitted
if ($des_id !== null) {
    $desCheck = $db->prepare('SELECT 1 FROM designations WHERE id = ? LIMIT 1');
    $desCheck->execute([$des_id]);
    if (!$desCheck->fetchColumn()) {
        $errors[] = 'Selected designation is invalid.';
        $des_id = null;
    }
}

// Verify manager if submitted
if ($mgr_id !== null) {
    $mgrCheck = $db->prepare("SELECT 1 FROM employees WHERE id = ? AND status = 'Active' LIMIT 1");
    $mgrCheck->execute([$mgr_id]);
    if (!$mgrCheck->fetchColumn()) {
        $errors[] = 'Selected reporting manager is invalid or inactive.';
        $mgr_id = null;
    }
}

// Validate gender whitelist
if ($gender_raw !== '' && !isset($gender_map[$gender_raw])) {
    $errors[] = 'Invalid gender value.';
    $gender   = null;
}

// Validate status whitelist
if (!isset($status_map[$status_raw])) {
    $status = 'Active';
}

if ($errors) {
    $_SESSION['errors']   = $errors;
    $_SESSION['form_old'] = $_POST;
    redirect(BASE_URL . '/modules/employee/add_form.php');
}

// ── Photo upload ──────────────────────────────────────────────────────────────
$photo = null;
if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
    $allowed_image_types = ['image/jpeg', 'image/png', 'image/webp'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($_FILES['photo']['tmp_name']);
    $size  = $_FILES['photo']['size'];

    if (!in_array($mime, $allowed_image_types)) {
        flash('warn', 'Photo must be JPG, PNG, or WebP. Employee was created without a photo.');
    } elseif ($size > 2 * 1024 * 1024) {
        flash('warn', 'Photo exceeds 2 MB limit. Employee was created without a photo.');
    } else {
        $ext    = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        $dest   = UPLOAD_PATH . '/photos';
        $fname  = 'emp_' . uniqid() . '.' . $ext;
        $fpath  = rtrim($dest, '/') . '/' . $fname;
        if (!is_dir($dest)) mkdir($dest, 0755, true);
        if (move_uploaded_file($_FILES['photo']['tmp_name'], $fpath)) {
            $photo = $fname;
        }
    }
}

// ── INSERT ────────────────────────────────────────────────────────────────────
$stmt = $db->prepare(
    "INSERT INTO employees
        (entity_id, employee_id, name, email, phone, dob, gender,
         department_id, designation_id, ot_enabled, manager_id,
         join_date, probation_end, status,
         fixed_salary, variable_salary,
         bank_name, bank_account, bank_ifsc,
         pan_number, aadhaar_number, uan_number, esic_number,
         photo, created_at)
     VALUES
        (:entity_id, :employee_id, :name, :email, :phone, :dob, :gender,
         :department_id, :designation_id, :ot_enabled, :manager_id,
         :join_date, :probation_end, :status,
         :fixed_salary, :variable_salary,
         :bank, :bacc, :bifsc,
         :pan, :aadh, :uan, :esic,
         :photo, NOW())"
);

$stmt->execute([
    ':entity_id'      => $entity_id,
    ':employee_id'    => $employee_code,
    ':name'           => $full_name,
    ':email'          => $email,
    ':phone'          => $phone         ?: null,
    ':dob'            => $dob           ?: null,
    ':gender'         => $gender,
    ':department_id'  => $dept_id,
    ':designation_id' => $des_id,
    ':ot_enabled'     => $ot_enabled,
    ':manager_id'     => $mgr_id,
    ':join_date'      => $joining_date  ?: null,
    ':probation_end'  => $probation_end ?: null,
    ':status'         => $status,
    ':fixed_salary'   => $fixed_salary,
    ':variable_salary'=> $variable_salary,
    ':bank'           => $bank  ?: null,
    ':bacc'           => $bacc  ?: null,
    ':bifsc'          => $bifsc ?: null,
    ':pan'            => $pan   ?: null,
    ':aadh'           => $aadh  ?: null,
    ':uan'            => $uan   ?: null,
    ':esic'           => $esic  ?: null,
    ':photo'          => $photo,
]);

$new_id = (int)$db->lastInsertId();

// ── Auto-create salary structure from CTC ────────────────────────────────────
// CTC breakdown: Basic 50%, HRA 20%, Conveyance 10%, Special Allowance 20%
if ($fixed_salary > 0) {
    $basic    = round($fixed_salary * 0.50, 2);
    $hra      = round($fixed_salary * 0.20, 2);
    $conv     = round($fixed_salary * 0.10, 2);
    $special  = round($fixed_salary - $basic - $hra - $conv, 2); // remaining
    $db->prepare(
        "INSERT INTO salary_structures
            (employee_id, basic, hra, conveyance, special_allow, effective_from, is_current, created_at)
         VALUES (:eid, :basic, :hra, :conv, :special, CURDATE(), 1, NOW())"
    )->execute([
        ':eid'     => $new_id,
        ':basic'   => $basic,
        ':hra'     => $hra,
        ':conv'    => $conv,
        ':special' => $special,
    ]);
}

// ── Auto-create login account (role 6 = Employee) ────────────────────────────
$ucheck = $db->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
$ucheck->execute([$email]);
if (!$ucheck->fetchColumn()) {
    $tmp_pass = password_hash('Hrms@' . substr($employee_code, -4), PASSWORD_BCRYPT);
    $db->prepare(
        'INSERT INTO users (email, name, password_hash, role_id, employee_id, is_active)
         VALUES (:email, :name, :pass, 6, :emp_id, 1)'
    )->execute([
        ':email'  => $email,
        ':name'   => $full_name,
        ':pass'   => $tmp_pass,
        ':emp_id' => $new_id,
    ]);
}

flash('success', "Employee $full_name ($employee_code) created successfully.");
redirect(BASE_URL . '/modules/employee/view.php?id=' . $new_id);
