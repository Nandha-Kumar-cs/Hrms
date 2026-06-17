<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('employee');
verify_csrf($_POST['csrf_token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . '/modules/employee/index.php');

$db     = db();
$emp_id = (int)($_POST['emp_id'] ?? 0);

$db->exec('CREATE TABLE IF NOT EXISTS employee_promotions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    effective_date DATE NOT NULL,
    previous_designation_id INT NULL,
    new_designation_id INT NOT NULL,
    department_id INT NULL,
    remarks TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$errors = [];
$effective_date          = trim($_POST['effective_date'] ?? '');
$previous_designation_id = (int)($_POST['previous_designation_id'] ?? 0) ?: null;
$new_designation_id      = (int)($_POST['new_designation_id'] ?? 0);
$department_id           = (int)($_POST['department_id'] ?? 0) ?: null;
$remarks                 = trim($_POST['remarks'] ?? '') ?: null;

if (!$effective_date)       $errors[] = 'Effective Date is required.';
if (!$new_designation_id)   $errors[] = 'New Designation is required.';
if (!$emp_id)               $errors[] = 'Invalid employee.';

if ($errors) {
    $_SESSION['errors']   = $errors;
    $_SESSION['form_old'] = $_POST;
    redirect(BASE_URL . '/modules/promotions/create.php?emp_id=' . $emp_id);
}

$db->prepare('INSERT INTO employee_promotions (employee_id,effective_date,previous_designation_id,new_designation_id,department_id,remarks) VALUES (?,?,?,?,?,?)')
   ->execute([$emp_id, $effective_date, $previous_designation_id, $new_designation_id, $department_id, $remarks]);

$desigName = function ($id) use ($db) {
    if (!$id) return '—';
    $s = $db->prepare('SELECT name FROM designations WHERE id = ?'); $s->execute([$id]);
    return $s->fetchColumn() ?: '—';
};
activity_log('created', 'Promotion', 'Promotion for ' . activity_emp_label($emp_id), [
    ['field' => 'Designation', 'from' => $desigName($previous_designation_id), 'to' => $desigName($new_designation_id)],
]);
flash('success', 'Promotion recorded successfully.');
redirect(BASE_URL . '/modules/employee/view.php?id=' . $emp_id . '#promotions');
