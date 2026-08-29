<?php
require_once '../../includes/bootstrap.php';
require_login();
// The Export button on index.php is itself gated on attendance.export, not
// attendance.view — this endpoint checked the weaker permission, so anyone
// who could merely VIEW attendance (a permission far more commonly granted)
// could already reach the CSV export by URL (security audit H-9).
require_permission('attendance', 'export');
block_cross_employee();

$month = (int)($_GET['month'] ?? date('m'));
$year  = (int)($_GET['year']  ?? date('Y'));
$empId = (int)($_GET['employee_id'] ?? 0);

// The attendance table's columns are att_date / in_time / out_time — there is
// no a.date, a.check_in or a.check_out, so this export fataled on every request.
$where = "WHERE MONTH(a.att_date)=$month AND YEAR(a.att_date)=$year";
if ($empId) $where .= " AND a.employee_id=$empId";

$rows = db()->query("SELECT e.employee_id AS emp_code, e.name AS emp_name,
    d.name AS department, a.att_date, a.status, a.in_time, a.out_time, a.remarks
    FROM attendance a
    JOIN employees e ON a.employee_id = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    $where
    ORDER BY e.name, a.att_date")->fetchAll(PDO::FETCH_ASSOC);

$monthName = date('F', mktime(0,0,0,$month,1,$year));
$filename  = "attendance_{$year}_{$month}.csv";

header('Content-Type: text/csv');
header("Content-Disposition: attachment; filename=\"$filename\"");

$out = fopen('php://output','w');
fputcsv($out, ['Employee ID','Employee Name','Department','Date','Status','Check In','Check Out','Remarks']);
foreach ($rows as $r) {
    fputcsv($out, [
        $r['emp_code'], $r['emp_name'], $r['department'],
        date('d M Y', strtotime($r['att_date'])),
        $r['status'], $r['in_time'] ?? '', $r['out_time'] ?? '', $r['remarks'] ?? ''
    ]);
}
fclose($out);
exit;
