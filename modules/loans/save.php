<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('employee');
verify_csrf($_POST['csrf_token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . '/modules/employee/index.php');

$db     = db();
$emp_id = (int)($_POST['emp_id'] ?? 0);

$db->exec('CREATE TABLE IF NOT EXISTS employee_loans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    type ENUM(\'loan\',\'advance\') DEFAULT \'loan\',
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    interest_rate DECIMAL(5,2) DEFAULT 0,
    date_given DATE NOT NULL,
    monthly_deduction DECIMAL(10,2) DEFAULT 0,
    returned_amount DECIMAL(10,2) DEFAULT 0,
    status ENUM(\'active\',\'closed\') DEFAULT \'active\',
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
$status            = in_array($_POST['status'] ?? '', ['active','closed']) ? $_POST['status'] : 'active';
$notes             = trim($_POST['notes'] ?? '') ?: null;

if ($amount <= 0)          $errors[] = 'Principal Amount must be greater than zero.';
if (!$date_given)          $errors[] = 'Date Given is required.';
if ($monthly_deduction < 0) $errors[] = 'Monthly Deduction must be a positive number.';
if (!$emp_id)              $errors[] = 'Invalid employee.';

if ($errors) {
    $_SESSION['errors']   = $errors;
    $_SESSION['form_old'] = $_POST;
    redirect(BASE_URL . '/modules/loans/create.php?emp_id=' . $emp_id);
}

$db->prepare('INSERT INTO employee_loans (employee_id,type,amount,interest_rate,date_given,monthly_deduction,status,notes) VALUES (?,?,?,?,?,?,?,?)')
   ->execute([$emp_id, $type, $amount, $interest_rate, $date_given, $monthly_deduction, $status, $notes]);

flash('success', ucfirst($type) . ' recorded successfully.');
redirect(BASE_URL . '/modules/employee/view.php?id=' . $emp_id . '#loans');
