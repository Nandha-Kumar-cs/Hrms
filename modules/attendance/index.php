<?php
$page_title = 'Attendance';
require_once __DIR__ . '/../../includes/header.php';
require_permission('attendance');

$db = db();
$month = $_GET['month'] ?? date('Y-m');
$emp_filter = (int)($_GET['emp_id'] ?? 0);

// Employees for filter
$employees = $db->query('SELECT id, name, employee_id FROM employees WHERE status="Active" ORDER BY name')->fetchAll();

// Build attendance data
$monthStart = $month . '-01';
$monthEnd   = date('Y-m-t', strtotime($monthStart));

$sql = 'SELECT a.*, e.name, e.employee_id AS emp_code FROM attendance a
        JOIN employees e ON e.id = a.employee_id
        WHERE a.att_date BETWEEN ? AND ?';
$params = [$monthStart, $monthEnd];
if ($emp_filter) { $sql .= ' AND a.employee_id = ?'; $params[] = $emp_filter; }
$sql .= ' ORDER BY a.att_date DESC, e.name';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

// Summary counts for the month
$summary = $db->prepare(
    'SELECT status, COUNT(*) as cnt FROM attendance
     WHERE att_date BETWEEN ? AND ?' .
    ($emp_filter ? ' AND employee_id = ?' : '') .
    ' GROUP BY status'
);
$summary->execute($emp_filter ? [$monthStart,$monthEnd,$emp_filter] : [$monthStart,$monthEnd]);
$summaryMap = [];
foreach ($summary->fetchAll() as $s) $summaryMap[$s['status']] = $s['cnt'];
?>

