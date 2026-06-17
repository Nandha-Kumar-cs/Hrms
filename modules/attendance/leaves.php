<?php
/**
 * Leave Requests — apply, approve, reject, edit, delete.
 *
 * Ported from the Employee_Management LeaveRequestController, adapted to HRMS
 * plain PHP. Approving deducts the yearly leave balance and auto-marks
 * attendance as "On Leave"; reversing (edit/delete) restores both.
 *
 * Rules carried over:
 *   • days_requested = calendar days in range excluding Sundays.
 *   • Max 1 approved PAID leave per month (comp-off excluded — own quota).
 *   • Comp-off leave types are gated by the comp-off balance (see comp_off.php).
 */
require_once __DIR__ . '/../../includes/comp_off.php';
require_login();
require_permission('leaves', 'view');

$db   = db();
$user = current_user();
$yearNow = (int)date('Y');

$canCreate = can('leaves', 'create');
$canEdit   = can('leaves', 'edit');
$canDelete = can('leaves', 'delete');

// Employee self-service: non-privileged employees only see / act on their own records.
$isEmployee = (($user['role_name'] ?? '') === 'Employee');
$selfEmpId  = (int)($user['employee_id'] ?? 0);

/* ── Helpers ──────────────────────────────────────────────────────────────── */
function leave_days(string $start, string $end): int
{
    $d = new DateTime($start); $e = new DateTime($end); $n = 0;
    for (; $d <= $e; $d->modify('+1 day')) if ((int)$d->format('N') !== 7) $n++;
    return $n;
}
function leave_apply_attendance(int $emp, string $start, string $end, string $typeName, int $by): void
{
    $up = db()->prepare(
        'INSERT INTO attendance (employee_id, att_date, status, remarks, marked_by)
         VALUES (?,?,"On Leave",?,?)
         ON DUPLICATE KEY UPDATE status="On Leave", in_time=NULL, out_time=NULL, ot_hours=NULL, remarks=VALUES(remarks), marked_by=VALUES(marked_by)'
    );
    $d = new DateTime($start); $e = new DateTime($end);
    for (; $d <= $e; $d->modify('+1 day')) {
        if ((int)$d->format('N') === 7) continue;
        $up->execute([$emp, $d->format('Y-m-d'), 'Approved leave: ' . $typeName, $by]);
    }
}
function leave_remove_attendance(int $emp, string $start, string $end, string $typeName): void
{
    $del = db()->prepare("DELETE FROM attendance WHERE employee_id=? AND att_date=? AND remarks=?");
    $d = new DateTime($start); $e = new DateTime($end);
    for (; $d <= $e; $d->modify('+1 day')) {
        if ((int)$d->format('N') === 7) continue;
        $del->execute([$emp, $d->format('Y-m-d'), 'Approved leave: ' . $typeName]);
    }
}
function leave_balance_adjust(int $emp, int $typeId, int $year, float $totalAllowed, float $delta): void
{
    db()->prepare(
        'INSERT INTO leave_balances (employee_id, leave_type_id, year, total_days, used_days)
         VALUES (?,?,?,?,GREATEST(0,?))
         ON DUPLICATE KEY UPDATE used_days = GREATEST(0, used_days + ?), total_days = VALUES(total_days)'
    )->execute([$emp, $typeId, $year, $totalAllowed, max(0, $delta), $delta]);
}
/** 1 approved paid leave per month (comp-off excluded). Returns error or null. */
function leave_paid_conflict(int $emp, int $typeId, string $start, string $end, ?int $excludeId = null): ?string
{
    $lt = db()->prepare('SELECT is_paid, is_comp_off FROM leave_types WHERE id=?');
    $lt->execute([$typeId]);
    $t = $lt->fetch();
    if (!$t || !$t['is_paid'] || $t['is_comp_off']) return null;

    $cur = new DateTime((new DateTime($start))->format('Y-m-01'));
    $endD = new DateTime($end);
    while ($cur <= $endD) {
        $mStart = $cur->format('Y-m-01');
        $mEnd   = (clone $cur)->modify('last day of this month')->format('Y-m-d');
        $sql = "SELECT 1 FROM leave_requests lr JOIN leave_types t ON t.id=lr.leave_type_id
                WHERE lr.employee_id=? AND lr.status='approved' AND t.is_paid=1
                AND lr.end_date>=? AND lr.start_date<=?" . ($excludeId ? ' AND lr.id<>?' : '') . ' LIMIT 1';
        $params = [$emp, $mStart, $mEnd];
        if ($excludeId) $params[] = $excludeId;
        $q = db()->prepare($sql); $q->execute($params);
        if ($q->fetchColumn()) {
            return 'This employee already has an approved paid leave in ' . $cur->format('F Y') . '. Only 1 paid leave is allowed per month.';
        }
        $cur->modify('first day of next month');
    }
    return null;
}

