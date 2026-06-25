<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('bonuses', 'create');
verify_csrf($_POST['csrf_token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . '/modules/employee/index.php');

$db     = db();
$user   = current_user();
$emp_id = (int)($_POST['emp_id'] ?? 0);
$id     = (int)($_POST['id'] ?? 0);
$return = ($_POST['return'] ?? '') === 'profile';

$db->exec('CREATE TABLE IF NOT EXISTS employee_bonuses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    type ENUM(\'monthly_bonus\',\'performance\',\'festival\',\'overtime\',\'one_time\') NOT NULL DEFAULT \'monthly_bonus\',
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    reason VARCHAR(255) NOT NULL,
    remarks TEXT NULL,
    payroll_month TINYINT NOT NULL,
    payroll_year SMALLINT NOT NULL,
    added_by INT NULL,
    status ENUM(\'pending\',\'approved\',\'rejected\') NOT NULL DEFAULT \'approved\',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$bonusTypes = ['monthly_bonus','performance','festival','overtime','one_time'];

$errors = [];
$type          = in_array($_POST['type'] ?? '', $bonusTypes, true) ? $_POST['type'] : 'monthly_bonus';
$amount        = (float)($_POST['amount'] ?? 0);
$reason        = trim($_POST['reason'] ?? '');
$remarks       = trim($_POST['remarks'] ?? '') ?: null;
$payroll_month = (int)($_POST['payroll_month'] ?? 0);
$payroll_year  = (int)($_POST['payroll_year'] ?? 0);
$status        = in_array($_POST['status'] ?? '', ['pending','approved','rejected'], true) ? $_POST['status'] : 'approved';

if (!$emp_id)                                  $errors[] = 'Invalid employee.';
if ($amount <= 0)                              $errors[] = 'Amount must be greater than zero.';
if ($reason === '')                            $errors[] = 'Reason is required.';
if (mb_strlen($reason) > 255)                  $errors[] = 'Reason must be 255 characters or fewer.';
if ($payroll_month < 1 || $payroll_month > 12) $errors[] = 'Invalid payroll month.';
if ($payroll_year < 2000 || $payroll_year > 2099) $errors[] = 'Invalid payroll year.';
// No salary → no bonus (bonuses are paid through the salary slip).
if ($emp_id && !$id && !employee_has_salary($emp_id))
    $errors[] = 'This employee has no salary configured. Set their salary before adding a bonus / incentive.';

if ($errors) {
    $_SESSION['errors']   = $errors;
    $_SESSION['form_old'] = $_POST;
    redirect(BASE_URL . '/modules/bonuses/create.php?' . ($id ? 'id=' . $id : 'emp_id=' . $emp_id));
}

$typeLabel = ucwords(str_replace('_', ' ', $type));

if ($id) {
    $db->prepare('UPDATE employee_bonuses SET type=?, amount=?, reason=?, remarks=?, payroll_month=?, payroll_year=?, status=? WHERE id=?')
       ->execute([$type, $amount, $reason, $remarks, $payroll_month, $payroll_year, $status, $id]);
    activity_log('updated', 'Bonus', 'Updated ' . $typeLabel . ' for ' . activity_emp_label($emp_id)
        . ': ₹' . number_format($amount, 2) . ($reason ? ' — ' . $reason : '') . ' (' . ucfirst($status) . ')');
    flash('success', 'Bonus / Incentive updated successfully.');
} else {
    $db->prepare('INSERT INTO employee_bonuses (employee_id,type,amount,reason,remarks,payroll_month,payroll_year,added_by,status) VALUES (?,?,?,?,?,?,?,?,?)')
       ->execute([$emp_id, $type, $amount, $reason, $remarks, $payroll_month, $payroll_year, (int)($user['id'] ?? 0) ?: null, $status]);
    activity_log('created', 'Bonus', 'Added ' . $typeLabel . ' of ₹' . number_format($amount, 2) . ' for ' . activity_emp_label($emp_id)
        . ($reason ? ' — ' . $reason : '') . ' (Status: ' . ucfirst($status) . ')');
    flash('success', 'Bonus / Incentive added.');
}

if ($return) redirect(BASE_URL . '/modules/employee/view.php?id=' . $emp_id . '#bonuses');
redirect(BASE_URL . '/modules/bonuses/index.php');
