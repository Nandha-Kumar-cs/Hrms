<?php
require_once '../../includes/bootstrap.php';
require_login();
require_permission('roles', 'edit');
verify_csrf($_POST['csrf_token'] ?? '');

$id = (int)($_POST['id'] ?? 0);
$st = db()->prepare("SELECT * FROM roles WHERE id=?"); $st->execute([$id]);
$role = $st->fetch(PDO::FETCH_ASSOC);

// The `roles` table has no `is_system` column, so the old guard was always false.
// Protect the Super Admin role explicitly (by id 1 OR name) so it can't be deleted.
$isProtected = $role && ((int)$role['id'] === 1 || strcasecmp((string)$role['name'], 'Super Admin') === 0);

if (!$role || $isProtected) {
    flash('danger','This role cannot be deleted.');
} else {
    $uc = db()->prepare("SELECT COUNT(*) FROM users WHERE role_id=?"); $uc->execute([$id]);
    if ((int)$uc->fetchColumn() > 0) {
        flash('danger','Cannot delete role with assigned users. Reassign users first.');
    } else {
        db()->prepare("DELETE FROM role_permissions WHERE role_id=?")->execute([$id]);
        db()->prepare("DELETE FROM roles WHERE id=?")->execute([$id]);
        flash('success','Role deleted.');
    }
}
redirect(BASE_URL . '/modules/roles/index.php');
