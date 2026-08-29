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

/**
 * Role names that confer ADMINISTRATOR powers — impersonating other users
 * (modules/settings/impersonate.php) and deleting issued letters
 * (modules/letters/delete.php).
 *
 * Single source of truth. is_admin(), can_assign_role() and the role
 * create/rename guards all read this one list, so a name added here is
 * privileged AND protected everywhere at once. They used to test the literal
 * string 'Super Admin' independently, which let anyone holding users.create or
 * roles.edit mint an account with the "Admin" role and gain impersonation
 * (security audit H-4).
 */
function admin_role_names(): array {
    return ['super admin', 'admin'];
}

/** Whether a ROLE NAME grants administrator powers. */
function is_admin_role_name(string $roleName): bool {
    return in_array(strtolower(trim($roleName)), admin_role_names(), true);
}

// True for Super Admin / Admin roles (privileged override).
function is_admin(): bool {
    $user = current_user();
    if (!$user) return false;
    return is_admin_role_name((string)($user['role_name'] ?? ''));
}

/** True only for the Super Admin role (strict). */
function is_super_admin(): bool {
    $user = current_user();
    return $user && ($user['role_name'] ?? '') === 'Super Admin';
}

/**
 * Guard the roles.self_scope flag (security audit M-17).
 *
 * self_scope is what confines a user to their OWN employee record — it drives
 * roughly two dozen visibility checks: the employee list and profiles, payroll
 * slips and PDFs, attendance, leaves, documents, assets, ID cards, and the
 * file.php download gateway. Losing it means seeing everyone's salary.
 *
 * Permissions on the role screens are filtered through
 * filter_assignable_permission_ids(), so nobody can grant reach they do not have.
 * self_scope was a plain checkbox with no such filter, which left the whole
 * boundary one POST away: a self-scoped user holding roles.manage could open
 * their OWN role, untick the box, and immediately see the entire company.
 *
 * Same rule as permissions, applied to scoping: you cannot give a role more
 * reach than you have yourself. A self-scoped actor may therefore never clear
 * the flag — on their own role or anyone else's. Tightening it (0 → 1) is still
 * allowed, mirroring the fact that they may also REMOVE permissions.
 *
 * Returns an error message, or null when the change is permitted.
 */
function self_scope_change_error(int $requested, int $current): ?string {
    if ($requested === $current) return null;   // nothing is changing
    if ($requested === 1)        return null;   // tightening reduces access
    if (!is_self_scoped())       return null;   // actor already sees everything
    return 'You are not allowed to switch off data scoping — your own access is '
         . 'limited to your employee record, so you cannot grant a role wider access than you hold.';
}

/**
 * Guard a Super Admin account against being locked out (security audit M-12).
 *
 * settings/users.php refused to DELETE a Super Admin but left the two other ways
 * of stripping one unguarded:
 *   • the status toggle, which only ever checked "is this me?"; and
 *   • the edit form, which could clear is_active, or move the account to an
 *     ordinary role — can_assign_role() vets the role being GRANTED and never
 *     the one being taken away, so demotion sailed through.
 * Any account holding users.edit could therefore switch off every Super Admin.
 * On an install with a single Super Admin that is a one-click total lockout,
 * recoverable only by direct database access.
 *
 * Two rules:
 *   1. Only a Super Admin may deactivate or re-role another Super Admin.
 *   2. Nobody — Super Admin included — may remove the LAST ACTIVE Super Admin.
 *
 * $newRoleId / $newActive are the values about to be written; pass null for
 * "unchanged". Returns an error message, or null when the change is allowed.
 */
function super_admin_lockout_error(PDO $db, int $targetId, ?int $newRoleId, ?int $newActive): ?string {
    $t = $db->prepare('SELECT r.name AS role_name FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ?');
    $t->execute([$targetId]);
    $row = $t->fetch(PDO::FETCH_ASSOC);
    if (!$row || (string)($row['role_name'] ?? '') !== 'Super Admin') return null;  // not a Super Admin

    // Does the pending change actually take Super Admin away?
    $losesActive = $newActive !== null && (int)$newActive === 0;
    $losesRole   = false;
    if ($newRoleId !== null) {
        $r = $db->prepare('SELECT name FROM roles WHERE id = ?');
        $r->execute([$newRoleId]);
        $newName = $r->fetchColumn();
        // Unknown role id → treat as a demotion and refuse; fail closed.
        $losesRole = ($newName === false) || (string)$newName !== 'Super Admin';
    }
    if (!$losesActive && !$losesRole) return null;

    if (!is_super_admin()) {
        return 'Only a Super Admin can deactivate or change the role of a Super Admin account.';
    }

    $c = $db->prepare(
        'SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
          WHERE r.name = "Super Admin" AND u.is_active = 1 AND u.id <> ?'
    );
    $c->execute([$targetId]);
    if ((int)$c->fetchColumn() === 0) {
        return 'This is the last active Super Admin. Activate or promote another Super Admin first.';
    }
    return null;
}

