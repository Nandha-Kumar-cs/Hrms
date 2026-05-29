<?php
require_once '../../includes/bootstrap.php';
require_login();
require_permission('assets_edit');
verify_csrf($_POST['csrf_token'] ?? '');

$assetId = (int)$_POST['asset_id'];
$empId   = (int)$_POST['employee_id'];
$date    = $_POST['assigned_date'] ?? date('Y-m-d');
$notes   = trim($_POST['notes'] ?? '');

if (!$assetId || !$empId) {
    flash('danger','Invalid data.');
    redirect($_SERVER['HTTP_REFERER'] ?? BASE_URL . '/modules/assets/index.php');
}

// Update asset
db()->prepare("UPDATE assets SET status='Assigned', assigned_to=:eid WHERE id=:aid")
     ->execute([':eid'=>$empId,':aid'=>$assetId]);

// Record assignment
db()->prepare("INSERT INTO asset_assignments (asset_id,employee_id,assigned_date,assigned_by,notes,created_at)
    VALUES (:aid,:eid,:d,:uid,:n,NOW())")
    ->execute([':aid'=>$assetId,':eid'=>$empId,':d'=>$date,':uid'=>current_user()['id'],':n'=>$notes]);

flash('success','Asset assigned successfully.');
redirect(BASE_URL . "/modules/assets/view.php?id=$assetId");