/* ── POST actions (PRG) ─────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf($_POST['csrf_token'] ?? '');
    $action = $_POST['action'] ?? '';
    $self   = BASE_URL . '/modules/attendance/leaves.php';

    // Validate a date pair shared by create/edit.
    $loadType = function (int $id) use ($db): ?array {
        $s = $db->prepare('SELECT * FROM leave_types WHERE id=?'); $s->execute([$id]);
        return $s->fetch() ?: null;
    };

    if ($action === 'create') {
        if (!$canCreate) { http_response_code(403); exit('Forbidden'); }
        $emp   = (int)($_POST['employee_id'] ?? 0);
        if ($isEmployee) $emp = $selfEmpId;   // employees can only apply for themselves
        $typeId= (int)($_POST['leave_type_id'] ?? 0);
        $start = sanitize($_POST['start_date'] ?? '');
        $end   = sanitize($_POST['end_date'] ?? '');
        $reason= trim($_POST['reason'] ?? '');
        $type  = $loadType($typeId);

        $errors = [];
        if (!$emp)  $errors[] = 'Employee is required.';
        if (!$type) $errors[] = 'Leave type is required.';
        $sd = DateTime::createFromFormat('Y-m-d', $start); $ed = DateTime::createFromFormat('Y-m-d', $end);
        if (!$sd || $sd->format('Y-m-d') !== $start) $errors[] = 'A valid start date is required.';
        if (!$ed || $ed->format('Y-m-d') !== $end)   $errors[] = 'A valid end date is required.';
        if ($sd && $ed && $end < $start)             $errors[] = 'End date must be on or after start date.';
        if (mb_strlen($reason) > 500)                $errors[] = 'Reason must be 500 characters or fewer.';

        if (!$errors) {
            if ($c = leave_paid_conflict($emp, $typeId, $start, $end)) $errors[] = $c;
            $days = leave_days($start, $end);
            if (!$errors && $type['is_comp_off'] && ($c = comp_off_check_balance($emp, $days))) $errors[] = $c;
        }
        if ($errors) { flash('error', implode(' ', $errors)); redirect($self); }

        $doc = null;
        if (!empty($_FILES['document']['name'])) {
            $doc = upload_file($_FILES['document'], __DIR__ . '/../../uploads/leave_docs', 'leave_');
            if ($doc === null) { flash('error', 'Document upload failed (allowed: PDF/JPG/PNG/DOC/DOCX, max size).'); redirect($self); }
        }
        $days = leave_days($start, $end);
        $db->prepare('INSERT INTO leave_requests (employee_id, leave_type_id, start_date, end_date, days_requested, reason, document, status) VALUES (?,?,?,?,?,?,?,"pending")')
           ->execute([$emp, $typeId, $start, $end, $days, $reason ?: null, $doc]);
        flash('success', 'Leave request submitted.');
        redirect($self);
    }

    // All remaining actions operate on an existing request.
    $id  = (int)($_POST['id'] ?? 0);
    $lr  = $db->prepare('SELECT lr.*, lt.name AS type_name, lt.days_allowed, lt.is_comp_off FROM leave_requests lr JOIN leave_types lt ON lt.id=lr.leave_type_id WHERE lr.id=?');
    $lr->execute([$id]);
    $req = $lr->fetch();
    if (!$req) { flash('error', 'Leave request not found.'); redirect($self); }

    if ($action === 'approve' || $action === 'reject') {
        if (!$canEdit) { http_response_code(403); exit('Forbidden'); }
        if ($req['status'] !== 'pending') { flash('error', 'Only pending requests can be ' . $action . 'd.'); redirect($self); }
        $remarks = trim($_POST['remarks'] ?? '');

        if ($action === 'reject') {
            $db->prepare("UPDATE leave_requests SET status='rejected', approved_by=?, approved_at=NOW(), remarks=? WHERE id=?")
               ->execute([(int)$user['id'], $remarks ?: null, $id]);
            flash('success', 'Leave request rejected.');
            redirect($self);
        }

        // approve
        if ($c = leave_paid_conflict((int)$req['employee_id'], (int)$req['leave_type_id'], $req['start_date'], $req['end_date'], $id)) { flash('error', $c); redirect($self); }
        if ($req['is_comp_off'] && ($c = comp_off_check_balance((int)$req['employee_id'], (int)$req['days_requested'], $id))) { flash('error', $c); redirect($self); }

        $db->prepare("UPDATE leave_requests SET status='approved', approved_by=?, approved_at=NOW(), remarks=? WHERE id=?")
           ->execute([(int)$user['id'], $remarks ?: null, $id]);
        leave_balance_adjust((int)$req['employee_id'], (int)$req['leave_type_id'], $yearNow, (float)$req['days_allowed'], (float)$req['days_requested']);
        leave_apply_attendance((int)$req['employee_id'], $req['start_date'], $req['end_date'], $req['type_name'], (int)$user['id']);
        flash('success', 'Leave request approved.');
        redirect($self);
    }

    if ($action === 'delete') {
        if (!$canDelete) { http_response_code(403); exit('Forbidden'); }
        if ($req['status'] === 'approved') {
            leave_balance_adjust((int)$req['employee_id'], (int)$req['leave_type_id'], $yearNow, (float)$req['days_allowed'], -(float)$req['days_requested']);
            leave_remove_attendance((int)$req['employee_id'], $req['start_date'], $req['end_date'], $req['type_name']);
        }
        if ($req['document'] && is_file(__DIR__ . '/../../uploads/leave_docs/' . $req['document'])) {
            @unlink(__DIR__ . '/../../uploads/leave_docs/' . $req['document']);
        }
        $db->prepare('DELETE FROM leave_requests WHERE id=?')->execute([$id]);
        flash('success', 'Leave request deleted.');
        redirect($self);
    }

    if ($action === 'update') {
        if (!$canEdit) { http_response_code(403); exit('Forbidden'); }
        $emp    = (int)($_POST['employee_id'] ?? 0);
        $typeId = (int)($_POST['leave_type_id'] ?? 0);
        $start  = sanitize($_POST['start_date'] ?? '');
        $end    = sanitize($_POST['end_date'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        $status = in_array($_POST['status'] ?? '', ['pending','approved','rejected'], true) ? $_POST['status'] : 'pending';
        $remarks= trim($_POST['remarks'] ?? '');
        $type   = $loadType($typeId);

        $errors = [];
        if (!$emp || !$type) $errors[] = 'Employee and leave type are required.';
        $sd = DateTime::createFromFormat('Y-m-d', $start); $ed = DateTime::createFromFormat('Y-m-d', $end);
        if (!$sd || !$ed || $end < $start) $errors[] = 'A valid date range is required.';
        if ($errors) { flash('error', implode(' ', $errors)); redirect($self); }

        $days = leave_days($start, $end);

        // Reverse old approved effects first.
        if ($req['status'] === 'approved') {
            leave_balance_adjust((int)$req['employee_id'], (int)$req['leave_type_id'], $yearNow, (float)$req['days_allowed'], -(float)$req['days_requested']);
            leave_remove_attendance((int)$req['employee_id'], $req['start_date'], $req['end_date'], $req['type_name']);
        }

        if ($status === 'approved') {
            if ($c = leave_paid_conflict($emp, $typeId, $start, $end, $id)) { flash('error', $c); redirect($self); }
            if ($type['is_comp_off'] && ($c = comp_off_check_balance($emp, $days, $id))) { flash('error', $c); redirect($self); }
        }

        // Document: replace / remove / keep.
        $doc = $req['document'];
        if (!empty($_FILES['document']['name'])) {
            $new = upload_file($_FILES['document'], __DIR__ . '/../../uploads/leave_docs', 'leave_');
            if ($new === null) { flash('error', 'Document upload failed.'); redirect($self); }
            if ($doc && is_file(__DIR__ . '/../../uploads/leave_docs/' . $doc)) @unlink(__DIR__ . '/../../uploads/leave_docs/' . $doc);
            $doc = $new;
        } elseif (!empty($_POST['remove_document'])) {
            if ($doc && is_file(__DIR__ . '/../../uploads/leave_docs/' . $doc)) @unlink(__DIR__ . '/../../uploads/leave_docs/' . $doc);
            $doc = null;
        }

        $appBy = $status === 'pending' ? null : (int)$user['id'];
        $appAt = $status === 'pending' ? null : date('Y-m-d H:i:s');
        $rem   = $status === 'pending' ? null : ($remarks ?: null);
        $db->prepare('UPDATE leave_requests SET employee_id=?, leave_type_id=?, start_date=?, end_date=?, days_requested=?, reason=?, document=?, status=?, approved_by=?, approved_at=?, remarks=? WHERE id=?')
           ->execute([$emp, $typeId, $start, $end, $days, $reason ?: null, $doc, $status, $appBy, $appAt, $rem, $id]);

        if ($status === 'approved') {
            $tn = $type['name'];
            leave_balance_adjust($emp, $typeId, $yearNow, (float)$type['days_allowed'], (float)$days);
            leave_apply_attendance($emp, $start, $end, $tn, (int)$user['id']);
        }
        flash('success', 'Leave request updated.');
        redirect($self);
    }

    redirect($self);
}

/* ── View ───────────────────────────────────────────────────────────────────── */
$rowsSql = "SELECT lr.*, e.name AS emp_name, e.employee_id AS emp_code, lt.name AS type_name,
            lt.days_allowed, u.name AS approver
     FROM leave_requests lr
     JOIN employees e ON e.id = lr.employee_id
     JOIN leave_types lt ON lt.id = lr.leave_type_id
     LEFT JOIN users u ON u.id = lr.approved_by";
