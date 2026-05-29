<?php
require_once '../../includes/bootstrap.php';
require_login();
require_permission('attendance_view');

$month = (int)($_GET['month'] ?? date('m'));
$year  = (int)($_GET['year']  ?? date('Y'));
$empId = (int)($_GET['employee_id'] ?? 0);

$where = "WHERE MONTH(a.date)=$month AND YEAR(a.date)=$year";
if ($empId) $where .= " AND a.employee_id=$empId";

$rows = db()->query("SELECT e.employee_id AS emp_code, CONCAT(e.first_name,' ',e.last_name) AS emp_name,
    d.name AS department, a.date, a.status, a.check_in, a.check_out, a.remarks
    FROM attendance a
    JOIN employees e ON a.employee_id = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    $where
    ORDER BY e.first_name, a.date")->fetchAll(PDO::FETCH_ASSOC);

$monthName = date('F', mktime(0,0,0,$month,1,$year));
$filename  = "attendance_{$year}_{$month}.csv";

header('Content-Type: text/csv');
header("Content-Disposition: attachment; filename=\"$filename\"");

$out = fopen('php://output','w');
fputcsv($out, ['Employee ID','Employee Name','Department','Date','Status','Check In','Check Out','Remarks']);
foreach ($rows as $r) {
    fputcsv($out, [
        $r['emp_code'], $r['emp_name'], $r['department'],
        date('d M Y', strtotime($r['date'])),
        $r['status'], $r['check_in'] ?? '', $r['check_out'] ?? '', $r['remarks'] ?? ''
    ]);
}
fclose($out);
exit;
