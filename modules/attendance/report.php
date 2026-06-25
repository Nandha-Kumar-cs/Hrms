<?php
/**
 * Attendance Monthly Report — Matrix Grid
 * ─────────────────────────────────────────
 * Displays a full employee × day grid for a selected month.
 * Columns: Employee | 1..31 | P | A | H | L | Late Used | Remaining | Exceeded | Deducted | OT Hrs | Man Hrs
 *
 * Non-working days (Sundays, 1st/3rd Saturdays, holidays) span all employee
 * rows via rowspan and show the day label rotated vertically.
 *
 * Status badges:
 *   P   = On Time   (green)
 *   L   = Late      (blue/info)  — hover shows minutes late
 *   H   = Half Day  (yellow)
 *   A   = Absent    (red)
 *   A   = No Checkout: On Time/Late with no out_time (orange #e67e22)
 *   CO  = Comp Off  (purple #6f42c1)
 *   OD  = On Duty   (golden #c8a96e)
 *   —   = Future date with no record (light/neutral)
 */

$page_title = 'Monthly Attendance Report';
require_once __DIR__ . '/../../includes/header.php';
require_permission('attendance', 'report');   // sub-module: Attendance Report

$db  = db();
$now = new DateTime();

/* ── Month / Year params ─────────────────────────────────────────────────── */
$reqMonth = max(1, min(12, (int)($_GET['month'] ?? $now->format('n'))));
$reqYear  = max(2020, min((int)$now->format('Y'), (int)($_GET['year'] ?? $now->format('Y'))));

$padMonth   = str_pad($reqMonth, 2, '0', STR_PAD_LEFT);
$mStartStr  = "$reqYear-$padMonth-01";
$mEndStr    = date('Y-m-t', strtotime($mStartStr));
$days       = (int)date('t', strtotime($mStartStr));
$todayStr   = $now->format('Y-m-d');
$monthLabel = date('F Y', strtotime($mStartStr));

/* ── Config constants ────────────────────────────────────────────────────── */
$officeStart      = WORK_START_TIME;           // '09:00'
$dailyGraceMins   = ATTENDANCE_GRACE_MINUTES;  // 15
$monthlyGraceMins = MONTHLY_GRACE_MINUTES;     // 90

$officeStartMins = _rep_timeToMins($officeStart);
$lateThreshFmt   = date('h:i A', strtotime("1970-01-01 $officeStart") + $dailyGraceMins * 60);
$halfDayFmt      = date('h:i A', strtotime("1970-01-01 $officeStart") + 120 * 60);
$officeStartFmt  = date('h:i A', strtotime("1970-01-01 $officeStart"));
$mGraceH         = intdiv($monthlyGraceMins, 60);
$mGraceM         = $monthlyGraceMins % 60;
$mGraceFmt       = ($mGraceH > 0 ? $mGraceH . 'h ' : '') . $mGraceM . 'm';

/* ── Load holidays for the month ─────────────────────────────────────────── */
$hStmt = $db->prepare(
    "SELECT h_date, name, is_working_day FROM holidays WHERE h_date BETWEEN ? AND ?"
);
$hStmt->execute([$mStartStr, $mEndStr]);
$holidayMap = []; // 'YYYY-MM-DD' => ['name' => ..., 'is_working_day' => bool]
foreach ($hStmt->fetchAll() as $h) {
    $holidayMap[$h['h_date']] = $h;
}

/* ── Approved PAID leaves in the month ───────────────────────────────────────
 * A day that would otherwise show as Absent but is covered by an admin-approved
 * PAID leave (leave_types.is_paid = 1) is shown in lavender instead of red.    */
$paidLeaveDates = []; // [empId]['YYYY-MM-DD'] => true
$plStmt = $db->prepare(
    "SELECT lr.employee_id, lr.start_date, lr.end_date
       FROM leave_requests lr
       JOIN leave_types lt ON lt.id = lr.leave_type_id
      WHERE lr.status = 'approved' AND lt.is_paid = 1
        AND lr.start_date <= ? AND lr.end_date >= ?"
);
$plStmt->execute([$mEndStr, $mStartStr]);
foreach ($plStmt->fetchAll() as $r) {
    $cur = new DateTime(max($r['start_date'], $mStartStr));
    $end = new DateTime(min($r['end_date'],   $mEndStr));
    while ($cur <= $end) {
        $paidLeaveDates[(int)$r['employee_id']][$cur->format('Y-m-d')] = true;
        $cur->modify('+1 day');
    }
}

/* ── Build non-working / working-holiday day maps ────────────────────────── */
$nonWorkingDays  = [];   // int day => label string
$workingHolDays  = [];   // int day => holiday name
$compOffDays     = [];   // int day => label (no comp_off_days table in HRMS — always empty)
$nwDateSet       = [];   // 'YYYY-MM-DD' => true  (for O(1) stat lookups)

for ($d = 1; $d <= $days; $d++) {
    $dateStr = "$reqYear-$padMonth-" . str_pad($d, 2, '0', STR_PAD_LEFT);
    $dayDate = new DateTime($dateStr);
    $dow     = (int)$dayDate->format('N'); // 1=Mon … 7=Sun
    $hol     = $holidayMap[$dateStr] ?? null;

    // Working-holiday override: counts as a normal working day
    if ($hol && ($hol['is_working_day'] ?? false)) {
        $workingHolDays[$d] = $hol['name'];
        continue;
    }

    if ($dow === 7) {
        $nonWorkingDays[$d] = 'Sunday';
        $nwDateSet[$dateStr] = true;
    } elseif (_rep_is13Sat($dayDate)) {
        $satN = _rep_satCountUpTo($dayDate);
        $nonWorkingDays[$d] = ($satN === 1 ? '1st' : '3rd') . ' Saturday';
        $nwDateSet[$dateStr] = true;
    } elseif ($hol) {
        $nonWorkingDays[$d] = $hol['name'];
        $nwDateSet[$dateStr] = true;
    }
}

