<?php
/**
 * Mark Attendance — Daily Sheet
 * ─────────────────────────────
 * Saves attendance for all active employees for a chosen date.
 *
 * Status auto-classification (JS mirrors server-side logic):
 *   • No check-in                          → Absent
 *   • Check-in ≤ WORK_START + grace        → Present (On Time)
 *   • Check-in > WORK_START + grace        → Late
 *   • Check-in > WORK_START + 2h (11 AM)  → Half Day
 *   • Present/Late with NO checkout        → saved as Absent (no-checkout rule)
 *
 * Auto OT (ot_enabled employees only):
 *   • Checkout ≥ OT_TRIGGER_TIME           → OT hours = (checkout − OT_BASELINE_TIME) / 60
 */

$page_title = 'Mark Attendance';
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/comp_off.php';
require_login();
require_permission('attendance', 'mark');
block_cross_employee();   // marking attendance is a management action — self-scoped users can't.

$db   = db();
$user = current_user();

/* ── Date param ──────────────────────────────────────────────────────────── */
$selDate = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selDate)) $selDate = date('Y-m-d');
if ($selDate > date('Y-m-d')) $selDate = date('Y-m-d'); // no future dates

$prevDate = $_GET['prev_date'] ?? date('Y-m-d'); // for holiday modal back button

/* ── Holiday / non-working day check ─────────────────────────────────────── */
$holStmt = $db->prepare("SELECT name, is_working_day FROM holidays WHERE h_date = ? LIMIT 1");
$holStmt->execute([$selDate]);
$holRow = $holStmt->fetch() ?: null;
$holidayName     = $holRow['name'] ?? null;
$isWorkingHoliday = (bool)($holRow['is_working_day'] ?? false);

$dateObj = new DateTime($selDate);
$dow     = (int)$dateObj->format('N'); // 1=Mon…7=Sun

function _mark_is13Sat(DateTime $d): bool {
    if ((int)$d->format('N') !== 6) return false;
    $n = 0; $t = (clone $d)->modify('first day of this month');
    while ($t <= $d) { if ((int)$t->format('N') === 6) $n++; $t->modify('+1 day'); }
    return in_array($n, [1, 3]);
}

$isNonWorking    = false;
$nonWorkingLabel = null;
$compOffWorkingDay = null;

if ($isWorkingHoliday) {
    $compOffWorkingDay = $holRow;
    $compOffWorkingDay['day_type_label'] = 'Working Holiday';
} elseif ($dow === 7) {
    $isNonWorking    = true;
    $nonWorkingLabel = 'Sunday';
} elseif (_mark_is13Sat($dateObj)) {
    $n = 0; $t = (clone $dateObj)->modify('first day of this month');
    while ($t <= $dateObj) { if ((int)$t->format('N') === 6) $n++; $t->modify('+1 day'); }
    $isNonWorking    = true;
    $nonWorkingLabel = ($n === 1 ? '1st' : '3rd') . ' Saturday';
} elseif ($holidayName) {
    $isNonWorking    = true;
    $nonWorkingLabel = 'Public Holiday: ' . $holidayName;
}

/* ── Config: legacy global defaults (fallback when an employee has no shift) ─ */
$officeStartMins = (function($t) { [$h,$m] = explode(':', $t); return $h*60+$m; })(WORK_START_TIME);
$dailyGraceMins  = ATTENDANCE_GRACE_MINUTES;
$lateThreshMins  = $officeStartMins + $dailyGraceMins;
$halfDayCutoff   = $officeStartMins + 120;

$otTriggerTime  = OT_TRIGGER_TIME;
$otBaselineTime = OT_BASELINE_TIME;
$otTriggerMins  = (function($t) { [$h,$m] = explode(':', $t); return $h*60+$m; })($otTriggerTime);
$otBaselineMins = (function($t) { [$h,$m] = explode(':', $t); return $h*60+$m; })($otBaselineTime);

/* ── Per-employee shift timing map ────────────────────────────────────────────
 * Classification (On Time / Late / Half Day) and auto-OT are judged against the
 * EMPLOYEE'S SHIFT (employees.shift_id → shifts). Employees without a shift row
 * fall back to the legacy globals above, so behaviour is unchanged for them.
 * OT needs BOTH gates on: shifts.ot_enabled AND employees.ot_enabled.       */
// The shift is resolved AS OF $selDate, so a rotating employee is judged by the
// shift they were on that day — including when marking a back-dated sheet.
$shiftTiming = [];   // emp_id => [shift_id, shift_name, late_thresh, half_cutoff, shift_ot_on, ot_trigger, ot_baseline]
$_shiftCols = "e.id AS emp_id, s.id AS shift_id, s.name AS shift_name,
               s.start_time, s.daily_grace_mins, s.half_day_cutoff,
               s.ot_enabled AS shift_ot, s.ot_trigger_time, s.ot_baseline_time";
