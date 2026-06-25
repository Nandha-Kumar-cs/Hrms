<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('loans', 'create');
verify_csrf($_POST['csrf_token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . '/modules/employee/index.php');

$db     = db();
$emp_id = (int)($_POST['emp_id'] ?? 0);
$id     = (int)($_POST['id'] ?? 0);          // >0 = editing an existing record
$return = ($_POST['return'] ?? '') === 'profile';

$db->exec('CREATE TABLE IF NOT EXISTS employee_loans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    type ENUM(\'loan\',\'advance\') DEFAULT \'loan\',
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    interest_rate DECIMAL(5,2) DEFAULT 0,
    date_given DATE NOT NULL,
    monthly_deduction DECIMAL(10,2) DEFAULT 0,
    total_months INT UNSIGNED NOT NULL DEFAULT 1,
    paid_months INT UNSIGNED NOT NULL DEFAULT 0,
    returned_amount DECIMAL(10,2) DEFAULT 0,
    status ENUM(\'active\',\'closed\',\'completed\') DEFAULT \'active\',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$errors = [];
$type              = in_array($_POST['type'] ?? '', ['loan','advance']) ? $_POST['type'] : 'loan';
$amount            = (float)($_POST['amount'] ?? 0);
$interest_rate     = (float)($_POST['interest_rate'] ?? 0);
$date_given        = trim($_POST['date_given'] ?? '');
$monthly_deduction = (float)($_POST['monthly_deduction'] ?? 0);
$total_months      = (int)($_POST['total_months'] ?? 0);
$status            = in_array($_POST['status'] ?? '', ['active','closed','completed']) ? $_POST['status'] : 'active';
$notes             = trim($_POST['notes'] ?? '') ?: null;

if ($amount <= 0)             $errors[] = 'Principal Amount must be greater than zero.';
if (!$date_given)             $errors[] = 'Date Given is required.';
if ($total_months < 1)        $errors[] = 'Total Months must be at least 1.';
if ($monthly_deduction <= 0)  $errors[] = 'Monthly Deduction (EMI) must be greater than zero.';
if ($interest_rate < 0 || $interest_rate > 100) $errors[] = 'Interest Rate must be between 0 and 100.';
if (!$emp_id)                 $errors[] = 'Invalid employee.';
// No salary → no loan (deductions are computed from the employee's salary).
if ($emp_id && !$id && !employee_has_salary($emp_id))
    $errors[] = 'This employee has no salary configured. Set their salary before creating a loan / advance.';

if ($errors) {
    $_SESSION['errors']   = $errors;
    $_SESSION['form_old'] = $_POST;
    redirect(BASE_URL . '/modules/loans/create.php?' . ($id ? 'id=' . $id : 'emp_id=' . $emp_id));
}

if ($id) {
    $db->prepare('UPDATE employee_loans SET type=?, amount=?, interest_rate=?, date_given=?, monthly_deduction=?, total_months=?, status=?, notes=? WHERE id=?')
       ->execute([$type, $amount, $interest_rate, $date_given, $monthly_deduction, $total_months, $status, $notes, $id]);
    if (function_exists('activity_log')) activity_log('updated', 'Loan', 'Updated ' . ucfirst($type) . ' of ' . money($amount) . ' — EMI ' . money($monthly_deduction) . ' × ' . $total_months . ' months');
    flash('success', ucfirst($type) . ' updated successfully.');
} else {
    $db->prepare('INSERT INTO employee_loans (employee_id,type,amount,interest_rate,date_given,monthly_deduction,total_months,status,notes) VALUES (?,?,?,?,?,?,?,?,?)')
       ->execute([$emp_id, $type, $amount, $interest_rate, $date_given, $monthly_deduction, $total_months, $status, $notes]);
    if (function_exists('activity_log')) activity_log('created', 'Loan', 'Added ' . ucfirst($type) . ' of ' . money($amount) . ' — EMI ' . money($monthly_deduction) . ' × ' . $total_months . ' months');
    flash('success', ucfirst($type) . ' recorded successfully.');
}

if ($return) redirect(BASE_URL . '/modules/employee/view.php?id=' . $emp_id . '#loans');
redirect(BASE_URL . '/modules/loans/index.php');
