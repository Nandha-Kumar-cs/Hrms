<?php
$page_title = 'Mark Attendance';
require_once __DIR__ . '/../../includes/header.php';
require_permission('attendance', 'mark');

$db      = db();
$today   = date('Y-m-d');
$user    = current_user();

// Check holidays
$holiday = $db->prepare('SELECT name FROM holidays WHERE h_date = ?');
$holiday->execute([$today]);
$holidayName = $holiday->fetchColumn();

$employees = $db->query(
    'SELECT e.id, e.name, e.employee_id,
            a.id AS att_id, a.status, a.in_time, a.out_time, a.remarks
     FROM employees e
     LEFT JOIN attendance a ON a.employee_id = e.id AND a.att_date = CURDATE()
     WHERE e.status = "Active"
     ORDER BY e.name'
)->fetchAll();

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_save'])) {
    if (!csrf_verify()) { $msg = 'Invalid request.'; }
    else {
        $rows = $_POST['attendance'] ?? [];
        $marked = 0;
        foreach ($rows as $emp_id => $data) {
            $status   = $data['status'] ?? 'Absent';
            $in_time  = $data['in_time'] ?? null;
            $out_time = $data['out_time'] ?? null;
            $remarks  = sanitize($data['remarks'] ?? '');
            $stmt = $db->prepare(
                'INSERT INTO attendance (employee_id, att_date, status, in_time, out_time, remarks, marked_by)
                 VALUES (?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE status=VALUES(status), in_time=VALUES(in_time),
                 out_time=VALUES(out_time), remarks=VALUES(remarks), marked_by=VALUES(marked_by)'
            );
            $stmt->execute([(int)$emp_id, $today, $status, $in_time?:null, $out_time?:null, $remarks, $user['id']]);
            $marked++;
        }
        flash('success', "Attendance marked for $marked employees on " . date_fmt($today));
        redirect(BASE_URL . '/modules/attendance/index.php');
    }
}
?>

<div class="page-head">
    <div>
        <h1>Mark Attendance</h1>
        <p class="muted"><?= date('l, d F Y', strtotime($today)) ?>
            <?php if ($holidayName): ?> — <span class="pill pill-success"><?= h($holidayName) ?></span><?php endif; ?>
        </p>
    </div>
    <div class="head-actions">
        <a href="index.php" class="btn btn-ghost" accesskey="b" data-shortcut data-key="B"><u>B</u>ack</a>
    </div>
</div>

<?php if ($msg): ?>
<div class="alert alert-error"><?= h($msg) ?></div>
<?php endif; ?>

<!-- Quick set all -->
<div class="card form-card" style="margin-bottom:14px">
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
        <span style="font-weight:600;font-size:13px">Set all to:</span>
        <?php foreach (['On Time','Late','Absent','OD','Comp Off','Half Day'] as $s): ?>
        <button type="button" class="btn btn-sm" onclick="setAll('<?= $s ?>')"><?= $s ?></button>
        <?php endforeach; ?>
        <span style="color:var(--text-muted);font-size:12px;margin-left:8px">Default In: <?= WORK_START_TIME ?>, Out: <?= WORK_END_TIME ?></span>
    </div>
</div>

<form method="POST">
<?= csrf_field() ?>
<div class="card">
    <div class="card-head">
        <h3>Employees (<?= count($employees) ?>)</h3>
        <div class="search">
            <input type="search" placeholder="Filter employee..." id="empSearch" oninput="filterRows(this.value)" data-search>
        </div>
    </div>
    <div style="overflow-x:auto">
    <table class="data-table" id="attTable">
        <thead><tr>
            <th>Employee</th>
            <th style="width:140px">Status</th>
            <th style="width:110px">In Time</th>
            <th style="width:110px">Out Time</th>
            <th>Remarks</th>
        </tr></thead>
        <tbody>
        <?php foreach ($employees as $e):
            $defStatus = $holidayName ? 'Holiday' : ($e['status'] ?? 'On Time');
        ?>
        <tr class="att-row" data-name="<?= strtolower(h($e['name'])) ?>">
            <td>
                <div style="display:flex;align-items:center;gap:8px">
                    <div class="emp-avatar"><?= strtoupper(substr($e['name'],0,1)) ?></div>
                    <div>
                        <div style="font-weight:500"><?= h($e['name']) ?></div>
                        <div class="small muted"><?= h($e['employee_id']) ?></div>
                    </div>
                </div>
            </td>
            <td>
                <select name="attendance[<?= $e['id'] ?>][status]" class="status-select" data-emp="<?= $e['id'] ?>"
                        style="padding:5px 8px;border:1px solid var(--border-strong);border-radius:var(--radius);font-size:13px;width:100%"
                        onchange="toggleTimes(this)">
                    <?php foreach (['On Time','Late','Absent','OD','Comp Off','Half Day','Holiday'] as $s): ?>
                    <option <?= $defStatus===$s?'selected':'' ?>><?= $s ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td>
                <input type="time" name="attendance[<?= $e['id'] ?>][in_time]" class="in-time"
                       value="<?= $e['in_time'] ?? WORK_START_TIME ?>"
                       style="padding:5px 8px;border:1px solid var(--border-strong);border-radius:var(--radius);font-size:12px;width:100%">
            </td>
            <td>
                <input type="time" name="attendance[<?= $e['id'] ?>][out_time]" class="out-time"
                       value="<?= $e['out_time'] ?? WORK_END_TIME ?>"
                       style="padding:5px 8px;border:1px solid var(--border-strong);border-radius:var(--radius);font-size:12px;width:100%">
            </td>
            <td>
                <input type="text" name="attendance[<?= $e['id'] ?>][remarks]"
                       value="<?= h($e['remarks'] ?? '') ?>"
                       placeholder="Optional"
                       style="padding:5px 8px;border:1px solid var(--border-strong);border-radius:var(--radius);font-size:12px;width:100%">
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<div style="display:flex;gap:8px;margin-top:14px">
    <button type="submit" name="bulk_save" value="1" class="btn btn-primary" accesskey="s" data-shortcut data-key="S">
        ✓ <u>S</u>ave Attendance
    </button>
    <a href="index.php" class="btn btn-ghost">Cancel</a>
</div>
</form>

<script>
window.BASE_URL = '<?= BASE_URL ?>';

function setAll(status) {
    document.querySelectorAll('.status-select').forEach(sel => {
        sel.value = status;
        toggleTimes(sel);
    });
}

function toggleTimes(sel) {
    const row = sel.closest('tr');
    const s = sel.value;
    const hasTime = !['Absent','Holiday','Comp Off'].includes(s);
    row.querySelector('.in-time').disabled = !hasTime;
    row.querySelector('.out-time').disabled = !hasTime;
    if (!hasTime) {
        row.querySelector('.in-time').value = '';
        row.querySelector('.out-time').value = '';
    }
}

function filterRows(query) {
    const q = query.toLowerCase();
    document.querySelectorAll('.att-row').forEach(tr => {
        tr.style.display = tr.dataset.name.includes(q) ? '' : 'none';
    });
}

// Init time toggles
document.querySelectorAll('.status-select').forEach(toggleTimes);

window.PAGE_SHORTCUTS = {
    's': () => document.querySelector('form').submit(),
    'b': () => window.location.href = '<?= BASE_URL ?>/modules/attendance/index.php'
};
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
