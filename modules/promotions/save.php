<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('promotions', 'create');
verify_csrf($_POST['csrf_token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . '/modules/employee/index.php');

$db     = db();
$emp_id = (int)($_POST['emp_id'] ?? 0);
$id     = (int)($_POST['id'] ?? 0);
$return = ($_POST['return'] ?? '') === 'profile';

// Single creation workflow: this endpoint only UPDATES existing promotion
// records. Creation goes through the promotion letter flow.
if (!$id) {
    redirect(BASE_URL . '/modules/letters/create.php?type=Promotion' . ($emp_id ? '&emp_id=' . $emp_id : ''));
}

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
    redirect(BASE_URL . '/modules/promotions/create.php?' . ($id ? 'id=' . $id : 'emp_id=' . $emp_id));
}

$desigName = function ($id) use ($db) {
    if (!$id) return '—';
    $s = $db->prepare('SELECT name FROM designations WHERE id = ?'); $s->execute([$id]);
    return $s->fetchColumn() ?: '—';
};

if ($id) {
    $db->prepare('UPDATE employee_promotions SET effective_date=?, previous_designation_id=?, new_designation_id=?, department_id=?, remarks=? WHERE id=?')
       ->execute([$effective_date, $previous_designation_id, $new_designation_id, $department_id, $remarks, $id]);
    activity_log('updated', 'Promotion', 'Updated promotion for ' . activity_emp_label($emp_id), [
        ['field' => 'Designation', 'from' => $desigName($previous_designation_id), 'to' => $desigName($new_designation_id)],
    ]);
    flash('success', 'Promotion updated successfully.');
} else {
    $db->prepare('INSERT INTO employee_promotions (employee_id,effective_date,previous_designation_id,new_designation_id,department_id,remarks) VALUES (?,?,?,?,?,?)')
       ->execute([$emp_id, $effective_date, $previous_designation_id, $new_designation_id, $department_id, $remarks]);

    // Apply the promotion to the employee profile: set the new designation, and
    // the new department if one was chosen (mirrors the reference project).
    if ($department_id) {
        $db->prepare('UPDATE employees SET designation_id = ?, department_id = ? WHERE id = ?')
           ->execute([$new_designation_id, $department_id, $emp_id]);
    } else {
        $db->prepare('UPDATE employees SET designation_id = ? WHERE id = ?')
           ->execute([$new_designation_id, $emp_id]);
    }

    activity_log('created', 'Promotion', 'Promoted ' . activity_emp_label($emp_id) . ': '
        . $desigName($previous_designation_id) . ' → ' . $desigName($new_designation_id)
        . ' — Effective ' . date('d M Y', strtotime($effective_date)), [
        ['field' => 'Designation', 'from' => $desigName($previous_designation_id), 'to' => $desigName($new_designation_id)],
    ]);
    flash('success', 'Promotion recorded and employee profile updated.');
}

if ($return) redirect(BASE_URL . '/modules/employee/view.php?id=' . $emp_id . '#promotions');
redirect(BASE_URL . '/modules/promotions/index.php');
