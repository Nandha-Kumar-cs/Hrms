<?php
/**
 * On Duty (OD) Requests — create, list, edit/delete, approve/reject.
 *
 * Workflow: an employee (self-service) or HR submits a request for a date
 * range → status Pending. A manager/HR with od.edit Approves or Rejects it.
 * On approval each day in the range is marked 'OD' in attendance (counted as
 * present by payroll). Deleting an approved request reverts those OD marks.
 *
 * All writes run before any output (PRG). Mirrors the leave-request pattern.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('od', 'view');

$db        = db();
$user      = current_user();
$isEmployee = (($user['role_name'] ?? '') === 'Employee');
$selfEmpId = (int)($user['employee_id'] ?? 0);

$canCreate  = can('od', 'create');
$canApprove = can('od', 'edit');
$canDelete  = can('od', 'delete');

$self = BASE_URL . '/modules/attendance/od_requests.php';

/** Mark every day in [from,to] as 'OD' in attendance (upsert, keeps times). */
function od_apply_attendance(int $emp, string $from, string $to, int $by): void {
    $up = db()->prepare(
        'INSERT INTO attendance (employee_id, att_date, status, remarks, marked_by)
         VALUES (?,?,"OD","OD Approved",?)
         ON DUPLICATE KEY UPDATE status="OD", remarks="OD Approved", marked_by=VALUES(marked_by)'
    );
    $d = new DateTime($from); $e = new DateTime($to);
    for (; $d <= $e; $d->modify('+1 day')) {
        $up->execute([$emp, $d->format('Y-m-d'), $by]);
    }
}

/** Remove OD attendance marks created for an approved request's range. */
function od_clear_attendance(int $emp, string $from, string $to): void {
    db()->prepare('DELETE FROM attendance WHERE employee_id=? AND att_date BETWEEN ? AND ? AND status="OD"')
        ->execute([$emp, $from, $to]);
}

/** Fetch a single request row. */
function od_fetch(int $id): ?array {
    $s = db()->prepare('SELECT * FROM od_requests WHERE id=?');
    $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── POST: approve / reject ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf($_POST['csrf_token'] ?? '');
    if (!$canApprove) { http_response_code(403); exit('Forbidden'); }
    $rid    = (int)($_POST['request_id'] ?? 0);
    $action = $_POST['action'];
    $od     = od_fetch($rid);

    if ($od && in_array($action, ['Approved', 'Rejected'], true)) {
        if ($od['status'] !== 'Pending') {
            flash('error', 'Only pending requests can be reviewed.');
            redirect($self);
        }
        $db->prepare('UPDATE od_requests SET status=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?')
           ->execute([$action, (int)$user['id'], $rid]);
        if ($action === 'Approved') {
            od_apply_attendance((int)$od['employee_id'], $od['from_date'], $od['to_date'], (int)$user['id']);
        }
        activity_log(strtolower($action), 'OD', $action . ' OD request for ' . activity_emp_label((int)$od['employee_id'])
            . ' (' . date_fmt($od['from_date']) . ' – ' . date_fmt($od['to_date']) . ')');
        flash('success', "OD request {$action}.");
    }
    redirect($self);
}

// ── POST: create ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_request'])) {
    verify_csrf($_POST['csrf_token'] ?? '');
    if (!$canCreate) { http_response_code(403); exit('Forbidden'); }

    $empId = $isEmployee ? $selfEmpId : (int)($_POST['employee_id'] ?? 0);
    $from  = sanitize($_POST['from_date'] ?? '');
    $to    = sanitize($_POST['to_date'] ?? '');
    $place = trim($_POST['place'] ?? '');
    $reason = trim($_POST['reason'] ?? '');

    $errors = [];
    if (!$empId)                 $errors[] = 'Please select an employee.';
    if (!$from || !$to)          $errors[] = 'From and To dates are required.';
    if ($from && $to && $to < $from) $errors[] = 'To date cannot be before From date.';
    if ($reason === '')          $errors[] = 'A reason / purpose is required.';

    if ($errors) {
        flash('error', implode(' ', $errors));
        redirect($self);
    }
    $db->prepare('INSERT INTO od_requests (employee_id, from_date, to_date, place, reason, status, requested_at)
                  VALUES (?,?,?,?,?,"Pending",NOW())')
       ->execute([$empId, $from, $to, $place ?: null, $reason]);
    activity_log('created', 'OD', 'OD request submitted for ' . activity_emp_label($empId)
        . ' (' . date_fmt($from) . ' – ' . date_fmt($to) . ')');
    flash('success', 'OD request submitted.');
    redirect($self);
}

