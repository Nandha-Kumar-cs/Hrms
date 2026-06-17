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
    $key = $user['role_id'] . ':' . $module . ':' . $action;
    if (isset($cache[$key])) return $cache[$key];

    $stmt = db()->prepare(
        'SELECT 1 FROM role_permissions rp
         JOIN permissions p ON p.id = rp.permission_id
         WHERE rp.role_id = ? AND p.module = ? AND p.action = ?
         LIMIT 1'
    );
    $stmt->execute([$user['role_id'], $module, $action]);
    $result = (bool) $stmt->fetchColumn();
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

// True for Super Admin / Admin roles (privileged override).
function is_admin(): bool {
    $user = current_user();
    if (!$user) return false;
    $role = strtolower($user['role_name'] ?? '');
    return $role === 'super admin' || $role === 'admin';
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
