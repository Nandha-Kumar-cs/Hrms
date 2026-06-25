<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('benefits', 'create');
verify_csrf($_POST['csrf_token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . '/modules/employee/index.php');

$db     = db();
$user   = current_user();
$emp_id = (int)($_POST['emp_id'] ?? 0);
$id     = (int)($_POST['id'] ?? 0);
$return = ($_POST['return'] ?? '') === 'profile';

$db->exec('CREATE TABLE IF NOT EXISTS employee_benefits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    benefit_fund_type_id INT NULL,
    frequency ENUM(\'weekly\',\'fortnightly\',\'monthly\',\'quarterly\',\'half_yearly\',\'annual\') NOT NULL DEFAULT \'monthly\',
    start_date DATE NULL,
    end_date DATE NULL,
    benefit_name VARCHAR(255) NULL,
    fund_type VARCHAR(100) NOT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    effective_month DATE NOT NULL,
    status ENUM(\'active\',\'inactive\') DEFAULT \'active\',
    payment_mode ENUM(\'cash\',\'cashless\') NOT NULL DEFAULT \'cash\',
    added_by INT NULL,
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$freqList     = ['weekly','fortnightly','monthly','quarterly','half_yearly','annual'];
$fundTypeId   = (int)($_POST['benefit_fund_type_id'] ?? 0);
$amount       = (float)($_POST['amount'] ?? 0);
$benefit_name = trim($_POST['benefit_name'] ?? '') ?: null;
$frequencyRaw = trim($_POST['frequency'] ?? '');
$start_date   = trim($_POST['start_date'] ?? '') ?: null;
$end_date     = trim($_POST['end_date'] ?? '') ?: null;
$effRaw       = trim($_POST['effective_month'] ?? '');
$status       = in_array($_POST['status'] ?? '', ['active','inactive'], true) ? $_POST['status'] : 'active';
$payment_mode = in_array($_POST['payment_mode'] ?? '', ['cash','cashless'], true) ? $_POST['payment_mode'] : 'cash';
$description  = trim($_POST['description'] ?? '') ?: null;

// Resolve the fund-type name (also cached in legacy fund_type for slips/reports).
$ftName = '';
if ($fundTypeId) {
    $ft = $db->prepare('SELECT name FROM benefit_fund_types WHERE id = ?');
    $ft->execute([$fundTypeId]);
    $ftName = (string)($ft->fetchColumn() ?: '');
}

$errors = [];
if (!$emp_id)                  $errors[] = 'Invalid employee.';
if (!$fundTypeId || !$ftName)  $errors[] = 'Benefit Fund Type is required.';
if ($amount <= 0)              $errors[] = 'Amount must be greater than zero.';
// No salary → no benefit (benefits are paid through the salary slip).
if ($emp_id && !$id && !employee_has_salary($emp_id))
    $errors[] = 'This employee has no salary configured. Set their salary before assigning a benefit.';
// Frequency required when a start date is given (recurring mode).
if ($start_date && ($frequencyRaw === '' || !in_array($frequencyRaw, $freqList, true)))
    $errors[] = 'Please select a frequency when a start date is provided.';
if ($start_date && $end_date && $end_date < $start_date)
    $errors[] = 'End Date must be on or after the Start Date.';
if (!$start_date && $effRaw === '')
    $errors[] = 'Provide a Start Date (recurring) or an Effective Month (legacy).';

if ($errors) {
    $_SESSION['errors']   = $errors;
    $_SESSION['form_old'] = $_POST;
    redirect(BASE_URL . '/modules/benefits/create.php?' . ($id ? 'id=' . $id : 'emp_id=' . $emp_id));
}

// Default frequency to 'monthly' for legacy mode (no start_date / no explicit choice).
$frequency = in_array($frequencyRaw, $freqList, true) ? $frequencyRaw : 'monthly';

// effective_month: derive from start_date when not supplied; always 1st-of-month.
if ($effRaw !== '')          $effective_month = $effRaw . '-01';
elseif ($start_date)         $effective_month = date('Y-m-01', strtotime($start_date));
else                         $effective_month = date('Y-m-01');

// Legacy display name cached in fund_type (custom name wins, else fund-type name).
$fund_type = $benefit_name ?: $ftName;

if ($id) {
    $db->prepare('UPDATE employee_benefits SET benefit_fund_type_id=?, frequency=?, start_date=?, end_date=?, benefit_name=?, fund_type=?, amount=?, payment_mode=?, effective_month=?, status=?, description=? WHERE id=?')
       ->execute([$fundTypeId, $frequency, $start_date, $end_date, $benefit_name, $fund_type, $amount, $payment_mode, $effective_month, $status, $description, $id]);
    activity_log('updated', 'Benefit', 'Updated benefit (' . $fund_type . ') for ' . activity_emp_label($emp_id)
        . ': ₹' . number_format($amount, 2) . ' (' . ucfirst($status) . ')');
    flash('success', 'Benefit updated successfully.');
} else {
    $db->prepare('INSERT INTO employee_benefits (employee_id,benefit_fund_type_id,frequency,start_date,end_date,benefit_name,fund_type,amount,payment_mode,effective_month,status,added_by,description) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
       ->execute([$emp_id, $fundTypeId, $frequency, $start_date, $end_date, $benefit_name, $fund_type, $amount, $payment_mode, $effective_month, $status, (int)($user['id'] ?? 0) ?: null, $description]);
    $msg = 'Added benefit (' . $fund_type . ') for ' . activity_emp_label($emp_id) . ': ₹' . number_format($amount, 2);
    if ($start_date) {
        $msg .= ', ' . ucfirst(str_replace('_', '-', $frequency)) . ' from ' . date('d M Y', strtotime($start_date))
              . ($end_date ? ' until ' . date('d M Y', strtotime($end_date)) : ' (ongoing)');
    } else {
        $msg .= ', Month: ' . date('M Y', strtotime($effective_month));
    }
    activity_log('created', 'Benefit', $msg . ' (' . ucfirst($status) . ')');
    flash('success', 'Employee benefit assigned.');
}

if ($return) redirect(BASE_URL . '/modules/employee/view.php?id=' . $emp_id . '#benefits');
redirect(BASE_URL . '/modules/benefits/index.php');