// ── POST: edit (pending only) ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_request'])) {
    verify_csrf($_POST['csrf_token'] ?? '');
    $rid = (int)($_POST['request_id'] ?? 0);
    $od  = od_fetch($rid);
    if (!$od) { flash('error', 'Request not found.'); redirect($self); }

    // Owner may edit their own pending request; approvers may edit any pending one.
    $isOwner = $isEmployee && (int)$od['employee_id'] === $selfEmpId;
    if (!($canApprove || ($canCreate && $isOwner))) { http_response_code(403); exit('Forbidden'); }
    if ($od['status'] !== 'Pending') { flash('error', 'Only pending requests can be edited.'); redirect($self); }

    $from  = sanitize($_POST['from_date'] ?? '');
    $to    = sanitize($_POST['to_date'] ?? '');
    $place = trim($_POST['place'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    $errors = [];
    if (!$from || !$to)          $errors[] = 'From and To dates are required.';
    if ($from && $to && $to < $from) $errors[] = 'To date cannot be before From date.';
    if ($reason === '')          $errors[] = 'A reason / purpose is required.';
    if ($errors) { flash('error', implode(' ', $errors)); redirect($self); }

    $db->prepare('UPDATE od_requests SET from_date=?, to_date=?, place=?, reason=? WHERE id=?')
       ->execute([$from, $to, $place ?: null, $reason, $rid]);
    flash('success', 'OD request updated.');
    redirect($self);
}

// ── POST: delete ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_request'])) {
    verify_csrf($_POST['csrf_token'] ?? '');
    $rid = (int)($_POST['request_id'] ?? 0);
    $od  = od_fetch($rid);
    if (!$od) { flash('error', 'Request not found.'); redirect($self); }

    $isOwner = $isEmployee && (int)$od['employee_id'] === $selfEmpId;
    // Delete allowed for: od.delete holders, or owners deleting their own pending request.
    if (!($canDelete || ($isOwner && $od['status'] === 'Pending'))) { http_response_code(403); exit('Forbidden'); }

    // If it was approved, revert the OD attendance marks it created.
    if ($od['status'] === 'Approved') {
        od_clear_attendance((int)$od['employee_id'], $od['from_date'], $od['to_date']);
    }
    $db->prepare('DELETE FROM od_requests WHERE id=?')->execute([$rid]);
    flash('success', 'OD request deleted.');
    redirect($self);
}

// ── Listing ──────────────────────────────────────────────────────────────────
$statusFilter = sanitize($_GET['status'] ?? '');
$validStatus  = in_array($statusFilter, ['Pending', 'Approved', 'Rejected'], true);

$conds = [];
$params = [];
if ($isEmployee) { $conds[] = 'od.employee_id = ?'; $params[] = $selfEmpId; }
if ($validStatus) { $conds[] = 'od.status = ?'; $params[] = $statusFilter; }
$whereSql = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

$stmt = $db->prepare(
    "SELECT od.*, e.name AS emp_name, e.employee_id AS emp_code, u.name AS reviewer_name
     FROM od_requests od
     JOIN employees e ON od.employee_id = e.id
     LEFT JOIN users u ON od.reviewed_by = u.id
     $whereSql
     ORDER BY od.requested_at DESC, od.id DESC"
);
$stmt->execute($params);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Status counts (respect employee scoping).
$cntSql = 'SELECT status, COUNT(*) c FROM od_requests' . ($isEmployee ? ' WHERE employee_id = ?' : '') . ' GROUP BY status';
$cntStmt = $db->prepare($cntSql);
$cntStmt->execute($isEmployee ? [$selfEmpId] : []);
$counts = $cntStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$totalCount = array_sum($counts);

