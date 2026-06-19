<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('increments', 'create');
verify_csrf($_POST['csrf_token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . '/modules/employee/index.php');

$db     = db();
$emp_id = (int)($_POST['emp_id'] ?? 0);
$id     = (int)($_POST['id'] ?? 0);
$return = ($_POST['return'] ?? '') === 'profile';

$db->exec('CREATE TABLE IF NOT EXISTS employee_increments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    effective_date DATE NOT NULL,
    previous_salary DECIMAL(10,2) NOT NULL DEFAULT 0,
    new_salary DECIMAL(10,2) NOT NULL DEFAULT 0,
    increment_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    increment_percentage DECIMAL(5,2) NOT NULL DEFAULT 0,
    remarks TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$errors = [];
$effective_date  = trim($_POST['effective_date'] ?? '');
$previous_salary = (float)($_POST['previous_salary'] ?? 0);
$new_salary      = (float)($_POST['new_salary'] ?? 0);
$remarks         = trim($_POST['remarks'] ?? '') ?: null;

$sd = DateTime::createFromFormat('Y-m-d', $effective_date);
if (!$effective_date || !$sd || $sd->format('Y-m-d') !== $effective_date) $errors[] = 'A valid Effective Date is required.';
if ($new_salary <= 0)     $errors[] = 'New Salary must be greater than zero.';
if (!$emp_id)             $errors[] = 'Invalid employee.';

if ($errors) {
    $_SESSION['errors']   = $errors;
    $_SESSION['form_old'] = $_POST;
    redirect(BASE_URL . '/modules/increments/create.php?' . ($id ? 'id=' . $id : 'emp_id=' . $emp_id));
}

// On CREATE, snapshot the employee's CURRENT salary as previous_salary (immutable
// audit trail), mirroring the reference project — the form value is informational.
if (!$id) {
    $cs = $db->prepare('SELECT fixed_salary FROM employees WHERE id = ?');
    $cs->execute([$emp_id]);
    $cur = $cs->fetchColumn();
    if ($cur !== false && $cur !== null) $previous_salary = (float)$cur;
}

$increment_amount     = round($new_salary - $previous_salary, 2);
$increment_percentage = $previous_salary > 0 ? round(($new_salary - $previous_salary) / $previous_salary * 100, 2) : 0;
$isFuture             = $effective_date > date('Y-m-d');

if ($id) {
    $db->prepare('UPDATE employee_increments SET effective_date=?, previous_salary=?, new_salary=?, increment_amount=?, increment_percentage=?, remarks=? WHERE id=?')
       ->execute([$effective_date, $previous_salary, $new_salary, $increment_amount, $increment_percentage, $remarks, $id]);
    activity_log('updated', 'Increment', 'Updated salary increment for ' . activity_emp_label($emp_id), [
        ['field' => 'Salary', 'from' => '₹' . number_format((float)$previous_salary, 2), 'to' => '₹' . number_format((float)$new_salary, 2)],
    ]);
    flash('success', 'Increment updated. Old salary slips are unaffected.');
} else {
    $db->prepare('INSERT INTO employee_increments (employee_id,effective_date,previous_salary,new_salary,increment_amount,increment_percentage,remarks) VALUES (?,?,?,?,?,?,?)')
       ->execute([$emp_id, $effective_date, $previous_salary, $new_salary, $increment_amount, $increment_percentage, $remarks]);
    activity_log('created', 'Increment', 'Salary increment for ' . activity_emp_label($emp_id) . ': ₹' . number_format($previous_salary, 2) . ' → ₹' . number_format($new_salary, 2) . ' (' . $increment_percentage . '%) — Effective ' . date('d M Y', strtotime($effective_date)), [
        ['field' => 'Salary', 'from' => '₹' . number_format((float)$previous_salary, 2), 'to' => '₹' . number_format((float)$new_salary, 2)],
    ]);
    flash('success', $isFuture
        ? 'Increment scheduled for ' . date('d M Y', strtotime($effective_date)) . '. Employee salary will apply from that date during payroll generation.'
        : 'Increment recorded and salary updated to ' . money($new_salary) . '.');
}

// Reflect the increment in the employee's current CTC / salary structure
// (only increments effective on/before today change the live salary).
sync_current_salary_from_increments($db, $emp_id);

if ($return) redirect(BASE_URL . '/modules/employee/view.php?id=' . $emp_id . '#increments');
redirect(BASE_URL . '/modules/increments/index.php');