<div class="page-head">
    <div>
        <h1>Attendance</h1>
        <p class="muted"><?= date('F Y', strtotime($monthStart)) ?></p>
    </div>
    <div class="head-actions">
        <?php if (can('attendance','mark')): ?>
        <a href="mark.php" class="btn btn-primary" accesskey="m" data-shortcut data-key="M">
            <u>M</u>ark Today
        </a>
        <a href="bulk.php" class="btn" accesskey="u" data-shortcut data-key="U">
            B<u>u</u>lk Mark
        </a>
        <?php endif; ?>
        <?php if (can('attendance','export')): ?>
        <a href="export.php?month=<?= h($month) ?>&emp_id=<?= $emp_filter ?>" class="btn">
            ↓ Export
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Filters -->
<div class="card form-card" style="margin-bottom:14px">
    <form method="GET" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
        <div class="field" style="margin-bottom:0">
            <label>Month</label>
            <input type="month" name="month" value="<?= h($month) ?>">
        </div>
        <div class="field" style="margin-bottom:0">
            <label>Employee</label>
            <select name="emp_id">
                <option value="">All Employees</option>
                <?php foreach ($employees as $e): ?>
                <option value="<?= $e['id'] ?>" <?= $emp_filter==$e['id']?'selected':'' ?>>
                    <?= h($e['name']) ?> (<?= h($e['employee_id']) ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary" accesskey="f" data-shortcut data-key="F"><u>F</u>ilter</button>
        <a href="index.php" class="btn btn-ghost">Reset</a>
    </form>
</div>

<!-- Summary stat row -->
<div class="stat-grid" style="margin-bottom:18px;grid-template-columns:repeat(6,1fr)">
    <?php
    $stats = [
        ['On Time','pill-on-time','stat-success'],
        ['Late','pill-late','stat-warn'],
        ['Absent','pill-absent','stat-danger'],
        ['OD','pill-od','stat-info'],
        ['Comp Off','pill-comp-off',''],
        ['Half Day','pill-half','stat-warn'],
    ];
    foreach ($stats as [$label,$pill,$sc]):
    ?>
    <div class="stat-card <?= $sc ?>" style="padding:12px 16px">
        <div class="stat-label"><?= $label ?></div>
        <div class="stat-value"><?= $summaryMap[$label] ?? 0 ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Attendance Table -->
<div class="card">
    <div class="card-head">
        <h3>Attendance Records — <?= date('F Y', strtotime($monthStart)) ?></h3>
        <span class="muted small"><?= count($records) ?> entries</span>
    </div>
    <div style="overflow-x:auto">
    <table class="data-table" id="tbl-attendance" data-page-length="50">
        <thead><tr>
            <th>Date</th>
            <th>Employee</th>
            <th>Status</th>
            <th>In Time</th>
            <th>Out Time</th>
            <th>Remarks</th>
            <?php if (can('attendance','edit')): ?><th class="r">Action</th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($records as $r): ?>
        <tr>
            <td><?= date_fmt($r['att_date'], 'd M Y') ?> <span class="small muted"><?= date('D', strtotime($r['att_date'])) ?></span></td>
            <td>
                <a href="../employee/view.php?id=<?= $r['employee_id'] ?>" style="font-weight:500"><?= h($r['name']) ?></a>
                <div class="small muted"><?= h($r['emp_code']) ?></div>
            </td>
            <td>
                <?php
                $pillMap = ['On Time'=>'pill-on-time','Late'=>'pill-late','Absent'=>'pill-absent',
                            'OD'=>'pill-od','Comp Off'=>'pill-comp-off','Half Day'=>'pill-half','Holiday'=>'pill-success'];
                $pc = $pillMap[$r['status']] ?? 'pill-neutral';
                echo '<span class="pill '.$pc.'">'.h($r['status']).'</span>';
                ?>
            </td>
            <td><?= $r['in_time'] ? date('h:i A', strtotime($r['in_time'])) : '—' ?></td>
            <td><?= $r['out_time'] ? date('h:i A', strtotime($r['out_time'])) : '—' ?></td>
            <td class="small muted"><?= h($r['remarks'] ?? '') ?></td>
            <?php if (can('attendance','edit')): ?>
            <td class="r">
                <button class="btn btn-sm" onclick="openEditModal(<?= $r['id'] ?>, '<?= h($r['status']) ?>', '<?= $r['in_time']?:''.'' ?>', '<?= $r['out_time']?:''.'' ?>', '<?= h(addslashes($r['remarks']??'')) ?>')">Edit</button>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        <?php if (!$records): ?>
        <!-- <tr>
            <td colspan="6" class="center muted">
                No attendance records found for this month.
            </td>
        </tr> -->
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- Quick links -->
<div style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap">
    <a href="comp_off.php" class="btn">Comp Off Requests</a>
    <a href="od_requests.php" class="btn">OD Requests</a>
    <a href="calendar.php<?= $emp_filter ? '?emp_id='.$emp_filter : '' ?>" class="btn">Calendar View</a>
</div>

<!-- Edit Modal -->
<?php if (can('attendance','edit')): ?>
<div class="modal" id="editModal">
    <div class="modal-content">
        <h3>Edit Attendance</h3>
        <form method="POST" action="update.php">
            <?= csrf_field() ?>
            <input type="hidden" name="att_id" id="edit_att_id">
            <input type="hidden" name="redirect_month" value="<?= h($month) ?>">
            <input type="hidden" name="redirect_emp" value="<?= $emp_filter ?>">
            <label>Status</label>
            <select name="status" id="edit_status" style="width:100%;padding:8px;border:1px solid var(--border-strong);border-radius:var(--radius)">
                <?php foreach (['On Time','Late','Absent','OD','Comp Off','Half Day','Holiday'] as $s): ?>
                <option><?= $s ?></option>
                <?php endforeach; ?>
            </select>
            <label>In Time</label>
            <input type="time" name="in_time" id="edit_in" style="width:100%">
            <label>Out Time</label>
            <input type="time" name="out_time" id="edit_out" style="width:100%">
            <label>Remarks</label>
            <textarea name="remarks" id="edit_remarks" style="width:100%;margin-top:4px"></textarea>
            <div style="display:flex;gap:8px;margin-top:16px">
                <button type="submit" class="btn btn-primary">Save</button>
                <button type="button" class="btn btn-ghost" onclick="closeModal('editModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php
$_canEdit = can('attendance', 'edit');
$page_scripts = '<script>
$(document).ready(function () {
    $("#tbl-attendance").DataTable({
        pageLength: 50,
        order: [],
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
        columnDefs: [' . ($_canEdit ? '{ orderable: false, targets: [6] }' : '') . ']
    });
});
</script>';
?>
<script>
window.BASE_URL = '<?= BASE_URL ?>';
function openEditModal(id, status, inTime, outTime, remarks) {
    document.getElementById('edit_att_id').value = id;
    document.getElementById('edit_status').value = status;
    document.getElementById('edit_in').value = inTime;
    document.getElementById('edit_out').value = outTime;
    document.getElementById('edit_remarks').value = remarks;
    openModal('editModal');
}
window.PAGE_SHORTCUTS = {
    'm': () => window.location.href = '<?= BASE_URL ?>/modules/attendance/mark.php',
    'c': () => window.location.href = '<?= BASE_URL ?>/modules/attendance/comp_off.php',
    'o': () => window.location.href = '<?= BASE_URL ?>/modules/attendance/od_requests.php'
};
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>