$stRows = [];
try {
    // Rotation-aware: the schedule row covering $selDate wins, else the standing shift.
    $stSt = $db->prepare(
        "SELECT {$_shiftCols}
           FROM employees e
           LEFT JOIN shifts s ON s.id = COALESCE(
                (SELECT sc.shift_id FROM employee_shift_schedule sc
                  WHERE sc.employee_id = e.id
                    AND sc.start_date <= :d1
                    AND (sc.end_date IS NULL OR sc.end_date >= :d2)
                  ORDER BY sc.start_date DESC, sc.id DESC LIMIT 1),
                e.shift_id)
          WHERE e.status = 'Active'"
    );
    $stSt->execute([':d1' => $selDate, ':d2' => $selDate]);
    $stRows = $stSt->fetchAll();
} catch (Throwable $e) {
    // No rotation table on this install — fall back to the standing shift only.
    try {
        $stRows = $db->query(
            "SELECT {$_shiftCols} FROM employees e
               LEFT JOIN shifts s ON s.id = e.shift_id
              WHERE e.status = 'Active'"
        )->fetchAll();
    } catch (Throwable $e2) { $stRows = []; }   // no shifts table at all → legacy globals
}
try {
    foreach ($stRows as $r) {
        if ($r['shift_id'] === null) continue;               // no shift → legacy fallback
        $sStart = time_to_mins(substr((string)$r['start_time'], 0, 5));
        $grace  = (int)$r['daily_grace_mins'];
        $shiftTiming[(int)$r['emp_id']] = [
            'shift_id'    => (int)$r['shift_id'],
            'shift_name'  => (string)$r['shift_name'],
            'late_thresh' => $sStart + $grace,
            'half_cutoff' => $r['half_day_cutoff'] !== null
                                ? time_to_mins(substr((string)$r['half_day_cutoff'], 0, 5))
                                : $sStart + 120,
            'shift_ot_on' => (bool)$r['shift_ot'],
            'ot_trigger'  => $r['ot_trigger_time']  !== null ? time_to_mins(substr((string)$r['ot_trigger_time'], 0, 5))  : null,
            'ot_baseline' => $r['ot_baseline_time'] !== null ? time_to_mins(substr((string)$r['ot_baseline_time'], 0, 5)) : null,
        ];
    }
} catch (Throwable $e) { /* shifts table absent — everyone uses legacy globals */ }

/** Timing set for one employee: their shift's, else the legacy globals. */
function _mark_timing(int $empId, array $shiftTiming, int $lateThresh, int $halfCut, int $otTrig, int $otBase): array {
    return $shiftTiming[$empId] ?? [
        'shift_id'    => null,
        'shift_name'  => null,
        'late_thresh' => $lateThresh,
        'half_cutoff' => $halfCut,
        'shift_ot_on' => true,          // legacy behaviour: OT gate is employee-level only
        'ot_trigger'  => $otTrig,
        'ot_baseline' => $otBase,
    ];
}

/* ── OT format helper ─────────────────────────────────────────────────────── */
function _mark_fmtOtHrs(float $dec): string {
    $total = (int)round($dec * 60);
    $h = intdiv($total, 60); $m = $total % 60;
    return ($h > 0 ? $h . 'h ' : '') . $m . 'm';
}

/* ── POST: save attendance ────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_save'])) {
    verify_csrf($_POST['csrf_token'] ?? '');

    $rows   = $_POST['attendance'] ?? [];
    $marked = 0;

    // Pre-fetch ot_enabled map for server-side OT calculation
    $otStmt = $db->query("SELECT id, ot_enabled FROM employees WHERE status='Active'");
    $otEnabledMap = array_column($otStmt->fetchAll(), 'ot_enabled', 'id');

    $upsert = $db->prepare(
        "INSERT INTO attendance
             (employee_id, shift_id, att_date, status, in_time, out_time, ot_hours, remarks, marked_by)
         VALUES (?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
             shift_id  = VALUES(shift_id),
             status    = VALUES(status),
             in_time   = VALUES(in_time),
             out_time  = VALUES(out_time),
             ot_hours  = VALUES(ot_hours),
             remarks   = VALUES(remarks),
             marked_by = VALUES(marked_by)"
    );

    foreach ($rows as $empId => $data) {
        $status   = $data['status']   ?? 'Absent';
        $inTime   = trim($data['in_time']  ?? '');
        $outTime  = trim($data['out_time'] ?? '');
        $remarks  = sanitize($data['remarks'] ?? '');

        // This employee's timing (their shift, or the legacy globals)
        $t = _mark_timing((int)$empId, $shiftTiming, $lateThreshMins, $halfDayCutoff, $otTriggerMins, $otBaselineMins);

        // Auto-classify whenever check-in is present; only skip truly manual statuses
        if ($inTime && !in_array($status, ['OD','Comp Off','On Leave'], true)) {
            $inParts = explode(':', $inTime);
            $inMins  = (int)$inParts[0] * 60 + (int)($inParts[1] ?? 0);
            if ($inMins >= $t['half_cutoff']) {
                $status = 'Half Day';
            } elseif ($inMins > $t['late_thresh']) {
                $status = 'Late';
            } else {
                $status = 'On Time';
            }
        }

        // No checkout on ANY check-in-derived status → Absent. Half Day used to be
        // exempt, which paid a late arrival with no punch-out MORE than a punctual
        // one with no punch-out (security audit M-18).
        if (in_array($status, attendance_checkin_statuses(), true) && !$outTime) {
            $status = 'Absent';
        }

        // OT hours — two gates: the SHIFT must allow OT and the employee must be
        // ot_enabled. A straight shift (ot_enabled=0) earns no OT, auto OR manual.
        if (!$t['shift_ot_on']) {
            $otHours = null;
        } else {
            $isOtEnabled = (bool)($otEnabledMap[(int)$empId] ?? false);
            if ($isOtEnabled) {
                $otHours = null;
                if ($outTime && $t['ot_trigger'] !== null && $t['ot_baseline'] !== null) {
                    $outParts = explode(':', $outTime);
                    $outMins  = (int)$outParts[0] * 60 + (int)($outParts[1] ?? 0);
                    if ($outMins >= $t['ot_trigger']) {
                        $otCalc = round(($outMins - $t['ot_baseline']) / 60, 2);
                        if ($otCalc > 0) $otHours = $otCalc;
                    }
                }
            } else {
                $otHours = ($data['ot_hours'] ?? '') !== '' ? (float)($data['ot_hours']) : null;
            }
        }

        // Clear times only for truly manual no-time statuses
        // Absent intentionally keeps in_time so the report can show orange A
        // (checked in but no checkout) vs red A (no data at all)
        if (in_array($status, ['Comp Off', 'On Leave'], true)) {
            $inTime  = null;
            $outTime = null;
            $otHours = null;
        }
        if ($status === 'Absent') {
            $outTime = null; // ensure no stale checkout on absent record
        }

        $upsert->execute([
            (int)$empId,
            $t['shift_id'],          // freeze the shift that judged this day
            $selDate,
            $status,
            $inTime  ?: null,
            $outTime ?: null,
            $otHours,
            $remarks ?: null,
            $user['id'],
        ]);

        // Auto comp-off credit: present on a non-working / declared-working day → credit.
        comp_off_process_attendance_credit((int)$empId, $selDate, $status);

        $marked++;
    }

    flash('success', "Attendance saved for $marked employees on " . date('d M Y', strtotime($selDate)) . '.');
    redirect(BASE_URL . '/modules/attendance/mark.php?date=' . $selDate);
}

/* ── Load employees + existing records for selected date ─────────────────── */
$employees = $db->prepare(
    "SELECT e.id, e.name, e.employee_id AS emp_code,
            COALESCE(d.name, '—') AS dept_name,
            COALESCE(e.ot_enabled, 0) AS ot_enabled,
            s.name AS shift_name,
            a.id        AS att_id,
            a.status    AS att_status,
            a.shift_id  AS att_shift_id,
            a.in_time,
            a.out_time,
            a.ot_hours,
            a.remarks
     FROM   employees e
     LEFT JOIN departments d  ON d.id = e.department_id
     LEFT JOIN shifts      s  ON s.id = e.shift_id
     LEFT JOIN attendance  a  ON a.employee_id = e.id AND a.att_date = ?
     WHERE  e.status = 'Active'
     ORDER  BY e.name"
);
$employees->execute([$selDate]);
$employees = $employees->fetchAll();

