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

    $findStruct = $db->prepare(
        'SELECT id, basic, hra, conveyance, medical, special_allow, other_allow, gross
           FROM salary_structures WHERE employee_id = ? AND effective_from = ? LIMIT 1'
    );
    /* salary_structures.gross and .lop_per_day are STORED GENERATED columns
     * (gross = basic+hra+conveyance+medical+special_allow+other_allow — see
     * install/schema.sql). They can only be changed by changing the components,
     * and naming one in an INSERT/UPDATE column list is an ERROR on a strict-mode
     * server (MySQL 8's default) — silently ignored only because this box runs
     * without STRICT_TRANS_TABLES. Both statements therefore write components
     * only, and every component is listed so gross is always fully determined. */
    $insStruct = $db->prepare(
        'INSERT INTO salary_structures (employee_id, basic, hra, conveyance, medical, special_allow, other_allow, effective_from, is_current, created_at)
         VALUES (?,?,?,?,?,?,?,?,0,NOW())'
    );
    $updStruct = $db->prepare(
        'UPDATE salary_structures
            SET basic = ?, hra = ?, conveyance = ?, medical = ?, special_allow = ?, other_allow = ?
          WHERE id = ?'
    );

    /**
     * Make the structure at $eff say $gross — inserting it when missing, and
     * CORRECTING it when it is there but stale.
     *
     * This used to `return` the moment a row existed at that date, so editing an
     * increment's amount without moving its date left the old gross in place.
     * Everything that reads the is_current row then showed the superseded figure:
     * the employee profile panel, the Salary Structure editor, the salary printed
     * on Increment/Promotion letters, and the payroll employee list
     * (security audit M-10).
     *
     * A gross change rescales whatever component split is already on the row, so
     * a breakdown an admin entered by hand in payroll/salary_structure.php keeps
     * its proportions instead of being flattened back to the default formula.
     * Only when the existing components do not reconcile with the old gross does
     * it fall back to the 50 / 20 / 10 / rest split used on insert.
     */
    $ensure = function (float $gross, string $eff) use ($empId, $findStruct, $insStruct, $updStruct): void {
        if ($gross <= 0) return;

        $standard = function (float $g): array {
            $basic = round($g * 0.50, 2);
            $hra   = round($g * 0.20, 2);
            $conv  = round($g * 0.10, 2);
            return ['basic' => $basic, 'hra' => $hra, 'conveyance' => $conv, 'medical' => 0.0,
                    'special_allow' => round($g - $basic - $hra - $conv, 2), 'other_allow' => 0.0];
        };

        $findStruct->execute([$empId, $eff]);
        $row = $findStruct->fetch(PDO::FETCH_ASSOC);

        if (!$row) {                                  // no row yet → create it
            $c = $standard($gross);
            $insStruct->execute([$empId, $c['basic'], $c['hra'], $c['conveyance'],
                                 $c['medical'], $c['special_allow'], $c['other_allow'], $eff]);
            return;
        }

        $old = (float) $row['gross'];
        if (abs($old - $gross) < 0.005) return;       // already correct → nothing to do

        $parts = ['basic', 'hra', 'conveyance', 'medical', 'special_allow', 'other_allow'];
        $sum   = 0.0;
        foreach ($parts as $k) $sum += (float) $row[$k];

        // Where gross is generated the sum always reconciles, so this normally
        // takes the rescale path; the guard still matters for a zeroed row, and
        // for older installs where gross is a plain stored column.
        if ($old > 0 && abs($sum - $old) < 0.05) {    // components reconcile → keep their shape
            $factor = $gross / $old;
            $c = [];
            foreach ($parts as $k) $c[$k] = round((float) $row[$k] * $factor, 2);
            // Absorb rounding drift so the parts still add up to gross exactly.
            $c['special_allow'] = round($c['special_allow'] + ($gross - array_sum($c)), 2);
        } else {
            $c = $standard($gross);
        }

        $updStruct->execute([
            $c['basic'], $c['hra'], $c['conveyance'], $c['medical'],
            $c['special_allow'], $c['other_allow'], (int) $row['id'],
        ]);
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
