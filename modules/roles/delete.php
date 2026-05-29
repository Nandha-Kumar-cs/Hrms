<?php
require_once '../../includes/bootstrap.php';
require_login();
require_permission('roles', 'edit');
verify_csrf($_POST['csrf_token'] ?? '');

$id = (int)$_POST['id'];
$role = db()->query("SELECT * FROM roles WHERE id=$id")->fetch(PDO::FETCH_ASSOC);

if (!$role || $role['is_system']) {
    flash('danger','Cannot delete system roles.');
} else {
    $users = db()->query("SELECT COUNT(*) FROM users WHERE role_id=$id")->fetchColumn();
    if ($users > 0) {
        flash('danger','Cannot delete role with assigned users. Reassign users first.');
    } else {
        db()->query("DELETE FROM role_permissions WHERE role_id=$id");
        db()->query("DELETE FROM roles WHERE id=$id");
        flash('success','Role deleted.');
    }
}
redirect(BASE_URL . '/modules/roles/index.php');