/* ── Sandwich leave on the selected date ──────────────────────────────────────
   Two things can be true of this date: it is a company leave being CHARGED as
   LOP, or it is a LEAVE that closed a sandwich (what the month grids badge).
   Resolved for the whole roster in one call rather than per row, and read
   straight from the payroll engine so the badge can never disagree with the
   payslip. */
$selTs         = strtotime($selDate);
$selMonth      = (int)date('n', $selTs);
$selYear       = (int)date('Y', $selTs);
$empIdList     = array_column($employees, 'id');
$sandwichMap   = attendance_sandwich_map($empIdList, $selMonth, $selYear);
$sandwichLeave = attendance_sandwich_leave_map($empIdList, $selMonth, $selYear);

$sandwichHere = [];
foreach ($sandwichMap as $eid => $dates) {
    if (isset($dates[$selDate])) $sandwichHere[$eid] = 0;              // charged company leave
}
foreach ($sandwichLeave as $eid => $dates) {
    if (isset($dates[$selDate])) $sandwichHere[$eid] = $dates[$selDate]; // leave that closed one
}

/* ── Misc vars ───────────────────────────────────────────────────────────── */
$yesterday = date('Y-m-d', strtotime($selDate . ' -1 day'));
$tomorrow  = date('Y-m-d', strtotime($selDate . ' +1 day'));
$todayDate = date('Y-m-d');

$month = substr($selDate, 0, 7);
$dailyResult   = $_SESSION['att_daily_result']   ?? null;
$monthlyResult = $_SESSION['att_monthly_result'] ?? null;
unset($_SESSION['att_daily_result'], $_SESSION['att_monthly_result']);

require_once __DIR__ . '/../../includes/header.php';
?>

<?php if ($monthlyResult): ?>
<div class="alert alert-<?= $monthlyResult['success'] ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
    <i class="fa fa-<?= $monthlyResult['success'] ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
    <?php if ($monthlyResult['success'] && !empty($monthlyResult['message'])): ?>
        <div class="d-flex align-items-center gap-2 mb-1">
            <i class="fa fa-calendar-check fs-5 text-success"></i>
            <strong>Monthly Attendance Import Complete</strong>
        </div>
        <div class="d-flex gap-4 flex-wrap">
            <?php if (isset($monthlyResult['employees'])): ?>
                <span><i class="fa fa-users text-primary me-1"></i><strong><?= (int)$monthlyResult['employees'] ?></strong> employees processed</span>
                <span><i class="fa fa-plus-circle text-success me-1"></i><strong><?= (int)$monthlyResult['saved'] ?></strong> new records</span>
                <span><i class="fa fa-pen text-primary me-1"></i><strong><?= (int)$monthlyResult['updated'] ?></strong> updated</span>
                <span><i class="fa fa-ban text-warning me-1"></i><strong><?= (int)$monthlyResult['skipped'] ?></strong> employees not matched</span>
            <?php else: ?>
                <?= h($monthlyResult['message']) ?>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?= h($monthlyResult['message'] ?? '') ?>
    <?php endif; ?>
    <?php if (!empty($monthlyResult['details'])): ?>
    <ul class="mb-0 mt-1 small">
        <?php foreach ($monthlyResult['details'] as $d): ?><li><?= h($d) ?></li><?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($dailyResult): ?>