/* ── Total working days ──────────────────────────────────────────────────── */
$totalWorkingDays = 0;
for ($d = 1; $d <= $days; $d++) {
    $dateStr = "$reqYear-$padMonth-" . str_pad($d, 2, '0', STR_PAD_LEFT);
    if (!isset($nwDateSet[$dateStr])) $totalWorkingDays++;
}

/* ── Load employees (self-scoped users see only their own row) ───────────── */
$employees = $db->query(
    "SELECT e.id, e.name, e.employee_id AS emp_code,
            COALESCE(d.name, '—') AS dept_name
     FROM   employees e
     LEFT JOIN departments d ON d.id = e.department_id
     WHERE  e.status = 'Active'" . (is_self_scoped() ? ' AND e.id = ' . current_employee_id() : '') . "
     ORDER  BY e.name"
)->fetchAll();
$empCount = count($employees);

/* ── Load all attendance records for the month ───────────────────────────── */
$attStmt = $db->prepare(
    "SELECT employee_id, att_date, status, leave_classification, in_time, out_time, ot_hours, remarks
     FROM   attendance
     WHERE  att_date BETWEEN ? AND ?"
);
$attStmt->execute([$mStartStr, $mEndStr]);
$allRecs = $attStmt->fetchAll();

// Paid leaves already used this month per employee (attendance 'paid' classifications
// + admin-approved paid leave_requests). Drives the "1 paid leave/month" UI cap.
$paidUsedByEmp = [];   // [empId] => count
foreach ($allRecs as $rec) {
    if (($rec['status'] ?? '') === 'Absent' && ($rec['leave_classification'] ?? '') === 'paid') {
        $paidUsedByEmp[(int)$rec['employee_id']] = ($paidUsedByEmp[(int)$rec['employee_id']] ?? 0) + 1;
    }
}
foreach ($paidLeaveDates as $eId => $dates) {           // approved paid leave_requests (loaded above)
    $paidUsedByEmp[(int)$eId] = ($paidUsedByEmp[(int)$eId] ?? 0) + count($dates);
}
$canClassify = can('attendance', 'edit') && !is_self_scoped();

// Group: $records[$emp_id][$day_number] = record
$records    = [];
$recDateSet = []; // $recDateSet[$emp_id]['YYYY-MM-DD'] = true
foreach ($allRecs as $rec) {
    $dayNum = (int)date('j', strtotime($rec['att_date']));
    $records[$rec['employee_id']][$dayNum] = $rec;
    $recDateSet[$rec['employee_id']][$rec['att_date']] = true;
}

/* ── Per-employee stats ──────────────────────────────────────────────────── */
$empStats  = [];
$lateByDay = []; // [$empId][$dayNum] = late_minutes

foreach ($employees as $emp) {
    $empId    = (int)$emp['id'];
    $empRecs  = $records[$empId] ?? [];
    $empDates = $recDateSet[$empId] ?? [];

    $presentCnt = 0;
    $absentCnt  = 0;
    $halfCnt    = 0;
    $paidLvCnt  = 0;
    $lateMins   = 0;
    $otHrs      = 0.0;
    $manMins    = 0;
    $lateByDay[$empId] = [];

    foreach ($empRecs as $dayNum => $rec) {
        // OT accumulates even on non-working days
        if (($rec['ot_hours'] ?? 0) > 0) $otHrs += (float)$rec['ot_hours'];

        $dateStr    = "$reqYear-$padMonth-" . str_pad($dayNum, 2, '0', STR_PAD_LEFT);
        $isNonWrk   = isset($nwDateSet[$dateStr]);
        $status     = $rec['status'];
        // Orange A: had check-in but no checkout (saved as Absent by no-checkout rule,
        // or legacy On Time/Late records with missing checkout)
        $noCheckout = (in_array($status, ['On Time', 'Late'], true) && empty($rec['out_time']))
                   || ($status === 'Absent' && !empty($rec['in_time']) && empty($rec['out_time']));

        // Man Hours (midnight-crossing safe) — count on working days only
        if (!$isNonWrk && !empty($rec['in_time']) && !empty($rec['out_time'])) {
            $inM  = _rep_timeToMins($rec['in_time']);
            $outM = _rep_timeToMins($rec['out_time']);
            if ($outM <= $inM) $outM += 1440; // midnight crossing
            $manMins += max(0, $outM - $inM);
        }

        // Skip non-working days for penalty/count calculations
        if ($isNonWrk) continue;

        // No-checkout = treated as absent
        if ($noCheckout || $status === 'Absent') {
            // An Absent day classified as Paid Leave counts as leave, not absent
            // (excluded from LOP). Unpaid / unclassified stay absent.
            if ($status === 'Absent' && ($rec['leave_classification'] ?? '') === 'paid') {
                $paidLvCnt++;
            } else {
                $absentCnt++;
            }
            continue;
        }
        if ($status === 'Half Day') { $halfCnt++; continue; }
        // OD, Comp Off, Holiday = neither absent nor late
        if (in_array($status, ['OD', 'Comp Off', 'Holiday'], true)) continue;

        // On Time or Late
        if (in_array($status, ['On Time', 'Late'], true)) {
            // Half/short day (check-in after ~11:00, or net ≤ 4h)? Such days are
            // EXEMPT from the late penalty (they're already deducted as Half/Short
            // Hours), so their late minutes must NOT be added to the late pool —
            // this keeps the report's Late total in step with the salary slip.
            $isHalfShort = _rep_isEarlyOut($rec, employee_lunch_window($empId));
            if ($status === 'Late' && !$isHalfShort && !empty($rec['in_time'])) {
                $ciMins  = _rep_timeToMins($rec['in_time']);
                $dayLate = max(0, $ciMins - $officeStartMins);
                if ($dayLate > 0) {
                    $lateByDay[$empId][$dayNum] = $dayLate;
                    $lateMins += $dayLate;
                }
            }
            if ($isHalfShort) { $halfCnt++; }
            else              { $presentCnt++; }
        }
    }

    // Working days with NO record at all → count as absent (past/today only)
    for ($d = 1; $d <= $days; $d++) {
        $dateStr = "$reqYear-$padMonth-" . str_pad($d, 2, '0', STR_PAD_LEFT);
        if ($dateStr > $todayStr) continue;
        if (isset($nwDateSet[$dateStr])) continue;
        if (!isset($empDates[$dateStr])) $absentCnt++;
    }

    $exceeds      = $lateMins > $monthlyGraceMins;
    $exceededMins = $exceeds ? ($lateMins - $monthlyGraceMins) : 0;
    $deductMins   = $exceeds ? ($lateMins * 2) : 0;
    $remainPerm   = max(0, $monthlyGraceMins - $lateMins);

    $empStats[$empId] = [
        'present_count'  => $presentCnt,
        'absent_days'    => $absentCnt,
        'half_days'      => $halfCnt,
        'leave_days'     => $paidLvCnt,
        'late_mins'      => $lateMins,
        'remaining_perm' => $remainPerm,
        'exceeded_mins'  => $exceededMins,
        'deduct_mins'    => $deductMins,
        'ot_hours'       => round($otHrs, 2),
        'man_hours'      => round($manMins / 60, 2),
    ];
}
/* ─────────────────────────────────────────────────────────────────────────── */
?>

