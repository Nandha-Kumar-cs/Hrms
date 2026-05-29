<?php
require_once '../../includes/bootstrap.php';
require_login();
require_permission('attendance_view');

$user = current_user();
$isEmployee = ($user['role'] === 'Employee');

$month = (int)($_GET['month'] ?? date('m'));
$year  = (int)($_GET['year']  ?? date('Y'));
if ($month < 1) { $month = 12; $year--; }
if ($month > 12){ $month = 1;  $year++; }

$empId = $isEmployee ? $user['employee_id'] : (int)($_GET['employee_id'] ?? ($user['employee_id'] ?? 0));

$employees = $isEmployee ? [] : db()->query("SELECT id, CONCAT(first_name,' ',last_name,' (',employee_id,')') AS name FROM employees WHERE status='Active' ORDER BY first_name")->fetchAll(PDO::FETCH_ASSOC);

// Load attendance for month
$records = [];
if ($empId) {
    $rows = db()->query("SELECT date, status, check_in, check_out FROM attendance
        WHERE employee_id=$empId AND MONTH(date)=$month AND YEAR(date)=$year")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) $records[$row['date']] = $row;
}

// Load holidays
$holidays = db()->query("SELECT date, name FROM holidays WHERE MONTH(date)=$month AND YEAR(date)=$year")->fetchAll(PDO::FETCH_KEY_PAIR);

// Summary
$summary = ['Present'=>0,'Late'=>0,'Absent'=>0,'On Duty'=>0,'Comp Off'=>0,'Half Day'=>0,'Holiday'=>0,'Week Off'=>0];
foreach ($records as $r) {
    if (isset($summary[$r['status']])) $summary[$r['status']]++;
}

$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$firstDay    = date('N', mktime(0,0,0,$month,1,$year)); // 1=Mon, 7=Sun
$monthName   = date('F', mktime(0,0,0,$month,1,$year));

$page_title = 'Attendance Calendar';
include '../../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Attendance Calendar</h1>
        <p class="page-subtitle"><?= $monthName ?> <?= $year ?></p>
    </div>
    <div class="page-actions">
        <a href="?month=<?= $month-1 ?>&year=<?= $year ?>&employee_id=<?= $empId ?>" class="btn btn-secondary">&laquo; Prev</a>
        <a href="?month=<?= date('m') ?>&year=<?= date('Y') ?>&employee_id=<?= $empId ?>" class="btn btn-secondary">Today</a>
        <a href="?month=<?= $month+1 ?>&year=<?= $year ?>&employee_id=<?= $empId ?>" class="btn btn-secondary">Next &raquo;</a>
        <a href="index.php" class="btn btn-secondary" data-key="B"><u>B</u>ack</a>
    </div>
</div>

<?php render_flash(); ?>

<!-- Employee selector -->
<?php if (!$isEmployee): ?>
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="form-row align-items-end">
            <div class="form-group col-4">
                <label class="form-label">Select Employee</label>
                <select name="employee_id" class="form-control">
                    <option value="">Select Employee</option>
                    <?php foreach ($employees as $e): ?>
                        <option value="<?= $e['id'] ?>" <?= $empId==$e['id']?'selected':'' ?>><?= h($e['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <input type="hidden" name="month" value="<?= $month ?>">
            <input type="hidden" name="year" value="<?= $year ?>">
            <div class="form-group">
                <button type="submit" class="btn btn-primary">View</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Summary Pills -->
<div class="row mb-4">
    <?php
    $colors = ['Present'=>'success','Late'=>'warn','Absent'=>'danger','On Duty'=>'primary','Comp Off'=>'info','Half Day'=>'warn','Holiday'=>'secondary','Week Off'=>'secondary'];
    foreach ($summary as $label => $count): ?>
    <div class="col-auto mb-2">
        <div class="stat-card" style="min-width:100px;padding:0.75rem 1rem;">
            <div class="stat-value" style="font-size:1.5rem"><?= $count ?></div>
            <div class="stat-label"><?= $label ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Calendar -->
<div class="card">
    <div class="card-body">
        <div class="attendance-calendar">
            <!-- Day headers -->
            <div class="cal-grid">
                <?php foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $d): ?>
                    <div class="cal-header"><?= $d ?></div>
                <?php endforeach; ?>

                <!-- Empty cells before first day -->
                <?php for ($i = 1; $i < $firstDay; $i++): ?>
                    <div class="cal-day empty"></div>
                <?php endfor; ?>

                <!-- Days -->
                <?php for ($day = 1; $day <= $daysInMonth; $day++):
                    $dateStr  = sprintf('%04d-%02d-%02d', $year, $month, $day);
                    $dow      = date('N', mktime(0,0,0,$month,$day,$year)); // 6=Sat,7=Sun
                    $isHol    = isset($holidays[$dateStr]);
                    $isWeekend= in_array($dow, [6,7]);
                    $rec      = $records[$dateStr] ?? null;
                    $status   = $rec ? $rec['status'] : ($isHol ? 'Holiday' : ($isWeekend ? 'Week Off' : ''));
                    $isToday  = ($dateStr === date('Y-m-d'));

                    $statusClass = [
                        'Present' =>'cal-present','Late'=>'cal-late','Absent'=>'cal-absent',
                        'On Duty' =>'cal-od','Comp Off'=>'cal-compoff','Half Day'=>'cal-halfday',
                        'Holiday' =>'cal-holiday','Week Off'=>'cal-weekend',''=> 'cal-empty-day'
                    ][$status] ?? '';
                ?>
                <div class="cal-day <?= $statusClass ?> <?= $isToday?'cal-today':'' ?>"
                     title="<?= $dateStr ?>: <?= $status ?: 'No record' ?>
<?= $rec&&$rec['check_in']?'In: '.$rec['check_in']:'' ?>
<?= $rec&&$rec['check_out']?'Out: '.$rec['check_out']:'' ?>
<?= $isHol?$holidays[$dateStr]:'' ?>">
                    <span class="cal-day-num"><?= $day ?></span>
                    <?php if ($status): ?>
                        <span class="cal-day-status"><?= $status === 'Week Off' ? 'WO' : strtoupper(substr($status,0,2)) ?></span>
                    <?php endif; ?>
                    <?php if ($isHol): ?>
                        <span class="cal-hol-name"><?= h($holidays[$dateStr]) ?></span>
                    <?php endif; ?>
                    <?php if ($rec && $rec['check_in']): ?>
                        <span class="cal-time"><?= substr($rec['check_in'],0,5) ?></span>
                    <?php endif; ?>
                </div>
                <?php endfor; ?>
            </div>
        </div>

        <!-- Legend -->
        <div class="cal-legend mt-3">
            <span class="cal-leg cal-present">Present</span>
            <span class="cal-leg cal-late">Late</span>
            <span class="cal-leg cal-absent">Absent</span>
            <span class="cal-leg cal-od">On Duty</span>
            <span class="cal-leg cal-compoff">Comp Off</span>
            <span class="cal-leg cal-halfday">Half Day</span>
            <span class="cal-leg cal-holiday">Holiday</span>
            <span class="cal-leg cal-weekend">Week Off</span>
        </div>
    </div>
</div>

<style>
.cal-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:4px; }
.cal-header { text-align:center; font-weight:600; font-size:.75rem; color:var(--text-muted); padding:6px; background:var(--bg-secondary); border-radius:4px; }
.cal-day { border-radius:6px; padding:6px 8px; min-height:70px; position:relative; font-size:.75rem; cursor:default; transition:transform .15s; }
.cal-day:hover { transform:scale(1.03); z-index:2; }
.cal-day-num { font-weight:700; font-size:.9rem; }
.cal-day-status { display:block; font-size:.6rem; font-weight:700; letter-spacing:.05em; margin-top:2px; }
.cal-hol-name { display:block; font-size:.55rem; color:inherit; opacity:.8; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.cal-time { display:block; font-size:.6rem; color:inherit; opacity:.7; }
.cal-today { ring:2px solid white; outline:2px solid var(--primary); }
.cal-empty,.cal-empty-day { background:var(--bg-secondary); opacity:.3; }
.cal-present { background:#d1fae5; color:#065f46; }
.cal-late    { background:#fef3c7; color:#92400e; }
.cal-absent  { background:#fee2e2; color:#991b1b; }
.cal-od      { background:#dbeafe; color:#1e40af; }
.cal-compoff { background:#ede9fe; color:#5b21b6; }
.cal-halfday { background:#fef9c3; color:#854d0e; }
.cal-holiday { background:#e0f2fe; color:#0369a1; }
.cal-weekend { background:#f1f5f9; color:#64748b; }
.cal-legend  { display:flex; flex-wrap:wrap; gap:.5rem; }
.cal-leg     { padding:3px 10px; border-radius:4px; font-size:.7rem; font-weight:600; }
</style>

<script>
addLocalShortcut('b', () => location.href = 'index.php');
</script>
<?php include '../../includes/footer.php'; ?>