<div class="alert alert-<?= $dailyResult['success'] ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
    <?php if ($dailyResult['success']): ?>
        <div class="d-flex align-items-center gap-2 mb-1">
            <i class="fa fa-file-excel fs-5"></i>
            <strong>Attendance Import Complete</strong>
            <span class="text-muted small ms-1">for <?= date('d M Y', strtotime($selDate)) ?></span>
        </div>
        <div class="d-flex gap-4 flex-wrap">
            <?php if (isset($dailyResult['saved'])): ?>
                <span><i class="fa fa-plus-circle text-success me-1"></i><strong><?= (int)$dailyResult['saved'] ?></strong> new records</span>
                <span><i class="fa fa-pen text-primary me-1"></i><strong><?= (int)$dailyResult['updated'] ?></strong> updated</span>
                <span><i class="fa fa-ban text-danger me-1"></i><strong><?= (int)$dailyResult['skipped'] ?></strong> skipped (emp not found)</span>
            <?php else: ?>
                <?= h($dailyResult['message'] ?? '') ?>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <i class="fa fa-exclamation-triangle me-2"></i><?= h($dailyResult['message'] ?? '') ?>
    <?php endif; ?>
    <?php if (!empty($dailyResult['details'])): ?>
    <ul class="mb-0 mt-1 small">
        <?php foreach ($dailyResult['details'] as $d): ?><li><?= h($d) ?></li><?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card page-card">
    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <h5 class="mb-0 fw-semibold">
            <i class="fa fa-calendar-check me-2 text-primary"></i>Daily Attendance Mark Sheet
        </h5>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm btn-outline-success" onclick="openModal('dailyImportModal')">
                <i class="fa fa-file-excel me-1"></i>Daily Import
            </button>
            <button type="button" class="btn btn-sm btn-success" onclick="openModal('monthlyImportModal')">
                <i class="fa fa-calendar-arrow-up me-1"></i>Monthly Import
            </button>
            <a href="report.php?month=<?= date('n', strtotime($selDate)) ?>&year=<?= date('Y', strtotime($selDate)) ?>"
               class="btn btn-sm btn-outline-secondary">
                <i class="fa fa-chart-bar me-1"></i>Monthly Report
            </a>
        </div>
    </div>

    <div class="card-body">

        <form method="GET" class="row g-2 mb-3 align-items-end">
            <input type="hidden" name="prev_date" value="<?= $selDate ?>">
            <div class="col-auto">
                <label class="form-label fw-semibold mb-1">Date</label>
                <input type="date" name="date" class="form-control" value="<?= $selDate ?>"
                       max="<?= $todayDate ?>">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-search me-1"></i>Load
                </button>
            </div>
        </form>

        <?php if ($compOffWorkingDay): ?>
        <div class="alert alert-success d-flex align-items-start gap-2 mb-3 py-2">
            <i class="fa fa-briefcase fa-lg text-success mt-1"></i>
            <div>
                <strong>Company Working Day</strong> —
                <?= date('l, d M Y', strtotime($selDate)) ?>
                (<?= h($compOffWorkingDay['day_type_label']) ?>
                <?php if ($holidayName): ?>: <?= h($holidayName) ?><?php endif; ?>)
                <br>
                <span class="small">
                    <i class="fa fa-rotate-left me-1 text-success"></i>
                    Employees marked <strong>Present / Late / Half Day</strong> automatically earn
                    <strong>1 Comp Off credit</strong> — see <a href="comp_off_credits.php">Comp Off Credits</a>.
                </span>
            </div>
        </div>
        <?php endif; ?>

        <form action="mark.php?date=<?= $selDate ?>" method="POST" id="attendanceForm">
            <?= csrf_field() ?>
            <input type="hidden" name="date" value="<?= $selDate ?>">

            <?php if (empty($employees)): ?>
                <p class="text-muted text-center py-4">No active employees found.</p>
            <?php else: ?>
            <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle" id="attTable" style="font-size:13px">
                <thead class="table-dark">
                    <tr>
                        <th style="width:40px">#</th>
                        <th>Employee</th>
                        <th>Department</th>
                        <th>Shift</th>
                        <th style="min-width:160px">
                            Status
                            <small class="fw-normal text-warning d-block" style="font-size:.65rem">
                                Auto-calculated from the employee's shift
                            </small>
                        </th>
                        <th style="min-width:110px">Check In</th>
                        <th style="min-width:110px">Check Out</th>
                        <th style="min-width:90px">
                            OT Hrs
                            <br><small class="fw-normal text-warning" style="font-size:.65rem"
                                 title="Auto OT: checkout must reach the shift's trigger time. Hours counted from the shift's baseline. Shifts with OT off never earn OT.">
                                &#9201; per shift
                            </small>
                        </th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                // Manual-only statuses (no time input needed)
                $manualOpts = [
                    'Absent'   => 'Absent',
                    'On Leave' => 'On Leave',
                    'Comp Off' => 'Comp Off',
                    'OD'       => 'On Duty',
                ];
                // Auto statuses (shown as badge when check-in/out is set)
                $autoLabels = [
                    'On Time'  => 'Present',
                    'Late'     => 'Late',
                    'Half Day' => 'Half Day',
                ];
                $allStatuses = array_merge(array_keys($manualOpts), array_keys($autoLabels));

                foreach ($employees as $i => $e):
                    $savedStat = $e['att_status'] ?? 'Absent';
                    $hasTime   = !empty($e['in_time']) || !empty($e['out_time']);
                    $isAutoMode = $hasTime && array_key_exists($savedStat, $autoLabels);
                    // This row's timing: the employee's shift, else legacy globals
                    $t = _mark_timing((int)$e['id'], $shiftTiming, $lateThreshMins, $halfDayCutoff, $otTriggerMins, $otBaselineMins);
                    $shiftOtOn   = $t['shift_ot_on'];

                    // EO (Early Out) — the same call the Attendance Report badges
                    // with, so a day docked under "Others" on the payslip reads the
                    // same on both screens. 'half' is left alone: the saved status
                    // is already Half Day, which the badge beside it shows.
                    $isEarlyOut = false; $eoWorkedH = 0.0;
                    if (!empty($e['in_time']) && !empty($e['out_time'])
                        && in_array($savedStat, ['On Time', 'Late', 'Half Day'], true)) {
                        $inM  = time_to_mins((string)$e['in_time']);
                        $outM = time_to_mins((string)$e['out_time']);
                        if ($outM <= $inM) $outM += 1440;                  // overnight shift
                        // Prefer the shift STAMPED on this attendance row, as the
                        // report does, so an old date is judged by the shift that
                        // was actually worked and not by today's assignment.
                        $rowShift = $e['att_shift_id'] !== null ? (int)$e['att_shift_id'] : ($t['shift_id'] ?? null);
                        $rt  = attendance_row_timing($rowShift, (int)$e['id']);
                        $net = max(0, ($outM - $inM) - break_minutes_within($inM, $outM, $rt['lunch'], $rt['breaks']));
                        $cls = attendance_classify(
                            $net, $inM, $rt['start_mins'], $rt['daily_grace'], $outM, $rt['end_mins'],
                            $rt['shift_id'] !== null ? $rt['half_cutoff'] : null
                        );
                        $isEarlyOut = $cls['status'] !== 'half' && ($cls['ded_days'] ?? 0) > 0;
                        $eoWorkedH  = $cls['worked_h'] ?? 0;
                    }
                    $sandwichOffs = $sandwichHere[(int)$e['id']] ?? null;
                    $isOtEnabled = (bool)$e['ot_enabled'] && $shiftOtOn;   // effective auto-OT
                ?>
                <tr class="att-row"
                    data-emp-id="<?= $e['id'] ?>"
                    data-ot-enabled="<?= $isOtEnabled ? '1' : '0' ?>"
                    data-late-thresh="<?= (int)$t['late_thresh'] ?>"
                    data-half-cutoff="<?= (int)$t['half_cutoff'] ?>"
                    data-shift-ot="<?= $shiftOtOn ? '1' : '0' ?>"
                    data-ot-trigger="<?= $t['ot_trigger'] !== null ? (int)$t['ot_trigger'] : '' ?>"
                    data-ot-baseline="<?= $t['ot_baseline'] !== null ? (int)$t['ot_baseline'] : '' ?>"
                    data-name="<?= strtolower(h($e['name'])) ?> <?= strtolower(h($e['emp_code'])) ?>">
                    <td class="text-muted"><?= $i + 1 ?></td>
                    <td>
                        <strong><?= h($e['name']) ?></strong><br>
                        <small class="text-muted"><?= h($e['emp_code']) ?></small>
                        <?php if ($isOtEnabled): ?>
                        <br><span class="badge mt-1"
                                  style="background:#fef9c3;color:#92400e;font-size:.65rem;border:1px solid #fde68a">
                            <i class="fa fa-clock me-1"></i>Auto OT
                        </span>
                        <?php endif; ?>
                    </td>
                    <td><small><?= h($e['dept_name']) ?></small></td>
                    <td>
                        <small><span class="badge bg-light text-dark border" style="font-size:.68rem">
                            <?= h($e['shift_name'] ?? 'General') ?>
                        </span></small>
                    </td>
                    <td style="min-width:130px">
                        <input type="hidden"
                               name="attendance[<?= $e['id'] ?>][status]"
                               class="status-value"
                               value="<?= h($savedStat) ?>">

                        <select class="form-select form-select-sm status-manual-select <?= $isAutoMode ? 'd-none' : '' ?>">
                            <?php foreach ($manualOpts as $val => $label): ?>
                            <option value="<?= $val ?>"
                                <?= (!$isAutoMode && $savedStat === $val) ? 'selected' : '' ?>>
                                <?= $label ?>
                            </option>
                            <?php endforeach; ?>
                        </select>

                        <div class="status-auto-display <?= $isAutoMode ? '' : 'd-none' ?>">
                            <?php
                            // Badge classes for auto-detected statuses
                            $badgeCls = ['On Time'=>'bg-success','Late'=>'bg-info','Half Day'=>'bg-warning text-dark'];
                            $bc  = $badgeCls[$savedStat] ?? 'bg-secondary';
                            $lbl = $autoLabels[$savedStat] ?? ucfirst($savedStat);
                            ?>
                            <span class="badge status-auto-badge <?= $bc ?>"
                                  style="font-size:.78rem;padding:.35em .65em;letter-spacing:.3px">
                                <?= $lbl ?>
                            </span>
                            <small class="text-muted d-block mt-1" style="font-size:.6rem;white-space:nowrap">
                                <i class="fa fa-lock me-1"></i>Auto-calculated
                            </small>
                        </div>

                        <?php if ($isEarlyOut): ?>
                        <span class="badge mt-1" style="background:#b45309;color:#fff;font-size:.65rem"
                              title="Early Out &mdash; <?= number_format($eoWorkedH, 2) ?>h net worked, short of a full day. Deducted under &quot;Others&quot; on the payslip.">EO</span>
                        <?php endif; ?>

                        <?php if ($sandwichOffs !== null):
                            $swTip = $sandwichOffs > 0
                                ? 'Sandwich Leave — this leave closed a sandwich, so ' . $sandwichOffs
                                  . ' company leave day' . ($sandwichOffs == 1 ? '' : 's') . ' beside it '
                                  . ($sandwichOffs == 1 ? 'is' : 'are') . ' charged as LOP too.'
                                : 'Sandwich Leave — this company leave is charged as LOP because leave was taken on the working day before AND after it.';
                        ?>
                        <span class="badge mt-1" style="background:#9d174d;color:#fff;font-size:.65rem"
                              title="<?= h($swTip) ?>">SL</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <input type="time"
                               name="attendance[<?= $e['id'] ?>][in_time]"
                               class="form-control form-control-sm checkin-input"
                               value="<?= $e['in_time'] ? substr($e['in_time'], 0, 5) : '' ?>">
                    </td>
                    <td>
                        <input type="time"
                               name="attendance[<?= $e['id'] ?>][out_time]"
                               class="form-control form-control-sm checkout-input"
                               value="<?= $e['out_time'] ? substr($e['out_time'], 0, 5) : '' ?>">
                    </td>
                    <td>
                        <?php if (!$shiftOtOn): ?>
                            <span class="text-muted" title="This shift does not earn overtime">—</span>
                        <?php elseif ($isOtEnabled): ?>
                            <input type="hidden"
                                   name="attendance[<?= $e['id'] ?>][ot_hours]"
                                   class="ot-hours-input"
                                   value="<?= $e['ot_hours'] !== null ? (float)$e['ot_hours'] : '' ?>">
                            <span class="ot-hours-display fw-semibold"
                                  style="font-size:.85rem;color:#16a34a">
                                <?= $e['ot_hours'] !== null ? _mark_fmtOtHrs((float)$e['ot_hours']) : '—' ?>
                            </span>
                        <?php else: ?>
                            <input type="number"
                                   name="attendance[<?= $e['id'] ?>][ot_hours]"
                                   class="form-control form-control-sm ot-input"
                                   min="0" max="24" step="0.01" placeholder="0"
                                   value="<?= $e['ot_hours'] !== null ? (float)$e['ot_hours'] : '' ?>">
                        <?php endif; ?>
                    </td>
                    <td>
                        <input type="text"
                               name="attendance[<?= $e['id'] ?>][remarks]"
                               class="form-control form-control-sm"
                               placeholder="Optional"
                               value="<?= h($e['remarks'] ?? '') ?>">
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <div class="mt-3 d-flex gap-2">
                <button type="submit" name="bulk_save" value="1" class="btn btn-success">
                    <i class="fa fa-save me-1"></i>Save Attendance
                </button>
                <button type="button" id="markAllAbsent" class="btn btn-outline-danger">Mark All Absent</button>
                <button type="button" id="markAllLeave"  class="btn btn-outline-secondary">Mark All On Leave</button>
            </div>
            <?php endif; ?>
        </form>

    </div><!-- /card-body -->
