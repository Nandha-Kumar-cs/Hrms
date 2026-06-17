<?php
/**
 * MagDyn HRMS — Activity Log helper
 *
 * Records audit entries into activity_logs. Mirrors the Laravel ActivityLog
 * model's record() / recordWithChanges() helpers.
 *
 * Logging must NEVER break a page, so all DB work is wrapped in try/catch.
 *
 * Usage:
 *   activity_log('created', 'Employee', "Added employee: Jane (EMP0009)");
 *   activity_log('updated', 'Employee', "Updated employee: Jane (EMP0009)", [
 *       ['field' => 'Department', 'from' => 'Sales', 'to' => 'Marketing'],
 *   ]);
 */

/**
 * Record an activity. When $changes is non-empty the description is stored as
 * JSON {summary, changes:[{field,from,to}]} (rendered as old→new in the viewer).
 */
function activity_log(string $action, string $module, string $description, array $changes = [], ?array $actor = null): void
{
    try {
        $user  = $actor ?? (function_exists('current_user') ? current_user() : null);
        $uid   = isset($user['id']) ? (int)$user['id'] : null;
        $uname = $user['name'] ?? 'System';

        if (!empty($changes)) {
            $description = json_encode(
                ['summary' => $description, 'changes' => array_values($changes)],
                JSON_UNESCAPED_UNICODE
            );
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        if ($ip !== null) $ip = substr($ip, 0, 45);

        db()->prepare(
            'INSERT INTO activity_logs (user_id, user_name, action, module, description, ip_address, created_at)
             VALUES (?,?,?,?,?,?,NOW())'
        )->execute([$uid, $uname, $action, $module, $description, $ip]);
    } catch (Throwable $e) {
        error_log('activity_log failed: ' . $e->getMessage());
    }
}

/** Resolve a "Name (CODE)" label for an employee id, for log descriptions. */
function activity_emp_label(int $empId): string
{
    try {
        $s = db()->prepare('SELECT name, employee_id FROM employees WHERE id = ?');
        $s->execute([$empId]);
        $r = $s->fetch();
        return $r ? ($r['name'] . ' (' . $r['employee_id'] . ')') : ('Employee #' . $empId);
    } catch (Throwable $e) {
        return 'Employee #' . $empId;
    }
}

/**
 * Build a [field, from, to] change row for activity_log()'s $changes array,
 * returning null when the value did not actually change (so callers can filter).
 */
function activity_change(string $field, $from, $to): ?array
{
    $f = (string)$from;
    $t = (string)$to;
    if ($f === $t) return null;
    return ['field' => $field, 'from' => $f === '' ? '—' : $f, 'to' => $t === '' ? '—' : $t];
}
