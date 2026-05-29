<?php
require_once '../../includes/bootstrap.php';
require_login();
require_permission('attendance', 'view');

$user = current_user();
$isEmployee = ($user['role'] === 'Employee');

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf($_POST['csrf_token'] ?? '');
    $rid    = (int)$_POST['request_id'];
    $action = $_POST['action'];
    if (in_array($action, ['Approved','Rejected']) && can('attendance', 'edit')) {
        db()->prepare("UPDATE comp_off_requests SET status=:s, reviewed_by=:rb, reviewed_at=NOW() WHERE id=:id")
             ->execute([':s'=>$action,':rb'=>$user['id'],':id'=>$rid]);
        flash('success',"Comp Off request $action.");
    }
    redirect(BASE_URL . '/modules/attendance/comp_off.php');
}

// Handle new request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_request'])) {
    verify_csrf($_POST['csrf_token'] ?? '');
    $empId   = $isEmployee ? $user['employee_id'] : (int)$_POST['employee_id'];
    $wDate   = $_POST['worked_date'] ?? '';
    $coDate  = $_POST['comp_off_date'] ?? '';
    $reason  = trim($_POST['reason'] ?? '');
    if ($empId && $wDate && $coDate) {
        db()->prepare("INSERT INTO comp_off_requests (employee_id,worked_date,comp_off_date,reason,status,requested_at) VALUES (:eid,:wd,:cod,:r,'Pending',NOW())")
             ->execute([':eid'=>$empId,':wd'=>$wDate,':cod'=>$coDate,':r'=>$reason]);
        flash('success','Comp Off request submitted.');
    }
    redirect(BASE_URL . '/modules/attendance/comp_off.php');
}

// Fetch requests
$where = $isEmployee ? "WHERE cr.employee_id = {$user['employee_id']}" : '';
$requests = db()->query("SELECT cr.*, e.name AS emp_name, e.employee_id AS emp_code,
    u.name AS reviewer_name
    FROM comp_off_requests cr
    JOIN employees e ON cr.employee_id = e.id
    LEFT JOIN users u ON cr.reviewed_by = u.id
    $where
    ORDER BY cr.requested_at DESC")->fetchAll(PDO::FETCH_ASSOC);

$employees = $isEmployee ? [] : db()->query("SELECT id, CONCAT(name,' (',employee_id,')') AS label FROM employees WHERE status='Active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Comp Off Requests';
include '../../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Comp Off Requests</h1>
        <p class="page-subtitle">Manage compensatory off requests</p>
    </div>
    <div class="page-actions">
        <button class="btn btn-primary" onclick="openModal('newCompOffModal')" data-key="N"><u>N</u>ew Request</button>
        <a href="index.php" class="btn btn-secondary" data-key="B"><u>B</u>ack</a>
    </div>
</div>

<?php render_flash(); ?>

<div class="card">
    <div class="card-body">
        <table class="table datatable">
            <thead>
                <tr>
                    <th>#</th>
                    <?php if (!$isEmployee): ?><th>Employee</th><?php endif; ?>
                    <th>Worked Date</th>
                    <th>Comp Off Date</th>
                    <th>Reason</th>
                    <th>Requested</th>
                    <th>Status</th>
                    <th>Reviewed By</th>
                    <?php if (can('attendance', 'edit') && !$isEmployee): ?><th>Action</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $r): ?>
                <tr>
                    <td><?= $r['id'] ?></td>
                    <?php if (!$isEmployee): ?><td><strong><?= h($r['emp_code']) ?></strong><br><small><?= h($r['emp_name']) ?></small></td><?php endif; ?>
                    <td><?= date_fmt($r['worked_date']) ?></td>
                    <td><?= date_fmt($r['comp_off_date']) ?></td>
                    <td><?= h($r['reason']) ?></td>
                    <td><?= date_fmt($r['requested_at'], 'd M Y H:i') ?></td>
                    <td>
                        <?php
                        $cls = ['Pending'=>'pill-warn','Approved'=>'pill-success','Rejected'=>'pill-danger'];
                        echo '<span class="pill ' . ($cls[$r['status']]??'') . '">' . $r['status'] . '</span>';
                        ?>
                    </td>
                    <td><?= h($r['reviewer_name'] ?? '—') ?></td>
                    <?php if (can('attendance', 'edit') && !$isEmployee): ?>
                    <td>
                        <?php if ($r['status'] === 'Pending'): ?>
                        <form method="POST" class="d-inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="request_id" value="<?= $r['id'] ?>">
                            <button name="action" value="Approved" class="btn btn-xs btn-success">Approve</button>
                            <button name="action" value="Rejected" class="btn btn-xs btn-danger">Reject</button>
                        </form>
                        <?php else: echo '—'; endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- New Comp Off Modal -->
<div class="modal-overlay" id="newCompOffModal" style="display:none">
    <div class="modal-box">
        <div class="modal-header">
            <h3>New Comp Off Request</h3>
            <button class="modal-close" onclick="closeModal('newCompOffModal')">&times;</button>
        </div>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="submit_request" value="1">
            <div class="modal-body">
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
                <div class="form-group">
                    <label class="form-label">Worked Date (Extra day)</label>
                    <input type="date" name="worked_date" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Comp Off Date (To be availed)</label>
                    <input type="date" name="comp_off_date" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Reason</label>
                    <textarea name="reason" class="form-control" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary">Submit Request</button>
                <button type="button" class="btn btn-secondary" onclick="closeModal('newCompOffModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
addLocalShortcut('n', () => openModal('newCompOffModal'));
addLocalShortcut('b', () => location.href = 'index.php');
</script>
<?php include '../../includes/footer.php'; ?>
