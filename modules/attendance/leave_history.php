<?php
/**
 * Leave History — complete leave records with status tabs, filters and a summary.
 * Ported from the Employee_Management LeaveRequestController::history screen.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('leave_history', 'view');

$db = db();

// Employee self-service: an Employee only sees their own history.
$user       = current_user();
$isEmployee = (($user['role_name'] ?? '') === 'Employee');
$selfEmpId  = (int)($user['employee_id'] ?? 0);

// ── Filters ───────────────────────────────────────────────────────────────────
$fEmp    = (int)($_GET['employee_id'] ?? 0);
if ($isEmployee) $fEmp = $selfEmpId;   // force own records
$fType   = (int)($_GET['leave_type_id'] ?? 0);
$fStatus = in_array($_GET['status'] ?? 'all', ['all','approved','pending','rejected'], true) ? ($_GET['status'] ?? 'all') : 'all';
$fYear   = (int)($_GET['year'] ?? date('Y'));
$fMonth  = (int)($_GET['month'] ?? 0);

$employees  = $db->query("SELECT id, name, employee_id FROM employees WHERE status='Active' ORDER BY name")->fetchAll();
$leaveTypes = $db->query("SELECT id, name FROM leave_types ORDER BY name")->fetchAll();

// Base WHERE (everything except status) — shared by counts and list.
$baseWhere  = 'YEAR(lr.start_date) = ?';
$baseParams = [$fYear];
if ($fEmp)   { $baseWhere .= ' AND lr.employee_id = ?';   $baseParams[] = $fEmp; }
if ($fType)  { $baseWhere .= ' AND lr.leave_type_id = ?'; $baseParams[] = $fType; }
if ($fMonth) { $baseWhere .= ' AND MONTH(lr.start_date) = ?'; $baseParams[] = $fMonth; }

$base = "FROM leave_requests lr
         JOIN employees e ON e.id = lr.employee_id
         JOIN leave_types lt ON lt.id = lr.leave_type_id
         LEFT JOIN users u ON u.id = lr.approved_by
         WHERE $baseWhere";

// Tab counts (ignore status filter).
$cntStmt = $db->prepare("SELECT lr.status, COUNT(*) c, COALESCE(SUM(lr.days_requested),0) d $base GROUP BY lr.status");
$cntStmt->execute($baseParams);
$counts = ['all' => 0, 'approved' => 0, 'pending' => 0, 'rejected' => 0];
$approvedDays = 0;
foreach ($cntStmt->fetchAll() as $r) {
    $counts[$r['status']] = (int)$r['c'];
    $counts['all'] += (int)$r['c'];
    if ($r['status'] === 'approved') $approvedDays = (float)$r['d'];
}

// Top-5 employees by approved days.
$topStmt = $db->prepare("SELECT e.name, COUNT(*) cnt, SUM(lr.days_requested) days
                         $base AND lr.status='approved' GROUP BY lr.employee_id ORDER BY days DESC LIMIT 5");
$topStmt->execute($baseParams);
$topEmps = $topStmt->fetchAll();

// List (adds status filter).
$listWhere = $baseWhere; $listParams = $baseParams;
if ($fStatus !== 'all') { $listWhere .= ' AND lr.status = ?'; $listParams[] = $fStatus; }
$listStmt = $db->prepare(
    "SELECT lr.*, e.name AS emp_name, e.employee_id AS emp_code, lt.name AS type_name,
            lt.is_paid, lt.is_comp_off, u.name AS approver
     FROM leave_requests lr
     JOIN employees e ON e.id = lr.employee_id
     JOIN leave_types lt ON lt.id = lr.leave_type_id
     LEFT JOIN users u ON u.id = lr.approved_by
     WHERE $listWhere ORDER BY lr.start_date DESC"
);
$listStmt->execute($listParams);
$leaves = $listStmt->fetchAll();

$statusBadge = ['pending' => 'warning text-dark', 'approved' => 'success', 'rejected' => 'danger'];
$qs = function (array $over) use ($fEmp, $fType, $fStatus, $fYear, $fMonth) {
    return http_build_query(array_merge(
        ['employee_id' => $fEmp ?: '', 'leave_type_id' => $fType ?: '', 'status' => $fStatus, 'year' => $fYear, 'month' => $fMonth ?: ''],
        $over
    ));
};

$page_title = 'Leave History';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Leave History</h1>
        <p class="page-subtitle"><?= $counts['all'] ?> request(s) in <?= $fYear ?> · <?= rtrim(rtrim(number_format($approvedDays, 1), '0'), '.') ?> approved day(s)</p>
    </div>
    <div class="page-actions">
        <a href="<?= BASE_URL ?>/modules/attendance/leaves.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Leave Requests</a>
    </div>
</div>

<?php render_flash(); ?>

<!-- Filters -->
<div class="card page-card mb-3"><div class="card-body">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label small mb-1">Employee</label>
            <?php if ($isEmployee): $sn = ''; foreach ($employees as $e) if ((int)$e['id'] === $selfEmpId) $sn = $e['name'] . ' (' . $e['employee_id'] . ')'; ?>
                <input type="text" class="form-control form-control-sm" value="<?= h($sn) ?>" readonly>
            <?php else: ?>
            <select name="employee_id" class="form-select form-select-sm"><option value="">All</option>
                <?php foreach ($employees as $e): ?><option value="<?= $e['id'] ?>" <?= $fEmp === (int)$e['id'] ? 'selected' : '' ?>><?= h($e['name']) ?> (<?= h($e['employee_id']) ?>)</option><?php endforeach; ?>
            </select>
            <?php endif; ?></div>
        <div class="col-md-3"><label class="form-label small mb-1">Leave Type</label>
            <select name="leave_type_id" class="form-select form-select-sm"><option value="">All</option>
                <?php foreach ($leaveTypes as $lt): ?><option value="<?= $lt['id'] ?>" <?= $fType === (int)$lt['id'] ? 'selected' : '' ?>><?= h($lt['name']) ?></option><?php endforeach; ?>
            </select></div>
        <div class="col-md-2"><label class="form-label small mb-1">Year</label>
            <select name="year" class="form-select form-select-sm"><?php for ($y = (int)date('Y') + 1; $y >= (int)date('Y') - 3; $y--): ?><option value="<?= $y ?>" <?= $y === $fYear ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select></div>
        <div class="col-md-2"><label class="form-label small mb-1">Month</label>
            <select name="month" class="form-select form-select-sm"><option value="">All</option>
                <?php for ($m = 1; $m <= 12; $m++): ?><option value="<?= $m ?>" <?= $fMonth === $m ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option><?php endfor; ?></select></div>
        <input type="hidden" name="status" value="<?= h($fStatus) ?>">
        <div class="col-md-2 d-flex gap-2"><button class="btn btn-sm btn-primary"><i class="fa fa-filter me-1"></i>Filter</button>
            <a href="<?= BASE_URL ?>/modules/attendance/leave_history.php" class="btn btn-sm btn-outline-secondary">Reset</a></div>
    </form>
</div></div>

<!-- Summary strip -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="border rounded p-3 text-center h-100">
        <div class="fs-3 fw-bold text-dark"><?= $counts['all'] ?></div><div class="small text-muted">Total Requests</div></div></div>
    <div class="col-6 col-md-3"><div class="border rounded p-3 text-center h-100" style="border-color:#198754 !important;background:#f0fdf4">
        <div class="fs-3 fw-bold text-success"><?= $counts['approved'] ?></div>
        <div class="small text-muted">Approved &nbsp;<span class="badge bg-success"><?= rtrim(rtrim(number_format($approvedDays, 1), '0'), '.') ?>d</span></div></div></div>
    <div class="col-6 col-md-3"><div class="border rounded p-3 text-center h-100" style="border-color:#ffc107 !important;background:#fffbeb">
        <div class="fs-3 fw-bold text-warning"><?= $counts['pending'] ?></div><div class="small text-muted">Pending</div></div></div>
    <div class="col-6 col-md-3"><div class="border rounded p-3 text-center h-100" style="border-color:#dc3545 !important;background:#fff5f5">
        <div class="fs-3 fw-bold text-danger"><?= $counts['rejected'] ?></div><div class="small text-muted">Rejected</div></div></div>
</div>

<?php if ($topEmps && !$isEmployee): ?>
<div class="card page-card mb-3"><div class="card-body">
    <h6 class="fw-semibold mb-2"><i class="fa fa-trophy me-1 text-warning"></i>Top Employees by Approved Leave (<?= $fYear ?>)</h6>
    <div class="d-flex flex-wrap gap-2">
        <?php foreach ($topEmps as $t): ?><span class="badge bg-light text-dark border p-2"><?= h($t['name']) ?> — <strong><?= rtrim(rtrim(number_format((float)$t['days'], 1), '0'), '.') ?></strong> day(s), <?= (int)$t['cnt'] ?> req</span><?php endforeach; ?>
    </div>
</div></div>
<?php endif; ?>

<!-- Status tabs -->
<ul class="nav nav-tabs mb-3">
    <?php foreach (['all' => 'All', 'approved' => 'Approved', 'pending' => 'Pending', 'rejected' => 'Rejected'] as $k => $lbl): ?>
    <li class="nav-item"><a class="nav-link <?= $fStatus === $k ? 'active' : '' ?>" href="?<?= h($qs(['status' => $k])) ?>"><?= $lbl ?> <span class="badge bg-secondary"><?= $counts[$k] ?></span></a></li>
    <?php endforeach; ?>
</ul>

<div class="card page-card"><div class="card-body table-responsive">
    <table class="table table-hover table-bordered align-middle" id="tbl-history" style="font-size:.875rem">
        <thead class="table-dark"><tr>
            <th style="width:36px">#</th><th>Employee</th><th>Leave Type</th><th>From</th><th>To</th>
            <th class="text-center">Days</th><th>Reason</th><th class="text-center">Status</th>
            <th>Approved / Rejected By</th><th>Date</th><th class="text-center" style="width:60px">Action</th>
        </tr></thead>
        <tbody>
        <?php foreach ($leaves as $i => $r):
            $sO = new DateTime($r['start_date']); $eO = new DateTime($r['end_date']);
            $rowCls = $r['status'] === 'rejected' ? 'table-danger' : ($r['status'] === 'pending' ? 'table-warning' : '');
            $payLbl = $r['is_comp_off'] ? '<small class="text-primary">Comp Off</small>' : ($r['is_paid'] ? '<small class="text-success">Paid</small>' : '<small class="text-muted">Unpaid</small>');
        ?>
            <tr class="<?= $rowCls ?>">
                <td class="text-muted small"><?= $i + 1 ?></td>
                <td><div class="fw-semibold"><?= h($r['emp_name']) ?></div><small class="text-muted"><?= h($r['emp_code']) ?></small></td>
                <td><span class="badge bg-primary bg-opacity-75"><?= h($r['type_name']) ?></span><br><?= $payLbl ?></td>
                <td class="fw-semibold text-primary"><?= $sO->format('d M Y') ?></td>
                <td class="<?= $r['start_date'] !== $r['end_date'] ? 'fw-semibold text-primary' : 'text-muted' ?>"><?= $r['start_date'] === $r['end_date'] ? '—' : $eO->format('d M Y') ?></td>
                <td class="text-center"><span class="badge bg-secondary"><?= rtrim(rtrim(number_format((float)$r['days_requested'], 1), '0'), '.') ?>d</span></td>
                <td class="text-muted small" style="max-width:180px"><?= $r['reason'] ? h(mb_strimwidth($r['reason'], 0, 60, '…')) : '—' ?></td>
                <td class="text-center"><span class="badge bg-<?= $statusBadge[$r['status']] ?? 'secondary' ?>"><?= ucfirst($r['status']) ?></span></td>
                <td class="small">
                    <?php if ($r['approver']): ?><div><?= h($r['approver']) ?></div><small class="text-muted"><?= $r['approved_at'] ? date_fmt($r['approved_at'], 'd M Y, h:i A') : '' ?></small>
                    <?php elseif ($r['status'] === 'pending'): ?><span class="text-muted fst-italic">Awaiting</span><?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    <?php if ($r['remarks']): ?><br><small class="text-info" title="<?= h($r['remarks']) ?>"><i class="fa fa-comment-dots me-1"></i><?= h(mb_strimwidth($r['remarks'], 0, 40, '…')) ?></small><?php endif; ?>
                </td>
                <td class="small text-muted"><?= $r['created_at'] ? date_fmt($r['created_at'], 'd M Y') : '—' ?></td>
                <td class="text-center"><a href="<?= BASE_URL ?>/modules/attendance/leaves.php?view=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary" title="View details"><i class="fa fa-eye"></i></a></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$leaves): ?><tr><td colspan="11" class="text-center text-muted py-5"><i class="fa fa-calendar-xmark fa-2x mb-2 d-block text-secondary"></i>No leave records found for the selected filters.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>

<?php $page_scripts = <<<'JS'
<script>
$(function () { if ($.fn.DataTable) $('#tbl-history').DataTable({ pageLength: 25, order: [], language: { search: '', searchPlaceholder: 'Search...' } }); });
</script>
JS; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