if ($isEmployee) {
    $rs = $db->prepare($rowsSql . " WHERE lr.employee_id = ? ORDER BY lr.created_at DESC, lr.id DESC");
    $rs->execute([$selfEmpId]);
    $rows = $rs->fetchAll();
} else {
    $rows = $db->query($rowsSql . " ORDER BY lr.created_at DESC, lr.id DESC")->fetchAll();
}

$employees  = $db->query("SELECT id, name, employee_id FROM employees WHERE status='Active' ORDER BY name")->fetchAll();
$selfName   = '';
if ($isEmployee) {
    foreach ($employees as $e) if ((int)$e['id'] === $selfEmpId) { $selfName = $e['name'] . ' (' . $e['employee_id'] . ')'; break; }
}
$leaveTypes = $db->query("SELECT id, name, days_allowed FROM leave_types WHERE status='active' ORDER BY name")->fetchAll();

// Per-row data for the View/Edit modals.
$rowData = [];
foreach ($rows as $r) {
    $rowData[$r['id']] = [
        'id' => $r['id'], 'employee_id' => $r['employee_id'], 'emp' => $r['emp_name'] . ' (' . $r['emp_code'] . ')',
        'leave_type_id' => $r['leave_type_id'], 'type' => $r['type_name'],
        'start_date' => $r['start_date'], 'end_date' => $r['end_date'], 'days' => $r['days_requested'],
        'reason' => $r['reason'], 'status' => $r['status'], 'remarks' => $r['remarks'],
        'document' => $r['document'], 'approver' => $r['approver'], 'approved_at' => $r['approved_at'],
    ];
}