<?= render_flash() ?>

<div class="card page-card">

    <!-- ── Card header: back button + title (left) | month/year filter form (right) ── -->
    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
            <a href="index.php?tab=records&month=<?= $reqYear . '-' . $padMonth ?>"
               class="btn btn-sm btn-outline-secondary" title="Back to Attendance">
                <i class="fa fa-arrow-left"></i>
            </a>
            <h5 class="mb-0 fw-semibold">Monthly Attendance Report</h5>
        </div>
        <form method="GET" class="d-flex gap-2 align-items-end">
            <select name="month" class="form-select form-select-sm">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $m === $reqMonth ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
                <?php endfor; ?>
            </select>
            <select name="year" class="form-select form-select-sm">
                <?php for ($y = (int)date('Y'); $y >= (int)date('Y') - 3; $y--): ?>
                <option value="<?= $y ?>" <?= $y === $reqYear ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
            <button type="submit" class="btn btn-sm btn-primary">Go</button>
        </form>
    </div>

    <div class="card-body">

        <!-- ── Sub-header: month summary + Manage Holidays button ── -->
        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
            <h6 class="text-muted mb-0">
                <?= $monthLabel ?> &mdash; <?= $days ?> days &nbsp;|&nbsp;
                <strong><?= $totalWorkingDays ?></strong> working days
            </h6>
            <a href="<?= BASE_URL ?>/modules/holidays/index.php"
               class="btn btn-sm btn-outline-secondary">
                <i class="fa fa-calendar-day me-1"></i>Manage Holidays
            </a>
        </div>

        <!-- ── Status legend bar ──────────────────────────────────────────── -->
        <div class="d-flex gap-3 mb-2 flex-wrap align-items-center">
            <!-- Expanded from Laravel's Attendance::$statusColors loop -->
            <span class="badge bg-success">Present</span>
            <span class="badge bg-danger">Absent</span>
            <span class="badge bg-warning text-dark">Half Day</span>
            <span class="badge bg-info">Late</span>
            <span class="badge bg-secondary">On Leave</span>
            <span class="badge" style="background:#6f42c1">Comp Off</span>

            <span class="badge bg-danger">A</span>
            <small class="text-muted">Absent / No record</small>

            <span class="ms-2" style="display:inline-block;width:16px;height:16px;background:#e9ecef;border:1px solid #dee2e6;vertical-align:middle;border-radius:2px"></span>
            <small class="text-muted">Weekend / Holiday</small>

            <span class="ms-2" style="display:inline-block;width:16px;height:16px;background:#fef9c3;border:1px solid #fde68a;vertical-align:middle;border-radius:2px;text-align:center;line-height:16px">
                <i class="fa fa-briefcase" style="font-size:.55rem;color:#b45309"></i>
            </span>
            <small class="text-muted">Working Holiday</small>

            <span class="ms-2" style="display:inline-block;width:16px;height:16px;background:#6f42c1;vertical-align:middle;border-radius:2px"></span>
            <small class="text-muted">Comp Off</small>

            <span class="ms-2 badge" style="background:#c8a96e;font-size:.7rem">OD</span>
            <small class="text-muted">On Duty (counted as Present)</small>

            <span class="ms-2" style="display:inline-block;width:16px;height:16px;background:#f3eeff;border:1px solid #d8b4fe;vertical-align:middle;border-radius:2px;text-align:center;line-height:16px">
                <i class="fa fa-calendar-plus" style="font-size:.55rem;color:#6f42c1"></i>
            </span>
            <small class="text-muted">Comp Off Day</small>

            <span class="ms-2 badge bg-info" style="font-size:.7rem">L</span>
            <small class="text-muted">Late (hover for minutes)</small>

            <span class="ms-2 badge" style="background:#e67e22;font-size:.7rem">A</span>
            <small class="text-muted">Absent &mdash; checked in but no checkout</small>

            <span class="ms-2 badge" style="background:#e6e6fa;color:#5b21b6;border:1px solid #c4b5fd;font-size:.7rem">PL</span>
            <small class="text-muted">Approved Paid Leave (lavender)</small>
        </div>

        <!-- ── Non-working day banner ─────────────────────────────────────── -->
        <?php if (!empty($nonWorkingDays)): ?>
        <div class="alert alert-light border py-2 mb-3 small">
            <i class="fa fa-moon me-1 text-secondary"></i>
            <strong>Holidays &amp; Weekly Offs this month:</strong>
            <span class="ms-1">
                <?php foreach ($nonWorkingDays as $nwDay => $nwLabel):
                    $isWknd = in_array($nwLabel, ['Sunday', '1st Saturday', '3rd Saturday']);
                ?>
                    <?php if ($isWknd): ?>
                    <span class="badge bg-secondary me-1"><?= $nwDay ?> &mdash; <?= h($nwLabel) ?></span>
                    <?php else: ?>
                    <span class="badge me-1" style="background:#c0392b;"><?= $nwDay ?> &mdash; <?= h($nwLabel) ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>
            </span>
        </div>
        <?php endif; ?>

        <!-- ── Late permission info box ───────────────────────────────────── -->
        <div class="alert alert-info border py-2 mb-3 small">
            <i class="fa fa-clock me-1"></i>
            <strong>Office start: <?= $officeStartFmt ?></strong>.
            &nbsp;Daily grace: <strong><?= $dailyGraceMins ?> min</strong> &rarr; late if check-in after <strong><?= $lateThreshFmt ?></strong>.
            &nbsp;Monthly late permission: <strong><?= $mGraceFmt ?> (<?= $monthlyGraceMins ?> min)</strong>.
            If total late exceeds <strong><?= $mGraceFmt ?></strong>, the <em>entire</em> late amount is deducted at <strong>2&times; rate</strong>.
            <span class="text-muted ms-2">Half-day auto-triggers if check-in is after <strong><?= $halfDayFmt ?></strong>.</span>
            <a href="<?= BASE_URL ?>/modules/settings/" class="ms-2 text-decoration-none small">
                <i class="fa fa-gear"></i> Change
            </a>
        </div>

        <style>
            .att-table thead th {
                position: sticky;
                top: 0;
                z-index: 10;
            }
        </style>

        <!-- ── Matrix table ───────────────────────────────────────────────── -->
        <div class="table-responsive" style="max-height:calc(100vh - 220px);overflow-y:auto;">
            <table class="table table-bordered table-sm align-middle att-table" style="font-size:.8rem">

                <thead class="table-dark">
                <tr>
                    <th style="min-width:160px">Employee</th>

                    <?php for ($d = 1; $d <= $days; $d++):
                        $thDateStr = "$reqYear-$padMonth-" . str_pad($d, 2, '0', STR_PAD_LEFT);
                        $thDayDate = new DateTime($thDateStr);
                        $dayAbbr   = $thDayDate->format('D');  // Mon, Tue, …
                        $isNonWrk  = isset($nonWorkingDays[$d]);
                        $isWorkHol = isset($workingHolDays[$d]);
                        $isCompOff = isset($compOffDays[$d]);
                        $coLabel   = $compOffDays[$d] ?? null;

                        $thStyle = 'min-width:38px;';
                        $thClass = 'text-center ';
                        if ($isNonWrk)      { $thClass .= 'table-secondary'; }
                        elseif ($isWorkHol) { $thClass .= 'table-warning'; }
                        elseif ($isCompOff) { $thStyle .= 'background:#ede9fe;'; }

                        $thTitle = $nonWorkingDays[$d]
                            ?? ($isWorkHol ? 'Working Holiday: ' . $workingHolDays[$d]
                            : ($isCompOff  ? 'Comp Off Day: '    . ($coLabel ?? '')
                            : ''));
                    ?>
                    <th class="<?= $thClass ?>" style="<?= $thStyle ?>"
                        <?= $thTitle ? 'title="' . h($thTitle) . '"' : '' ?>>
                        <?= $d ?><br>
                        <small style="font-size:.6rem"><?= $dayAbbr ?></small>
                        <?php if ($isWorkHol): ?>
                        <br><i class="fa fa-briefcase" style="font-size:.55rem;color:#b45309"></i>
                        <?php elseif ($isCompOff && !$isNonWrk): ?>
                        <br><i class="fa fa-calendar-plus" style="font-size:.55rem;color:#6f42c1"></i>
                        <?php endif; ?>
                    </th>
                    <?php endfor; ?>

                    <!-- Summary columns (exact widths, classes, and titles from Laravel source) -->
                    <th class="text-center" style="min-width:32px" title="Present days">P</th>
                    <th class="text-center" style="min-width:32px" title="Absent days">A</th>
                    <th class="text-center" style="min-width:32px" title="Half days">H</th>
                    <th class="text-center" style="min-width:32px" title="Leave days">L</th>
                    <th class="text-center text-dark bg-warning bg-opacity-10" style="min-width:72px">Late Used</th>
                    <th class="text-center text-dark bg-info bg-opacity-10"   style="min-width:72px">Remaining</th>
                    <th class="text-center text-dark bg-danger bg-opacity-10" style="min-width:72px">Exceeded</th>
                    <th class="text-center text-dark bg-danger bg-opacity-25" style="min-width:80px">Deducted</th>
                    <th class="text-center text-dark bg-success bg-opacity-10" style="min-width:56px">OT Hrs</th>
                    <th class="text-center text-dark" style="min-width:72px;background:rgba(13,110,253,0.45)"
                        title="Total logged working hours for the month">Man Hrs</th>
                </tr>
                </thead>

                <tbody>
                <?php foreach ($employees as $empIdx => $emp):
                    $empId   = (int)$emp['id'];
                    $empRecs = $records[$empId] ?? [];
                    $stat    = $empStats[$empId] ?? [];
                    $lateMap = $lateByDay[$empId] ?? [];

                    // Grid counters — reset per employee (mirrors Laravel's $presentCount etc.)
                    $presentCount = 0;
                    $absentCount  = 0;
                    $halfCount    = 0;
                    $leaveCount   = 0; // no leave_requests table in HRMS — always 0
                ?>
                <tr>
                    <!-- Employee name cell — NOT sticky (matches Laravel source) -->
                    <td>
                        <strong><?= h($emp['name']) ?></strong><br>
                        <small class="text-muted"><?= h($emp['emp_code']) ?></small>
                    </td>

                    <!-- ── Daily grid cells ──────────────────────────────── -->
                    <?php for ($d = 1; $d <= $days; $d++):
                        $cellDateStr  = "$reqYear-$padMonth-" . str_pad($d, 2, '0', STR_PAD_LEFT);
                        $isNonWrk     = isset($nonWorkingDays[$d]);
                        $isCompOffDay = isset($compOffDays[$d]);
                        $rec          = $empRecs[$d] ?? null;
                        $status       = $rec['status'] ?? null;
                        $isFuture     = ($cellDateStr > $todayStr);
                        $dailyLateMins = $lateMap[$d] ?? 0;

                        // Orange A: had check-in but no checkout (saved as Absent by server,
                        // or legacy On Time/Late records missing checkout)
                        $noCheckout = (in_array($status, ['On Time', 'Late'], true) && empty($rec['out_time'] ?? ''))
                                   || ($status === 'Absent' && !empty($rec['in_time'] ?? '') && empty($rec['out_time'] ?? ''));

                        // OD override (only on working, non-comp-off days)
                        $isOnDuty = (!$isNonWrk && !$isCompOffDay && $status === 'OD');

                        // Under 8 net working hours (after breaks) on a working day → Half Day.
                        $isEarlyOut = !$isNonWrk && !$isCompOffDay && !$noCheckout && _rep_isEarlyOut($rec, employee_lunch_window($empId));

                        // Admin-approved PAID leave covering this date.
                        $hasPaidLeave = isset($paidLeaveDates[$empId][$cellDateStr]);
                    ?>

                    <?php if ($isNonWrk): ?>
                        <!-- Non-working day: first employee row renders the spanning cell; rest skip -->
                        <?php if ($empIdx === 0):
                            $cellNwLabel   = $nonWorkingDays[$d];
                            $cellIsWeekend = in_array($cellNwLabel, ['Sunday', '1st Saturday', '3rd Saturday']);
                            $nwLabelColor  = $cellIsWeekend ? '#6c757d' : '#c0392b';
                        ?>
                        <?php
                            // The rotated label is doubled + padded so it fills a column
                            // that spans many employee rows. When only a few rows exist
                            // (e.g. an employee viewing just their own row) that padding
                            // would stretch the single row absurdly tall, so render the
                            // label once, compactly, instead.
                            $nwTall = $empCount > 3;
                        ?>
                        <td class="text-center table-secondary"
                            rowspan="<?= $empCount ?>"
                            style="vertical-align:middle;padding:4px 2px;">
                            <span style="writing-mode:vertical-rl;transform:rotate(180deg);font-size:.6rem;color:<?= $nwLabelColor ?>;font-weight:700;letter-spacing:<?= $nwTall ? '4px' : '1px' ?>;display:inline-block;line-height:1;<?= $nwTall ? 'word-spacing:2em;' : '' ?>"
                                  title="<?= h($cellNwLabel) ?>"><?= h($cellNwLabel) ?><?php if ($nwTall): ?>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<?= h($cellNwLabel) ?><?php endif; ?></span>
                        </td>
                        <?php endif; ?>
                        <!-- All other employee rows: skip — covered by rowspan above -->

                    <?php else: /* Regular working day cell */ ?>

                    <?php
                        // Count tracking — mirrors Laravel's logic exactly
                        if (!$isCompOffDay && !$isOnDuty) {
                            if ($noCheckout && !$isFuture) {
                                $absentCount++;
                            } elseif (in_array($status, ['On Time', 'Late'], true) && !$noCheckout) {
                                if ($isEarlyOut) { $halfCount++; }   // early checkout → half day
                                else             { $presentCount++; }
                            } elseif (($status === 'Absent' || $status === null) && !$isFuture) {
                                $absentCount++;
                            } elseif ($status === 'Half Day') {
                                $halfCount++;
                            }
                            // 'on_leave' not in HRMS ENUM — $leaveCount stays 0
                        }
                        if ($isOnDuty) $presentCount++; // OD = present

                        // A day covered by an admin-approved PAID leave that would
                        // otherwise read as absent (no record, "Absent", or "On Leave").
                        // Present/Late/Half-Day/Holiday/Comp-Off/OD keep their own colour;
                        // unpaid leaves are NOT lavender (they stay red/absent).
                        $paidLeaveAbsent = $hasPaidLeave && !$noCheckout && !$isFuture
                            && !in_array($status, ['On Time', 'Late', 'Half Day', 'Holiday', 'Comp Off', 'OD'], true);

                        // Cell background
                        $cellBg = '';
                        if ($isCompOffDay)        $cellBg = 'background:#ede9fe;';
                        elseif ($isOnDuty)        $cellBg = 'background:#fdf3e3;';
                        elseif ($paidLeaveAbsent) $cellBg = 'background:#e6e6fa;'; // lavender
                        elseif ($status === 'Absent' && ($rec['leave_classification'] ?? '') === 'paid') $cellBg = 'background:#e6e6fa;';
                    ?>

                    <td class="text-center" style="<?= $cellBg ?>">
                        <?php if ($isCompOffDay): ?>
                            <span class="badge" style="background:#6f42c1;font-size:.68rem"
                                  title="Comp Off<?= $coLabel ? ' &mdash; ' . h($coLabel) : '' ?>">CO</span>

                        <?php elseif ($isOnDuty): ?>
                            <span class="badge" style="background:#c8a96e;font-size:.68rem"
                                  title="On Duty<?= !empty($rec['remarks']) ? ': ' . h($rec['remarks']) : '' ?>">OD</span>

                        <?php else: ?>
                            <?php
                                // Build title attribute
                                if ($noCheckout) {
                                    $titleAttr = 'No Checkout &mdash; checked in but no checkout recorded';
                                } elseif ($status === 'Late' && $dailyLateMins > 0) {
                                    $titleAttr = 'Late by ' . _rep_fmtMins($dailyLateMins);
                                } elseif ($status === 'Half Day') {
                                    $titleAttr = 'Half Day';
                                } elseif ($status === 'Comp Off') {
                                    $titleAttr = 'Comp Off';
                                } elseif ($status === 'On Time') {
                                    $titleAttr = 'Present';
                                } elseif ($status === 'Absent') {
                                    $titleAttr = 'Absent';
                                } elseif ($status === null && !$isFuture) {
                                    $titleAttr = 'Absent';
                                } else {
                                    $titleAttr = '';
                                }
                                $titleHtml = $titleAttr ? ' title="' . $titleAttr . '"' : '';

                                // Render badge
                                if ($paidLeaveAbsent) {
                                    // Admin-approved PAID leave → lavender (not red absent)
                                    echo '<span class="badge" style="background:#e6e6fa;color:#5b21b6;font-size:.7rem;border:1px solid #c4b5fd" title="Approved paid leave">PL</span>';
                                } elseif ($status === 'Absent') {
                                    // Absent day (real row) — classifiable as Paid / Unpaid leave.
                                    echo _rep_absentBadge($empId, $cellDateStr, $rec['leave_classification'] ?? null, $canClassify, $paidUsedByEmp[$empId] ?? 0);
                                } elseif ($noCheckout) {
                                    // Orange A — checked in but no checkout
                                    echo '<span class="badge" style="background:#e67e22;font-size:.7rem;"' . $titleHtml . '>A</span>';
                                } elseif ($isEarlyOut) {
                                    // Under a full 8 net hours (excl. breaks) → partial day (Half)
                                    echo '<span class="badge bg-warning text-dark" style="font-size:.7rem" title="Half / Short day &mdash; under 8 net working hours, excl. breaks (in ' . h(date('h:i A', strtotime($rec['in_time']))) . ', out ' . h(date('h:i A', strtotime($rec['out_time']))) . ')">H</span>';
                                } elseif ($status === null && $isFuture) {
                                    // Future date with no record — neutral dash
                                    echo '<span class="badge bg-light text-dark" style="font-size:.7rem;border:1px solid #dee2e6">&#8212;</span>';
                                } elseif ($status === null && !$isFuture) {
                                    // Past/today with no record → absent (classifiable; the row is
                                    // created on demand when a classification is chosen).
                                    echo _rep_absentBadge($empId, $cellDateStr, null, $canClassify, $paidUsedByEmp[$empId] ?? 0);
                                } else {
                                    // Map HRMS ENUM status to Bootstrap color + abbreviation
                                    switch ($status) {
                                        case 'On Time':  $color = 'success';   $abbr = 'P';  break;
                                        case 'Late':     $color = 'info';      $abbr = 'L';  break;
                                        case 'Absent':   $color = 'danger';    $abbr = 'A';  break;
                                        case 'Half Day': $color = 'warning';   $abbr = 'H';  break;
                                        case 'Holiday':  $color = 'secondary'; $abbr = 'H';  break;
                                        case 'Comp Off': $color = 'compoff';   $abbr = 'CO'; break;
                                        default:         $color = 'danger';    $abbr = 'A';  break;
                                    }

                                    if ($status === 'Comp Off') {
                                        echo '<span class="badge" style="background:#6f42c1;font-size:.7rem"' . $titleHtml . '>CO</span>';
                                    } else {
                                        $textColor  = in_array($color, ['warning', 'light']) ? 'dark' : 'white';
                                        $extraStyle = ($color === 'light') ? ';border:1px solid #dee2e6' : '';
                                        echo '<span class="badge bg-' . $color . ' text-' . $textColor . '" style="font-size:.7rem' . $extraStyle . '"' . $titleHtml . '>' . $abbr . '</span>';
                                    }
                                }
                            ?>
                        <?php endif; ?>
                    </td>

                    <?php endif; /* end isNonWrk / regular cell */ ?>

                    <?php endfor; /* end day loop */ ?>

                    <!-- ── Summary columns ─────────────────────────────── -->
                    <?php
                        $lateMins    = (int)($stat['late_mins']      ?? 0);
                        $remainPerm  = (int)($stat['remaining_perm'] ?? $monthlyGraceMins);
                        $exceededMin = (int)($stat['exceeded_mins']  ?? 0);
                        $deductMin   = (int)($stat['deduct_mins']    ?? 0);
                        $otHrsVal    = (float)($stat['ot_hours']     ?? 0);
                        $manHrsVal   = (float)($stat['man_hours']    ?? 0);
                        $absDays     = (int)($stat['absent_days']    ?? $absentCount);
                        $halfDays    = (int)($stat['half_days']      ?? $halfCount);
                    ?>

                    <!-- P: Present count (grid) -->
                    <td class="text-center fw-bold text-success"><?= $presentCount ?></td>

                    <!-- A: Absent count (pre-computed stats, fallback to grid) -->
                    <td class="text-center fw-bold text-danger"><?= $absDays ?></td>

                    <!-- H: Half Day count -->
                    <td class="text-center fw-bold text-warning"><?= $halfDays ?></td>

                    <!-- L: Paid leave days (absences classified as Paid Leave) -->
                    <?php $leaveDays = (int)($stat['leave_days'] ?? 0); ?>
                    <td class="text-center fw-bold <?= $leaveDays > 0 ? 'text-info' : 'text-secondary' ?>"
                        title="Absences classified as Paid Leave this month"><?= $leaveDays ?></td>

                    <!-- Late Used -->
                    <td class="text-center <?= $lateMins > 0 ? 'text-warning fw-semibold' : 'text-muted' ?>"
                        style="font-size:.78rem"
                        title="Total late time this month">
                        <?= $lateMins > 0 ? _rep_fmtMins($lateMins) : '&mdash;' ?>
                    </td>

                    <!-- Remaining Permission — hardcoded 90 matches Laravel source exactly -->
                    <td class="text-center <?= $remainPerm < 90 ? ($remainPerm === 0 ? 'text-danger fw-semibold' : 'text-info fw-semibold') : 'text-muted' ?>"
                        style="font-size:.78rem"
                        title="Remaining late grace (90 min/month)">
                        <?= _rep_fmtMins($remainPerm) ?>
                    </td>

                    <!-- Exceeded -->
                    <td class="text-center <?= $exceededMin > 0 ? 'text-danger fw-semibold' : 'text-muted' ?>"
                        style="font-size:.78rem"
                        title="Late time beyond the 90-min grace">
                        <?= $exceededMin > 0 ? _rep_fmtMins($exceededMin) : '&mdash;' ?>
                    </td>

                    <!-- Deducted (2× penalty on full late amount) -->
                    <td class="text-center <?= $deductMin > 0 ? 'text-danger fw-bold' : 'text-muted' ?>"
                        style="font-size:.78rem"
                        title="Deducted at 2&times; of total late time">
                        <?php if ($deductMin > 0): ?>
                            <?= _rep_fmtMins($deductMin) ?>
                            <br><small style="font-size:.62rem">(2&times; penalty)</small>
                        <?php else: ?>&mdash;<?php endif; ?>
                    </td>

                    <!-- OT Hours -->
                    <td class="text-center <?= $otHrsVal > 0 ? 'text-success fw-semibold' : 'text-muted' ?>"
                        style="font-size:.78rem">
                        <?= $otHrsVal > 0 ? $otHrsVal . 'h' : '&mdash;' ?>
                    </td>

                    <!-- Man Hours (total logged working hours for the month) -->
                    <?php
                        if ($manHrsVal > 0) {
                            $whH       = (int)floor($manHrsVal);
                            $whM       = (int)round(($manHrsVal - $whH) * 60);
                            $whDisplay = $whH . 'h' . ($whM > 0 ? ' ' . $whM . 'm' : '');
                        } else {
                            $whDisplay = '&mdash;';
                        }
                    ?>
                    <td class="text-center <?= $manHrsVal > 0 ? 'text-primary fw-semibold' : 'text-muted' ?>"
                        style="font-size:.78rem"
                        title="Total working hours logged this month (<?= $manHrsVal ?>h raw)">
                        <?= $whDisplay ?>
                    </td>

                </tr>
                <?php endforeach; /* end employees loop */ ?>
                </tbody>

            </table>
        </div><!-- /table-responsive -->

        <!-- ── Footer legend ──────────────────────────────────────────────── -->
        <div class="small text-muted mt-2">
            <strong>Legend:</strong>
            P = Present &nbsp;|&nbsp;
            A = Absent &nbsp;|&nbsp;
            <span class="badge" style="background:#e67e22;font-size:.7rem">A</span> = No Checkout (marked absent, orange) &nbsp;|&nbsp;
            H = Half Day &nbsp;|&nbsp;
            L = Late &nbsp;|&nbsp;
            OL = On Leave
            &nbsp;|&nbsp;
            <span class="badge" style="background:#6f42c1;font-size:.7rem">CO</span> = Comp Off
            &nbsp;|&nbsp;
            <span class="badge" style="background:#0891b2;font-size:.7rem">OD</span> = On Duty (present, no deduction)
            &nbsp;|&nbsp;
            <span class="badge bg-secondary" style="font-size:.7rem">H</span> = Weekend (Sunday / Saturday)
            &nbsp;|&nbsp;
            <span style="writing-mode:vertical-rl;transform:rotate(180deg);font-size:.6rem;color:#c0392b;font-weight:700;letter-spacing:2px;display:inline-block;vertical-align:middle;">Diwali</span> = Public Holiday (name shown rotated)
            &nbsp;|&nbsp;
            <strong>Late Used</strong> = Total monthly late time
            &nbsp;|&nbsp;
            <strong>Remaining</strong> = Grace left (resets each month)
            &nbsp;|&nbsp;
            <strong>Exceeded</strong> = Minutes beyond 90-min grace
            &nbsp;|&nbsp;
            <strong>Deducted</strong> = Penalty charged (2&times; total late when grace exceeded)
            &nbsp;|&nbsp;
            <strong>Man Hrs</strong> = Total working hours logged (check-in &rarr; check-out) for the month
            &nbsp;|&nbsp;
            <span class="badge" style="background:#e6e6fa;color:#5b21b6;border:1px solid #c4b5fd;font-size:.7rem">PL</span> = Paid Leave
            &nbsp;
            <span class="badge bg-danger" style="font-size:.7rem">UL</span> = Unpaid Leave
            <?php if ($canClassify): ?><span class="text-muted">&nbsp;— click any <span class="badge bg-danger" style="font-size:.65rem">A</span> to classify the absence</span><?php endif; ?>
        </div>

    </div><!-- /card-body -->