/**
 * Permission IDs the current user effectively holds (role grants ∪ direct grants).
 * Returns null for Super Admin → means "all permissions" (no restriction).
 */
function current_user_permission_ids(): ?array {
    $user = current_user();
    if (!$user) return [];
    if (is_super_admin()) return null;                       // null = ALL
    $ids = [];
    try {
        $r = db()->prepare('SELECT permission_id FROM role_permissions WHERE role_id = ?');
        $r->execute([(int)($user['role_id'] ?? 0)]);
        foreach ($r->fetchAll(PDO::FETCH_COLUMN) as $pid) $ids[(int)$pid] = true;
        $u = db()->prepare('SELECT permission_id FROM user_permissions WHERE user_id = ?');
        $u->execute([(int)($user['id'] ?? 0)]);
        foreach ($u->fetchAll(PDO::FETCH_COLUMN) as $pid) $ids[(int)$pid] = true;
    } catch (Throwable $e) { /* table missing — fail closed below */ }
    return $ids;
}

/**
 * Filter a requested list of permission IDs down to those the actor is allowed
 * to grant — you can never grant a permission you do not already hold. Super
 * Admin may grant anything.
 */
function filter_assignable_permission_ids(array $requested): array {
    $own = current_user_permission_ids();
    $requested = array_values(array_unique(array_map('intval', $requested)));
    if ($own === null) return $requested;                    // Super Admin: anything
    return array_values(array_filter($requested, fn($pid) => $pid > 0 && isset($own[$pid])));
}

/**
 * Whether the actor may assign the given role to a user.
 *
 * Only a Super Admin may hand out ANY administrator role — not merely the one
 * literally named "Super Admin". Testing that single name let a user with
 * users.create assign the "Admin" role, whose holder is_admin() then accepts
 * for impersonation and letter deletion (security audit H-4).
 */
