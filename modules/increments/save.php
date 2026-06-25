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
// No base salary → no increment (an increment revises an existing salary).
if ($emp_id && !$id && !employee_has_salary($emp_id))
    $errors[] = 'This employee has no salary configured. Set their base salary on the employee profile before recording an increment.';

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

    // Auto-generate the matching Increment letter (Draft). Content mirrors the
    // manual letter template (modules/letters/create.php) so includes/increment_letter.php
    // parses the figures identically. Non-fatal: a letter failure never blocks the increment.
    try {
        $en = $db->prepare('SELECT name FROM employees WHERE id = ?');
        $en->execute([$emp_id]);
        $empName = (string)($en->fetchColumn() ?: '');

        $effFmt    = date('d F Y', strtotime($effective_date));
        $pctStr    = rtrim(rtrim(number_format($increment_percentage, 2), '0'), '.');
        $letterContent = "Dear {$empName},\n\n"
            . "We are pleased to inform you that the management has decided to revise your salary, with effect from {$effFmt}. This reflects your outstanding contribution to the organization.\n\n"
            . 'Previous Monthly Gross: ₹ ' . number_format($previous_salary, 2) . "\n"
            . 'Revised Monthly Gross: ₹ ' . number_format($new_salary, 2) . "\n"
            . "Increment: {$pctStr}%\n\n"
            . 'We appreciate your dedication and expect continued excellence in your work. Congratulations on this well-deserved recognition.';

        $cnt = $db->query('SELECT COUNT(*)+1 FROM letters')->fetchColumn();
        $ref = 'HR/I/' . date('Y') . '/' . str_pad((string)$cnt, 4, '0', STR_PAD_LEFT);

        $db->prepare('INSERT INTO letters (employee_id,type,issued_date,reference,content,issued_by,status) VALUES (?,?,?,?,?,?,?)')
           ->execute([$emp_id, 'Increment', $effective_date, $ref, $letterContent, (int)(current_user()['id'] ?? 0) ?: null, 'Draft']);

        if (function_exists('activity_log')) {
            activity_log('created', 'Letter', 'Auto-generated Increment letter ' . $ref . ' for ' . activity_emp_label($emp_id));
        }
    } catch (Throwable $e) {
        error_log('Auto increment-letter generation failed: ' . $e->getMessage());
    }

    flash('success', ($isFuture
        ? 'Increment scheduled for ' . date('d M Y', strtotime($effective_date)) . '. Employee salary will apply from that date during payroll generation.'
        : 'Increment recorded and salary updated to ' . money($new_salary) . '.')
        . ' An Increment letter draft was created automatically.');
}

// Reflect the increment in the employee's current CTC / salary structure
// (only increments effective on/before today change the live salary).
sync_current_salary_from_increments($db, $emp_id);

if ($return) redirect(BASE_URL . '/modules/employee/view.php?id=' . $emp_id . '#increments');
redirect(BASE_URL . '/modules/increments/index.php');