</div><!-- /card page-card -->

<?php if ($canClassify): ?>
<!-- ── Leave-classification floating menu (single shared instance) ──────────── -->
<div id="lcMenu" class="card shadow-sm p-1" style="display:none;position:fixed;z-index:1080;min-width:170px;font-size:.8rem">
    <div class="px-2 py-1 fw-semibold text-muted" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.04em">Classify Absence</div>
    <button type="button" class="btn btn-sm btn-light w-100 text-start" data-act="paid"   id="lcPaid">Paid Leave</button>
    <button type="button" class="btn btn-sm btn-light w-100 text-start" data-act="unpaid" id="lcUnpaid">Unpaid Leave</button>
    <button type="button" class="btn btn-sm btn-light w-100 text-start text-muted" data-act="none" id="lcClear">Clear (Absent)</button>
</div>
<script>
(function () {
    var CSRF = <?= json_encode(csrf_token()) ?>;
    var URL  = '<?= BASE_URL ?>/modules/attendance/leave_classify.php';
    var menu = document.getElementById('lcMenu');
    var cur  = null;   // the badge currently being classified

    window.openLcMenu = function (badge, ev) {
        ev = ev || window.event;
        if (ev) ev.stopPropagation();
        cur = badge;
        var disabled = badge.getAttribute('data-paiddisabled') === '1';
        var paidBtn  = document.getElementById('lcPaid');
        paidBtn.disabled = disabled;
        paidBtn.classList.toggle('text-muted', disabled);
        paidBtn.textContent = disabled ? 'Paid Leave (limit reached)' : 'Paid Leave';
        // Position near the badge, clamped to the viewport.
        var r = badge.getBoundingClientRect();
        menu.style.display = 'block';
        var mw = menu.offsetWidth, mh = menu.offsetHeight;
        var left = Math.min(r.left, window.innerWidth  - mw - 8);
        var top  = (r.bottom + mh + 8 < window.innerHeight) ? r.bottom + 4 : r.top - mh - 4;
        menu.style.left = Math.max(8, left) + 'px';
        menu.style.top  = Math.max(8, top)  + 'px';
    };

    function close() { menu.style.display = 'none'; cur = null; }
    document.addEventListener('click', function (e) { if (!menu.contains(e.target) && (!cur || e.target !== cur)) close(); });

    menu.querySelectorAll('button[data-act]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!cur || btn.disabled) return;
            var emp = cur.getAttribute('data-emp'), date = cur.getAttribute('data-date');
            var body = new URLSearchParams({ employee_id: emp, att_date: date, classification: btn.getAttribute('data-act'), csrf_token: CSRF });
            btn.disabled = true;
            fetch(URL, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
                .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
                .then(function (res) {
                    if (!res.ok || !res.j.ok) { alert(res.j.message || 'Could not save.'); btn.disabled = false; return; }
                    location.reload();   // reflect new counts / paid-cap state
                })
                .catch(function () { alert('Network error — please retry.'); btn.disabled = false; });
        });
    });
})();
</script>
<?php endif; ?>