function can_assign_role(int $roleId): bool {
    if (is_super_admin()) return true;
    try {
        $s = db()->prepare('SELECT name FROM roles WHERE id = ?');
        $s->execute([$roleId]);
        $name = (string)$s->fetchColumn();
    } catch (Throwable $e) {
        return false;      // cannot verify the role — refuse rather than guess
    }
    if ($name === '') return false;          // unknown role id — fail closed
    return !is_admin_role_name($name);
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

/**
 * Short human hint describing what a permission unlocks — shown as a hover
 * tooltip (eye icon) in the Roles & Permissions screen. Explicit text per
 * module/action, with a sensible generated fallback so every permission has one.
 */
function permission_hint(string $module, string $action): string {
    static $map = [
        'dashboard' => ['view' => 'View the main dashboard and summary widgets.'],
        'employee'  => ['view' => 'View the employee list and profiles.', 'create' => 'Add new employees.', 'edit' => 'Edit employee details.', 'delete' => 'Delete employee records.'],
        'documents' => ['view' => 'View employee documents.', 'create' => 'Upload documents.', 'delete' => 'Delete documents.'],
        'letters'   => ['view' => 'View issued letters.', 'create' => 'Create / issue letters.', 'delete' => 'Delete letters.', 'offer' => 'Access Offer letters.', 'increment' => 'Access Increment letters.', 'confirmation' => 'Access Confirmation letters.', 'promotion' => 'Access Promotion letters.', 'experience' => 'Access Experience / relieving letters.', 'internship' => 'Access Internship certificates.'],
        'assets'    => ['view' => 'View assets and assignments.', 'assign' => 'Assign assets to employees.', 'return' => 'Return assets / process No-Due clearance.'],
        'attendance'=> ['view' => 'View attendance records.', 'mark' => 'Mark daily attendance.', 'edit' => 'Edit attendance records.', 'export' => 'Export attendance data.', 'report' => 'Open the Attendance Report.', 'calendar' => 'View the monthly attendance calendar.'],
        'leaves'    => ['view' => 'View leave requests.', 'create' => 'Apply for leave.', 'edit' => 'Edit leave requests.', 'delete' => 'Delete leave requests.', 'approve' => 'Approve or reject leave requests.'],
        'leave_history' => ['view' => 'View leave history.'],
        'holidays'  => ['view' => 'View the holiday calendar.', 'create' => 'Add holidays.', 'edit' => 'Edit holidays.', 'delete' => 'Delete holidays.'],
        'compoff'   => ['view' => 'View comp-off records.', 'edit' => 'Grant and manage comp-offs.'],
        'compoff_credits' => ['view' => 'View comp-off credit balances.', 'edit' => 'Adjust comp-off credits.'],
        'od'        => ['view' => 'View On-Duty (OD) requests.', 'create' => 'Create OD requests.', 'edit' => 'Edit OD requests.', 'delete' => 'Delete OD requests.'],
        'payroll'   => ['view' => 'View salary slips.', 'calculate' => 'Open Salary Calculation.', 'process' => 'Process payroll, generate slips & manage salary components.', 'export' => 'Export payroll CSV (includes bank / PAN details).'],
        'loans'     => ['view' => 'View loans & advances.', 'create' => 'Create and manage loans / advances.'],
        'increments'=> ['view' => 'View increment history.', 'create' => 'Add salary increments.'],
        'promotions'=> ['view' => 'View promotions.', 'create' => 'Add promotions.'],
        'benefits'  => ['view' => 'View employee benefits.', 'create' => 'Assign and manage benefits.'],
        'bonuses'   => ['view' => 'View bonuses & incentives.', 'create' => 'Add bonuses & incentives.'],
        'report_benefits' => ['view' => 'View the Monthly Benefits report.', 'export' => 'Export the Monthly Benefits report.'],
        'report_bonus'    => ['view' => 'View the Bonus report.', 'export' => 'Export the Bonus report.'],
        'report_history'  => ['view' => 'View the Employee History report.', 'export' => 'Export the Employee History report.'],
        'report_impact'   => ['view' => 'View the Payroll Impact report.', 'export' => 'Export the Payroll Impact report.'],
        'training'  => ['view' => 'View training content.', 'manage' => 'Manage training content.', 'enroll' => 'Enroll in training.'],
        'roles'     => ['view' => 'View roles & permissions.', 'manage' => 'Create and edit roles & permissions.'],
        'users'     => ['view' => 'View user accounts.', 'create' => 'Create user accounts.', 'edit' => 'Edit user accounts.', 'delete' => 'Delete user accounts.'],
        'pwa'       => ['view' => 'View Mobile Access (PWA) settings.', 'manage' => 'Manage Mobile Access (PWA) settings.'],
        'sso'       => ['view' => 'View SSO settings.', 'manage' => 'Manage SSO settings.'],
        'activity'  => ['view' => 'View the activity / audit log.'],
        'settings'  => [
            'view' => 'Open the Settings area and its overview page.',
            'manage' => 'General settings management (legacy catch-all).',
            'entities' => 'Manage company entities — name, address, logo and signatory used on letters & payslips.',
            'departments' => 'Create, edit and delete departments.',
            'designations' => 'Create, edit and delete job designations / titles.',
            'leave_types' => 'Configure leave types and their rules (paid/unpaid, annual quota).',
            'holiday_types' => 'Configure holiday categories used by the Holidays module.',
            'benefit_fund_types' => 'Configure benefit fund types (PF, ESI, etc.) selectable on employee benefits.',
            'asset_categories' => 'Manage asset categories used when registering assets.',
            'office' => 'Set office timings, late grace, OT trigger/baseline, tea breaks and lunch batches.',
            'branding' => 'Set the sidebar brand name and upload the brand logo.',
        ],
    ];
    if (isset($map[$module][$action])) return $map[$module][$action];

    // Fallback: "<Verb> <Module label>." so every permission still gets a hint.
    $verbs = ['view'=>'View','create'=>'Create','edit'=>'Edit','delete'=>'Delete',
              'export'=>'Export','manage'=>'Manage','approve'=>'Approve','mark'=>'Mark',
              'assign'=>'Assign','return'=>'Return','enroll'=>'Enroll in','process'=>'Process',
              'calculate'=>'Calculate','report'=>'Report','calendar'=>'Calendar'];
    $verb = $verbs[$action] ?? ucfirst($action);
    return $verb . ' ' . module_label($module) . '.';
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
