<?php
/**
 * MagDyn HRMS — Salary sync helpers.
 *
 * An employee's salary is read from two places:
 *   • employees.fixed_salary           — current CTC (list, edit form, fallback)
 *   • salary_structures                — dated history (effective_from). The row
 *     flagged is_current = 1 is the one active TODAY and drives the profile.
 *
 * Increments are the audit trail (previous_salary → new_salary, effective_date).
 * Recording one does not, by itself, update the salary the rest of the app reads.
 * sync_current_salary_from_increments() reconciles everything after any
 * create / edit / delete of an increment:
 *
 *   1. Builds a COMPLETE, dated salary_structures history from the increments —
 *      one row per increment effective date (gross = new_salary) plus one for the
 *      pre-increment original salary (the earliest increment's previous_salary,
 *      dated at the employee's join date). This lets payroll for ANY past month
 *      resolve the salary that applied then (see PayrollCalculator::getSalaryForMonth
 *      and modules/payroll/process.php).
 *   2. Sets employees.fixed_salary to the salary active as of TODAY (the latest
 *      increment whose effective_date has arrived) — future-dated increments do
 *      not change the current CTC until their date.
 *   3. Marks is_current on the structure active as of today.
 *
 * Idempotent: safe to call repeatedly; only fills gaps and fixes the flags.
 */
function sync_current_salary_from_increments(PDO $db, int $empId): void {
    if ($empId <= 0) return;
    $today = date('Y-m-d');

    $incs = $db->prepare(
        'SELECT effective_date, previous_salary, new_salary FROM employee_increments
         WHERE employee_id = ? ORDER BY effective_date ASC, id ASC'
    );
    $incs->execute([$empId]);
    $rows = $incs->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return;   // no increments → leave the salary untouched

    $hasStruct = $db->prepare('SELECT 1 FROM salary_structures WHERE employee_id = ? AND effective_from = ? LIMIT 1');
    $insStruct = $db->prepare(
        'INSERT INTO salary_structures (employee_id, basic, hra, conveyance, special_allow, gross, effective_from, is_current, created_at)
         VALUES (?,?,?,?,?,?,?,0,NOW())'
    );
    // Component breakdown mirrors modules/employee/edit.php (50 / 20 / 10 / rest).
    $ensure = function (float $gross, string $eff) use ($db, $empId, $hasStruct, $insStruct): void {
        if ($gross <= 0) return;
        $hasStruct->execute([$empId, $eff]);
        if ($hasStruct->fetchColumn()) return;       // already present → keep as-is
        $basic = round($gross * 0.50, 2);
        $hra   = round($gross * 0.20, 2);
        $conv  = round($gross * 0.10, 2);
        $spec  = round($gross - $basic - $hra - $conv, 2);
        $insStruct->execute([$empId, $basic, $hra, $conv, $spec, $gross, $eff]);
    };

    // 1a) Original (pre-increment) salary, dated at/just before the first increment.
    $first   = $rows[0];
    $origEff = (string)($db->query('SELECT join_date FROM employees WHERE id = ' . (int)$empId)->fetchColumn() ?: '');
    if ($origEff === '' || $origEff >= $first['effective_date']) {
        $origEff = date('Y-m-d', strtotime($first['effective_date'] . ' -1 day'));
    }
    $ensure((float)$first['previous_salary'], $origEff);

    // 1b) One structure per increment effective date.
    foreach ($rows as $r) $ensure((float)$r['new_salary'], $r['effective_date']);

    // 2) Current CTC = salary active as of today.
    $act = $db->prepare(
        'SELECT new_salary FROM employee_increments
         WHERE employee_id = ? AND effective_date <= ? ORDER BY effective_date DESC, id DESC LIMIT 1'
    );
    $act->execute([$empId, $today]);
    $activeNew = $act->fetchColumn();
    if ($activeNew !== false && (float)$activeNew > 0) {
        $cur = (float)$db->query('SELECT fixed_salary FROM employees WHERE id = ' . (int)$empId)->fetchColumn();
        if (abs((float)$activeNew - $cur) > 0.005) {
            $db->prepare('UPDATE employees SET fixed_salary = ? WHERE id = ?')->execute([(float)$activeNew, $empId]);
        }
    }

    // 3) is_current = the structure in effect today.
    $db->prepare('UPDATE salary_structures SET is_current = 0 WHERE employee_id = ?')->execute([$empId]);
    $db->prepare(
        'UPDATE salary_structures s
            JOIN (SELECT id FROM salary_structures
                   WHERE employee_id = ? AND effective_from <= ?
                   ORDER BY effective_from DESC, id DESC LIMIT 1) t ON t.id = s.id
            SET s.is_current = 1'
    )->execute([$empId, $today]);
}