<?php
/* ═══════════════════════════════════════════════════════════════════════════
   HELPER FUNCTIONS (file-scoped, _rep_ prefix)
   ═══════════════════════════════════════════════════════════════════════════ */

/** Convert "HH:MM" or "HH:MM:SS" or datetime string to minutes since midnight. */
function _rep_timeToMins(string $t): int {
    $t = trim($t);
    if (str_contains($t, ' ')) $t = trim(substr(strrchr($t, ' '), 1));
    if (str_contains($t, 'T')) $t = trim(substr(strrchr($t, 'T'), 1));
    $parts = explode(':', $t);
    return (int)($parts[0] ?? 0) * 60 + (int)($parts[1] ?? 0);
}

/** Returns true if $d is the 1st or 3rd Saturday of its month. */
function _rep_is13Sat(DateTime $d): bool {
    if ((int)$d->format('N') !== 6) return false;
    return in_array(_rep_satCountUpTo($d), [1, 3]);
}

/** Count how many Saturdays have occurred from the 1st of the month up to and including $d. */
function _rep_satCountUpTo(DateTime $d): int {
    $n = 0;
    $t = (clone $d)->modify('first day of this month');
    while ($t <= $d) {
        if ((int)$t->format('N') === 6) $n++;
        $t->modify('+1 day');
    }
    return $n;
}

