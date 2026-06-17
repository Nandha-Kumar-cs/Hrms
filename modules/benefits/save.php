<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('employee');
verify_csrf($_POST['csrf_token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . '/modules/employee/index.php');

$db     = db();
$emp_id = (int)($_POST['emp_id'] ?? 0);

$db->exec('CREATE TABLE IF NOT EXISTS employee_benefits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    fund_type VARCHAR(100) NOT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    effective_month DATE NOT NULL,
    status ENUM(\'active\',\'inactive\') DEFAULT \'active\',
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$errors = [];
$fund_type       = trim($_POST['fund_type'] ?? '');
$amount          = (float)($_POST['amount'] ?? 0);
$effective_month = trim($_POST['effective_month'] ?? '');
$status          = in_array($_POST['status'] ?? '', ['active','inactive']) ? $_POST['status'] : 'active';
$description     = trim($_POST['description'] ?? '') ?: null;

if (!$fund_type)       $errors[] = 'Fund Type is required.';
if ($amount <= 0)      $errors[] = 'Amount must be greater than zero.';
if (!$effective_month) $errors[] = 'Effective Month is required.';
if (!$emp_id)          $errors[] = 'Invalid employee.';

if ($errors) {
    $_SESSION['errors']   = $errors;
    $_SESSION['form_old'] = $_POST;
    redirect(BASE_URL . '/modules/benefits/create.php?emp_id=' . $emp_id);
}

$db->prepare('INSERT INTO employee_benefits (employee_id,fund_type,amount,effective_month,status,description) VALUES (?,?,?,?,?,?)')
   ->execute([$emp_id, $fund_type, $amount, $effective_month . '-01', $status, $description]);

activity_log('created', 'Benefit', 'Added benefit (' . $fund_type . ') for ' . activity_emp_label($emp_id)
    . ': ₹' . number_format($amount, 2) . ' (' . ucfirst($status) . ')');
flash('success', 'Benefit assigned successfully.');
redirect(BASE_URL . '/modules/employee/view.php?id=' . $emp_id . '#benefits');
