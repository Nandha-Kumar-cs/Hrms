<?php
/**
 * Comp-Off engine — ported from the Employee_Management reference
 * (App\Helpers\WorkCalendar + App\Services\CompOffService) to HRMS plain PHP.
 *
 * Weekly-off rule (same as modules/attendance/mark.php): Sundays and the
 * 1st & 3rd Saturday of each month are non-working. A holidays row with
 * is_working_day = 1 overrides everything and counts as a working day.
 *
 * Two comp-off currencies, mirroring the reference:
 *   • comp_offs        — granted to every employee when a holiday is declared a
 *                        working day; later "availed" on a chosen date.
 *   • comp_off_credits — earned when an employee actually works a non-working
 *                        day (auto-created from attendance).
 *
 * HRMS has no leave-request workflow (only leave_types), so credit *consumption*
 * via leave does not apply here; balance is reported as
 *   credited credits − availed comp-offs   (see comp_off_balance()).
 */

require_once __DIR__ . '/bootstrap.php';

/* ─────────────────────────────────────────────────────────────────────────────
 * WorkCalendar
 * ───────────────────────────────────────────────────────────────────────────*/
final class WorkCalendar
{
    public static array $dayTypeLabels = [
        'sunday'         => 'Sunday',
        'saturday'       => 'Saturday Off',
        'public_holiday' => 'Public Holiday',
    ];

    public static array $dayTypeColors = [
        'sunday'         => 'primary',
        'saturday'       => 'warning',
        'public_holiday' => 'danger',
    ];

    /** 1st or 3rd Saturday of its month? */
    public static function isFirstOrThirdSaturday(DateTimeInterface $date): bool
    {
        if ((int)$date->format('N') !== 6) return false;
        $count = 0;
        $d = (new DateTime($date->format('Y-m-01')));
        $target = $date->format('Y-m-d');
        while ($d->format('Y-m-d') <= $target) {
            if ((int)$d->format('N') === 6) $count++;
            $d->modify('+1 day');
        }
        return in_array($count, [1, 3], true);
    }

    /**
     * Is the given date a working day?
     * A holidays row flagged is_working_day overrides the weekly-off rules.
     */
    public static function isWorkingDay(DateTimeInterface $date): bool
    {
        $ds = $date->format('Y-m-d');
        $stmt = db()->prepare('SELECT is_working_day FROM holidays WHERE h_date = ? LIMIT 1');
        $stmt->execute([$ds]);
        $holiday = $stmt->fetch();

        if ($holiday && (int)$holiday['is_working_day'] === 1) return true; // explicit override
        if ((int)$date->format('N') === 7) return false;                     // Sunday
        if (self::isFirstOrThirdSaturday($date)) return false;               // 1st/3rd Sat
        if ($holiday) return false;                                          // declared holiday
        return true;
    }

    /** All 1st & 3rd Saturdays of a year as ['date' => 'YYYY-MM-DD', 'label' => '1st Saturday — March']. */
    public static function firstAndThirdSaturdays(int $year): array
    {
        $result = [];
        for ($month = 1; $month <= 12; $month++) {
            $d   = new DateTime(sprintf('%04d-%02d-01', $year, $month));
            $end = (clone $d)->modify('last day of this month');
            $satCount = 0;
            while ($d <= $end) {
                if ((int)$d->format('N') === 6) {
                    $satCount++;
                    if (in_array($satCount, [1, 3], true)) {
                        $label = ($satCount === 1 ? '1st' : '3rd') . ' Saturday — ' . $d->format('F');
                        $result[$d->format('Y-m-d')] = $label;
                    }
                }
                $d->modify('+1 day');
            }
        }
        return $result;
    }