$employees = $isEmployee ? [] : $db->query(
    "SELECT id, CONCAT(name,' (',employee_id,')') AS label FROM employees WHERE status='Active' ORDER BY name"
)->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'On Duty Requests';
include '../../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title">On Duty (OD) Requests</h1>
        <p class="page-subtitle">Manage on-duty travel and field-work requests</p>
    </div>
    <div class="page-actions">
        <?php if ($canCreate): ?>
        <button class="btn btn-primary" onclick="openModal('newODModal')" data-key="N"><u>N</u>ew OD Request</button>
        <?php endif; ?>
        <a href="index.php" class="btn btn-secondary" data-key="B"><u>B</u>ack</a>
    </div>
</div>

<?php render_flash(); ?>

<!-- Status filter -->
<div class="tabs" style="margin-bottom:16px;flex-wrap:wrap">
    <a class="tab <?= !$validStatus ? 'active' : '' ?>" href="?">All <span class="pill pill-neutral" style="margin-left:4px"><?= (int)$totalCount ?></span></a>
    <?php foreach (['Pending','Approved','Rejected'] as $st): ?>
    <a class="tab <?= $statusFilter === $st ? 'active' : '' ?>" href="?status=<?= $st ?>">
        <?= $st ?> <span class="pill pill-neutral" style="margin-left:4px"><?= (int)($counts[$st] ?? 0) ?></span>
    </a>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-body">
        <table class="table datatable">
            <thead>
                <tr>
                    <th>#</th>
                    <?php if (!$isEmployee): ?><th>Employee</th><?php endif; ?>
                    <th>From</th>
                    <th>To</th>
                    <th>Days</th>
                    <th>Place</th>
                    <th>Reason</th>
                    <th>Status</th>
                    <th>Reviewed By</th>
                    <th>Requested</th>
                    <th class="r">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $r):
                    $days = (new DateTime($r['from_date']))->diff(new DateTime($r['to_date']))->days + 1;
                    $isOwner = $isEmployee && (int)$r['employee_id'] === $selfEmpId;
                    $canEditThis = ($r['status'] === 'Pending') && ($canApprove || ($canCreate && $isOwner));
                    $canDelThis  = $canDelete || ($isOwner && $r['status'] === 'Pending');
                ?>
                <tr>
                    <td><?= $r['id'] ?></td>
                    <?php if (!$isEmployee): ?><td><strong><?= h($r['emp_code']) ?></strong><br><small><?= h($r['emp_name']) ?></small></td><?php endif; ?>
                    <td><?= date_fmt($r['from_date']) ?></td>
                    <td><?= date_fmt($r['to_date']) ?></td>
                    <td><?= $days ?></td>
                    <td><?= h($r['place'] ?? '') ?: '—' ?></td>
                    <td><?= h($r['reason'] ?? '') ?: '—' ?></td>
                    <td>
                        <?php
                        $cls = ['Pending'=>'pill-warn','Approved'=>'pill-success','Rejected'=>'pill-danger'];
                        echo '<span class="pill ' . ($cls[$r['status']] ?? '') . '">' . h($r['status']) . '</span>';
                        ?>
                    </td>
                    <td><?= h($r['reviewer_name'] ?? '') ?: '—' ?></td>
                    <td><?= date_fmt($r['requested_at'], 'd M Y') ?></td>
                    <td class="r nowrap">
                        <?php if ($r['status'] === 'Pending' && $canApprove): ?>
                        <form method="POST" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                            <button name="action" value="Approved" class="btn btn-xs btn-success">Approve</button>
                            <button name="action" value="Rejected" class="btn btn-xs btn-danger">Reject</button>
                        </form>
                        <?php endif; ?>
                        <?php if ($canEditThis): ?>
                        <button class="btn btn-xs btn-secondary od-edit"
                                data-id="<?= $r['id'] ?>"
                                data-from="<?= h($r['from_date']) ?>"
                                data-to="<?= h($r['to_date']) ?>"
                                data-place="<?= h($r['place'] ?? '') ?>"
                                data-reason="<?= h($r['reason'] ?? '') ?>">Edit</button>
                        <?php endif; ?>
                        <?php if ($canDelThis): ?>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this OD request<?= $r['status']==='Approved' ? ' and remove its OD attendance marks' : '' ?>?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="delete_request" value="1">
                            <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                            <button type="submit" class="btn btn-xs btn-danger">Del</button>
                        </form>
                        <?php endif; ?>
                        <?php if ($r['status'] !== 'Pending' && !$canEditThis && !$canDelThis): ?>—<?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($canCreate): ?>
