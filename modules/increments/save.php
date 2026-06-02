<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('employee');
verify_csrf($_POST['csrf_token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . '/modules/employee/index.php');

$db     = db();
$emp_id = (int)($_POST['emp_id'] ?? 0);

$db->exec('CREATE TABLE IF NOT EXISTS employee_increments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    effective_date DATE NOT NULL,
    previous_salary DECIMAL(10,2) NOT NULL DEFAULT 0,
    new_salary DECIMAL(10,2) NOT NULL DEFAULT 0,
    remarks TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$errors = [];
$effective_date  = trim($_POST['effective_date'] ?? '');
$previous_salary = (float)($_POST['previous_salary'] ?? 0);
$new_salary      = (float)($_POST['new_salary'] ?? 0);
$remarks         = trim($_POST['remarks'] ?? '') ?: null;

if (!$effective_date)    $errors[] = 'Effective Date is required.';
if ($previous_salary < 0) $errors[] = 'Previous Salary must be a positive number.';
if ($new_salary <= 0)    $errors[] = 'New Salary must be greater than zero.';
if (!$emp_id)            $errors[] = 'Invalid employee.';

if ($errors) {
    $_SESSION['errors']   = $errors;
    $_SESSION['form_old'] = $_POST;
    redirect(BASE_URL . '/modules/increments/create.php?emp_id=' . $emp_id);
}

$db->prepare('INSERT INTO employee_increments (employee_id,effective_date,previous_salary,new_salary,remarks) VALUES (?,?,?,?,?)')
   ->execute([$emp_id, $effective_date, $previous_salary, $new_salary, $remarks]);

flash('success', 'Increment recorded successfully.');
redirect(BASE_URL . '/modules/employee/view.php?id=' . $emp_id . '#increments');
