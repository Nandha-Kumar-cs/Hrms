<?php
/**
 * Factory Reset — DESTRUCTIVE.
 * Wipes ALL data and keeps ONLY the Super Admin login(s) + the Super Admin role,
 * so the admin can still sign in afterwards. Super-Admin only, CSRF-protected,
 * and guarded by a typed "DELETE" confirmation.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$user = current_user();
$self = BASE_URL . '/index.php';

// Only a Super Admin may perform a factory reset.
if (($user['role_name'] ?? '') !== 'Super Admin') {
    http_response_code(403);
    flash('error', 'Only a Super Admin can reset the application data.');
    redirect($self);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect($self);
if (!csrf_verify()) { flash('error', 'Invalid or expired request.'); redirect($self); }

// Typed confirmation must be exactly DELETE.
if (trim((string)($_POST['confirm'] ?? '')) !== 'DELETE') {
    flash('error', 'Reset cancelled — you must type DELETE to confirm.');
    redirect($self);
}

$db = db();

try {
    // The role + user rows to preserve.
    $saRoleIds = $db->query("SELECT id FROM roles WHERE name = 'Super Admin'")->fetchAll(PDO::FETCH_COLUMN);
    if (!$saRoleIds) { flash('error', 'Super Admin role not found — reset aborted to avoid lock-out.'); redirect($self); }
    $saList = implode(',', array_map('intval', $saRoleIds));

    // Every table in this database except the two we partially keep.
    $tables = $db->query(
        "SELECT table_name FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
    )->fetchAll(PDO::FETCH_COLUMN);

    $keepTables = ['users', 'roles'];   // these are pruned, not emptied

    $db->exec('SET FOREIGN_KEY_CHECKS = 0');

    // 1) Empty every other table completely.
    foreach ($tables as $t) {
        if (in_array($t, $keepTables, true)) continue;
        $db->exec('TRUNCATE TABLE `' . str_replace('`', '', $t) . '`');
    }

    // 2) Keep only Super Admin logins; drop all other users.
    $db->exec("DELETE FROM users WHERE role_id NOT IN ($saList)");

    // 3) Keep only the Super Admin role; drop all other roles.
    $db->exec("DELETE FROM roles WHERE id NOT IN ($saList)");

    $db->exec('SET FOREIGN_KEY_CHECKS = 1');

    flash('success', 'All data has been deleted. Only the admin login was kept.');
} catch (Throwable $e) {
    @$db->exec('SET FOREIGN_KEY_CHECKS = 1');
    error_log('Factory reset failed: ' . $e->getMessage());
    flash('error', 'Reset failed: ' . $e->getMessage());
}

redirect($self);