/**
 * True when a present working-day (On Time / Late, both punches) should count as
 * a HALF DAY: the employee either checked in after 11:00 AM, or worked under 8
 * hours (which also covers early checkout and the 9:00–1:30 case). A 9:00–6:15
 * day (check-in by 11:00 and ≥ 8 hours) is a full day. Shown as Half Day in the
 * report; payroll pro-rates the salary on the hours actually worked.
 */
function _rep_isEarlyOut(?array $rec, ?array $lunchWindow = null): bool {
    if (!$rec || empty($rec['in_time']) || empty($rec['out_time'])) return false;
    if (!in_array($rec['status'] ?? '', ['On Time', 'Late'], true)) return false;
    $in  = _rep_timeToMins($rec['in_time']);
    $out = _rep_timeToMins($rec['out_time']);
    if ($out <= $in) return false;                 // ignore midnight-cross / bad data
    // Net worked = presence − break windows (the employee's lunch batch + 2 tea breaks) within it.
    // Shows "H" when: check-in after ~11:00, OR net worked ≤ 4h (half/short day). A day
    // worked > 4h with an on-time-ish check-in stays PRESENT (salary is pro-rated by payroll).
    // (The slip splits H into "Half Day" vs "Short Hours"; the report shows both as H.)
    $worked = max(0, ($out - $in) - break_minutes_within($in, $out, $lunchWindow));
    $osM = function_exists('setting_office_start_mins') ? (int) setting_office_start_mins() : 9 * 60;
    $grM = function_exists('setting_daily_grace_mins')  ? (int) setting_daily_grace_mins()  : 15;
    $oeM = function_exists('setting_office_end_mins')   ? (int) setting_office_end_mins()    : 18 * 60;
    return in_array(attendance_classify($worked, $in, $osM, $grM, $out, $oeM)['status'], ['half', 'short'], true);
}