    /** Classify a non-working day → [day_type, holiday_name]. */
    public static function classifyNonWorkingDay(DateTimeInterface $date): array
    {
        if ((int)$date->format('N') === 7) return ['sunday', 'Sunday'];

        if (self::isFirstOrThirdSaturday($date)) {
            $count = 0;
            $d = new DateTime($date->format('Y-m-01'));
            $target = $date->format('Y-m-d');
            while ($d->format('Y-m-d') <= $target) {
                if ((int)$d->format('N') === 6) $count++;
                $d->modify('+1 day');
            }
            return ['saturday', ($count === 1 ? '1st' : '3rd') . ' Saturday'];
        }

        $stmt = db()->prepare('SELECT name FROM holidays WHERE h_date = ? LIMIT 1');
        $stmt->execute([$date->format('Y-m-d')]);
        $name = $stmt->fetchColumn();
        return ['public_holiday', $name !== false ? $name : 'Public Holiday'];
    }
}

/* ─────────────────────────────────────────────────────────────────────────────
 * Comp-off working-day declarations  (comp_off_working_days)
 * ───────────────────────────────────────────────────────────────────────────*/

/** The declaration row for a date, or null. */
function comp_off_working_day_for(string $date): ?array
{
    $stmt = db()->prepare('SELECT * FROM comp_off_working_days WHERE work_date = ? LIMIT 1');
    $stmt->execute([$date]);
    return $stmt->fetch() ?: null;
}

/** Declare (or refresh) a company working day. */
function comp_off_declare_working_day(string $date, string $dayType, ?string $holidayName, ?string $reason, ?int $userId): void
{
    db()->prepare(
        'INSERT INTO comp_off_working_days (work_date, day_type, holiday_name, reason, declared_by)
         VALUES (:d, :t, :n, :r, :u)
         ON DUPLICATE KEY UPDATE day_type = VALUES(day_type), holiday_name = VALUES(holiday_name),
                                 reason = VALUES(reason), declared_by = VALUES(declared_by)'
    )->execute([':d' => $date, ':t' => $dayType, ':n' => $holidayName, ':r' => $reason, ':u' => $userId]);
}

/** Remove a working-day declaration. */
function comp_off_undeclare_working_day(string $date): void
{
    db()->prepare('DELETE FROM comp_off_working_days WHERE work_date = ?')->execute([$date]);
}

/* ─────────────────────────────────────────────────────────────────────────────
 * Comp-off credits  (comp_off_credits)
 * ───────────────────────────────────────────────────────────────────────────*/

/**
 * comp_off_credits predates the credit_days column; add it lazily, the way this
 * project does elsewhere. NEVER call this from inside a transaction — ALTER TABLE
 * forces an implicit COMMIT (the M-5 lesson).
 */
function comp_off_credit_columns_ready(): void
{
    static $done = false;
    if ($done || db()->inTransaction()) return;
    $done = true;
    db_ensure_column('comp_off_credits', 'credit_days', 'DECIMAL(3,1) NOT NULL DEFAULT 1.0');
}

/**
 * What is a day worked on a non-working day actually worth? (security audit M-21)
 *
 * The old rule was "any present status → one full day", so a Half Day — or a
 * ten-minute punch that classified as one — earned a whole day off. Credit is now
 * proportional to hours actually worked, measured from the punch pair on the
 * attendance row:
 *
 *   ≥ COMP_OFF_MIN_HOURS_FULL  → 1.0 day
 *   ≥ COMP_OFF_MIN_HOURS_HALF  → 0.5 day
 *   otherwise                  → nothing
 *
 * No punch-out means the day cannot be measured, so it earns nothing — the same
 * position attendance takes on a missing check-out (M-18). Overnight pairs are
 * normalised, so a 22:00→06:00 shift counts as eight hours and not minus sixteen.
 */