$statusBadge = ['pending' => 'warning text-dark', 'approved' => 'success', 'rejected' => 'danger'];

$page_title = 'Leave Requests';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Leave Requests</h1>
        <p class="page-subtitle">Apply, approve and track employee leave</p>
    </div>
    <div class="page-actions d-flex gap-2 align-items-center">
        <a href="<?= BASE_URL ?>/modules/attendance/leave_history.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-clock-rotate-left me-1"></i>Leave History</a>
        <?php if ($canCreate): ?>
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#leaveNewModal"><i class="fa fa-plus me-1"></i>New Request</button>
        <?php endif; ?>
    </div>
</div>

<?php render_flash(); ?>

<div class="card page-card">
    <div class="card-body table-responsive">
        <table class="table table-hover table-bordered align-middle" id="tbl-leaves">
            <thead class="table-dark"><tr>
                <th>Employee</th><th>Leave Type</th><th>Period</th><th class="text-center">Days</th><th class="text-center">Status</th><th class="text-center" style="width:200px">Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r): $sObj = new DateTime($r['start_date']); $eObj = new DateTime($r['end_date']); ?>
                <tr>
                    <td><strong><?= h($r['emp_code']) ?></strong> <span class="text-muted">— <?= h($r['emp_name']) ?></span></td>
                    <td><?= h($r['type_name']) ?></td>
                    <td><?= $sObj->format('d M Y') ?><?= $r['start_date'] !== $r['end_date'] ? ' – ' . $eObj->format('d M Y') : '' ?></td>
                    <td class="text-center"><?= rtrim(rtrim(number_format((float)$r['days_requested'], 1), '0'), '.') ?></td>
                    <td class="text-center"><span class="badge bg-<?= $statusBadge[$r['status']] ?? 'secondary' ?>"><?= ucfirst($r['status']) ?></span></td>
                    <td class="text-center text-nowrap">
                        <button class="btn btn-sm btn-outline-primary leave-view" data-id="<?= $r['id'] ?>" title="View"><i class="fa fa-eye"></i></button>
                        <?php if ($canEdit && $r['status'] === 'pending'): ?>
                        <form method="POST" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= $r['id'] ?>">
                            <button class="btn btn-sm btn-outline-success" title="Approve" onclick="return confirm('Approve this leave?')"><i class="fa fa-check"></i></button></form>
                        <button class="btn btn-sm btn-outline-danger leave-reject" data-id="<?= $r['id'] ?>" title="Reject"><i class="fa fa-xmark"></i></button>
                        <?php endif; ?>
                        <?php if ($canEdit): ?>
                        <button class="btn btn-sm btn-outline-warning leave-edit" data-id="<?= $r['id'] ?>" title="Edit"><i class="fa fa-pen"></i></button>
                        <?php endif; ?>
                        <?php if ($canDelete): ?>
                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this leave request? Approved requests will have balance &amp; attendance reversed.')">
                            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa fa-trash"></i></button></form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($canCreate): ?>
