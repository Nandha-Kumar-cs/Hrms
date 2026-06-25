<?php
/**
 * MagDyn HRMS — Employee data-access scoping.
 *
 * A "self-scoped" user is a logged-in user who is linked to an employee record
 * and whose role is NOT a privileged/management role. Such users may only see
 * their OWN employee data; privileged roles (admin / HR / manager / finance)
 * and Super Admin keep full access.
 *
 * Usage in pages:
 *   • Per-employee page (profile, slips, attendance):
 *       if (is_self_scoped()) $empId = current_employee_id();   // force own
 *       require_own_employee($empId);                           // 403 on mismatch
 *   • Cross-employee management page (registers, reports, exports):
 *       block_cross_employee();                                 // redirect self-scoped away
 *
 * This only changes data VISIBILITY — no calculation or business logic.
 */

/** Logged-in user's linked employee id (0 if none). */
function current_employee_id(): int {
    $u = function_exists('current_user') ? current_user() : null;
    if (!$u) return 0;

    $eid = (int)($u['employee_id'] ?? 0);
    if ($eid > 0) return $eid;

    // Fallback: the user account was never linked via users.employee_id. Resolve
    // it by matching the login email to an employee record so self-scoping still
    // works (and isn't silently disabled). Cached per request.
    static $resolved = null;
    if ($resolved !== null) return $resolved;
    $email = trim((string)($u['email'] ?? ''));
    if ($email === '') return $resolved = 0;
    try {
        $s = db()->prepare('SELECT id FROM employees WHERE LOWER(email) = LOWER(?) LIMIT 1');
        $s->execute([$email]);
        $resolved = (int)($s->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        $resolved = 0;
    }
    return $resolved;
}

/** Privileged roles that may view/manage ALL employees' data. Used only as a
 *  fallback when the roles.self_scope flag is unavailable (pre-migration). */
function scope_privileged_roles(): array {
    return ['super admin', 'admin', 'hr manager', 'hr executive', 'manager', 'finance'];
}

/**
 * Whether a given role is configured to be self-scoped. Driven by the
 * roles.self_scope flag (set per role in Settings → Roles). Result is cached
 * per request. Falls back to the legacy role-name heuristic only if the column
 * is missing (e.g. migration not yet applied).
 */
function role_has_self_scope(int $roleId, string $roleNameLower = ''): bool {
    static $cache = [];
    $nameFallback = fn() => $roleNameLower !== ''
        && !in_array($roleNameLower, scope_privileged_roles(), true);

    if ($roleId <= 0) return $nameFallback();
    if (array_key_exists($roleId, $cache)) return $cache[$roleId];

    try {
        $s = db()->prepare('SELECT self_scope FROM roles WHERE id = ?');
        $s->execute([$roleId]);
        $val = $s->fetchColumn();
        $flag = ($val === false) ? $nameFallback() : ((int)$val === 1);
    } catch (Throwable $e) {
        $flag = $nameFallback();   // self_scope column absent — legacy behaviour
    }
    return $cache[$roleId] = $flag;
}

/** True when the current user must be restricted to their own employee data. */
function is_self_scoped(): bool {
    $u = function_exists('current_user') ? current_user() : null;
    if (!$u) return false;
    if (current_employee_id() <= 0) return false;          // not linked to an employee
    return role_has_self_scope((int)($u['role_id'] ?? 0), strtolower($u['role_name'] ?? ''));
}

/**
 * Block a cross-employee/management page for self-scoped users. They are sent
 * back to the dashboard with a message. Call right after require_permission().
 */
function block_cross_employee(): void {
    if (is_self_scoped()) {
        if (function_exists('flash')) flash('error', 'You can only access your own records.');
        redirect(BASE_URL . '/index.php');
    }
}

/**
 * The employee id a LIST/REPORT page should be filtered to. For self-scoped
 * users this is their own employee id; for privileged users it returns the
 * supplied $current (the page's existing employee filter, 0 = "all"). Use this
 * on cross-employee lists/reports so employees transparently see only their own
 * rows instead of being blocked.
 */
function scope_employee_id(int $current = 0): int {
    return is_self_scoped() ? current_employee_id() : $current;
}

/**
 * Employee rows for a filter dropdown, limited to the user's own record when
 * self-scoped (so the picker never leaks other employees' names). Mirrors the
 * common `SELECT id, name, employee_id AS emp_code FROM employees` shape.
 */
function scope_employees_for_dropdown(?PDO $db = null): array {
    $db = $db ?: db();
    if (is_self_scoped()) {
        $s = $db->prepare('SELECT id, name, employee_id AS emp_code FROM employees WHERE id = ? ORDER BY name');
        $s->execute([current_employee_id()]);
        return $s->fetchAll();
    }
    return $db->query('SELECT id, name, employee_id AS emp_code FROM employees ORDER BY name')->fetchAll();
}

/**
 * For a per-employee detail page reached via ?id=. A self-scoped user requesting
 * an employee other than their own gets a clean 403.
 */
function require_own_employee(int $empId): void {
    if (is_self_scoped() && $empId !== current_employee_id()) {
        http_response_code(403);
        include BASE_PATH . '/includes/403.php';
        exit;
    }
}
