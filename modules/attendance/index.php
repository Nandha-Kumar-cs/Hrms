<?php
/**
 * Attendance — Main Hub
 * Tabs: Attendance Records | Monthly Report
 * Actions: Mark Today · Daily Import · Monthly Import · Export
 */
$page_title = 'Attendance';
require_once __DIR__ . '/../../includes/header.php';
require_permission('attendance', 'view');

$db         = db();
$month      = preg_replace('/[^0-9\-]/', '', $_GET['month'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
$emp_filter = (int)($_GET['emp_id'] ?? 0);
$activeTab  = in_array($_GET['tab'] ?? '', ['records','report']) ? $_GET['tab'] : 'records';

// Employee self-service: force the filter to the logged-in employee's own id.
if (is_self_scoped()) $emp_filter = current_employee_id();
$scopeEmp = is_self_scoped() ? (' AND e.id = ' . current_employee_id()) : '';

// Month bounds
$monthStart = $month . '-01';
$monthEnd   = date('Y-m-t', strtotime($monthStart));

// Month/year split for Full Report link
$reqYear  = (int)date('Y', strtotime($monthStart));
$reqMonth = (int)date('n', strtotime($monthStart));

// Employees for filter dropdown
$employees = $db->query(
    "SELECT id, name, employee_id FROM employees e WHERE status='Active'{$scopeEmp} ORDER BY name"
)->fetchAll();

/* ──────────────────────────────────────────────────────────────────────────
   TAB 1: Records — attendance rows for the month
   ────────────────────────────────────────────────────────────────────────── */
$sql = "SELECT a.*, e.name AS emp_name, e.employee_id AS emp_code, d.name AS dept_name
        FROM attendance a
        JOIN  employees e  ON e.id = a.employee_id
        LEFT JOIN departments d ON d.id = e.department_id
        WHERE a.att_date BETWEEN ? AND ?";
$params = [$monthStart, $monthEnd];
if ($emp_filter) { $sql .= ' AND a.employee_id = ?'; $params[] = $emp_filter; }
$sql .= ' ORDER BY a.att_date DESC, e.name';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

// Summary counts
$sumStmt = $db->prepare(
    "SELECT status, COUNT(*) AS cnt FROM attendance
     WHERE att_date BETWEEN ? AND ?" .
    ($emp_filter ? ' AND employee_id = ?' : '') .
    " GROUP BY status"
);
$sumStmt->execute($emp_filter ? [$monthStart, $monthEnd, $emp_filter] : [$monthStart, $monthEnd]);
$summaryMap = [];
foreach ($sumStmt->fetchAll() as $s) $summaryMap[$s['status']] = (int)$s['cnt'];

/* ── Matrix report helpers (used by the matrix tab and by standalone report.php).
   Defined BEFORE the report computation below — they are wrapped in
   function_exists() guards, so PHP does not hoist them; declaring them here
   ensures they exist when the report tab runs. ── */
if (!function_exists('_rep_timeToMins')) {
    function _rep_timeToMins(string $t): int {
        $t = trim($t);
        if (str_contains($t, ' ')) $t = trim(substr(strrchr($t, ' '), 1));
        if (str_contains($t, 'T')) $t = trim(substr(strrchr($t, 'T'), 1));
        $parts = explode(':', $t);
        return (int)($parts[0] ?? 0) * 60 + (int)($parts[1] ?? 0);
    }
}
if (!function_exists('_rep_satCountUpTo')) {
    function _rep_satCountUpTo(DateTime $d): int {
        $n = 0; $t = (clone $d)->modify('first day of this month');
        while ($t <= $d) { if ((int)$t->format('N') === 6) $n++; $t->modify('+1 day'); }
        return $n;
    }
}
if (!function_exists('_rep_is13Sat')) {
    function _rep_is13Sat(DateTime $d): bool {
        if ((int)$d->format('N') !== 6) return false;
        return in_array(_rep_satCountUpTo($d), [1, 3]);
    }
}
if (!function_exists('_rep_fmtMins')) {
    function _rep_fmtMins(int $m): string {
        if ($m <= 0) return '0m';
        return ($m >= 60 ? intdiv($m, 60) . 'h ' : '') . ($m % 60) . 'm';
    }
}

/* ──────────────────────────────────────────────────────────────────────────
   MATRIX TAB: additional computation (only executed when tab=matrix)
   ────────────────────────────────────────────────────────────────────────── */
if ($activeTab === 'report') {
    $padMonth   = str_pad($reqMonth, 2, '0', STR_PAD_LEFT);
    $mStartStr  = $monthStart;
    $mEndStr    = $monthEnd;
    $days       = (int)date('t', strtotime($monthStart));
    $todayStr   = date('Y-m-d');
    $monthLabel = date('F Y', strtotime($monthStart));

    // Config
    $officeStart      = WORK_START_TIME;
    $dailyGraceMins   = ATTENDANCE_GRACE_MINUTES;
    $monthlyGraceMins = MONTHLY_GRACE_MINUTES;

    $officeStartMins = _rep_timeToMins($officeStart);
    $officeStartFmt  = date('h:i A', strtotime("1970-01-01 $officeStart"));
    $lateThreshFmt   = date('h:i A', strtotime("1970-01-01 $officeStart") + $dailyGraceMins * 60);
    $halfDayFmt      = date('h:i A', strtotime("1970-01-01 $officeStart") + 120 * 60);
    $mGraceH         = intdiv($monthlyGraceMins, 60);
    $mGraceM         = $monthlyGraceMins % 60;
    $mGraceFmt       = ($mGraceH > 0 ? $mGraceH . 'h ' : '') . $mGraceM . 'm';

    // Holidays
    $hStmt2 = $db->prepare("SELECT h_date, name, is_working_day FROM holidays WHERE h_date BETWEEN ? AND ?");
    $hStmt2->execute([$mStartStr, $mEndStr]);
    $holidayMap = [];
    foreach ($hStmt2->fetchAll() as $h) { $holidayMap[$h['h_date']] = $h; }

    // Non-working / working-holiday / comp-off day maps
    $nonWorkingDays = [];
    $workingHolDays = [];
    $compOffDays    = []; // always empty — no comp_off_days table in HRMS
    $nwDateSet      = [];

    for ($d = 1; $d <= $days; $d++) {
        $dateStr = "$reqYear-$padMonth-" . str_pad($d, 2, '0', STR_PAD_LEFT);
        $dayDate = new DateTime($dateStr);
        $dow     = (int)$dayDate->format('N');
        $hol     = $holidayMap[$dateStr] ?? null;
        if ($hol && ($hol['is_working_day'] ?? false)) { $workingHolDays[$d] = $hol['name']; continue; }
        if ($dow === 7) {
            $nonWorkingDays[$d] = 'Sunday'; $nwDateSet[$dateStr] = true;
        } elseif (_rep_is13Sat($dayDate)) {
            $satN = _rep_satCountUpTo($dayDate);
            $nonWorkingDays[$d] = ($satN === 1 ? '1st' : '3rd') . ' Saturday'; $nwDateSet[$dateStr] = true;
        } elseif ($hol) {
            $nonWorkingDays[$d] = $hol['name']; $nwDateSet[$dateStr] = true;
        }
    }

    $totalWorkingDays = 0;
    for ($d = 1; $d <= $days; $d++) {
        $dateStr = "$reqYear-$padMonth-" . str_pad($d, 2, '0', STR_PAD_LEFT);
        if (!isset($nwDateSet[$dateStr])) $totalWorkingDays++;
    }

    // Employees with dept (separate from filter-dropdown query)
    $mxEmployees = $db->query(
        "SELECT e.id, e.name, e.employee_id AS emp_code, COALESCE(d.name,'—') AS dept_name
         FROM employees e
         LEFT JOIN departments d ON d.id = e.department_id
         WHERE e.status='Active'{$scopeEmp} ORDER BY e.name"
    )->fetchAll();
    $empCount = count($mxEmployees);

    // Attendance records for the month
    $attStmt2 = $db->prepare(
        "SELECT employee_id, att_date, status, in_time, out_time, ot_hours, remarks
         FROM attendance WHERE att_date BETWEEN ? AND ?"
    );
    $attStmt2->execute([$mStartStr, $mEndStr]);
    $mxRecords    = [];
    $mxRecDateSet = [];
    foreach ($attStmt2->fetchAll() as $rec) {
        $dn = (int)date('j', strtotime($rec['att_date']));
        $mxRecords[$rec['employee_id']][$dn]           = $rec;
        $mxRecDateSet[$rec['employee_id']][$rec['att_date']] = true;
    }

    // Per-employee stats
    $mxEmpStats  = [];
    $mxLateByDay = [];

    foreach ($mxEmployees as $emp) {
        $empId    = (int)$emp['id'];
        $empRecs  = $mxRecords[$empId] ?? [];
        $empDates = $mxRecDateSet[$empId] ?? [];
        $presentCnt = $absentCnt = $halfCnt = $lateMins = 0;
        $otHrs = 0.0; $manMins = 0;
        $mxLateByDay[$empId] = [];

        foreach ($empRecs as $dayNum => $rec) {
            if (($rec['ot_hours'] ?? 0) > 0) $otHrs += (float)$rec['ot_hours'];
            $dateStr    = "$reqYear-$padMonth-" . str_pad($dayNum, 2, '0', STR_PAD_LEFT);
            $isNonWrk   = isset($nwDateSet[$dateStr]);
            $status     = $rec['status'];
            $noCheckout = in_array($status, ['On Time','Late'], true) && empty($rec['out_time']);
            if (!$isNonWrk && !empty($rec['in_time']) && !empty($rec['out_time'])) {
                $inM  = _rep_timeToMins($rec['in_time']);
                $outM = _rep_timeToMins($rec['out_time']);
                if ($outM <= $inM) $outM += 1440;
                $manMins += max(0, $outM - $inM);
            }
            if ($isNonWrk) continue;
            if ($noCheckout || $status === 'Absent') { $absentCnt++; continue; }
            if ($status === 'Half Day') { $halfCnt++; continue; }
            if (in_array($status, ['OD','Comp Off','Holiday'], true)) continue;
            if (in_array($status, ['On Time','Late'], true)) {
                if ($status === 'Late' && !empty($rec['in_time'])) {
                    $ciMins  = _rep_timeToMins($rec['in_time']);
                    $dayLate = max(0, $ciMins - $officeStartMins);
                    if ($dayLate > 0) { $mxLateByDay[$empId][$dayNum] = $dayLate; $lateMins += $dayLate; }
                }
                $presentCnt++;
            }
        }
        for ($d = 1; $d <= $days; $d++) {
            $dateStr = "$reqYear-$padMonth-" . str_pad($d, 2, '0', STR_PAD_LEFT);
            if ($dateStr > $todayStr || isset($nwDateSet[$dateStr]) || isset($empDates[$dateStr])) continue;
            $absentCnt++;
        }
        $exceeds = $lateMins > $monthlyGraceMins;
        $mxEmpStats[$empId] = [
            'present_count'  => $presentCnt,
            'absent_days'    => $absentCnt,
            'half_days'      => $halfCnt,
            'late_mins'      => $lateMins,
            'remaining_perm' => max(0, $monthlyGraceMins - $lateMins),
            'exceeded_mins'  => $exceeds ? ($lateMins - $monthlyGraceMins) : 0,
            'deduct_mins'    => $exceeds ? ($lateMins * 2) : 0,
            'ot_hours'       => round($otHrs, 2),
            'man_hours'      => round($manMins / 60, 2),
        ];
    }
} // end if activeTab === 'report'
?>

<?= render_flash() ?>

<?php /* ── Import result banners (rich detail, mirrors Laravel source) ──── */
$dailyResult   = $_SESSION['att_daily_result']   ?? null;
$monthlyResult = $_SESSION['att_monthly_result'] ?? null;
unset($_SESSION['att_daily_result'], $_SESSION['att_monthly_result']);
?>

<?php if ($monthlyResult): ?>
<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:var(--radius);
            padding:12px 16px;margin-bottom:14px">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
        <i class="fa fa-calendar-check" style="color:#16a34a;font-size:1.1em"></i>
        <strong>Monthly Attendance Import Complete</strong>
        <?php if (!empty($monthlyResult['month'])): ?>
        <span style="color:var(--text-muted);font-size:12px">— <?= h($monthlyResult['month']) ?></span>
        <?php endif; ?>
    </div>
    <div style="display:flex;gap:20px;flex-wrap:wrap;font-size:13px">
        <span><i class="fa fa-users" style="color:#3b82f6;margin-right:4px"></i><strong><?= (int)($monthlyResult['employees'] ?? 0) ?></strong> employees processed</span>
        <span><i class="fa fa-plus-circle" style="color:#16a34a;margin-right:4px"></i><strong><?= (int)($monthlyResult['saved'] ?? 0) ?></strong> new records</span>
        <span><i class="fa fa-pen" style="color:#3b82f6;margin-right:4px"></i><strong><?= (int)($monthlyResult['updated'] ?? 0) ?></strong> updated</span>
        <span><i class="fa fa-ban" style="color:#f59e0b;margin-right:4px"></i><strong><?= (int)($monthlyResult['skipped'] ?? 0) ?></strong> skipped</span>
    </div>
    <?php if (!empty($monthlyResult['warnings'])): ?>
    <details style="margin-top:8px">
        <summary style="cursor:pointer;font-size:12px;font-weight:600;color:#d97706">
            <?= count($monthlyResult['warnings']) ?> warning(s) — click to expand
        </summary>
        <ul style="margin:6px 0 0 16px;font-size:12px;color:var(--text-muted)">
            <?php foreach ($monthlyResult['warnings'] as $w): ?>
            <li><?= h($w) ?></li>
            <?php endforeach; ?>
        </ul>
    </details>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($dailyResult): ?>
<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:var(--radius);
            padding:12px 16px;margin-bottom:14px">
    <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
        <i class="fa fa-file-arrow-up" style="color:#16a34a;font-size:1.1em"></i>
        <strong>Daily Import Complete</strong>
        <?php if (!empty($dailyResult['date'])): ?>
        <span style="color:var(--text-muted);font-size:12px">
            for <?= date('d M Y', strtotime($dailyResult['date'])) ?>
        </span>
        <?php endif; ?>
    </div>
    <div style="display:flex;gap:20px;flex-wrap:wrap;font-size:13px">
        <span><i class="fa fa-plus-circle" style="color:#16a34a;margin-right:4px"></i><strong><?= (int)($dailyResult['saved'] ?? 0) ?></strong> new records</span>
        <span><i class="fa fa-pen" style="color:#3b82f6;margin-right:4px"></i><strong><?= (int)($dailyResult['updated'] ?? 0) ?></strong> updated</span>
        <span><i class="fa fa-ban" style="color:#ef4444;margin-right:4px"></i><strong><?= (int)($dailyResult['skipped'] ?? 0) ?></strong> skipped (not matched)</span>
    </div>
    <?php if (!empty($dailyResult['warnings'])): ?>
    <details style="margin-top:8px">
        <summary style="cursor:pointer;font-size:12px;font-weight:600;color:#d97706">
            <?= count($dailyResult['warnings']) ?> warning(s) — click to expand
        </summary>
        <ul style="margin:6px 0 0 16px;font-size:12px;color:var(--text-muted)">
            <?php foreach ($dailyResult['warnings'] as $w): ?>
            <li><?= h($w) ?></li>
            <?php endforeach; ?>
        </ul>
    </details>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="page-head">
    <div>
        <h1>Attendance</h1>
        <p class="muted"><?= date('F Y', strtotime($monthStart)) ?></p>
    </div>
    <div class="head-actions">
        <a href="report.php?month=<?= $reqMonth ?>&year=<?= $reqYear ?>"
           class="btn btn-sm btn-ghost"
           title="Open full matrix report as standalone page">
            <i class="fa fa-table-cells"></i> Matrix Report
        </a>

        <?php if (can('attendance','mark')): ?>
        <a href="mark.php?date=<?= date('Y-m-d') ?>" class="btn btn-primary" accesskey="m">
            <i class="fa fa-check-circle"></i> Mark Today
        </a>
        <?php endif; ?>

        <?php if (can('attendance','mark')): ?>
        <button type="button" class="btn" onclick="openModal('dailyImportModal')"
                title="Import attendance from CSV/XLSX for a single day">
            <i class="fa fa-file-arrow-up"></i> Daily Import
        </button>
        <button type="button" class="btn" onclick="openModal('monthlyImportModal')"
                title="Import full-month attendance from CSV/XLSX">
            <i class="fa fa-calendar-arrow-up"></i> Monthly Import
        </button>
        <?php endif; ?>

        <?php if (can('attendance','export')): ?>
        <a href="export.php?month=<?= h($month) ?>&emp_id=<?= $emp_filter ?>" class="btn">
            <i class="fa fa-file-export"></i> Export
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- ── Filter form ──────────────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:14px;overflow:visible">
    <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;padding:16px 18px">
        <input type="hidden" name="tab" value="<?= h($activeTab) ?>">
        <div class="field" style="margin:0;min-width:140px">
            <label>Month</label>
            <input type="month" name="month" value="<?= h($month) ?>">
        </div>
        <div class="field" style="margin:0;min-width:200px">
            <label>Employee</label>
            <select name="emp_id">
                <option value="">All Employees</option>
                <?php foreach ($employees as $e): ?>
                <option value="<?= $e['id'] ?>" <?= $emp_filter == $e['id'] ? 'selected' : '' ?>>
                    <?= h($e['name']) ?> (<?= h($e['employee_id']) ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fa fa-filter"></i> Filter</button>
        <a href="index.php" class="btn btn-ghost">Reset</a>
    </form>
</div>

<!-- ── Summary stat cards ─────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(110px,1fr));gap:10px;margin-bottom:18px">
    <?php
    $stats = [
        ['On Time',  '✓', 'stat-success', 'on_time_days'],
        ['Late',     '⏰', 'stat-warn',    'late_days'],
        ['Absent',   '✗', 'stat-danger',  'absent_days'],
        ['Half Day', '½', '',             'half_days'],
        ['OD',       '🚗', 'stat-info',   'od_days'],
        ['Comp Off', '🔄', '',            'comp_off_days'],
        ['Holiday',  '🎉', 'stat-success','holiday_days'],
    ];
    // Map summary stat to report totals (even on records tab show aggregated)
    $displayMap = [
        'On Time'  => $summaryMap['On Time']  ?? 0,
        'Late'     => $summaryMap['Late']      ?? 0,
        'Absent'   => $summaryMap['Absent']    ?? 0,
        'Half Day' => $summaryMap['Half Day']  ?? 0,
        'OD'       => $summaryMap['OD']        ?? 0,
        'Comp Off' => $summaryMap['Comp Off']  ?? 0,
        'Holiday'  => $summaryMap['Holiday']   ?? 0,
    ];
    foreach ($stats as [$label, $icon, $sc, $key]): ?>
    <div class="stat-card <?= $sc ?>" style="padding:12px 14px;text-align:center">
        <div style="font-size:18px;margin-bottom:4px"><?= $icon ?></div>
        <div class="stat-value"><?= $displayMap[$label] ?></div>
        <div class="stat-label" style="font-size:11px"><?= $label ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── Tab navigation ─────────────────────────────────────────────────── -->
<div class="tabs" style="margin-bottom:0">
    <a href="?month=<?= h($month) ?>&emp_id=<?= $emp_filter ?>&tab=records"
       class="tab <?= $activeTab === 'records' ? 'active' : '' ?>">
        <i class="fa fa-list"></i> Records
        <span class="pill pill-neutral" style="margin-left:4px;font-size:11px"><?= count($records) ?></span>
    </a>
    <a href="?month=<?= h($month) ?>&emp_id=<?= $emp_filter ?>&tab=report"
       class="tab <?= $activeTab === 'report' ? 'active' : '' ?>">
        <i class="fa fa-table-cells"></i> Monthly Report
    </a>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     TAB 1 — ATTENDANCE RECORDS
     ═══════════════════════════════════════════════════════════════════════ -->
<?php if ($activeTab === 'records'): ?>
<div class="card" style="overflow:visible;border-top-left-radius:0">
    <div class="card-head">
        <h3>Records — <?= date('F Y', strtotime($monthStart)) ?></h3>
        <span class="muted small"><?= count($records) ?> entries</span>
    </div>
    <div style="overflow-x:auto;min-height:300px">
    <table class="data-table" id="tbl-records">
        <thead><tr>
            <th>Date</th>
            <th>Employee</th>
            <th>Dept</th>
            <th>Status</th>
            <th>In</th>
            <th>Out</th>
            <th>Hours</th>
            <th>Remarks</th>
            <?php if (can('attendance','edit')): ?><th class="r">Action</th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($records as $r):
            // Hours worked
            $hrs = '';
            if ($r['in_time'] && $r['out_time']) {
                $tIn  = strtotime($r['att_date'] . ' ' . $r['in_time']);
                $tOut = strtotime($r['att_date'] . ' ' . $r['out_time']);
                if ($tOut <= $tIn) $tOut += 86400; // midnight crossing
                $diff = ($tOut - $tIn) / 3600;
                $hrs  = sprintf('%dh %dm', floor($diff), round(($diff - floor($diff)) * 60));
            }
            $pillMap = [
                'On Time'  => 'pill-on-time',
                'Late'     => 'pill-late',
                'Absent'   => 'pill-absent',
                'OD'       => 'pill-od',
                'Comp Off' => 'pill-comp-off',
                'Half Day' => 'pill-half',
                'Holiday'  => 'pill-success',
            ];
            $pc = $pillMap[$r['status']] ?? 'pill-neutral';
        ?>
        <tr>
            <td data-order="<?= $r['att_date'] ?>">
                <?= date('d M Y', strtotime($r['att_date'])) ?>
                <span class="small muted"><?= date('D', strtotime($r['att_date'])) ?></span>
            </td>
            <td>
                <a href="../employee/view.php?id=<?= $r['employee_id'] ?>" style="font-weight:500">
                    <?= h($r['emp_name']) ?>
                </a>
                <div class="small muted"><?= h($r['emp_code']) ?></div>
            </td>
            <td class="small"><?= h($r['dept_name'] ?? '—') ?></td>
            <td><span class="pill <?= $pc ?>"><?= h($r['status']) ?></span></td>
            <td><?= $r['in_time']  ? date('h:i A', strtotime($r['in_time']))  : '—' ?></td>
            <td><?= $r['out_time'] ? date('h:i A', strtotime($r['out_time'])) : '—' ?></td>
            <td class="small"><?= $hrs ?: '—' ?></td>
            <td class="small muted"><?= h($r['remarks'] ?? '') ?></td>
            <?php if (can('attendance','edit')): ?>
            <td class="r">
                <button class="btn btn-sm" onclick="openEditModal(
                    <?= $r['id'] ?>,
                    '<?= h($r['status']) ?>',
                    '<?= $r['in_time'] ? substr($r['in_time'],0,5) : '' ?>',
                    '<?= $r['out_time'] ? substr($r['out_time'],0,5) : '' ?>',
                    '<?= h(addslashes($r['remarks'] ?? '')) ?>'
                )">Edit</button>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        <?php if (!$records): ?>
        <tr><td colspan="9" class="empty">No attendance records found for <?= date('F Y', strtotime($monthStart)) ?>.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php endif; /* end tab: records */ ?>


<!-- ═══════════════════════════════════════════════════════════════════════
     TAB 2 — MONTHLY REPORT (full employee × day matrix grid)
     ═══════════════════════════════════════════════════════════════════════ -->
<?php if ($activeTab === 'report'): ?>
<div class="card page-card" style="border-top-left-radius:0">
    <div class="card-body">

        <!-- Sub-header -->
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

        <!-- Status legend bar -->
        <div class="d-flex gap-3 mb-2 flex-wrap align-items-center">
            <span class="badge bg-success">Present</span>
            <span class="badge bg-danger">Absent</span>
            <span class="badge bg-warning text-dark">Half Day</span>
            <span class="badge bg-info">Late</span>
            <span class="badge bg-secondary">On Leave</span>
            <span class="badge" style="background:#6f42c1">Comp Off</span>
            <span class="badge bg-danger">A</span> <small class="text-muted">Absent / No record</small>
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

        <!-- Non-working days banner -->
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

        <!-- Late permission info box -->
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

        <style>.att-table thead th { position: sticky; top: 0; z-index: 10; }</style>

        <!-- Matrix table -->
        <div class="table-responsive" style="max-height:calc(100vh - 220px);overflow-y:auto;">
            <table class="table table-bordered table-sm align-middle att-table" style="font-size:.8rem">
                <thead class="table-dark">
                <tr>
                    <th style="min-width:160px">Employee</th>
                    <?php for ($d = 1; $d <= $days; $d++):
                        $thDateStr = "$reqYear-$padMonth-" . str_pad($d, 2, '0', STR_PAD_LEFT);
                        $thDayDate = new DateTime($thDateStr);
                        $dayAbbr   = $thDayDate->format('D');
                        $isNonWrk  = isset($nonWorkingDays[$d]);
                        $isWorkHol = isset($workingHolDays[$d]);
                        $isCompOff = isset($compOffDays[$d]);
                        $coLabel   = $compOffDays[$d] ?? null;
                        $thStyle   = 'min-width:38px;';
                        $thClass   = 'text-center ';
                        if ($isNonWrk)      { $thClass .= 'table-secondary'; }
                        elseif ($isWorkHol) { $thClass .= 'table-warning'; }
                        elseif ($isCompOff) { $thStyle .= 'background:#ede9fe;'; }
                        $thTitle = $nonWorkingDays[$d]
                            ?? ($isWorkHol ? 'Working Holiday: ' . $workingHolDays[$d]
                            : ($isCompOff  ? 'Comp Off Day: '    . ($coLabel ?? '') : ''));
                    ?>
                    <th class="<?= $thClass ?>" style="<?= $thStyle ?>" <?= $thTitle ? 'title="'.h($thTitle).'"' : '' ?>>
                        <?= $d ?><br><small style="font-size:.6rem"><?= $dayAbbr ?></small>
                        <?php if ($isWorkHol): ?><br><i class="fa fa-briefcase" style="font-size:.55rem;color:#b45309"></i>
                        <?php elseif ($isCompOff && !$isNonWrk): ?><br><i class="fa fa-calendar-plus" style="font-size:.55rem;color:#6f42c1"></i>
                        <?php endif; ?>
                    </th>
                    <?php endfor; ?>
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
                <?php foreach ($mxEmployees as $empIdx => $emp):
                    $empId        = (int)$emp['id'];
                    $empRecs      = $mxRecords[$empId] ?? [];
                    $mxStat       = $mxEmpStats[$empId] ?? [];
                    $lateMap      = $mxLateByDay[$empId] ?? [];
                    $presentCount = 0; $absentCount = 0; $halfCount = 0; $leaveCount = 0;
                ?>
                <tr>
                    <td>
                        <strong><?= h($emp['name']) ?></strong><br>
                        <small class="text-muted"><?= h($emp['emp_code']) ?></small>
                    </td>
                    <?php for ($d = 1; $d <= $days; $d++):
                        $cellDateStr   = "$reqYear-$padMonth-" . str_pad($d, 2, '0', STR_PAD_LEFT);
                        $isNonWrk      = isset($nonWorkingDays[$d]);
                        $isCompOffDay  = isset($compOffDays[$d]);
                        $rec           = $empRecs[$d] ?? null;
                        $status        = $rec['status'] ?? null;
                        $isFuture      = ($cellDateStr > $todayStr);
                        $dailyLateMins = $lateMap[$d] ?? 0;
                        $noCheckout    = in_array($status, ['On Time','Late'], true) && empty($rec['out_time'] ?? '');
                        $isOnDuty      = (!$isNonWrk && !$isCompOffDay && $status === 'OD');
                    ?>
                    <?php if ($isNonWrk): ?>
                        <?php if ($empIdx === 0):
                            $cellNwLabel   = $nonWorkingDays[$d];
                            $cellIsWeekend = in_array($cellNwLabel, ['Sunday','1st Saturday','3rd Saturday']);
                            $nwLabelColor  = $cellIsWeekend ? '#6c757d' : '#c0392b';
                        ?>
                        <td class="text-center table-secondary" rowspan="<?= $empCount ?>"
                            style="vertical-align:middle;padding:4px 2px;">
                            <span style="writing-mode:vertical-rl;transform:rotate(180deg);font-size:.6rem;color:<?= $nwLabelColor ?>;font-weight:700;letter-spacing:4px;display:inline-block;line-height:1;word-spacing:2em;"
                                  title="<?= h($cellNwLabel) ?>"><?= h($cellNwLabel) ?>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<?= h($cellNwLabel) ?></span>
                        </td>
                        <?php endif; ?>
                    <?php else: ?>
                    <?php
                        if (!$isCompOffDay && !$isOnDuty) {
                            if ($noCheckout && !$isFuture)                                       { $absentCount++; }
                            elseif (in_array($status, ['On Time','Late'], true) && !$noCheckout) { $presentCount++; }
                            elseif (($status === 'Absent' || $status === null) && !$isFuture)    { $absentCount++; }
                            elseif ($status === 'Half Day')                                       { $halfCount++; }
                        }
                        if ($isOnDuty) $presentCount++;
                        $cellBg = $isCompOffDay ? 'background:#ede9fe;' : ($isOnDuty ? 'background:#fdf3e3;' : '');
                    ?>
                    <td class="text-center" style="<?= $cellBg ?>">
                        <?php if ($isCompOffDay): ?>
                            <span class="badge" style="background:#6f42c1;font-size:.68rem" title="Comp Off">CO</span>
                        <?php elseif ($isOnDuty): ?>
                            <span class="badge" style="background:#c8a96e;font-size:.68rem"
                                  title="On Duty<?= !empty($rec['remarks']) ? ': '.h($rec['remarks']) : '' ?>">OD</span>
                        <?php else: ?>
                            <?php
                                if ($noCheckout) {
                                    $titleAttr = 'No Checkout — checked in but no checkout recorded';
                                } elseif ($status === 'Late' && $dailyLateMins > 0) {
                                    $titleAttr = 'Late by ' . _rep_fmtMins($dailyLateMins);
                                } elseif ($status === 'Half Day') { $titleAttr = 'Half Day'; }
                                elseif ($status === 'On Time')    { $titleAttr = 'Present'; }
                                elseif ($status === 'Absent')     { $titleAttr = 'Absent'; }
                                elseif ($status === null && !$isFuture) { $titleAttr = 'Absent'; }
                                else { $titleAttr = ''; }
                                $titleHtml = $titleAttr ? ' title="'.h($titleAttr).'"' : '';

                                if ($noCheckout) {
                                    echo '<span class="badge" style="background:#e67e22;font-size:.7rem;"'.$titleHtml.'>A</span>';
                                } elseif ($status === null && $isFuture) {
                                    echo '<span class="badge bg-light text-dark" style="font-size:.7rem;border:1px solid #dee2e6">&#8212;</span>';
                                } elseif ($status === null && !$isFuture) {
                                    echo '<span class="badge bg-danger"'.$titleHtml.' style="font-size:.7rem">A</span>';
                                } else {
                                    switch ($status) {
                                        case 'On Time':  $clr='success';   $abr='P';  break;
                                        case 'Late':     $clr='info';      $abr='L';  break;
                                        case 'Absent':   $clr='danger';    $abr='A';  break;
                                        case 'Half Day': $clr='warning';   $abr='H';  break;
                                        case 'Holiday':  $clr='secondary'; $abr='H';  break;
                                        case 'Comp Off': $clr='compoff';   $abr='CO'; break;
                                        default:         $clr='danger';    $abr='A';  break;
                                    }
                                    if ($status === 'Comp Off') {
                                        echo '<span class="badge" style="background:#6f42c1;font-size:.7rem"'.$titleHtml.'>CO</span>';
                                    } else {
                                        $txtClr = in_array($clr, ['warning','light']) ? 'dark' : 'white';
                                        $xStyle = ($clr === 'light') ? ';border:1px solid #dee2e6' : '';
                                        echo '<span class="badge bg-'.$clr.' text-'.$txtClr.'" style="font-size:.7rem'.$xStyle.'"'.$titleHtml.'>'.$abr.'</span>';
                                    }
                                }
                            ?>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <?php endfor; ?>

                    <?php
                        $mxLateM  = (int)($mxStat['late_mins']      ?? 0);
                        $mxRemain = (int)($mxStat['remaining_perm'] ?? $monthlyGraceMins);
                        $mxExceed = (int)($mxStat['exceeded_mins']  ?? 0);
                        $mxDeduct = (int)($mxStat['deduct_mins']    ?? 0);
                        $mxOt     = (float)($mxStat['ot_hours']     ?? 0);
                        $mxMh     = (float)($mxStat['man_hours']    ?? 0);
                        $mxAbs    = (int)($mxStat['absent_days']    ?? $absentCount);
                        $mxHalf   = (int)($mxStat['half_days']      ?? $halfCount);
                    ?>
                    <td class="text-center fw-bold text-success"><?= $presentCount ?></td>
                    <td class="text-center fw-bold text-danger"><?= $mxAbs ?></td>
                    <td class="text-center fw-bold text-warning"><?= $mxHalf ?></td>
                    <td class="text-center fw-bold text-secondary"
                        title="Approved paid leaves + comp-offs (from payroll)"><?= $leaveCount ?></td>
                    <td class="text-center <?= $mxLateM > 0 ? 'text-warning fw-semibold' : 'text-muted' ?>"
                        style="font-size:.78rem" title="Total late time this month">
                        <?= $mxLateM > 0 ? _rep_fmtMins($mxLateM) : '&mdash;' ?>
                    </td>
                    <td class="text-center <?= $mxRemain < 90 ? ($mxRemain === 0 ? 'text-danger fw-semibold' : 'text-info fw-semibold') : 'text-muted' ?>"
                        style="font-size:.78rem" title="Remaining late grace (90 min/month)">
                        <?= _rep_fmtMins($mxRemain) ?>
                    </td>
                    <td class="text-center <?= $mxExceed > 0 ? 'text-danger fw-semibold' : 'text-muted' ?>"
                        style="font-size:.78rem" title="Late time beyond the 90-min grace">
                        <?= $mxExceed > 0 ? _rep_fmtMins($mxExceed) : '&mdash;' ?>
                    </td>
                    <td class="text-center <?= $mxDeduct > 0 ? 'text-danger fw-bold' : 'text-muted' ?>"
                        style="font-size:.78rem" title="Deducted at 2&times; of total late time">
                        <?php if ($mxDeduct > 0): ?><?= _rep_fmtMins($mxDeduct) ?><br><small style="font-size:.62rem">(2&times; penalty)</small>
                        <?php else: ?>&mdash;<?php endif; ?>
                    </td>
                    <td class="text-center <?= $mxOt > 0 ? 'text-success fw-semibold' : 'text-muted' ?>"
                        style="font-size:.78rem">
                        <?= $mxOt > 0 ? $mxOt . 'h' : '&mdash;' ?>
                    </td>
                    <?php
                        if ($mxMh > 0) {
                            $mxWhH = (int)floor($mxMh);
                            $mxWhM = (int)round(($mxMh - $mxWhH) * 60);
                            $mxWhDisp = $mxWhH . 'h' . ($mxWhM > 0 ? ' ' . $mxWhM . 'm' : '');
                        } else { $mxWhDisp = '&mdash;'; }
                    ?>
                    <td class="text-center <?= $mxMh > 0 ? 'text-primary fw-semibold' : 'text-muted' ?>"
                        style="font-size:.78rem"
                        title="Total working hours logged this month (<?= $mxMh ?>h raw)">
                        <?= $mxWhDisp ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div><!-- /table-responsive -->

        <!-- Footer legend -->
        <div class="small text-muted mt-2">
            <strong>Legend:</strong>
            P = Present &nbsp;|&nbsp; A = Absent &nbsp;|&nbsp;
            <span class="badge" style="background:#e67e22;font-size:.7rem">A</span> = No Checkout (marked absent, orange) &nbsp;|&nbsp;
            H = Half Day &nbsp;|&nbsp; L = Late &nbsp;|&nbsp; OL = On Leave
            &nbsp;|&nbsp; <span class="badge" style="background:#6f42c1;font-size:.7rem">CO</span> = Comp Off
            &nbsp;|&nbsp; <span class="badge" style="background:#0891b2;font-size:.7rem">OD</span> = On Duty (present, no deduction)
            &nbsp;|&nbsp; <span class="badge bg-secondary" style="font-size:.7rem">H</span> = Weekend (Sunday / Saturday)
            &nbsp;|&nbsp; <span style="writing-mode:vertical-rl;transform:rotate(180deg);font-size:.6rem;color:#c0392b;font-weight:700;letter-spacing:2px;display:inline-block;vertical-align:middle;">Diwali</span> = Public Holiday (name shown rotated)
            &nbsp;|&nbsp; <strong>Late Used</strong> = Total monthly late time
            &nbsp;|&nbsp; <strong>Remaining</strong> = Grace left (resets each month)
            &nbsp;|&nbsp; <strong>Exceeded</strong> = Minutes beyond 90-min grace
            &nbsp;|&nbsp; <strong>Deducted</strong> = Penalty charged (2&times; total late when grace exceeded)
            &nbsp;|&nbsp; <strong>Man Hrs</strong> = Total working hours logged (check-in &rarr; check-out) for the month
        </div>

    </div><!-- /card-body -->
</div><!-- /card page-card -->
<?php endif; /* end tab: report */ ?>

<!-- ── Quick links ────────────────────────────────────────────────────── -->
<div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap">
    <a href="comp_off.php"  class="btn btn-sm"><i class="fa fa-arrows-rotate"></i> Comp Off Requests</a>
    <a href="od_requests.php" class="btn btn-sm"><i class="fa fa-car"></i> OD Requests</a>
</div>

<!-- ── Edit Modal ─────────────────────────────────────────────────────── -->
<?php if (can('attendance','edit')): ?>
<div class="modal" id="editModal">
    <div class="modal-content">
        <h3>Edit Attendance Record</h3>
        <form method="POST" action="update.php">
            <?= csrf_field() ?>
            <input type="hidden" name="att_id" id="edit_att_id">
            <input type="hidden" name="redirect_month" value="<?= h($month) ?>">
            <input type="hidden" name="redirect_emp"   value="<?= $emp_filter ?>">
            <input type="hidden" name="redirect_tab"   value="<?= h($activeTab) ?>">
            <label>Status</label>
            <select name="status" id="edit_status"
                    style="width:100%;padding:8px;border:1px solid var(--border-strong);border-radius:var(--radius)">
                <?php foreach (['On Time','Late','Absent','OD','Comp Off','Half Day','Holiday'] as $s): ?>
                <option><?= $s ?></option>
                <?php endforeach; ?>
            </select>
            <label>In Time</label>
            <input type="time" name="in_time"  id="edit_in"  style="width:100%">
            <label>Out Time</label>
            <input type="time" name="out_time" id="edit_out" style="width:100%">
            <label>Remarks</label>
            <textarea name="remarks" id="edit_remarks" rows="2" style="width:100%"></textarea>
            <div style="display:flex;gap:8px;margin-top:16px">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <button type="button" class="btn btn-ghost" onclick="closeModal('editModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ── Import Modals (inline include) ─────────────────────────────────── -->
<?php include __DIR__ . '/modals.php'; ?>

<?php
$_canEdit = can('attendance', 'edit');
$_hasRecords = !empty($records) ? 'true' : 'false';
$page_scripts = '<script>
$(document).ready(function () {
    // Records table — skip DataTables when the only row is the empty-state
    // colspan cell (its column count mismatches the header → tn/18 warning).
    if (' . $_hasRecords . ' && $("#tbl-records").length) {
        $("#tbl-records").DataTable({
            pageLength: 50,
            order: [[0,"desc"]],
            language: {
                search: "", searchPlaceholder: "Search...",
                lengthMenu: "Show _MENU_ rows",
                paginate: { previous: "‹", next: "›" }
            },
            dom: \'<"dt-top"lBf>rt<"dt-bottom"ip>\',
            buttons: [
                { extend: "excelHtml5", text: "↓ Excel", className: "btn btn-sm" },
                { extend: "print",      text: "⎙ Print", className: "btn btn-sm" }
            ],
            columnDefs: [' . ($_canEdit ? '{ orderable: false, targets: [-1] }' : '') . ']
        });
    }
});
</script>';
?>

<script>
window.BASE_URL = '<?= BASE_URL ?>';

/* ── Edit modal helper ────────────────────────────────────────────────── */
function openEditModal(id, status, inTime, outTime, remarks) {
    document.getElementById('edit_att_id').value   = id;
    document.getElementById('edit_status').value   = status;
    document.getElementById('edit_in').value        = inTime;
    document.getElementById('edit_out').value       = outTime;
    document.getElementById('edit_remarks').value   = remarks;
    openModal('editModal');
}

/* ── Daily Import — date default to today ─────────────────────────────── */
document.addEventListener('DOMContentLoaded', function () {
    var dateField = document.getElementById('daily_att_date');
    if (dateField && !dateField.value) {
        dateField.value = new Date().toISOString().split('T')[0];
    }
    var monthField = document.getElementById('monthly_att_month');
    if (monthField && !monthField.value) {
        monthField.value = '<?= $month ?>';
    }
});

/* ── Client-side file type validation ─────────────────────────────────── */
function validateImportFile(input) {
    var allowed = ['csv','xlsx'];
    var ext = input.value.split('.').pop().toLowerCase();
    if (!allowed.includes(ext)) {
        alert('Please select a .csv or .xlsx file only.');
        input.value = '';
        return false;
    }
    return true;
}

window.PAGE_SHORTCUTS = {
    'm': () => window.location.href = '<?= BASE_URL ?>/modules/attendance/mark.php',
    'c': () => window.location.href = '<?= BASE_URL ?>/modules/attendance/comp_off.php',
    'o': () => window.location.href = '<?= BASE_URL ?>/modules/attendance/od_requests.php'
};
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