<!-- New Request modal -->
<div class="modal fade" id="leaveNewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?><input type="hidden" name="action" value="create">
            <div class="modal-header"><h5 class="modal-title">New Leave Request</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3"><label class="form-label">Employee <span class="text-danger">*</span></label>
                    <?php if ($isEmployee): ?>
                        <input type="text" class="form-control" value="<?= h($selfName) ?>" readonly>
                    <?php else: ?>
                    <select name="employee_id" class="form-select" required><option value="">Select Employee</option>
                        <?php foreach ($employees as $e): ?><option value="<?= $e['id'] ?>"><?= h($e['name']) ?> (<?= h($e['employee_id']) ?>)</option><?php endforeach; ?>
                    </select>
                    <?php endif; ?></div>
                <div class="mb-3"><label class="form-label">Leave Type <span class="text-danger">*</span></label>
                    <select name="leave_type_id" class="form-select" required><option value="">Select Leave Type</option>
                        <?php foreach ($leaveTypes as $lt): ?><option value="<?= $lt['id'] ?>"><?= h($lt['name']) ?> (<?= (int)$lt['days_allowed'] ?> days/yr)</option><?php endforeach; ?>
                    </select></div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6"><label class="form-label">Start Date <span class="text-danger">*</span></label><input type="date" name="start_date" class="form-control" required></div>
                    <div class="col-md-6"><label class="form-label">End Date <span class="text-danger">*</span></label><input type="date" name="end_date" class="form-control" required></div>
                </div>
                <div class="mb-3"><label class="form-label">Reason</label><textarea name="reason" class="form-control" rows="2" maxlength="500"></textarea></div>
                <div class="mb-1"><label class="form-label">Supporting Document <span class="text-muted">(optional)</span></label>
                    <input type="file" name="document" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                    <div class="form-text">PDF, JPG, PNG, DOC, DOCX · max 5 MB.</div></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary"><i class="fa fa-paper-plane me-1"></i>Submit</button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($canEdit): ?>
<!-- Edit modal -->
<div class="modal fade" id="leaveEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" id="edId">
            <div class="modal-header"><h5 class="modal-title">Edit Leave Request</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3"><label class="form-label">Employee <span class="text-danger">*</span></label>
                    <select name="employee_id" id="edEmp" class="form-select" required>
                        <?php foreach ($employees as $e): ?><option value="<?= $e['id'] ?>"><?= h($e['name']) ?> (<?= h($e['employee_id']) ?>)</option><?php endforeach; ?>
                    </select></div>
                <div class="mb-3"><label class="form-label">Leave Type <span class="text-danger">*</span></label>
                    <select name="leave_type_id" id="edType" class="form-select" required>
                        <?php foreach ($leaveTypes as $lt): ?><option value="<?= $lt['id'] ?>"><?= h($lt['name']) ?></option><?php endforeach; ?>
                    </select></div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6"><label class="form-label">Start Date <span class="text-danger">*</span></label><input type="date" name="start_date" id="edStart" class="form-control" required></div>
                    <div class="col-md-6"><label class="form-label">End Date <span class="text-danger">*</span></label><input type="date" name="end_date" id="edEnd" class="form-control" required></div>
                </div>
                <div class="mb-3"><label class="form-label">Reason</label><textarea name="reason" id="edReason" class="form-control" rows="2" maxlength="500"></textarea></div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6"><label class="form-label">Status</label>
                        <select name="status" id="edStatus" class="form-select"><option value="pending">Pending</option><option value="approved">Approved</option><option value="rejected">Rejected</option></select></div>
                    <div class="col-md-6"><label class="form-label">Remarks</label><input type="text" name="remarks" id="edRemarks" class="form-control" maxlength="500"></div>
                </div>
                <div class="mb-1"><label class="form-label">Replace Document <span class="text-muted">(optional)</span></label>
                    <input type="file" name="document" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                    <div class="form-check mt-2"><input class="form-check-input" type="checkbox" name="remove_document" value="1" id="edRemoveDoc"><label class="form-check-label" for="edRemoveDoc">Remove existing document</label></div>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Save</button></div>
        </form>
    </div>
