<?php
require_once '../../includes/bootstrap.php';
require_login();
require_permission('attendance_edit');
verify_csrf($_POST['csrf_token'] ?? '');

$id     = (int)($_POST['id'] ?? 0);
$status = $_POST['status'] ?? '';
$in     = $_POST['check_in'] ?? null;
$out    = $_POST['check_out'] ?? null;
$rem    = trim($_POST['remarks'] ?? '');

$validStatuses = ['Present','Late','Absent','Half Day','On Duty','Comp Off','Holiday','Week Off'];
if (!$id || !in_array($status, $validStatuses)) {
    flash('danger','Invalid data.');
    redirect($_SERVER['HTTP_REFERER'] ?? BASE_URL . '/modules/attendance/index.php');
}

$stmt = db()->prepare("UPDATE attendance SET status=:s, check_in=:ci, check_out=:co, remarks=:r, updated_at=NOW() WHERE id=:id");
$stmt->execute([':s'=>$status,':ci'=>$in?:null,':co'=>$out?:null,':r'=>$rem,':id'=>$id]);

flash('success','Attendance record updated.');
redirect($_SERVER['HTTP_REFERER'] ?? BASE_URL . '/modules/attendance/index.php');
