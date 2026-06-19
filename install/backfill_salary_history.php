<?php
/**
 * One-time (idempotent) backfill: rebuild dated salary_structures history from
 * the employee_increments audit trail and re-sync each employee's current CTC /
 * is_current flag.
 *
 * Run once after deploying the increment-effective-date payroll fix:
 *   /c/xampp8.2/php/php.exe install/backfill_salary_history.php
 *
 * Going forward this happens automatically on every increment create/edit/delete
 * (see modules/increments/save.php & delete.php). Safe to re-run.
 */
require __DIR__ . '/../config/app.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/salary_sync.php';

$db  = db();
$ids = $db->query('SELECT DISTINCT employee_id FROM employee_increments')->fetchAll(PDO::FETCH_COLUMN);

$n = 0;
foreach ($ids as $id) {
    sync_current_salary_from_increments($db, (int) $id);
    $n++;
}

echo "Salary history backfilled for {$n} employee(s) with increments.\n";