</div>

<!-- Reject modal -->
<div class="modal fade" id="leaveRejectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <form class="modal-content" method="POST">
            <?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="id" id="rjId">
            <div class="modal-header"><h6 class="modal-title text-danger">Reject Leave</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body"><label class="form-label small">Remarks (optional)</label><textarea name="remarks" class="form-control" rows="2"></textarea></div>
            <div class="modal-footer"><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-sm btn-danger">Reject</button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- View modal -->
<div class="modal fade" id="leaveViewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Leave Request</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body" id="vwBody"></div>
        </div>
    </div>
</div>

<script>window.LEAVES = <?= json_encode($rowData) ?>; window.LEAVE_DOC_BASE = '<?= BASE_URL ?>/uploads/leave_docs/';</script>
<?php $page_scripts = <<<'JS'
<script>
$(function () {
    if ($.fn.DataTable) $('#tbl-leaves').DataTable({ pageLength: 25, order: [], columnDefs: [{ orderable: false, targets: [5] }], language: { search: '', searchPlaceholder: 'Search leaves...' } });

    $(document).on('click', '.leave-view', function () {
        var r = window.LEAVES[$(this).data('id')]; if (!r) return;
        var doc = r.document ? '<a href="' + window.LEAVE_DOC_BASE + r.document + '" target="_blank">View document</a>' : '<span class="text-muted">None</span>';
        $('#vwBody').html(
            '<dl class="row mb-0">' +
            '<dt class="col-4">Employee</dt><dd class="col-8">' + r.emp + '</dd>' +
            '<dt class="col-4">Leave Type</dt><dd class="col-8">' + r.type + '</dd>' +
            '<dt class="col-4">Period</dt><dd class="col-8">' + r.start_date + ' to ' + r.end_date + ' (' + r.days + ' day(s))</dd>' +
            '<dt class="col-4">Status</dt><dd class="col-8">' + r.status + '</dd>' +
            '<dt class="col-4">Reason</dt><dd class="col-8">' + (r.reason ? $('<div>').text(r.reason).html() : '—') + '</dd>' +
            '<dt class="col-4">Remarks</dt><dd class="col-8">' + (r.remarks ? $('<div>').text(r.remarks).html() : '—') + '</dd>' +
            '<dt class="col-4">Approved By</dt><dd class="col-8">' + (r.approver || '—') + (r.approved_at ? ' on ' + r.approved_at : '') + '</dd>' +
            '<dt class="col-4">Document</dt><dd class="col-8">' + doc + '</dd>' +
            '</dl>'
        );
        new bootstrap.Modal(document.getElementById('leaveViewModal')).show();
    });

    $(document).on('click', '.leave-edit', function () {
        var r = window.LEAVES[$(this).data('id')]; if (!r) return;
        $('#edId').val(r.id); $('#edEmp').val(r.employee_id); $('#edType').val(r.leave_type_id);
        $('#edStart').val(r.start_date); $('#edEnd').val(r.end_date); $('#edReason').val(r.reason || '');
        $('#edStatus').val(r.status); $('#edRemarks').val(r.remarks || ''); $('#edRemoveDoc').prop('checked', false);
        new bootstrap.Modal(document.getElementById('leaveEditModal')).show();
    });

    $(document).on('click', '.leave-reject', function () {
        $('#rjId').val($(this).data('id'));
        new bootstrap.Modal(document.getElementById('leaveRejectModal')).show();
    });
});
</script>
JS; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