</div><!-- /card -->

<?php if ($isNonWorking): ?>
<?php
    $isDayHoliday = (bool)$holidayName;
    $popupName  = $isDayHoliday ? $holidayName : $nonWorkingLabel;
    $popupType  = $isDayHoliday ? 'Public Holiday' : 'Weekly Off';
    $popupIcon  = $isDayHoliday ? 'fa-calendar-xmark' : 'fa-moon';
    $popupColor = $isDayHoliday ? '#f59e0b' : '#64748b';
?>
<div class="modal fade" id="holidayAlertModal" tabindex="-1"
     data-bs-backdrop="static" data-bs-keyboard="false" aria-labelledby="holidayModalLabel">
    <div class="modal-dialog modal-dialog-centered" style="max-width:520px">
        <div class="modal-content border-0 shadow-lg" style="border-radius:14px;overflow:hidden">
            <div class="modal-body text-center px-5 py-4">
                <div class="mx-auto mb-3 d-flex align-items-center justify-content-center"
                     style="width:60px;height:60px;border-radius:50%;background:<?= $popupColor ?>1a">
                    <i class="fa <?= $popupIcon ?> fa-xl" style="color:<?= $popupColor ?>"></i>
                </div>
                <div class="badge mb-2 px-3 py-1"
                     style="background:<?= $popupColor ?>22;color:<?= $popupColor ?>;font-size:.75rem;font-weight:600;border-radius:20px">
                    <?= $popupType ?>
                </div>
                <h5 class="fw-bold mb-1 mt-1" id="holidayModalLabel" style="font-size:1.15rem">
                    <?= h($popupName) ?>
                </h5>
                <p class="text-muted mb-4" style="font-size:.85rem">
                    <?= date('l, d F Y', strtotime($selDate)) ?>
                </p>
                <a href="mark.php?date=<?= $prevDate ?>"
                   class="btn btn-primary px-5 fw-semibold"
                   style="border-radius:8px;min-width:140px">
                    OK
                </a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Bulk status confirmation modal -->
