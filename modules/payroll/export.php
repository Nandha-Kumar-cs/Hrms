<?php
require_once '../../includes/bootstrap.php';
require_login();
// CSV export contains bank account / IFSC / PAN — gate behind the privileged
// payroll-process permission, not the generic Salary-Slips "view".
require_permission('payroll', 'process');
block_cross_employee();

$runId = (int)($_GET['run_id'] ?? 0);
if (!$runId) exit('Invalid run');

$run = db()->query("SELECT * FROM payroll_runs WHERE id=$runId")->fetch(PDO::FETCH_ASSOC);
if (!$run) exit('Invalid run');
$slips = db()->query("SELECT ss.*, e.name AS emp_name, e.employee_id AS emp_code,
    d.name AS dept_name, des.name AS designation, e.bank_name, e.bank_account, e.bank_ifsc
    FROM salary_slips ss
    JOIN employees e ON ss.employee_id = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN designations des ON e.designation_id = des.id
    WHERE ss.payroll_run_id=$runId ORDER BY e.name")->fetchAll(PDO::FETCH_ASSOC);

// payroll_runs has payroll_month ('YYYY-MM'), not year/month columns.
$filename = 'payroll_' . str_replace('-', '_', (string)$run['payroll_month']) . '.csv';
header('Content-Type: text/csv');
header("Content-Disposition: attachment; filename=\"$filename\"");

$out = fopen('php://output','w');
fputcsv($out, ['Emp ID','Name','Department','Designation','Working Days','LOP Days','Present Days',
    'Basic','HRA','Conveyance','Medical','Special','Other Allowance','Gross',
    'PF Employee','ESI Employee','LOP Deduction','Other Deductions','Total Deductions','Net Pay',
    'PF Employer','ESI Employer','Bank Name','Account No','IFSC']);

// Column names below are the ones salary_slips actually has. This row used
// six that do not exist (gross_salary, net_salary, special_allowance,
// other_allowance, lop_deduction), so the bank/PAN CSV either errored or
// exported blanks in the money columns (security audit H-6). The LOP amount is
// not a stored column — it is read from the slip's attendance summary.
foreach ($slips as $s) {
    $summary = json_decode((string)($s['attendance_summary'] ?? ''), true) ?: [];
    $lopDeduction = (float)($summary['absent_deduction'] ?? 0);
    fputcsv($out, [
        $s['emp_code'], $s['emp_name'], $s['dept_name'], $s['designation'],
        $s['working_days'], $s['lop_days'], $s['present_days'],
        $s['basic'], $s['hra'], $s['conveyance'], $s['medical'], $s['special_allow'],
        $s['other_allow'], $s['gross_earnings'],
        $s['pf_employee'], $s['esi_employee'], $lopDeduction, $s['other_deductions'],
        $s['total_deductions'], $s['net_pay'],
        $s['pf_employer'], $s['esi_employer'],
        $s['bank_name'], $s['bank_account'], $s['bank_ifsc']
    ]);
}
fclose($out);
exit;
