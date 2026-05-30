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
require_permission('attendance', 'view');

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

/* ── Load all employees ──────────────────────────────────────────────────── */
$employees = $db->query(
    "SELECT e.id, e.name, e.employee_id AS emp_code,
            COALESCE(d.name, '—') AS dept_name
     FROM   employees e
     LEFT JOIN departments d ON d.id = e.department_id
     WHERE  e.status = 'Active'
     ORDER  BY e.name"
)->fetchAll();
$empCount = count($employees);

/* ── Load all attendance records for the month ───────────────────────────── */
$attStmt = $db->prepare(
    "SELECT employee_id, att_date, status, in_time, out_time, ot_hours, remarks
     FROM   attendance
     WHERE  att_date BETWEEN ? AND ?"
);
$attStmt->execute([$mStartStr, $mEndStr]);
$allRecs = $attStmt->fetchAll();

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
            $absentCnt++;
            continue;
        }
        if ($status === 'Half Day') { $halfCnt++; continue; }
        // OD, Comp Off, Holiday = neither absent nor late
        if (in_array($status, ['OD', 'Comp Off', 'Holiday'], true)) continue;

        // On Time or Late
        if (in_array($status, ['On Time', 'Late'], true)) {
            if ($status === 'Late' && !empty($rec['in_time'])) {
                $ciMins  = _rep_timeToMins($rec['in_time']);
                $dayLate = max(0, $ciMins - $officeStartMins);
                if ($dayLate > 0) {
                    $lateByDay[$empId][$dayNum] = $dayLate;
                    $lateMins += $dayLate;
                }
            }
            $presentCnt++;
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
            <a href="<?= BASE_URL ?>/modules/settings/index.php?tab=holiday-types"
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
                    <th class="text-center bg-warning bg-opacity-10" style="min-width:72px">Late Used</th>
                    <th class="text-center bg-info bg-opacity-10"   style="min-width:72px">Remaining</th>
                    <th class="text-center bg-danger bg-opacity-10" style="min-width:72px">Exceeded</th>
                    <th class="text-center bg-danger bg-opacity-25" style="min-width:80px">Deducted</th>
                    <th class="text-center bg-success bg-opacity-10" style="min-width:56px">OT Hrs</th>
                    <th class="text-center text-white" style="min-width:72px;background:rgba(13,110,253,0.45)"
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
                    ?>

                    <?php if ($isNonWrk): ?>
                        <!-- Non-working day: first employee row renders the spanning cell; rest skip -->
                        <?php if ($empIdx === 0):
                            $cellNwLabel   = $nonWorkingDays[$d];
                            $cellIsWeekend = in_array($cellNwLabel, ['Sunday', '1st Saturday', '3rd Saturday']);
                            $nwLabelColor  = $cellIsWeekend ? '#6c757d' : '#c0392b';
                        ?>
                        <td class="text-center table-secondary"
                            rowspan="<?= $empCount ?>"
                            style="vertical-align:middle;padding:4px 2px;">
                            <span style="writing-mode:vertical-rl;transform:rotate(180deg);font-size:.6rem;color:<?= $nwLabelColor ?>;font-weight:700;letter-spacing:4px;display:inline-block;line-height:1;word-spacing:2em;"
                                  title="<?= h($cellNwLabel) ?>"><?= h($cellNwLabel) ?>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<?= h($cellNwLabel) ?></span>
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
                                $presentCount++;
                            } elseif (($status === 'Absent' || $status === null) && !$isFuture) {
                                $absentCount++;
                            } elseif ($status === 'Half Day') {
                                $halfCount++;
                            }
                            // 'on_leave' not in HRMS ENUM — $leaveCount stays 0
                        }
                        if ($isOnDuty) $presentCount++; // OD = present

                        // Cell background
                        $cellBg = '';
                        if ($isCompOffDay)   $cellBg = 'background:#ede9fe;';
                        elseif ($isOnDuty)  $cellBg = 'background:#fdf3e3;';
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
                                if ($noCheckout) {
                                    // Orange A — checked in but no checkout
                                    echo '<span class="badge" style="background:#e67e22;font-size:.7rem;"' . $titleHtml . '>A</span>';
                                } elseif ($status === null && $isFuture) {
                                    // Future date with no record — neutral dash
                                    echo '<span class="badge bg-light text-dark" style="font-size:.7rem;border:1px solid #dee2e6">&#8212;</span>';
                                } elseif ($status === null && !$isFuture) {
                                    // Past/today with no record → absent
                                    echo '<span class="badge bg-danger"' . $titleHtml . ' style="font-size:.7rem">A</span>';
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

                    <!-- L: Leave days (from payroll — always 0 in HRMS, no leave_requests table) -->
                    <td class="text-center fw-bold text-secondary"
                        title="Approved paid leaves + comp-offs (from payroll)"><?= $leaveCount ?></td>

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
        </div>

    </div><!-- /card-body -->
</div><!-- /card page-card -->

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

/** Format integer minutes as "Xh Ym" or "Ym". */
function _rep_fmtMins(int $m): string {
    if ($m <= 0) return '0m';
    return ($m >= 60 ? intdiv($m, 60) . 'h ' : '') . ($m % 60) . 'm';
}

include __DIR__ . '/../../includes/footer.php';
