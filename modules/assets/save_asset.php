<?php
require_once '../../includes/bootstrap.php';
require_login();
require_permission('assets_edit');
verify_csrf($_POST['csrf_token'] ?? '');

$name      = trim($_POST['asset_name'] ?? '');
$catId     = (int)$_POST['category_id'];
$serial    = trim($_POST['serial_number'] ?? '');
$tag       = trim($_POST['asset_tag'] ?? '');
$purchDate = $_POST['purchase_date'] ?? null;
$purchCost = $_POST['purchase_cost'] ? (float)$_POST['purchase_cost'] : null;
$warranty  = $_POST['warranty_until'] ?: null;
$condition = $_POST['condition_status'] ?? 'Good';
$location  = trim($_POST['location'] ?? '');
$notes     = trim($_POST['notes'] ?? '');

if (!$name) {
    flash('danger','Asset name is required.');
    redirect(BASE_URL . '/modules/assets/index.php');
}

db()->prepare("INSERT INTO assets (category_id,asset_name,serial_number,asset_tag,purchase_date,purchase_cost,warranty_until,condition_status,status,location,notes,created_at)
    VALUES (:cat,:name,:serial,:tag,:pd,:pc,:wu,:cs,'Available',:loc,:notes,NOW())")
    ->execute([':cat'=>$catId?:null,':name'=>$name,':serial'=>$serial,':tag'=>$tag,
               ':pd'=>$purchDate,':pc'=>$purchCost,':wu'=>$warranty,':cs'=>$condition,
               ':loc'=>$location,':notes'=>$notes]);

flash('success','Asset added successfully.');
redirect(BASE_URL . '/modules/assets/index.php');
