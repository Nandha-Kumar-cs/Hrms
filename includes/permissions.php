<?php
/**
 * MagDyn HRMS — Permissions Helper
 */

function has_permission(string $module, string $action = 'view'): bool {
    $user = current_user();
    if (!$user) return false;
    // Super-admin bypasses everything
    if (($user['role_name'] ?? '') === 'Super Admin') return true;

    static $cache = [];
    $uid = (int)($user['id'] ?? 0);
    // Cache per user (a permission may be granted via the role OR directly to the user).
    $key = $uid . ':' . ($user['role_id'] ?? 0) . ':' . $module . ':' . $action;
    if (isset($cache[$key])) return $cache[$key];

    // 1) Role permission.
    $stmt = db()->prepare(
        'SELECT 1 FROM role_permissions rp
         JOIN permissions p ON p.id = rp.permission_id
         WHERE rp.role_id = ? AND p.module = ? AND p.action = ?
         LIMIT 1'
    );
    $stmt->execute([$user['role_id'], $module, $action]);
    $result = (bool) $stmt->fetchColumn();

    // 2) Direct per-user permission (granted on top of the role).
    if (!$result && $uid) {
        try {
            $us = db()->prepare(
                'SELECT 1 FROM user_permissions up
                 JOIN permissions p ON p.id = up.permission_id
                 WHERE up.user_id = ? AND p.module = ? AND p.action = ?
                 LIMIT 1'
            );
            $us->execute([$uid, $module, $action]);
            $result = (bool) $us->fetchColumn();
        } catch (Throwable $e) { /* user_permissions table not present yet */ }
    }

    $cache[$key] = $result;
    return $result;
}

function get_user_permissions(int $roleId): array {
    $stmt = db()->prepare(
        'SELECT p.module, p.action FROM role_permissions rp
         JOIN permissions p ON p.id = rp.permission_id
         WHERE rp.role_id = ?'
    );
    $stmt->execute([$roleId]);
    $perms = [];
    foreach ($stmt->fetchAll() as $row) {
        $perms[$row['module']][] = $row['action'];
    }
    return $perms;
}

function can(string $module, string $action = 'view'): bool {
    return has_permission($module, $action);
}

/** True if the user has ANY permission (any action) within a module — used to
 *  decide whether to show a parent menu group. Role ∪ user grants; SA bypass. */
function can_any(string $module): bool {
    $user = current_user();
    if (!$user) return false;
    if (($user['role_name'] ?? '') === 'Super Admin') return true;

    static $cache = [];
    $uid = (int)($user['id'] ?? 0);
    $key = $uid . ':' . ($user['role_id'] ?? 0) . ':' . $module;
    if (isset($cache[$key])) return $cache[$key];

    $r = db()->prepare('SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id
                        WHERE rp.role_id=? AND p.module=? LIMIT 1');
    $r->execute([$user['role_id'], $module]);
    $ok = (bool)$r->fetchColumn();
    if (!$ok && $uid) {
        try {
            $u = db()->prepare('SELECT 1 FROM user_permissions up JOIN permissions p ON p.id=up.permission_id
                                WHERE up.user_id=? AND p.module=? LIMIT 1');
            $u->execute([$uid, $module]);
            $ok = (bool)$u->fetchColumn();
        } catch (Throwable $e) { /* user_permissions absent */ }
    }
    return $cache[$key] = $ok;
}

// True for Super Admin / Admin roles (privileged override).
function is_admin(): bool {
    $user = current_user();
    if (!$user) return false;
    $role = strtolower($user['role_name'] ?? '');
    return $role === 'super admin' || $role === 'admin';
}

/**
 * Friendly display name for a permission `module` key — used as the group header
 * in the Roles & Permissions UI. Falls back to a title-cased version of the key
 * (underscores → spaces) for any module not explicitly mapped.
 */
function module_label(string $module): string {
    static $map = [
        'dashboard'       => 'Dashboard',
        'employee'        => 'Employees',
        'letters'         => 'Letters',
        'documents'       => 'Documents',
        'assets'          => 'Assets',
        'attendance'      => 'Attendance',
        'leaves'          => 'Leave Requests',
        'leave_history'   => 'Leave History',
        'holidays'        => 'Holidays',
        'compoff'         => 'Comp Offs',
        'compoff_credits' => 'Comp Off Credits',
        'od'              => 'On Duty (OD)',
        'payroll'         => 'Payroll',
        'loans'           => 'Loans & Advances',
        'increments'      => 'Increments',
        'promotions'      => 'Promotions',
        'benefits'        => 'Benefits',
        'bonuses'         => 'Bonuses & Incentives',
        'reports'         => 'Reports',
        'report_benefits' => 'Report: Monthly Benefits',
        'report_bonus'    => 'Report: Bonus Report',
        'report_history'  => 'Report: Employee History',
        'report_impact'   => 'Report: Payroll Impact',
        'roles'           => 'Roles & Permissions',
        'settings'        => 'Settings',
        'users'           => 'Users',
        'activity'        => 'Activity Log',
    ];
    return $map[$module] ?? ucwords(str_replace('_', ' ', $module));
}

// Check notification permission for user
function can_notify(string $type): bool {
    $user = current_user();
    if (!$user) return false;
    $stmt = db()->prepare(
        'SELECT 1 FROM user_notification_prefs WHERE user_id = ? AND notification_type = ? AND enabled = 1 LIMIT 1'
    );
    $stmt->execute([$user['id'], $type]);
    return (bool) $stmt->fetchColumn();
}
