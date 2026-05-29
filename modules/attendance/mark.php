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
 * Auto OT:
 *   • Checkout ≥ OT_TRIGGER_TIME           → OT hours = (checkout − OT_BASELINE_TIME) / 60
 */

$page_title = 'Mark Attendance';
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('attendance', 'mark');

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

// Determine if it's a non-working day
function _mark_is13Sat(DateTime $d): bool {
    if ((int)$d->format('N') !== 6) return false;
    $n = 0; $t = (clone $d)->modify('first day of this month');
    while ($t <= $d) { if ((int)$t->format('N') === 6) $n++; $t->modify('+1 day'); }
    return in_array($n, [1, 3]);
}

$isNonWorking    = false;
$nonWorkingLabel = null;
$compOffWorkingDay = null; // working-holiday row if admin declared it

if ($isWorkingHoliday) {
    // Admin declared it working — show as comp-off earner banner
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

/* ── Config (passed to JS) ────────────────────────────────────────────────── */
$officeStartMins = (function($t) { [$h,$m] = explode(':', $t); return $h*60+$m; })(WORK_START_TIME);
$dailyGraceMins  = ATTENDANCE_GRACE_MINUTES;
$lateThreshMins  = $officeStartMins + $dailyGraceMins;
$halfDayCutoff   = $officeStartMins + 120; // 11:00 if start is 09:00

$otTriggerTime  = OT_TRIGGER_TIME;
$otBaselineTime = OT_BASELINE_TIME;
$otTriggerMins  = (function($t) { [$h,$m] = explode(':', $t); return $h*60+$m; })($otTriggerTime);
$otBaselineMins = (function($t) { [$h,$m] = explode(':', $t); return $h*60+$m; })($otBaselineTime);

/* ── POST: save attendance ────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_save'])) {
    verify_csrf($_POST['csrf_token'] ?? '');

    $rows   = $_POST['attendance'] ?? [];
    $marked = 0;

    $upsert = $db->prepare(
        "INSERT INTO attendance
             (employee_id, att_date, status, in_time, out_time, ot_hours, remarks, marked_by)
         VALUES (?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
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
        $otHours  = ($data['ot_hours'] ?? '') !== '' ? (float)($data['ot_hours']) : null;
        $remarks  = sanitize($data['remarks'] ?? '');

        // Auto-classify status from check-in if times are provided
        if ($inTime && !in_array($status, ['Absent','OD','Comp Off','Half Day','Holiday'], true)) {
            $inParts = explode(':', $inTime);
            $inMins  = (int)$inParts[0] * 60 + (int)($inParts[1] ?? 0);

            if ($inMins >= $halfDayCutoff) {
                $status = 'Half Day';
            } elseif ($inMins > $lateThreshMins) {
                $status = 'Late';
            } else {
                $status = 'On Time';
            }
        }

        // No checkout on Present/Late → mark Absent
        if (in_array($status, ['On Time','Late'], true) && !$outTime) {
            $status = 'Absent';
        }

        // Auto OT from checkout time
        if ($outTime && $otHours === null) {
            $outParts  = explode(':', $outTime);
            $outMins   = (int)$outParts[0] * 60 + (int)($outParts[1] ?? 0);
            if ($outMins >= $otTriggerMins) {
                $otCalc   = round(($outMins - $otBaselineMins) / 60, 2);
                if ($otCalc > 0) $otHours = $otCalc;
            }
        }

        // Clear times for non-time-based statuses
        if (in_array($status, ['Absent','Holiday','Comp Off'], true)) {
            $inTime  = null;
            $outTime = null;
            $otHours = null;
        }

        $upsert->execute([
            (int)$empId,
            $selDate,
            $status,
            $inTime  ?: null,
            $outTime ?: null,
            $otHours,
            $remarks ?: null,
            $user['id'],
        ]);
        $marked++;
    }

    flash('success', "Attendance saved for $marked employees on " . date('d M Y', strtotime($selDate)) . '.');
    redirect(BASE_URL . '/modules/attendance/index.php?month=' . substr($selDate, 0, 7));
}

/* ── Load employees + existing records for selected date ─────────────────── */
$employees = $db->prepare(
    "SELECT e.id, e.name, e.employee_id AS emp_code,
            COALESCE(d.name, '—') AS dept_name,
            a.id        AS att_id,
            a.status    AS att_status,
            a.in_time,
            a.out_time,
            a.ot_hours,
            a.remarks
     FROM   employees e
     LEFT JOIN departments d  ON d.id = e.department_id
     LEFT JOIN attendance  a  ON a.employee_id = e.id AND a.att_date = ?
     WHERE  e.status = 'Active'
     ORDER  BY e.name"
);
$employees->execute([$selDate]);
$employees = $employees->fetchAll();

/* ── Build a date-range for the previous/next date navigation ────────────── */
$yesterday = date('Y-m-d', strtotime($selDate . ' -1 day'));
$tomorrow  = date('Y-m-d', strtotime($selDate . ' +1 day'));
$todayDate = date('Y-m-d');

$month = substr($selDate, 0, 7); // for modals.php monthly default
$dailyResult   = $_SESSION['att_daily_result']   ?? null;
$monthlyResult = $_SESSION['att_monthly_result'] ?? null;
unset($_SESSION['att_daily_result'], $_SESSION['att_monthly_result']);

require_once __DIR__ . '/../../includes/header.php';
?>

<?php if ($monthlyResult): ?>
<div class="alert alert-<?= $monthlyResult['success'] ? 'success' : 'danger' ?> alert-dismissible fade show" role="alert">
    <i class="fa fa-<?= $monthlyResult['success'] ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
    <?= h($monthlyResult['message']) ?>
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
    <i class="fa fa-<?= $dailyResult['success'] ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
    <?= h($dailyResult['message']) ?>
    <?php if (!empty($dailyResult['details'])): ?>
    <ul class="mb-0 mt-1 small">
        <?php foreach ($dailyResult['details'] as $d): ?><li><?= h($d) ?></li><?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="page-head">
    <div>
        <h1>Mark Attendance</h1>
        <p class="muted"><?= date('l, d F Y', strtotime($selDate)) ?>
            <?php if ($holidayName && !$isWorkingHoliday): ?>
            &nbsp;— <span class="pill pill-danger"><?= h($holidayName) ?></span>
            <?php elseif ($isWorkingHoliday): ?>
            &nbsp;— <span class="pill pill-success"><i class="fa fa-briefcase me-1"></i><?= h($holidayName) ?> (Working)</span>
            <?php endif; ?>
        </p>
    </div>
    <div class="head-actions">
        <!-- Import + Report actions -->
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
        <!-- Date navigation -->
        <a href="mark.php?date=<?= $yesterday ?>&prev_date=<?= $selDate ?>" class="btn btn-sm btn-ghost"
           title="Previous day">‹</a>
        <form method="GET" style="display:inline-flex;gap:4px;align-items:center">
            <input type="date" name="date" value="<?= $selDate ?>"
                   max="<?= $todayDate ?>"
                   style="padding:5px 8px;border:1px solid var(--border-strong);border-radius:var(--radius);font-size:12px"
                   onchange="this.form.submit()">
            <button type="submit" class="btn btn-sm btn-primary" style="padding:5px 10px">
                <i class="fa fa-search"></i> Load
            </button>
        </form>
        <?php if ($selDate < $todayDate): ?>
        <a href="mark.php?date=<?= $tomorrow ?>&prev_date=<?= $selDate ?>" class="btn btn-sm btn-ghost"
           title="Next day">›</a>
        <?php endif; ?>
        <a href="index.php?month=<?= substr($selDate,0,7) ?>" class="btn btn-ghost">
            <i class="fa fa-arrow-left"></i> Back
        </a>
    </div>
</div>

<?php if ($compOffWorkingDay): ?>
<!-- Working Holiday banner -->
<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:var(--radius);
            padding:10px 14px;margin-bottom:14px;display:flex;gap:10px;align-items:flex-start">
    <i class="fa fa-briefcase fa-lg" style="color:#16a34a;margin-top:2px"></i>
    <div style="font-size:13px">
        <strong>Company Working Day</strong> —
        <?= date('l, d M Y', strtotime($selDate)) ?>
        (<?= h($compOffWorkingDay['day_type_label']) ?>
        <?php if ($holidayName): ?>: <?= h($holidayName) ?><?php endif; ?>)
        <br>
        <span style="color:var(--text-muted);font-size:12px">
            <i class="fa fa-rotate-left me-1" style="color:#16a34a"></i>
            Employees marked <strong>Present / Late / Half Day</strong> may earn
            <strong>1 Comp Off credit</strong> — record manually in Comp Off Requests.
        </span>
    </div>
</div>
<?php endif; ?>

<!-- Quick set all -->
<div class="card form-card" style="margin-bottom:14px">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <span style="font-weight:600;font-size:13px;color:var(--text-muted)">Set all to:</span>
        <?php
        $quickStatuses = ['On Time','Late','Absent','OD','Comp Off','Half Day'];
        if ($holidayName) array_unshift($quickStatuses, 'Holiday');
        foreach ($quickStatuses as $qs):
        ?>
        <button type="button" class="btn btn-sm" onclick="setAll('<?= $qs ?>')"><?= $qs ?></button>
        <?php endforeach; ?>
        <span style="color:var(--text-muted);font-size:11.5px;margin-left:auto">
            <i class="fa fa-clock"></i> Start: <?= WORK_START_TIME ?>
            &nbsp;·&nbsp; Grace: <?= ATTENDANCE_GRACE_MINUTES ?> min
            &nbsp;·&nbsp; OT trigger: ≥ <?= OT_TRIGGER_TIME ?>
        </span>
    </div>
</div>

<form method="POST" id="attendanceForm">
<?= csrf_field() ?>
<input type="hidden" name="prev_date" value="<?= $selDate ?>">

<div class="card">
    <div class="card-head">
        <h3>
            <i class="fa fa-calendar-check" style="color:var(--primary);margin-right:6px"></i>
            <?= count($employees) ?> employees — <?= date('d M Y', strtotime($selDate)) ?>
        </h3>
        <div class="search">
            <input type="search" placeholder="Filter employee…" id="empSearch"
                   oninput="filterRows(this.value)" data-search>
        </div>
    </div>
    <div style="overflow-x:auto">
    <table class="table table-bordered table-hover align-middle" id="attTable" style="font-size:13px">
        <thead class="table-dark">
            <tr>
                <th style="width:36px">#</th>
                <th style="min-width:180px">Employee</th>
                <th>Department</th>
                <th style="min-width:160px">
                    Status
                    <small class="fw-normal text-warning d-block" style="font-size:.65rem">
                        Auto-calculated when times are set
                    </small>
                </th>
                <th style="min-width:110px">Check In</th>
                <th style="min-width:110px">Check Out</th>
                <th style="min-width:90px">
                    OT Hrs
                    <small class="fw-normal text-warning d-block" style="font-size:.65rem"
                           title="Auto OT: checkout must reach trigger time. Hours counted from baseline.">
                        ≥<?= OT_TRIGGER_TIME ?> / from <?= OT_BASELINE_TIME ?>
                    </small>
                </th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $manualStatuses = ['Absent', 'OD', 'Comp Off', 'Holiday'];
        $autoStatuses   = ['On Time', 'Late', 'Half Day'];
        $badgeColors    = [
            'On Time'  => 'bg-success',
            'Late'     => 'bg-info',
            'Half Day' => 'bg-warning text-dark',
            'Absent'   => 'bg-danger',
            'OD'       => '',
            'Comp Off' => '',
            'Holiday'  => 'bg-secondary',
        ];
        $badgeStyles = [
            'OD'       => 'background:#c8a96e',
            'Comp Off' => 'background:#6f42c1',
        ];

        foreach ($employees as $i => $e):
            $hasTime   = !empty($e['in_time']) || !empty($e['out_time']);
            $savedStat = $e['att_status'] ?? 'Absent';
            // Determine initial display state
            $isAutoMode = $hasTime && in_array($savedStat, $autoStatuses, true);
        ?>
        <tr class="att-row"
            data-name="<?= strtolower(h($e['name'])) ?> <?= strtolower(h($e['emp_code'])) ?>">
            <td class="text-muted"><?= $i + 1 ?></td>
            <td>
                <div style="display:flex;align-items:center;gap:8px">
                    <div class="emp-avatar" style="flex-shrink:0"><?= strtoupper(substr($e['name'],0,1)) ?></div>
                    <div>
                        <div style="font-weight:500"><?= h($e['name']) ?></div>
                        <small style="color:var(--text-muted)"><?= h($e['emp_code']) ?></small>
                    </div>
                </div>
            </td>
            <td><small><?= h($e['dept_name']) ?></small></td>
            <td style="min-width:150px">
                <!-- Hidden input: always carries the active status value on submit -->
                <input type="hidden"
                       name="attendance[<?= $e['id'] ?>][status]"
                       class="status-value"
                       value="<?= h($savedStat) ?>">

                <!-- Manual select: shown when no times are set -->
                <select class="form-select form-select-sm status-manual-select <?= $isAutoMode ? 'd-none' : '' ?>"
                        style="font-size:12px">
                    <?php foreach (['On Time','Late','Absent','OD','Comp Off','Half Day','Holiday'] as $sv): ?>
                    <option value="<?= $sv ?>" <?= (!$isAutoMode && $savedStat === $sv) ? 'selected' : '' ?>>
                        <?= $sv ?>
                    </option>
                    <?php endforeach; ?>
                </select>

                <!-- Auto badge: shown when check-in or check-out is filled -->
                <div class="status-auto-display <?= $isAutoMode ? '' : 'd-none' ?>">
                    <?php
                    $bc  = $badgeColors[$savedStat] ?? 'bg-secondary';
                    $bs  = $badgeStyles[$savedStat] ?? '';
                    ?>
                    <span class="badge status-auto-badge <?= $bc ?>"
                          style="<?= $bs ?>;font-size:.78rem;padding:.35em .65em;letter-spacing:.3px">
                        <?= h($savedStat) ?>
                    </span>
                    <small class="d-block mt-1" style="font-size:.6rem;color:var(--text-muted);white-space:nowrap">
                        <i class="fa fa-lock me-1"></i>Auto-calculated
                    </small>
                </div>
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
                <!-- OT hours: manual number input + auto-display span -->
                <div style="display:flex;align-items:center;gap:4px">
                    <input type="number"
                           name="attendance[<?= $e['id'] ?>][ot_hours]"
                           class="form-control form-control-sm ot-input"
                           min="0" max="16" step="0.25" placeholder="0"
                           value="<?= $e['ot_hours'] !== null ? (float)$e['ot_hours'] : '' ?>"
                           style="width:70px">
                    <small class="ot-display text-success fw-semibold"
                           style="font-size:.75rem;display:none"></small>
                </div>
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
</div><!-- /card -->

<div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap">
    <button type="submit" name="bulk_save" value="1" class="btn btn-primary">
        <i class="fa fa-save"></i> Save Attendance
    </button>
    <button type="button" id="markAllAbsent" class="btn btn-outline-danger">Mark All Absent</button>
    <button type="button" id="markAllLeave"  class="btn btn-outline-secondary">Mark All Comp Off</button>
    <a href="index.php?month=<?= substr($selDate,0,7) ?>" class="btn btn-ghost">Cancel</a>
</div>
</form>

<?php if ($isNonWorking): ?>
<!-- ── Non-working day alert modal ───────────────────────────────────────── -->
<?php
    $isDayHoliday = (bool)$holidayName;
    $popupName  = $isDayHoliday ? $holidayName : $nonWorkingLabel;
    $popupType  = $isDayHoliday ? 'Public Holiday' : 'Weekly Off';
    $popupIcon  = $isDayHoliday ? 'fa-calendar-xmark' : 'fa-moon';
    $popupColor = $isDayHoliday ? '#f59e0b' : '#64748b';
?>
<div class="modal" id="holidayAlertModal">
    <div class="modal-content" style="max-width:460px;text-align:center;border-radius:14px;overflow:hidden">
        <div style="padding:32px 40px 28px">
            <div style="width:60px;height:60px;border-radius:50%;background:<?= $popupColor ?>1a;
                        display:flex;align-items:center;justify-content:center;margin:0 auto 14px">
                <i class="fa <?= $popupIcon ?> fa-xl" style="color:<?= $popupColor ?>"></i>
            </div>
            <span style="display:inline-block;background:<?= $popupColor ?>22;color:<?= $popupColor ?>;
                         font-size:.75rem;font-weight:600;border-radius:20px;padding:3px 12px;margin-bottom:10px">
                <?= $popupType ?>
            </span>
            <h4 style="font-size:1.1rem;font-weight:700;margin:6px 0"><?= h($popupName) ?></h4>
            <p style="color:var(--text-muted);font-size:.85rem;margin-bottom:24px">
                <?= date('l, d F Y', strtotime($selDate)) ?>
            </p>
            <a href="mark.php?date=<?= $prevDate ?>"
               class="btn btn-primary" style="min-width:130px;border-radius:8px">
                OK
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/modals.php'; ?>

<script>
$(function () {

    /* ── Settings from PHP ──────────────────────────────────────────────── */
    var OFFICE_START    = <?= $officeStartMins ?>;
    var GRACE_MINS      = <?= $dailyGraceMins ?>;
    var LATE_THRESHOLD  = <?= $lateThreshMins ?>;
    var HALF_DAY_CUTOFF = <?= $halfDayCutoff ?>;   // in minutes (e.g. 660 = 11:00)
    var OT_TRIGGER_MINS = <?= $otTriggerMins ?>;
    var OT_BASE_MINS    = <?= $otBaselineMins ?>;

    var BADGE_CLASS = {
        'On Time'  : 'badge bg-success',
        'Late'     : 'badge bg-info',
        'Half Day' : 'badge bg-warning text-dark',
        'Absent'   : 'badge bg-danger',
        'OD'       : 'badge',
        'Comp Off' : 'badge',
        'Holiday'  : 'badge bg-secondary',
    };
    var BADGE_STYLE = {
        'OD'       : 'background:#c8a96e;',
        'Comp Off' : 'background:#6f42c1;',
    };
    var MANUAL_STATUSES = ['Absent', 'OD', 'Comp Off', 'Holiday'];
    var AUTO_STATUSES   = ['On Time', 'Late', 'Half Day'];

    /* ── Auto-calculate status from check-in / check-out ────────────────── */
    function calcAutoStatus(checkIn, checkOut) {
        if (!checkIn) return 'Absent';

        var parts  = checkIn.split(':');
        var inMins = parseInt(parts[0], 10) * 60 + parseInt(parts[1] || '0', 10);

        // Check-in after half-day cutoff → always Half Day
        if (inMins >= HALF_DAY_CUTOFF) return 'Half Day';

        // Has checkout AND worked < 4h → Half Day
        if (checkOut) {
            var op     = checkOut.split(':');
            var outM   = parseInt(op[0], 10) * 60 + parseInt(op[1] || '0', 10);
            var worked = outM - inMins;
            if (worked > 0 && worked < 240) return 'Half Day';
        }

        return inMins > LATE_THRESHOLD ? 'Late' : 'On Time';
    }

    /* ── Format decimal OT hours as "Xh Ym" ─────────────────────────────── */
    function fmtOtHours(dec) {
        var total = Math.round(dec * 60);
        var h = Math.floor(total / 60), m = total % 60;
        return (h > 0 ? h + 'h ' : '') + m + 'm';
    }

    /* ── Update a single row ────────────────────────────────────────────── */
    function updateRow($tr) {
        var checkIn  = $tr.find('.checkin-input').val();
        var checkOut = $tr.find('.checkout-input').val();
        var $hidden  = $tr.find('.status-value');
        var $manual  = $tr.find('.status-manual-select');
        var $autoDsp = $tr.find('.status-auto-display');
        var $badge   = $tr.find('.status-auto-badge');

        if (checkIn || checkOut) {
            /* ── Auto mode ─────────────────────────────────────────────── */
            var status = calcAutoStatus(checkIn, checkOut);
            var bc = BADGE_CLASS[status] || 'badge bg-secondary';
            var bs = (BADGE_STYLE[status] || '') + 'font-size:.78rem;padding:.35em .65em;letter-spacing:.3px';
            $badge.attr('class', bc).attr('style', bs).text(status);
            $hidden.val(status);
            $manual.addClass('d-none');
            $autoDsp.removeClass('d-none');
        } else {
            /* ── Manual mode ───────────────────────────────────────────── */
            var cur = $hidden.val();
            if (!MANUAL_STATUSES.includes(cur) && !AUTO_STATUSES.includes(cur)) {
                cur = 'Absent'; $hidden.val(cur);
            }
            $manual.val(cur);
            $autoDsp.addClass('d-none');
            $manual.removeClass('d-none');
        }

        /* ── Auto OT ───────────────────────────────────────────────────── */
        var $otInput  = $tr.find('.ot-input');
        var $otDisp   = $tr.find('.ot-display');
        if (checkOut) {
            var op2    = checkOut.split(':');
            var outM2  = parseInt(op2[0],10)*60 + parseInt(op2[1]||'0',10);
            if (outM2 >= OT_TRIGGER_MINS) {
                var otH = Math.round((outM2 - OT_BASE_MINS) / 60 * 100) / 100;
                if (otH > 0) {
                    $otInput.val(otH.toFixed(2));
                    $otDisp.text(fmtOtHours(otH)).show();
                    return;
                }
            }
        }
        // No OT trigger met
        if ($otInput.val() === '' || $otInput.data('auto')) {
            $otInput.val('');
            $otDisp.hide();
        }
    }

    /* ── Sync manual select → hidden ────────────────────────────────────── */
    $(document).on('change', '.status-manual-select', function () {
        var $tr  = $(this).closest('tr');
        var stat = $(this).val();
        $tr.find('.status-value').val(stat);
        // Clear times if switching to no-time status
        if (['Absent','Holiday','Comp Off'].includes(stat)) {
            $tr.find('.checkin-input, .checkout-input').val('');
        }
    });

    /* ── On time change: recalculate ─────────────────────────────────────── */
    $(document).on('change', '.checkin-input, .checkout-input', function () {
        updateRow($(this).closest('tr'));
    });

    /* ── Mark All buttons ────────────────────────────────────────────────── */
    $('#markAllAbsent').on('click', function () {
        $('tbody tr').each(function () {
            var $tr  = $(this);
            var $sel = $tr.find('.status-manual-select');
            $tr.find('.checkin-input, .checkout-input').val('');
            if (!$sel.hasClass('d-none')) { $sel.val('Absent').trigger('change'); }
            else {
                $tr.find('.status-value').val('Absent');
                $tr.find('.status-auto-display').addClass('d-none');
                $sel.val('Absent').removeClass('d-none');
            }
        });
    });
    $('#markAllLeave').on('click', function () {
        $('tbody tr').each(function () {
            var $tr  = $(this);
            var $sel = $tr.find('.status-manual-select');
            $tr.find('.checkin-input, .checkout-input').val('');
            if (!$sel.hasClass('d-none')) { $sel.val('Comp Off').trigger('change'); }
            else {
                $tr.find('.status-value').val('Comp Off');
                $tr.find('.status-auto-display').addClass('d-none');
                $sel.val('Comp Off').removeClass('d-none');
            }
        });
    });

    /* ── Init all rows on load ───────────────────────────────────────────── */
    $('tbody tr').each(function () { updateRow($(this)); });

    /* ── Auto-open holiday modal ────────────────────────────────────────── */
    <?php if ($isNonWorking): ?>
    openModal('holidayAlertModal');
    <?php endif; ?>

    /* ── Pre-set daily import date to current selected date ─────────────── */
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

/* ── setAll helper ────────────────────────────────────────────────────────── */
function setAll(status) {
    var noTime = ['Absent','Holiday','Comp Off'];
    document.querySelectorAll('.att-row').forEach(function(tr) {
        var $tr  = $(tr);
        var $sel = $tr.find('.status-manual-select');
        // Clear times if needed
        if (noTime.includes(status)) {
            $tr.find('.checkin-input, .checkout-input').val('');
        }
        // Reset to manual mode
        $tr.find('.status-auto-display').addClass('d-none');
        $sel.removeClass('d-none').val(status).trigger('change');
    });
}

/* ── Filter rows ──────────────────────────────────────────────────────────── */
function filterRows(q) {
    q = q.toLowerCase();
    document.querySelectorAll('.att-row').forEach(function(tr) {
        tr.style.display = tr.dataset.name.includes(q) ? '' : 'none';
    });
}

window.BASE_URL = '<?= BASE_URL ?>';
window.PAGE_SHORTCUTS = {
    's': function() { document.querySelector('[name=bulk_save]').closest('form').submit(); },
    'b': function() { location.href = '<?= BASE_URL ?>/modules/attendance/index.php?month=<?= substr($selDate,0,7) ?>'; }
};
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
