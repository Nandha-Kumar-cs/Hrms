<?php
/**
 * Attendance ↔ Shift resync
 * ─────────────────────────
 * PROBLEM this solves: attendance status is classified when a day is MARKED or
 * IMPORTED, using the shift in force at that moment, and the resulting shift is
 * stamped on the row (attendance.shift_id). If an admin later changes the
 * employee's shift — or adds/edits a rotation block — the already-stored rows
 * keep the OLD status, so the Attendance Report shows stale values until the
 * Excel file is imported again.
 *
 * This re-derives the STATUS ONLY, from the punches already in the database and
 * the shift currently assigned for each attendance date. No Excel needed.
 *
 * Design notes
 * ────────────
 * • STALENESS IS CHEAP TO DETECT. A row is stale only when the shift stamped on
 *   it differs from the shift now resolved for that date. The SQL below returns
 *   ONLY those rows, so a report refresh with nothing changed does zero writes.
 * • PUNCHES ARE NEVER TOUCHED. in_time / out_time / remarks are read-only here.
 * • MANUAL STATUSES ARE NEVER OVERWRITTEN: OD, Comp Off, On Leave and Holiday
 *   are admin decisions, not derivations.
 * • MANUALLY TYPED OT IS PRESERVED. OT is only recomputed for employees on
 *   auto-OT (employees.ot_enabled = 1). The one exception is a shift with OT
 *   switched off, where OT is forced to 0 — a straight shift never earns OT.
 * • The classification rules mirror modules/attendance/mark.php exactly, so a
 *   resynced row is identical to one re-marked by hand.
 */

/**
 * Re-derive attendance status for rows whose stamped shift no longer matches the
 * shift assigned for that date.
 *
 * @param  string   $from   inclusive start date  (Y-m-d)
 * @param  string   $to     inclusive end date    (Y-m-d)
 * @param  int|null $empId  limit to one employee, or null for all
 * @return int              number of rows actually updated
 */
function attendance_resync_shifts(string $from, string $to, ?int $empId = null): int
{
    static $done = [];                       // one pass per range per request
    $key = $from . '|' . $to . '|' . ($empId ?? 'all');
    if (isset($done[$key])) return 0;
    $done[$key] = true;

    $db = db();

    // Resolve, per row, the shift that SHOULD apply on that attendance date:
    //   1. the rotation block covering the date (employee_shift_schedule)
    //   2. else the employee's standing shift (employees.shift_id)
    // Then keep only rows where that differs from what is stamped on the row.
    // <=> is MySQL's NULL-safe equality, so NULL↔NULL counts as unchanged.
    $rotationJoin = "COALESCE(
            (SELECT sc.shift_id FROM employee_shift_schedule sc
              WHERE sc.employee_id = a.employee_id
                AND sc.start_date <= a.att_date
                AND (sc.end_date IS NULL OR sc.end_date >= a.att_date)
              ORDER BY sc.start_date DESC, sc.id DESC LIMIT 1),
            e.shift_id)";

    $sql = "SELECT * FROM (
                SELECT a.id, a.employee_id, a.att_date, a.status,
                       a.in_time, a.out_time, a.ot_hours,
                       a.shift_id AS old_shift,
                       {$rotationJoin} AS new_shift,
                       COALESCE(e.ot_enabled, 0) AS emp_ot
                  FROM attendance a
                  JOIN employees e ON e.id = a.employee_id
                 WHERE a.att_date BETWEEN ? AND ?"
          . ($empId ? ' AND a.employee_id = ?' : '') . "
            ) x
            WHERE NOT (x.old_shift <=> x.new_shift)";

    $params = [$from, $to];
    if ($empId) $params[] = $empId;

    try {
        $st = $db->prepare($sql);
        $st->execute($params);
        $stale = $st->fetchAll();
    } catch (Throwable $e) {
        // Shift system not installed on this database — nothing to resync.
        return 0;
    }
    if (!$stale) return 0;                   // nothing changed → no writes at all

    $upd = $db->prepare(
        'UPDATE attendance SET shift_id = ?, status = ?, ot_hours = ? WHERE id = ?'
    );

    $updated = 0;
    foreach ($stale as $r) {
        $newShiftId = $r['new_shift'] !== null ? (int)$r['new_shift'] : null;
        $status     = (string)$r['status'];
        $otHours    = $r['ot_hours'] !== null ? (float)$r['ot_hours'] : null;

        // Timing for the NEW shift; null shift → legacy globals via the helper.
        $t = $newShiftId !== null
            ? _resync_timing_from_shift(get_shift($newShiftId))
            : _resync_legacy_timing();

        // ── Status ────────────────────────────────────────────────────────────
        // Mirrors mark.php: auto-classify only when a check-in exists and the
        // stored status is not one an admin set by hand.
        if (!empty($r['in_time']) && !in_array($status, ['OD', 'Comp Off', 'On Leave', 'Holiday'], true)) {
            $inMins = time_to_mins(substr((string)$r['in_time'], 0, 5));
            if ($inMins >= $t['half_cutoff'])      $status = 'Half Day';
            elseif ($inMins > $t['late_thresh'])   $status = 'Late';
            else                                   $status = 'On Time';

            // No checkout on a present day → Absent (mark.php's no-checkout rule)
            if (in_array($status, ['On Time', 'Late'], true) && empty($r['out_time'])) {
                $status = 'Absent';
            }
        }

        // ── OT ────────────────────────────────────────────────────────────────
        if (!$t['shift_ot_on']) {
            $otHours = null;                                   // straight shift: never any OT
        } elseif ((int)$r['emp_ot'] === 1) {                   // auto-OT employee: recompute
            $otHours = null;
            if (!empty($r['out_time']) && $t['ot_trigger'] !== null && $t['ot_baseline'] !== null) {
                $outMins = time_to_mins(substr((string)$r['out_time'], 0, 5));
                if ($outMins >= $t['ot_trigger']) {
                    $calc = round(($outMins - $t['ot_baseline']) / 60, 2);
                    if ($calc > 0) $otHours = $calc;
                }
            }
        }
        // else: manual-OT employee on an OT-enabled shift → keep what was typed.

        $upd->execute([$newShiftId, $status, $otHours, (int)$r['id']]);
        $updated++;
    }

    return $updated;
}

/** Timing set from a shifts row (same shape mark.php builds). */
function _resync_timing_from_shift(array $s): array
{
    if (!$s) return _resync_legacy_timing();
    $start = time_to_mins(substr((string)$s['start_time'], 0, 5));
    return [
        'late_thresh' => $start + (int)$s['daily_grace_mins'],
        'half_cutoff' => !empty($s['half_day_cutoff'])
                            ? time_to_mins(substr((string)$s['half_day_cutoff'], 0, 5))
                            : $start + 120,
        'shift_ot_on' => (bool)$s['ot_enabled'],
        'ot_trigger'  => !empty($s['ot_trigger_time'])  ? time_to_mins(substr((string)$s['ot_trigger_time'], 0, 5))  : null,
        'ot_baseline' => !empty($s['ot_baseline_time']) ? time_to_mins(substr((string)$s['ot_baseline_time'], 0, 5)) : null,
    ];
}

/** Legacy global settings — used when a row resolves to no shift at all. */
function _resync_legacy_timing(): array
{
    $start = (int) setting_office_start_mins();
    return [
        'late_thresh' => $start + (int) setting_daily_grace_mins(),
        'half_cutoff' => $start + 120,
        'shift_ot_on' => true,
        'ot_trigger'  => (int) setting_ot_trigger_mins(),
        'ot_baseline' => (int) setting_ot_baseline_mins(),
    ];
}