function comp_off_credit_days_for(int $empId, string $date, string $status): float
{
    if (!in_array($status, ['On Time', 'Late', 'Half Day'], true)) return 0.0;

    $st = db()->prepare('SELECT in_time, out_time FROM attendance WHERE employee_id = ? AND att_date = ? LIMIT 1');
    $st->execute([$empId, $date]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    $in  = (string) ($row['in_time']  ?? '');
    $out = (string) ($row['out_time'] ?? '');
    if ($in === '' || $out === '') return 0.0;      // unverifiable → earns nothing

    $toMins = static function (string $t): int {
        $p = explode(':', $t);
        return ((int) ($p[0] ?? 0)) * 60 + ((int) ($p[1] ?? 0));
    };
    $inM  = $toMins($in);
    $outM = $toMins($out);
    if ($outM <= $inM) $outM += 1440;               // crossed midnight
    $hours = ($outM - $inM) / 60;

    $full = defined('COMP_OFF_MIN_HOURS_FULL') ? (float) COMP_OFF_MIN_HOURS_FULL : 6.0;
    $half = defined('COMP_OFF_MIN_HOURS_HALF') ? (float) COMP_OFF_MIN_HOURS_HALF : 3.0;

    if ($hours >= $full) return 1.0;
    if ($hours >= $half) return 0.5;
    return 0.0;
}

/**
 * Auto-generate (or cancel) a comp-off credit for one attendance row.
 * Present on a non-working / declared-working day → credited; otherwise cancelled.
 * HRMS "present" statuses: On Time, Late, Half Day.
 *
 * @return string '' (no change / regular day), 'credited' or 'cancelled'
 */
function comp_off_process_attendance_credit(int $empId, string $date, string $status): string
{
    $dateObj  = new DateTime($date);
    $declared = comp_off_working_day_for($date);

    // Regular working day with no declaration → nothing to do.
    if (!$declared && WorkCalendar::isWorkingDay($dateObj)) return '';

    if ($declared) {
        $dayType     = $declared['day_type'];
        $holidayName = $declared['holiday_name'];
    } else {
        [$dayType, $holidayName] = WorkCalendar::classifyNonWorkingDay($dateObj);
    }

    // How much is this day actually worth? 0 = not enough worked to earn anything.
    $creditDays = comp_off_credit_days_for($empId, $date, $status);

    if ($creditDays > 0) {
        comp_off_credit_columns_ready();
        db()->prepare(
            'INSERT INTO comp_off_credits (employee_id, work_date, day_type, holiday_name, credit_days, status)
             VALUES (:e, :d, :t, :n, :c, "credited")
             ON DUPLICATE KEY UPDATE day_type = VALUES(day_type), holiday_name = VALUES(holiday_name),
                                     credit_days = VALUES(credit_days), status = "credited"'
        )->execute([':e' => $empId, ':d' => $date, ':t' => $dayType, ':n' => $holidayName, ':c' => $creditDays]);
        return 'credited';
    }

    // Not present → cancel an existing credit if any.
    db()->prepare('UPDATE comp_off_credits SET status = "cancelled" WHERE employee_id = ? AND work_date = ?')
        ->execute([$empId, $date]);
    return 'cancelled';
}

/**
 * Re-scan attendance over a date range and sync comp-off credits.
 * Returns the number of attendance records processed.
 */
function comp_off_sync_credits_for_range(string $from, string $to): int
{
    $rows = db()->prepare('SELECT employee_id, att_date, status FROM attendance WHERE att_date BETWEEN ? AND ?');
    $rows->execute([$from, $to]);
    $n = 0;
    foreach ($rows->fetchAll() as $r) {
        comp_off_process_attendance_credit((int)$r['employee_id'], $r['att_date'], $r['status']);
        $n++;
    }
    return $n;
}

/* ─────────────────────────────────────────────────────────────────────────────
 * Balance
 * ───────────────────────────────────────────────────────────────────────────*/

/** The configured comp-off leave type, or null. */
function comp_off_leave_type(): ?array
{
    $row = db()->query("SELECT * FROM leave_types WHERE is_comp_off = 1 AND status = 'active' ORDER BY id LIMIT 1")->fetch();
    return $row ?: null;
}

/** Credited (active) credit count for an employee. */
/**
 * Credits an employee can still spend (security audit M-21).
 *
 * Two changes from the original COUNT(*):
 *   • It SUMS credit_days, so a half-day credit is worth half a day rather than
 *     the whole day COUNT(*) gave it.
 *   • It ignores credits older than COMP_OFF_EXPIRY_DAYS. There was no expiry at
 *     all, so a day worked years ago stayed spendable for ever and the liability
 *     only ever grew. Set COMP_OFF_EXPIRY_DAYS to 0 to restore never-expires.
 */
function comp_off_credited_count(int $empId): float
{
    comp_off_credit_columns_ready();
    $days = defined('COMP_OFF_EXPIRY_DAYS') ? (int) COMP_OFF_EXPIRY_DAYS : 0;

    $sql    = "SELECT COALESCE(SUM(credit_days),0) FROM comp_off_credits
                WHERE employee_id = ? AND status = 'credited'";
    $params = [$empId];
    if ($days > 0) {
        $sql .= ' AND work_date >= ?';
        $params[] = date('Y-m-d', strtotime('-' . $days . ' days'));
    }
    $s = db()->prepare($sql);
    $s->execute($params);
    return (float) $s->fetchColumn();
}

/** Credits that have lapsed unused — shown so an expiry is never a silent loss. */
function comp_off_expired_count(int $empId): float
{
    $days = defined('COMP_OFF_EXPIRY_DAYS') ? (int) COMP_OFF_EXPIRY_DAYS : 0;
    if ($days <= 0) return 0.0;
    comp_off_credit_columns_ready();
    $s = db()->prepare("SELECT COALESCE(SUM(credit_days),0) FROM comp_off_credits
                         WHERE employee_id = ? AND status = 'credited' AND work_date < ?");
    $s->execute([$empId, date('Y-m-d', strtotime('-' . $days . ' days'))]);
    return (float) $s->fetchColumn();
}

/** Availed comp-offs for an employee (holiday-driven grants taken). */
function comp_off_availed_count(int $empId): int
{
    $s = db()->prepare("SELECT COUNT(*) FROM comp_offs WHERE employee_id = ? AND status = 'availed'");
    $s->execute([$empId]);
    return (int)$s->fetchColumn();
}

/**
 * Comp-off days "used" — approved comp-off-type leave days (matches the reference).
 * Optionally exclude one leave request (used when re-approving/editing it).
 */
function comp_off_used_days(int $empId, ?int $excludeRequestId = null): int
{
    $type = comp_off_leave_type();
    if (!$type) return 0;
    $sql = "SELECT COALESCE(SUM(days_requested),0) FROM leave_requests
            WHERE employee_id = ? AND leave_type_id = ? AND status = 'approved'";
    $params = [$empId, (int)$type['id']];
    if ($excludeRequestId) { $sql .= ' AND id <> ?'; $params[] = $excludeRequestId; }
    $s = db()->prepare($sql);
    $s->execute($params);
    return (int)$s->fetchColumn();
}

/** Comp-off balance = credited credits − used comp-off leave days (never below 0). */
function comp_off_balance(int $empId): float
{
    // float, not int: a half-day credit must not round up into a whole day (M-21).
    return max(0.0, comp_off_credited_count($empId) - comp_off_used_days($empId));
}

/**
 * Validate an employee has enough comp-off balance for $days.
 * Returns an error message string, or null if OK (mirrors CompOffService::checkBalance).
 */
function comp_off_check_balance(int $empId, int $days, ?int $excludeRequestId = null): ?string
{
    if (!comp_off_leave_type()) {
        return 'Comp Off leave type is not configured. Please contact admin.';
    }
    $balance = max(0.0, comp_off_credited_count($empId) - comp_off_used_days($empId, $excludeRequestId));
    if ($balance < $days) {
        $msg = 'Insufficient Comp Off balance. Available: ' . rtrim(rtrim(number_format($balance, 1), '0'), '.')
             . ' day(s), Requested: ' . $days . ' day(s).';
        // If credits lapsed, say so — otherwise a balance that quietly shrank
        // looks like the system losing days rather than a policy being applied.
        $lapsed = comp_off_expired_count($empId);
        if ($lapsed > 0) {
            $msg .= ' ' . rtrim(rtrim(number_format($lapsed, 1), '0'), '.') . ' day(s) expired after '
                  . (int) COMP_OFF_EXPIRY_DAYS . ' days and can no longer be used.';
        }
        return $msg . ' Earn Comp Off credits by working a full day on a public holiday or weekly off.';
    }
    return null;
}

/* ─────────────────────────────────────────────────────────────────────────────
 * Bulk comp-off operations  (comp_offs)  — shared by the Holiday & Comp Off pages
 * ───────────────────────────────────────────────────────────────────────────*/

/** Grant a comp-off to every active employee for a holiday date. Returns count granted. */
function comp_off_grant_all(string $date, string $name): int
{
    $emps = db()->query("SELECT id FROM employees WHERE status = 'Active'")->fetchAll(PDO::FETCH_COLUMN);
    if (!$emps) return 0;
    $ins = db()->prepare(
        'INSERT INTO comp_offs (employee_id, holiday_date, holiday_name, status)
         VALUES (:e, :d, :n, "pending")
         ON DUPLICATE KEY UPDATE holiday_name = VALUES(holiday_name)'
    );
    foreach ($emps as $eid) {
        $ins->execute([':e' => (int)$eid, ':d' => $date, ':n' => $name]);
    }
    return count($emps);
}

/**
 * Mark all pending comp-offs for a holiday date as availed on $availedDate,
 * and write a "Comp Off" attendance record for each employee on that date.
 * Returns the number of comp-offs availed.
 */
function comp_off_avail_all(string $date, string $availedDate, ?int $markedBy): int
{
    $pending = db()->prepare("SELECT * FROM comp_offs WHERE holiday_date = ? AND status = 'pending'");
    $pending->execute([$date]);
    $rows = $pending->fetchAll();
    if (!$rows) return 0;

    $upd = db()->prepare("UPDATE comp_offs SET status = 'availed', availed_date = ? WHERE id = ?");
    $att = db()->prepare(
        'INSERT INTO attendance (employee_id, att_date, status, in_time, out_time, ot_hours, remarks, marked_by)
         VALUES (:e, :d, "Comp Off", NULL, NULL, NULL, :r, :m)
         ON DUPLICATE KEY UPDATE status = "Comp Off", in_time = NULL, out_time = NULL,
                                 ot_hours = NULL, remarks = VALUES(remarks), marked_by = VALUES(marked_by)'
    );
    foreach ($rows as $co) {
        $upd->execute([$availedDate, $co['id']]);
        $att->execute([
            ':e' => (int)$co['employee_id'], ':d' => $availedDate,
            ':r' => 'Comp Off — ' . $co['holiday_name'], ':m' => $markedBy,
        ]);
    }
    return count($rows);
}

/**
 * Remove all comp-offs for a holiday date, reverting any "Comp Off" attendance
 * records auto-created when they were availed. Returns the number removed.
 */
function comp_off_remove_all(string $date): int
{
    $rows = db()->prepare('SELECT * FROM comp_offs WHERE holiday_date = ?');
    $rows->execute([$date]);
    $all = $rows->fetchAll();

    $del = db()->prepare(
        "DELETE FROM attendance WHERE employee_id = ? AND att_date = ?
         AND status = 'Comp Off' AND remarks LIKE 'Comp Off — %'"
    );
    foreach ($all as $co) {
        if ($co['status'] === 'availed' && $co['availed_date']) {
            $del->execute([(int)$co['employee_id'], $co['availed_date']]);
        }
    }
    db()->prepare('DELETE FROM comp_offs WHERE holiday_date = ?')->execute([$date]);
    return count($all);
}

/** Per-holiday-date summary [date => ['pending'=>n,'availed'=>n]] for a year. */
function comp_off_year_summary(int $year): array
{
    $stmt = db()->prepare(
        "SELECT holiday_date, status, COUNT(*) c FROM comp_offs
         WHERE YEAR(holiday_date) = ? GROUP BY holiday_date, status"
    );
    $stmt->execute([$year]);
    $map = [];
    foreach ($stmt->fetchAll() as $r) {
        $map[$r['holiday_date']][$r['status']] = (int)$r['c'];
    }
    return $map;
}

/** Dates in a year that have at least one availed comp-off (toggle is locked for these). */
function comp_off_availed_dates(int $year): array
{
    $stmt = db()->prepare(
        "SELECT DISTINCT holiday_date FROM comp_offs WHERE YEAR(holiday_date) = ? AND status = 'availed'"
    );
    $stmt->execute([$year]);
    return array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
}