/**
 * Render the badge for an absent day. When the user can classify, the badge is a
 * clickable control (Paid / Unpaid / Clear). Works for both real Absent rows and
 * "no record" past days (the attendance row is created on demand on first save).
 */
function _rep_absentBadge(int $empId, string $dateStr, ?string $cls, bool $canClassify, int $paidUsed): string {
    if ($cls === 'paid')       { $bClass = 'badge';           $bStyle = 'background:#e6e6fa;color:#5b21b6;border:1px solid #c4b5fd'; $bTxt = 'PL'; $bTitle = 'Paid Leave'; }
    elseif ($cls === 'unpaid') { $bClass = 'badge bg-danger'; $bStyle = '';                                                          $bTxt = 'UL'; $bTitle = 'Unpaid Leave (LOP)'; }
    else                       { $bClass = 'badge bg-danger'; $bStyle = '';                                                          $bTxt = 'A';  $bTitle = 'Absent'; }

    if (!$canClassify) {
        return '<span class="' . $bClass . '" style="font-size:.7rem;' . $bStyle . '" title="' . h($bTitle) . '">' . $bTxt . '</span>';
    }
    $paidDisabled = ($paidUsed >= 1 && $cls !== 'paid') ? 1 : 0;
    return '<span class="' . $bClass . ' lc-badge" style="font-size:.7rem;cursor:pointer;' . $bStyle . '"'
         . ' title="' . h($bTitle) . ' — click to classify"'
         . ' data-emp="' . $empId . '" data-date="' . h($dateStr) . '"'
         . ' data-cls="' . h((string)$cls) . '" data-paiddisabled="' . $paidDisabled . '"'
         . ' onclick="openLcMenu(this, event)">' . $bTxt . '</span>';
}

/** Format integer minutes as "Xh Ym" or "Ym". */
function _rep_fmtMins(int $m): string {
    if ($m <= 0) return '0m';
    return ($m >= 60 ? intdiv($m, 60) . 'h ' : '') . ($m % 60) . 'm';
}

include __DIR__ . '/../../includes/footer.php';