<!-- New OD Modal -->
<div class="modal" id="newODModal">
    <div class="modal-content">
        <h3>New OD Request</h3>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="submit_request" value="1">
            <?php if (!$isEmployee): ?>
            <div class="form-group">
                <label class="form-label">Employee</label>
                <select name="employee_id" class="form-control" required>
                    <option value="">Select Employee</option>
                    <?php foreach ($employees as $e): ?>
                        <option value="<?= $e['id'] ?>"><?= h($e['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="form-row">
                <div class="form-group col-6">
                    <label class="form-label">From Date</label>
                    <input type="date" name="from_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group col-6">
                    <label class="form-label">To Date</label>
                    <input type="date" name="to_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Place / Location</label>
                <input type="text" name="place" class="form-control" maxlength="200" placeholder="City / Client location">
            </div>
            <div class="form-group">
                <label class="form-label">Purpose / Reason</label>
                <textarea name="reason" class="form-control" rows="3" required></textarea>
            </div>
            <div style="display:flex;gap:8px;margin-top:16px">
                <button type="submit" class="btn btn-primary">Submit OD Request</button>
                <button type="button" class="btn btn-ghost" onclick="closeModal('newODModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($canCreate || $canApprove): ?>
<!-- Edit OD Modal -->
<div class="modal" id="editODModal">
    <div class="modal-content">
        <h3>Edit OD Request</h3>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="edit_request" value="1">
            <input type="hidden" name="request_id" id="edit_od_id">
            <div class="form-row">
                <div class="form-group col-6">
                    <label class="form-label">From Date</label>
                    <input type="date" name="from_date" id="edit_od_from" class="form-control" required>
                </div>
                <div class="form-group col-6">
                    <label class="form-label">To Date</label>
                    <input type="date" name="to_date" id="edit_od_to" class="form-control" required>
                </div>
            </div>
            <div class="form-group">
                <label class="form-label">Place / Location</label>
                <input type="text" name="place" id="edit_od_place" class="form-control" maxlength="200">
            </div>
            <div class="form-group">
                <label class="form-label">Purpose / Reason</label>
                <textarea name="reason" id="edit_od_reason" class="form-control" rows="3" required></textarea>
            </div>
            <div style="display:flex;gap:8px;margin-top:16px">
                <button type="submit" class="btn btn-primary">Save Changes</button>
                <button type="button" class="btn btn-ghost" onclick="closeModal('editODModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
window.PAGE_SHORTCUTS = {
    'b': () => location.href = 'index.php'<?php if ($canCreate): ?>,
    'n': () => openModal('newODModal')<?php endif; ?>
};
<?php if ($canCreate || $canApprove): ?>
document.querySelectorAll('.od-edit').forEach(function (b) {
    b.addEventListener('click', function () {
        document.getElementById('edit_od_id').value     = b.dataset.id;
        document.getElementById('edit_od_from').value   = b.dataset.from;
        document.getElementById('edit_od_to').value     = b.dataset.to;
        document.getElementById('edit_od_place').value  = b.dataset.place || '';
        document.getElementById('edit_od_reason').value = b.dataset.reason || '';
        openModal('editODModal');
    });
});
<?php endif; ?>
</script>
<?php include '../../includes/footer.php'; ?>
