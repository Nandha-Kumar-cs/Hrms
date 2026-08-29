<?php
require_once '../../includes/bootstrap.php';
require_login();
require_permission('attendance', 'edit');
block_cross_employee();   // editing attendance is admin/HR-only; self-scoped users can't whitewash their own.
verify_csrf($_POST['csrf_token'] ?? '');

// Field names match the Edit Attendance form in index.php (att_id / in_time /
// out_time) — the old id/check_in/check_out names matched nothing that form
// sends, so $id was always 0 and every save silently no-opped on "Invalid
// data" before it ever reached the (also broken) UPDATE below.
$id     = (int)($_POST['att_id'] ?? 0);
$status = $_POST['status'] ?? '';
$in     = $_POST['in_time'] ?? null;
$out    = $_POST['out_time'] ?? null;
$rem    = trim($_POST['remarks'] ?? '');

// Must match attendance.status's actual ENUM — 'Present', 'On Duty' and
// 'Week Off' are not values that column accepts; every one of the ENUM's real
// values is listed here even though the form's own dropdown only offers a
// subset, so a value written some other way (API, a future form change)
// isn't rejected by a whitelist that is stricter than the schema itself
// (security audit H-9).
$validStatuses = ['On Time','Late','Absent','OD','Comp Off','Half Day','Holiday','On Leave'];
if (!$id || !in_array($status, $validStatuses, true)) {
    flash('danger','Invalid data.');
    redirect($_SERVER['HTTP_REFERER'] ?? BASE_URL . '/modules/attendance/index.php');
}

// attendance has in_time/out_time and no updated_at column — check_in,
// check_out and updated_at all threw "Unknown column" (security audit H-9).
$stmt = db()->prepare("UPDATE attendance SET status=:s, in_time=:it, out_time=:ot, remarks=:r WHERE id=:id");
$stmt->execute([':s'=>$status,':it'=>$in?:null,':ot'=>$out?:null,':r'=>$rem,':id'=>$id]);

flash('success','Attendance record updated.');
redirect($_SERVER['HTTP_REFERER'] ?? BASE_URL . '/modules/attendance/index.php');