<div class="modal fade" id="bulkStatusConfirmModal" tabindex="-1" aria-labelledby="bulkStatusConfirmLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:420px">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-0 pb-0">
                <h6 class="modal-title fw-semibold" id="bulkStatusConfirmLabel">
                    <i class="fa fa-triangle-exclamation text-warning me-2"></i>Confirm Bulk Update
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body pt-2 pb-1">
                <p class="mb-0" id="bulkStatusConfirmMsg"></p>
            </div>
            <div class="modal-footer border-0 pt-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm" id="bulkStatusConfirmBtn">Confirm</button>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/modals.php'; ?>

<script>
$(function () {

    /* ── Settings from PHP ──────────────────────────────────────────────── */
    var OFFICE_START_MINS = <?= $officeStartMins ?>;
    var DAILY_GRACE_MINS  = <?= $dailyGraceMins ?>;
    var LATE_THRESHOLD    = <?= $lateThreshMins ?>;
    var HALF_DAY_CHECKIN  = <?= $halfDayCutoff ?>;
    var OT_TRIGGER_MINS   = <?= $otTriggerMins ?>;
    var OT_BASELINE_MINS  = <?= $otBaselineMins ?>;

    // Status definitions matching the old Payroll app behaviour
    var MANUAL_STATUSES = ['Absent', 'On Leave', 'Comp Off', 'OD'];
    var AUTO_STATUSES   = ['On Time', 'Late', 'Half Day'];

    // Labels for auto-mode badge (On Time stored in DB, displayed as "Present")
    var AUTO_LABELS = {
        'On Time'  : 'Present',
        'Late'     : 'Late',
        'Half Day' : 'Half Day',
    };
    var BADGE_CLASS = {
        'On Time'  : 'bg-success',
        'Late'     : 'bg-info',
        'Half Day' : 'bg-warning text-dark',
        'Absent'   : 'bg-danger',
    };

    /* ── Auto-calculate status from check-in / check-out ──────────────────
     * Thresholds come from the row's shift (data-late-thresh / data-half-cutoff);
     * the globals above remain as fallback for rows without shift data.     */
    function calcAutoStatus($tr, checkIn, checkOut) {
        if (!checkIn) return 'Absent';

        var lateThresh = parseInt($tr.attr('data-late-thresh'), 10) || LATE_THRESHOLD;
        var halfCutoff = parseInt($tr.attr('data-half-cutoff'), 10) || HALF_DAY_CHECKIN;

        var parts  = checkIn.split(':');
        var inMins = parseInt(parts[0], 10) * 60 + parseInt(parts[1] || '0', 10);

        /* Mirrors attendance_checkin_statuses() on the server: a check-in with no
           punch-out is Absent whatever time the check-in was (M-18). Before this,
           the sheet previewed "Half Day" for a late arrival that the server then
           saved as Absent — the row changed status the moment you pressed Save. */
        if (!checkOut) return 'Absent';

        if (inMins >= halfCutoff) return 'Half Day';

        var op   = checkOut.split(':');
        var outM = parseInt(op[0], 10) * 60 + parseInt(op[1] || '0', 10);
        if (outM - inMins > 0 && outM - inMins < 240) return 'Half Day';

        return inMins > lateThresh ? 'Late' : 'On Time';
    }

    /* ── Format decimal OT hours as "Xh Ym" ─────────────────────────────── */
    function fmtOtHrs(dec) {
        var total = Math.round(dec * 60);
        var h = Math.floor(total / 60), m = total % 60;
        return (h > 0 ? h + 'h ' : '') + m + 'm';
    }

    /* ── Update a single row's status cell ──────────────────────────────── */
    function updateRow($tr) {
        var checkIn  = $tr.find('.checkin-input').val();
        var checkOut = $tr.find('.checkout-input').val();
        var $hidden  = $tr.find('.status-value');
        var $manual  = $tr.find('.status-manual-select');
        var $autoDsp = $tr.find('.status-auto-display');
        var $badge   = $tr.find('.status-auto-badge');

        if (checkIn || checkOut) {
            /* ── Auto mode ──────────────────────────────────────────────── */
            var status = calcAutoStatus($tr, checkIn, checkOut);
            var bc = BADGE_CLASS[status] || 'bg-secondary';
            var lbl = AUTO_LABELS[status] || status;
            $badge.attr('class', 'badge status-auto-badge ' + bc)
                  .attr('style', 'font-size:.78rem;padding:.35em .65em;letter-spacing:.3px')
                  .text(lbl);
            $hidden.val(status);
            $manual.addClass('d-none');
            $autoDsp.removeClass('d-none');
        } else {
            /* ── Manual mode ────────────────────────────────────────────── */
            var cur = $hidden.val();
            // Auto-statuses (On Time/Late/Half Day) are not dropdown options —
            // reset to Absent when falling back to manual mode
            if (AUTO_STATUSES.includes(cur) || !MANUAL_STATUSES.includes(cur)) {
                cur = 'Absent';
                $hidden.val('Absent');
            }
            $manual.val(cur);
            $autoDsp.addClass('d-none');
            $manual.removeClass('d-none');
        }
    }

    /* ── Auto OT (ot_enabled employees on OT-enabled shifts only) ────────── */
    function calcAutoOT($tr, checkoutVal) {
        var $hoursInput = $tr.find('.ot-hours-input');
        var $hoursDisp  = $tr.find('.ot-hours-display');
        if (!$hoursInput.length) return;                    // shift has no OT — no input rendered
        if ($tr.attr('data-shift-ot') === '0') {            // hard gate: straight shift
            $hoursInput.val('');
            $hoursDisp.html('<span class="text-muted">—</span>');
            return;
        }
        var trigRaw = $tr.attr('data-ot-trigger');
        var baseRaw = $tr.attr('data-ot-baseline');
        var trigger  = trigRaw !== '' && trigRaw !== undefined ? parseInt(trigRaw, 10) : OT_TRIGGER_MINS;
        var baseline = baseRaw !== '' && baseRaw !== undefined ? parseInt(baseRaw, 10) : OT_BASELINE_MINS;
        if (!checkoutVal) {
            $hoursInput.val('');
            $hoursDisp.html('<span class="text-muted">—</span>');
            return;
        }
        var parts   = checkoutVal.split(':');
        var outMins = parseInt(parts[0], 10) * 60 + parseInt(parts[1] || '0', 10);
        if (outMins < trigger) {
            $hoursInput.val('');
            $hoursDisp.html('<span class="text-muted">—</span>');
            return;
        }
        var otHours = Math.round((outMins - baseline) / 60 * 100) / 100;
        $hoursInput.val(otHours.toFixed(2));
        $hoursDisp.text(fmtOtHrs(otHours));
    }

    /* ── Sync manual select → hidden input ──────────────────────────────── */
    $(document).on('change', '.status-manual-select', function () {
        var $tr  = $(this).closest('tr');
        var stat = $(this).val();
        $tr.find('.status-value').val(stat);
        if (['Absent', 'Comp Off', 'On Leave'].includes(stat)) {
            $tr.find('.checkin-input, .checkout-input').val('');
        }
    });

    /* ── Time inputs: live status update ───────────────────────────────────
     * Chrome's native <input type="time"> widget (AM/PM toggle, spinners)
     * does NOT reliably fire 'input' or 'change' while the user is
     * interacting with it — events only fire after blur in many cases.
     *
     * Fix: poll every 200 ms, compare each row's time values to their
     * last-known state, and call updateRow() only when something changed.
     * Events are kept as a fallback for non-Chrome / non-native pickers.
     * -------------------------------------------------------------------- */
    function _onTimeChange($tr) {
        updateRow($tr);
        var co = $tr.find('.checkout-input').val();
        if (co && parseInt($tr.data('ot-enabled'))) {
            calcAutoOT($tr, co);
        }
    }

    /* Store snapshot of time values per row so the poll can diff cheaply */
    $('tbody tr').each(function () {
        var $tr = $(this);
        $tr.data('_ci', $tr.find('.checkin-input').val());
        $tr.data('_co', $tr.find('.checkout-input').val());
    });

    /* Poll every 200 ms — detects AM/PM clicks, spinner arrows, paste, etc. */
    setInterval(function () {
        $('tbody tr').each(function () {
            var $tr = $(this);
            var ci  = $tr.find('.checkin-input').val();
            var co  = $tr.find('.checkout-input').val();
            if (ci !== $tr.data('_ci') || co !== $tr.data('_co')) {
                $tr.data('_ci', ci);
                $tr.data('_co', co);
                _onTimeChange($tr);
            }
        });
    }, 200);

    /* Keep event listeners as fallback (keyboard typing, non-Chrome) */
    $(document).on('input change blur', '.checkin-input, .checkout-input', function () {
        _onTimeChange($(this).closest('tr'));
    });

    /* Safety pass before form submit */
    $('#attendanceForm').on('submit', function () {
        $('tbody tr').each(function () { updateRow($(this)); });
    });

    /* ── Mark All Absent / On Leave — with confirmation ─────────────────── */
    var _bulkStatus = null;
    var _bulkModal  = new bootstrap.Modal(document.getElementById('bulkStatusConfirmModal'));

    function _applyBulkStatus(status) {
        $('tbody tr').each(function () {
            var $tr  = $(this);
            var $sel = $tr.find('.status-manual-select');
            $tr.find('.checkin-input, .checkout-input').val('');
            if (!$sel.hasClass('d-none')) {
                $sel.val(status).trigger('change');
            } else {
                $tr.find('.status-value').val(status);
                $tr.find('.status-auto-display').addClass('d-none');
                $sel.val(status).removeClass('d-none');
            }
        });
    }

    $('#markAllAbsent').on('click', function () {
        _bulkStatus = 'Absent';
        $('#bulkStatusConfirmMsg').text('Do you want to mark all employees as Absent? Check-in and check-out times will be cleared.');
        $('#bulkStatusConfirmBtn').removeClass('btn-secondary').addClass('btn-danger').text('Yes, Mark All Absent');
        _bulkModal.show();
    });

    $('#markAllLeave').on('click', function () {
        _bulkStatus = 'On Leave';
        $('#bulkStatusConfirmMsg').text('Do you want to mark all employees as On Leave? Check-in and check-out times will be cleared.');
        $('#bulkStatusConfirmBtn').removeClass('btn-danger').addClass('btn-secondary').text('Yes, Mark All On Leave');
        _bulkModal.show();
    });

    $('#bulkStatusConfirmBtn').on('click', function () {
        _bulkModal.hide();
        if (_bulkStatus) _applyBulkStatus(_bulkStatus);
        _bulkStatus = null;
    });

    /* ── Initialise all rows on page load ───────────────────────────────── */
    $('tbody tr').each(function () { updateRow($(this)); });

    /* ── Auto-open non-working day modal ────────────────────────────────── */
    <?php if ($isNonWorking): ?>
    var holidayModal = new bootstrap.Modal(document.getElementById('holidayAlertModal'), {
        backdrop: 'static', keyboard: false
    });
    holidayModal.show();
    <?php endif; ?>

    /* ── Pre-set daily import date field ────────────────────────────────── */
    var _df = document.getElementById('daily_att_date');
    if (_df) _df.value = '<?= $selDate ?>';
});

/* ── Import file validator (called from modals.php) ─────────────────────── */
function validateImportFile(input) {
    var ext = (input.value || '').split('.').pop().toLowerCase();
    if (!['csv', 'xlsx'].includes(ext)) {
        alert('Please select a .csv or .xlsx file only.');
        input.value = '';
        return false;
    }
    return true;
}

window.BASE_URL = '<?= BASE_URL ?>';
window.PAGE_SHORTCUTS = {
    's': function() { document.querySelector('[name=bulk_save]').closest('form').submit(); },
    'b': function() { location.href = '<?= BASE_URL ?>/modules/attendance/index.php?month=<?= substr($selDate,0,7) ?>'; }
};
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
