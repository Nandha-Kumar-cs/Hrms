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

$canCreate  = can('leaves', 'create');
$canEdit    = can('leaves', 'edit');                          // edit request FIELDS only (no approval)
$canApprove = can('leaves', 'approve') && !is_self_scoped();  // admin-only approve / reject
$canDelete  = can('leaves', 'delete');

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
        // Submitted request → engine status + admin approval both start "pending".
        $db->prepare('INSERT INTO leave_requests (employee_id, leave_type_id, start_date, end_date, days_requested, reason, document, status, admin_approval_status) VALUES (?,?,?,?,?,?,?,"pending","pending")')
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
        // Admin-only: requires the separate approve permission (never editors/employees).
        if (!$canApprove) { http_response_code(403); exit('Forbidden'); }
        if (($req['admin_approval_status'] ?? 'pending') !== 'pending') { flash('error', 'Only pending requests can be ' . $action . 'd.'); redirect($self); }
        $remarks = trim($_POST['remarks'] ?? '');

        if ($action === 'reject') {
            $db->prepare("UPDATE leave_requests SET admin_approval_status='rejected', status='rejected', approved_by=?, approved_at=NOW(), remarks=? WHERE id=?")
               ->execute([(int)$user['id'], $remarks ?: null, $id]);
            flash('success', 'Leave request rejected.');
            redirect($self);
        }

        // approve
        if ($c = leave_paid_conflict((int)$req['employee_id'], (int)$req['leave_type_id'], $req['start_date'], $req['end_date'], $id)) { flash('error', $c); redirect($self); }
        if ($req['is_comp_off'] && ($c = comp_off_check_balance((int)$req['employee_id'], (int)$req['days_requested'], $id))) { flash('error', $c); redirect($self); }

        $db->prepare("UPDATE leave_requests SET admin_approval_status='approved', status='approved', approved_by=?, approved_at=NOW(), remarks=? WHERE id=?")
           ->execute([(int)$user['id'], $remarks ?: null, $id]);
        leave_balance_adjust((int)$req['employee_id'], (int)$req['leave_type_id'], $yearNow, (float)$req['days_allowed'], (float)$req['days_requested']);
        leave_apply_attendance((int)$req['employee_id'], $req['start_date'], $req['end_date'], $req['type_name'], (int)$user['id']);
        flash('success', 'Leave request approved.');
        redirect($self);
    }

    if ($action === 'delete') {
        if (!$canDelete) { http_response_code(403); exit('Forbidden'); }
        if (is_self_scoped() && (int)$req['employee_id'] !== $selfEmpId) { http_response_code(403); exit('Forbidden'); }
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
        // Edit permission lets a user change the request FIELDS only — never the
        // admin approval decision. The admin_approval_status/status are preserved.
        if (!$canEdit) { http_response_code(403); exit('Forbidden'); }
        // Self-scoped employees may only edit their OWN request.
        if (is_self_scoped() && (int)$req['employee_id'] !== $selfEmpId) { http_response_code(403); exit('Forbidden'); }

        // A self-scoped employee cannot reassign the request to another employee.
        $emp    = is_self_scoped() ? (int)$req['employee_id'] : (int)($_POST['employee_id'] ?? 0);
        $typeId = (int)($_POST['leave_type_id'] ?? 0);
        $start  = sanitize($_POST['start_date'] ?? '');
        $end    = sanitize($_POST['end_date'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        $type   = $loadType($typeId);

        $errors = [];
        if (!$emp || !$type) $errors[] = 'Employee and leave type are required.';
        $sd = DateTime::createFromFormat('Y-m-d', $start); $ed = DateTime::createFromFormat('Y-m-d', $end);
        if (!$sd || !$ed || $end < $start) $errors[] = 'A valid date range is required.';
        if ($errors) { flash('error', implode(' ', $errors)); redirect($self); }

        $days = leave_days($start, $end);

        // The approval decision is left exactly as it was — editing never changes it.
        $wasApproved = ($req['status'] === 'approved');

        // If it was already approved, reverse the old effects so the edited dates
        // re-apply cleanly (then re-validate the new range against the rules).
        if ($wasApproved) {
            leave_balance_adjust((int)$req['employee_id'], (int)$req['leave_type_id'], $yearNow, (float)$req['days_allowed'], -(float)$req['days_requested']);
            leave_remove_attendance((int)$req['employee_id'], $req['start_date'], $req['end_date'], $req['type_name']);
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

        // Persist field edits only — status & admin_approval_status are untouched.
        $db->prepare('UPDATE leave_requests SET employee_id=?, leave_type_id=?, start_date=?, end_date=?, days_requested=?, reason=?, document=? WHERE id=?')
           ->execute([$emp, $typeId, $start, $end, $days, $reason ?: null, $doc, $id]);

        // Re-apply approved effects with the edited values.
        if ($wasApproved) {
            leave_balance_adjust($emp, $typeId, $yearNow, (float)$type['days_allowed'], (float)$days);
            leave_apply_attendance($emp, $start, $end, $type['name'], (int)$user['id']);
        }
        flash('success', 'Leave request updated.');
        redirect($self);
    }

    redirect($self);
}

/* ── View dispatcher (mirrors the Laravel index / create / show / edit screens) ── */
$self    = BASE_URL . '/modules/attendance/leaves.php';
$viewId  = (int)($_GET['view'] ?? 0);
$editId  = (int)($_GET['edit'] ?? 0);
$screen  = isset($_GET['new']) ? 'new' : ($viewId ? 'view' : ($editId ? 'edit' : 'list'));

$employees  = $db->query("SELECT id, name, employee_id FROM employees WHERE status='Active' ORDER BY name")->fetchAll();
$selfName   = '';
if ($isEmployee) foreach ($employees as $e) if ((int)$e['id'] === $selfEmpId) { $selfName = $e['name'] . ' (' . $e['employee_id'] . ')'; break; }
$leaveTypes = $db->query("SELECT id, name, days_allowed, is_paid, is_comp_off FROM leave_types WHERE status='active' ORDER BY name")->fetchAll();
$statusBadge = ['pending' => 'warning text-dark', 'approved' => 'success', 'rejected' => 'danger'];

/** Load one request with employee/type/approver context. */
$leaveLoad = function (int $id) use ($db) {
    $s = $db->prepare(
        "SELECT lr.*, e.name emp_name, e.employee_id emp_code, lt.name type_name, lt.days_allowed, lt.is_paid, lt.is_comp_off,
                u.name approver, d.name dept_name, des.name desig_name
         FROM leave_requests lr
         JOIN employees e ON e.id = lr.employee_id
         JOIN leave_types lt ON lt.id = lr.leave_type_id
         LEFT JOIN users u ON u.id = lr.approved_by
         LEFT JOIN departments d ON d.id = e.department_id
         LEFT JOIN designations des ON des.id = e.designation_id
         WHERE lr.id = ?"
    );
    $s->execute([$id]);
    return $s->fetch();
};

$current = null;
if ($screen === 'view' || $screen === 'edit') {
    $current = $leaveLoad($viewId ?: $editId);
    if (!$current || ($isEmployee && (int)$current['employee_id'] !== $selfEmpId)) { $screen = 'list'; $current = null; }
    if ($screen === 'edit' && !$canEdit) { $screen = 'view'; }
}
if (($screen === 'new' && !$canCreate)) { $screen = 'list'; }

/** Short period label "01 Jan 2026" or "01 Jan 2026 – 03 Jan 2026". */
$period = function ($s, $e) {
    $sf = (new DateTime($s))->format('d M Y');
    return $s === $e ? $sf : $sf . ' – ' . (new DateTime($e))->format('d M Y');
};
$fmtDays = fn ($d) => rtrim(rtrim(number_format((float)$d, 1), '0'), '.');

$page_title = 'Leave Requests';
require_once __DIR__ . '/../../includes/header.php';
render_flash();

/* ═══════════════ CREATE SCREEN ═══════════════ */
if ($screen === 'new'): ?>
<div class="card page-card" style="max-width:620px">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2">
        <a href="<?= $self ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left"></i></a>
        <h5 class="mb-0 fw-semibold">New Leave Request</h5>
    </div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?><input type="hidden" name="action" value="create">
            <div class="mb-3">
                <label class="form-label fw-semibold">Employee <span class="text-danger">*</span></label>
                <?php if ($isEmployee): ?>
                    <input type="text" class="form-control" value="<?= h($selfName) ?>" readonly>
                <?php else: ?>
                <select name="employee_id" class="form-select" required>
                    <option value="">Select Employee</option>
                    <?php foreach ($employees as $e): ?><option value="<?= $e['id'] ?>"><?= h($e['name']) ?> (<?= h($e['employee_id']) ?>)</option><?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Leave Type <span class="text-danger">*</span></label>
                <select name="leave_type_id" class="form-select" required>
                    <option value="">Select Leave Type</option>
                    <?php foreach ($leaveTypes as $lt): ?><option value="<?= $lt['id'] ?>"><?= h($lt['name']) ?> (<?= (int)$lt['days_allowed'] ?> days/yr)</option><?php endforeach; ?>
                </select>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-md-6"><label class="form-label fw-semibold">Start Date <span class="text-danger">*</span></label><input type="date" name="start_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
                <div class="col-md-6"><label class="form-label fw-semibold">End Date <span class="text-danger">*</span></label><input type="date" name="end_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
            </div>
            <div class="mb-3"><label class="form-label fw-semibold">Reason</label><textarea name="reason" class="form-control" rows="3" maxlength="500" placeholder="Optional reason"></textarea></div>
            <div class="mb-4">
                <label class="form-label fw-semibold">Supporting Document <span class="text-muted fw-normal">(Optional)</span></label>
                <input type="file" name="document" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                <div class="form-text"><i class="fa fa-info-circle me-1 text-primary"></i>Accepted: PDF, JPG, PNG, DOC, DOCX &nbsp;·&nbsp; Max size: 5 MB</div>
            </div>
            <button type="submit" class="btn btn-primary"><i class="fa fa-paper-plane me-1"></i>Submit Request</button>
        </form>
    </div>
</div>

<?php /* ═══════════════ SHOW SCREEN ═══════════════ */
elseif ($screen === 'view'):
    $balS = $db->prepare('SELECT total_days, used_days, GREATEST(0, total_days-used_days) AS remaining FROM leave_balances WHERE employee_id=? AND leave_type_id=? AND year=?');
    $balS->execute([$current['employee_id'], $current['leave_type_id'], $yearNow]);
    $bal = $balS->fetch();
?>
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card page-card">
            <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center gap-2">
                    <a href="<?= $self ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left"></i></a>
                    <h5 class="mb-0 fw-semibold">Leave Request #<?= (int)$current['id'] ?></h5>
                </div>
                <div class="d-flex gap-2">
                    <span class="badge bg-info">Submitted</span>
                    <span class="badge bg-<?= $statusBadge[$current['admin_approval_status']] ?? 'secondary' ?>">Admin: <?= ucfirst($current['admin_approval_status']) ?></span>
                </div>
            </div>
            <div class="card-body">
                <table class="table table-borderless table-sm">
                    <tr><th class="text-muted" style="width:160px">Employee</th><td><strong><?= h($current['emp_name']) ?></strong> (<?= h($current['emp_code']) ?>)</td></tr>
                    <tr><th class="text-muted">Department</th><td><?= h($current['dept_name'] ?? '') ?: '—' ?></td></tr>
                    <tr><th class="text-muted">Designation</th><td><?= h($current['desig_name'] ?? '') ?: '—' ?></td></tr>
                    <tr><th class="text-muted">Leave Type</th><td><?= h($current['type_name']) ?></td></tr>
                    <tr><th class="text-muted">From</th><td><?= date_fmt($current['start_date']) ?></td></tr>
                    <tr><th class="text-muted">To</th><td><?= date_fmt($current['end_date']) ?></td></tr>
                    <tr><th class="text-muted">Days Requested</th><td><strong><?= $fmtDays($current['days_requested']) ?></strong></td></tr>
                    <tr><th class="text-muted">Reason</th><td><?= h($current['reason'] ?? '') ?: '—' ?></td></tr>
                    <?php if ($current['document']): ?>
                    <tr><th class="text-muted">Document</th><td><a href="<?= BASE_URL ?>/uploads/leave_docs/<?= h($current['document']) ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fa fa-paperclip me-1"></i>View Attachment</a></td></tr>
                    <?php endif; ?>
                    <tr><th class="text-muted">Request Status</th><td><span class="badge bg-info">Submitted</span></td></tr>
                    <tr><th class="text-muted">Admin Approval Status</th><td><span class="badge bg-<?= $statusBadge[$current['admin_approval_status']] ?? 'secondary' ?>"><?= ucfirst($current['admin_approval_status']) ?></span></td></tr>
                    <?php if ($current['admin_approval_status'] !== 'pending'): ?>
                    <tr><th class="text-muted"><?= ucfirst($current['admin_approval_status']) ?> By</th><td><?= h($current['approver'] ?? '') ?: '—' ?></td></tr>
                    <tr><th class="text-muted"><?= ucfirst($current['admin_approval_status']) ?> At</th><td><?= $current['approved_at'] ? date_fmt($current['approved_at'], 'd M Y H:i') : '—' ?></td></tr>
                    <?php if ($current['remarks']): ?><tr><th class="text-muted">Admin Remarks</th><td><?= h($current['remarks']) ?></td></tr><?php endif; ?>
                    <?php endif; ?>
                </table>
                <?php if ($bal): ?>
                <div class="alert alert-info mt-3"><i class="fa fa-info-circle me-2"></i>
                    <strong><?= h($current['type_name']) ?> Balance (<?= $yearNow ?>):</strong>
                    <?= $fmtDays($bal['remaining']) ?> days remaining of <?= $fmtDays($bal['total_days']) ?> total (<?= $fmtDays($bal['used_days']) ?> used)
                </div>
                <?php endif; ?>
                <div class="d-flex gap-2 mt-2">
                    <?php if ($canEdit): ?><a href="?edit=<?= (int)$current['id'] ?>" class="btn btn-sm btn-outline-warning"><i class="fa fa-pen me-1"></i>Edit</a><?php endif; ?>
                    <?php if ($canDelete): ?>
                    <form method="POST" onsubmit="return confirm('Delete this leave request?<?= $current['status']==='approved' ? ' Balance & attendance will be reversed.' : '' ?>')">
                        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$current['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger"><i class="fa fa-trash me-1"></i>Delete</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php if ($current['admin_approval_status'] === 'pending' && $canApprove): ?>
    <div class="col-lg-4">
        <div class="card page-card">
            <div class="card-header bg-white py-3"><h6 class="mb-0 fw-semibold">Admin Approval</h6></div>
            <div class="card-body">
                <form method="POST" class="mb-3">
                    <?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= (int)$current['id'] ?>">
                    <div class="mb-2"><label class="form-label small">Approval Remarks (optional)</label><textarea name="remarks" class="form-control form-control-sm" rows="2" placeholder="e.g. Approved, enjoy your leave."></textarea></div>
                    <button class="btn btn-success w-100"><i class="fa fa-check me-1"></i>Approve Request</button>
                </form>
                <hr>
                <form method="POST">
                    <?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?= (int)$current['id'] ?>">
                    <div class="mb-2"><label class="form-label small">Rejection Reason</label><textarea name="remarks" class="form-control form-control-sm" rows="2" placeholder="Reason for rejection..."></textarea></div>
                    <button class="btn btn-danger w-100"><i class="fa fa-xmark me-1"></i>Reject Request</button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php /* ═══════════════ EDIT SCREEN ═══════════════ */
elseif ($screen === 'edit'): ?>
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card page-card">
            <div class="card-header bg-white py-3 d-flex align-items-center gap-2">
                <a href="?view=<?= (int)$current['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left"></i></a>
                <h5 class="mb-0 fw-semibold">Edit Leave Request #<?= (int)$current['id'] ?></h5>
                <span class="ms-2 badge bg-info">Submitted</span>
                <span class="badge bg-<?= $statusBadge[$current['admin_approval_status']] ?? 'secondary' ?>">Admin: <?= ucfirst($current['admin_approval_status']) ?></span>
            </div>
            <div class="card-body">
                <div class="alert alert-light border d-flex align-items-start gap-2 mb-4"><i class="fa fa-circle-info mt-1 text-primary"></i>
                    <div>You can edit the request details only. The <strong>Admin Approval status</strong> is not changed by editing — only an admin can approve or reject it.</div>
                </div>
                <?php if ($current['admin_approval_status'] === 'approved'): ?>
                <div class="alert alert-warning d-flex align-items-start gap-2 mb-4"><i class="fa fa-triangle-exclamation mt-1"></i>
                    <div><strong>This request is already Approved.</strong><br>Editing the dates re-syncs the leave balance and the <em>On Leave</em> attendance. The approval stays <strong>Approved</strong>.</div>
                </div>
                <?php endif; ?>
                <form method="POST" enctype="multipart/form-data">
                    <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int)$current['id'] ?>">
                    <div class="mb-3"><label class="form-label fw-semibold">Employee <span class="text-danger">*</span></label>
                        <?php if (is_self_scoped()): ?>
                        <input type="text" class="form-control" value="<?= h($current['emp_name'] . ' (' . $current['emp_code'] . ')') ?>" readonly>
                        <?php else: ?>
                        <select name="employee_id" class="form-select" required>
                            <?php foreach ($employees as $e): ?><option value="<?= $e['id'] ?>" <?= (int)$current['employee_id']===(int)$e['id']?'selected':'' ?>><?= h($e['name']) ?> (<?= h($e['employee_id']) ?>)</option><?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                    </div>
                    <div class="mb-3"><label class="form-label fw-semibold">Leave Type <span class="text-danger">*</span></label>
                        <select name="leave_type_id" class="form-select" required>
                            <?php foreach ($leaveTypes as $lt): ?><option value="<?= $lt['id'] ?>" <?= (int)$current['leave_type_id']===(int)$lt['id']?'selected':'' ?>><?= h($lt['name']) ?> (<?= $lt['is_paid']?'Paid':'Unpaid' ?>, <?= (int)$lt['days_allowed'] ?> days/yr)</option><?php endforeach; ?>
                        </select></div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6"><label class="form-label fw-semibold">Start Date <span class="text-danger">*</span></label><input type="date" name="start_date" class="form-control" value="<?= h($current['start_date']) ?>" required></div>
                        <div class="col-md-6"><label class="form-label fw-semibold">End Date <span class="text-danger">*</span></label><input type="date" name="end_date" class="form-control" value="<?= h($current['end_date']) ?>" required></div>
                    </div>
                    <div class="mb-3"><label class="form-label fw-semibold">Reason</label><textarea name="reason" class="form-control" rows="3" maxlength="500"><?= h($current['reason'] ?? '') ?></textarea></div>
                    <div class="mb-3"><label class="form-label fw-semibold">Supporting Document <span class="text-muted fw-normal">(Optional)</span></label>
                        <?php if ($current['document']): ?>
                        <div class="d-flex align-items-center gap-2 mb-2 p-2 border rounded bg-light">
                            <i class="fa fa-paperclip text-primary"></i>
                            <a href="<?= BASE_URL ?>/uploads/leave_docs/<?= h($current['document']) ?>" target="_blank" class="text-primary text-decoration-none fw-semibold small"><?= h($current['document']) ?></a>
                            <span class="ms-auto"><label class="form-check-label small text-danger d-flex align-items-center gap-1 mb-0" style="cursor:pointer"><input type="checkbox" name="remove_document" value="1" class="form-check-input mt-0">Remove file</label></span>
                        </div>
                        <div class="form-text mb-1">Upload a new file below to replace the existing one.</div>
                        <?php endif; ?>
                        <input type="file" name="document" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                        <div class="form-text"><i class="fa fa-info-circle me-1 text-primary"></i>Accepted: PDF, JPG, PNG, DOC, DOCX &nbsp;·&nbsp; Max size: 5 MB</div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Save Changes</button>
                        <a href="?view=<?= (int)$current['id'] ?>" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card page-card">
            <div class="card-header bg-white py-3"><h6 class="mb-0 fw-semibold"><i class="fa fa-circle-info me-2 text-primary"></i>Current Details</h6></div>
            <div class="card-body small">
                <table class="table table-sm table-borderless mb-0">
                    <tr><th class="text-muted">Employee</th><td><?= h($current['emp_name']) ?></td></tr>
                    <tr><th class="text-muted">Leave Type</th><td><?= h($current['type_name']) ?></td></tr>
                    <tr><th class="text-muted">Period</th><td><?= $period($current['start_date'], $current['end_date']) ?></td></tr>
                    <tr><th class="text-muted">Days</th><td><strong><?= $fmtDays($current['days_requested']) ?></strong></td></tr>
                    <tr><th class="text-muted">Request Status</th><td><span class="badge bg-info">Submitted</span></td></tr>
                    <tr><th class="text-muted">Admin Approval</th><td><span class="badge bg-<?= $statusBadge[$current['admin_approval_status']] ?? 'secondary' ?>"><?= ucfirst($current['admin_approval_status']) ?></span></td></tr>
                </table>
            </div>
        </div>
        <div class="card page-card mt-3 border-info">
            <div class="card-body small text-muted"><i class="fa fa-circle-info text-info me-1"></i>Editing changes the request details only. Approval is handled separately by an admin.</div>
        </div>
    </div>
</div>
<?php
/* ═══════════════ LIST SCREEN ═══════════════ */
else:
    // Only PENDING requests live here. Once approved/rejected, a request leaves
    // this list and is visible on the Leave History page instead.
    $rowsSql = "SELECT lr.*, e.name AS emp_name, e.employee_id AS emp_code, lt.name AS type_name
                FROM leave_requests lr JOIN employees e ON e.id=lr.employee_id JOIN leave_types lt ON lt.id=lr.leave_type_id";
    if ($isEmployee) { $rs = $db->prepare($rowsSql . " WHERE lr.employee_id=? AND lr.admin_approval_status='pending' ORDER BY lr.created_at DESC, lr.id DESC"); $rs->execute([$selfEmpId]); $rows = $rs->fetchAll(); }
    else             { $rows = $db->query($rowsSql . " WHERE lr.admin_approval_status='pending' ORDER BY lr.created_at DESC, lr.id DESC")->fetchAll(); }
?>
<div class="card page-card">
    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <h5 class="mb-0 fw-semibold"><i class="fa fa-calendar-xmark me-2 text-primary"></i>Leave Requests</h5>
        <div class="d-flex gap-2">
            <a href="<?= BASE_URL ?>/modules/attendance/leave_history.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-clock-rotate-left me-1"></i>Leave History</a>
            <?php if ($canCreate): ?><a href="?new=1" class="btn btn-sm btn-primary"><i class="fa fa-plus me-1"></i>New Request</a><?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table id="leaveTable" class="table table-hover table-bordered align-middle mb-0">
                <thead class="table-dark"><tr>
                    <th>Employee</th><th>Leave Type</th><th>Period</th><th class="text-center">Days</th><th class="text-center">Status</th><th class="text-center">Admin Approval</th><th class="text-center" style="width:150px">Action</th>
                </tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><div class="fw-semibold"><?= h($r['emp_name']) ?></div><small class="text-muted"><?= h($r['emp_code']) ?></small></td>
                        <td><span class="badge bg-primary bg-opacity-75"><?= h($r['type_name']) ?></span></td>
                        <td><?= $period($r['start_date'], $r['end_date']) ?></td>
                        <td class="text-center"><span class="badge bg-secondary"><?= $fmtDays($r['days_requested']) ?>d</span></td>
                        <td class="text-center"><span class="badge bg-info">Submitted</span></td>
                        <td class="text-center"><span class="badge bg-<?= $statusBadge[$r['admin_approval_status'] ?? 'pending'] ?? 'secondary' ?>"><?= ucfirst($r['admin_approval_status'] ?? 'pending') ?></span></td>
                        <td class="text-center text-nowrap">
                            <a href="?view=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary" title="View"><i class="fa fa-eye"></i></a>
                            <?php if ($canEdit): ?><a href="?edit=<?= $r['id'] ?>" class="btn btn-sm btn-outline-warning" title="Edit"><i class="fa fa-pen"></i></a><?php endif; ?>
                            <?php if ($canDelete): ?>
                            <form method="POST" class="d-inline leave-delete-form">
                                <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $r['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa fa-trash"></i></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$rows): ?>
                    <tr><td colspan="7" class="text-center text-muted py-5">
                        <i class="fa fa-calendar-check fa-2x mb-2 d-block text-secondary"></i>
                        No pending leave requests. Approved &amp; rejected requests appear in
                        <a href="<?= BASE_URL ?>/modules/attendance/leave_history.php">Leave History</a>.
                    </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>window.LEAVE_HAS_ROWS = <?= $rows ? 'true' : 'false' ?>;</script>
<?php $page_scripts = <<<'JS'
<script>
$(function () {
    // Skip DataTables on the empty-state row (its colspan would trigger a column-count warning).
    if (window.LEAVE_HAS_ROWS && $.fn.DataTable) $('#leaveTable').DataTable({ pageLength: 25, order: [[3,'desc']], columnDefs: [{ orderable:false, targets:[2,4,5,6] }], language: { search:'', searchPlaceholder:'Search…' } });
    $('#leaveTable').on('submit', '.leave-delete-form', function (e) {
        if (!confirm('Delete this leave request?\nApproved requests will have their balance and attendance automatically reversed.')) e.preventDefault();
    });
});
</script>
JS;
endif; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
