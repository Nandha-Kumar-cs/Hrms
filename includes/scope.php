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
    return (int)($u['employee_id'] ?? 0);
}

/** Privileged roles that may view/manage ALL employees' data. */
function scope_privileged_roles(): array {
    return ['super admin', 'admin', 'hr manager', 'hr executive', 'manager', 'finance'];
}

/** True when the current user must be restricted to their own employee data. */
function is_self_scoped(): bool {
    $u = function_exists('current_user') ? current_user() : null;
    if (!$u) return false;
    if (current_employee_id() <= 0) return false;          // not linked to an employee
    $role = strtolower($u['role_name'] ?? '');
    return !in_array($role, scope_privileged_roles(), true);
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
