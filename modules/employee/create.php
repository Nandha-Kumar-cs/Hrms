<?php
/**
 * Employee write handler — add / delete
 *
 * ── Why the FK error happened ────────────────────────────────────────────────
 * The old code did:  $dept_id = (int)($_POST['department_id'] ?? 0);
 * When the user left the "— Select —" option chosen, $_POST['department_id']
 * was "" which cast to 0. MySQL then tried to find departments.id = 0, which
 * does not exist → SQLSTATE[23000] FK constraint violation.
 *
 * The fix (line marked ★ below): treat 0 / empty as NULL.
 * departments.id is UNSIGNED, so 0 is never a valid PK. NULL is allowed
 * by the schema (department_id has no NOT NULL constraint) but we also
 * validate that a real department was chosen before touching the DB.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('employee', 'create');

// ── CSRF ─────────────────────────────────────────────────────────────────────
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

    // Soft-check: don't delete if they have unprocessed payroll runs
    $hasPayroll = $db->prepare(
        "SELECT 1 FROM salary_slips WHERE employee_id = ? LIMIT 1"
    );
    $hasPayroll->execute([$id]);
    if ($hasPayroll->fetchColumn()) {
        flash('error', 'Cannot delete an employee who has salary slips. Terminate them instead.');
        redirect(BASE_URL . '/modules/employee/index.php');
    }

    // Delete associated user account first (FK)
    $db->prepare("DELETE FROM users WHERE employee_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM employees WHERE id = ?")->execute([$id]);

    flash('success', 'Employee deleted.');
    redirect(BASE_URL . '/modules/employee/index.php');
}

// ============================================================================
// ADD — input collection
// ============================================================================
if ($action !== 'add') {
    flash('error', 'Unknown action.');
    redirect(BASE_URL . '/modules/employee/index.php');
}

// ── Collect & sanitise POST values ───────────────────────────────────────────
$name   = sanitize($_POST['name']  ?? '');
$email  = sanitize($_POST['email'] ?? '');
$phone  = sanitize($_POST['phone'] ?? '');
$dob    = sanitize($_POST['dob']   ?? '');
$gender = sanitize($_POST['gender'] ?? '');
$addr   = sanitize($_POST['address'] ?? '');
$city   = sanitize($_POST['city']    ?? '');
$state  = sanitize($_POST['state']   ?? '');
$pin    = sanitize($_POST['pincode'] ?? '');

$jdate = sanitize($_POST['join_date'] ?? '');
$etype = sanitize($_POST['employment_type'] ?? 'Full Time');
$ename = sanitize($_POST['emergency_name']  ?? '');
$ephone= sanitize($_POST['emergency_phone'] ?? '');

$bank  = sanitize($_POST['bank_name']    ?? '');
$bacc  = sanitize($_POST['bank_account'] ?? '');
$bifsc = sanitize($_POST['bank_ifsc']    ?? '');
$pan   = strtoupper(sanitize($_POST['pan_number']     ?? ''));
$aadh  = sanitize($_POST['aadhaar_number'] ?? '');
$uan   = sanitize($_POST['uan_number']     ?? '');

// ★ FK FIX: cast to int then turn 0 → NULL so the FK check is satisfied.
//   An empty <select> posts "" → (int)"" = 0 → stored as NULL (allowed).
//   A real choice posts "3"  → (int)"3" = 3 → stored as 3 (valid FK).
$dept_id = (int)($_POST['department_id']  ?? 0) ?: null;
$des_id  = (int)($_POST['designation_id'] ?? 0) ?: null;
$mgr_id  = (int)($_POST['manager_id']     ?? 0) ?: null;

// ── Validation ───────────────────────────────────────────────────────────────
$errors = [];

if ($name === '')  $errors[] = 'Full name is required.';
if ($email === '') $errors[] = 'Email address is required.';
if ($jdate === '') $errors[] = 'Join date is required.';

// Department is required — a NULL dept_id means the user left it unselected
if ($dept_id === null) {
    $errors[] = 'Please select a department.';
} else {
    // Verify the submitted ID actually exists (guards against tampered POSTs)
    $deptCheck = $db->prepare('SELECT 1 FROM departments WHERE id = ? LIMIT 1');
    $deptCheck->execute([$dept_id]);
    if (!$deptCheck->fetchColumn()) {
        $errors[] = 'Selected department is invalid. Please choose one from the list.';
        $dept_id  = null;
    }
}

// Designation optional, but verify if submitted
if ($des_id !== null) {
    $desCheck = $db->prepare('SELECT 1 FROM designations WHERE id = ? LIMIT 1');
    $desCheck->execute([$des_id]);
    if (!$desCheck->fetchColumn()) {
        $errors[] = 'Selected designation is invalid.';
        $des_id = null;
    }
}

// Email uniqueness
if ($email) {
    $emailCheck = $db->prepare('SELECT 1 FROM employees WHERE email = ? LIMIT 1');
    $emailCheck->execute([$email]);
    if ($emailCheck->fetchColumn()) {
        $errors[] = "An employee with email '$email' already exists.";
    }
}

// Employment type whitelist
$validTypes = ['Full Time', 'Part Time', 'Contract', 'Intern'];
if (!in_array($etype, $validTypes, true)) $etype = 'Full Time';

if ($errors) {
    $_SESSION['errors']   = $errors;
    $_SESSION['form_old'] = $_POST;
    redirect(BASE_URL . '/modules/employee/add_form.php');
}

// ── Photo upload ──────────────────────────────────────────────────────────────
$photo = null;
if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
    $photo = upload_file($_FILES['photo'], UPLOAD_PATH . '/photos', 'emp_');
    if ($photo === null) {
        // upload_file returns null if type/size not allowed — non-fatal
        flash('warn', 'Photo could not be saved (unsupported type or size). Employee was created without a photo.');
    }
}

// ── Generate employee ID ──────────────────────────────────────────────────────
$emp_code = generate_employee_id();

// ── INSERT using named PDO placeholders ──────────────────────────────────────
//   Named placeholders (:department_id, :designation_id, etc.) make the
//   mapping explicit — no accidental column/value position mismatch.
$stmt = $db->prepare(
    "INSERT INTO employees
        (employee_id, name, email, phone, dob, gender,
         address, city, state, pincode,
         department_id, designation_id, manager_id,
         join_date, employment_type,
         bank_name, bank_account, bank_ifsc,
         pan_number, aadhaar_number, uan_number,
         emergency_name, emergency_phone,
         photo, status, created_at)
     VALUES
        (:employee_id, :name, :email, :phone, :dob, :gender,
         :address, :city, :state, :pincode,
         :department_id, :designation_id, :manager_id,
         :join_date, :employment_type,
         :bank_name, :bank_account, :bank_ifsc,
         :pan_number, :aadhaar_number, :uan_number,
         :emergency_name, :emergency_phone,
         :photo, 'Active', NOW())"
);

$stmt->execute([
    ':employee_id'    => $emp_code,
    ':name'           => $name,
    ':email'          => $email,
    ':phone'          => $phone    ?: null,
    ':dob'            => $dob      ?: null,
    ':gender'         => $gender   ?: null,
    ':address'        => $addr     ?: null,
    ':city'           => $city     ?: null,
    ':state'          => $state    ?: null,
    ':pincode'        => $pin      ?: null,
    ':department_id'  => $dept_id,      // ★ NULL-safe — never 0
    ':designation_id' => $des_id,       // ★ NULL-safe
    ':manager_id'     => $mgr_id,       // ★ NULL-safe
    ':join_date'      => $jdate,
    ':employment_type'=> $etype,
    ':bank_name'      => $bank    ?: null,
    ':bank_account'   => $bacc    ?: null,
    ':bank_ifsc'      => $bifsc   ?: null,
    ':pan_number'     => $pan     ?: null,
    ':aadhaar_number' => $aadh    ?: null,
    ':uan_number'     => $uan     ?: null,
    ':emergency_name' => $ename   ?: null,
    ':emergency_phone'=> $ephone  ?: null,
    ':photo'          => $photo,
]);

$new_id = (int)$db->lastInsertId();

// ── Auto-create login account (role 6 = Employee) ────────────────────────────
$ucheck = $db->prepare('SELECT 1 FROM users WHERE email = ? LIMIT 1');
$ucheck->execute([$email]);
if (!$ucheck->fetchColumn()) {
    // Temporary password: Hrms@ + last 4 chars of employee code (e.g. Hrms@0012)
    $tmp_pass = password_hash('Hrms@' . substr($emp_code, -4), PASSWORD_BCRYPT);
    $db->prepare(
        'INSERT INTO users (email, name, password_hash, role_id, employee_id, is_active)
         VALUES (:email, :name, :pass, 6, :emp_id, 1)'
    )->execute([
        ':email'  => $email,
        ':name'   => $name,
        ':pass'   => $tmp_pass,
        ':emp_id' => $new_id,
    ]);
}

flash('success', "Employee $name ($emp_code) created successfully.");
redirect(BASE_URL . '/modules/employee/view.php?id=' . $new_id);
