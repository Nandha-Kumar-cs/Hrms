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
        db()->prepare("UPDATE od_requests SET status=:s, reviewed_by=:rb, reviewed_at=NOW() WHERE id=:id")
             ->execute([':s'=>$action,':rb'=>$user['id'],':id'=>$rid]);
        // If approved, update attendance for those dates
        if ($action === 'Approved') {
            $od = db()->query("SELECT * FROM od_requests WHERE id=$rid")->fetch(PDO::FETCH_ASSOC);
            if ($od) {
                $start = new DateTime($od['from_date']);
                $end   = new DateTime($od['to_date']);
                for ($d = clone $start; $d <= $end; $d->modify('+1 day')) {
                    $dateStr = $d->format('Y-m-d');
                    // Upsert attendance
                    $exists = db()->query("SELECT id FROM attendance WHERE employee_id={$od['employee_id']} AND date='$dateStr'")->fetchColumn();
                    if ($exists) {
                        db()->query("UPDATE attendance SET status='On Duty',remarks='OD Approved' WHERE id=$exists");
                    } else {
                        db()->prepare("INSERT INTO attendance (employee_id,date,status,remarks,created_at) VALUES (:eid,:d,'On Duty','OD Approved',NOW())")
                             ->execute([':eid'=>$od['employee_id'],':d'=>$dateStr]);
                    }
                }
            }
        }
        flash('success',"OD request $action.");
    }
    redirect(BASE_URL . '/modules/attendance/od_requests.php');
}

// Handle new request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_request'])) {
    verify_csrf($_POST['csrf_token'] ?? '');
    $empId  = $isEmployee ? $user['employee_id'] : (int)$_POST['employee_id'];
    $from   = $_POST['from_date'] ?? '';
    $to     = $_POST['to_date'] ?? '';
    $place  = trim($_POST['place'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    if ($empId && $from && $to) {
        db()->prepare("INSERT INTO od_requests (employee_id,from_date,to_date,place,reason,status,requested_at) VALUES (:eid,:fd,:td,:pl,:r,'Pending',NOW())")
             ->execute([':eid'=>$empId,':fd'=>$from,':td'=>$to,':pl'=>$place,':r'=>$reason]);
        flash('success','OD request submitted.');
    }
    redirect(BASE_URL . '/modules/attendance/od_requests.php');
}

$where = $isEmployee ? "WHERE od.employee_id = {$user['employee_id']}" : '';
$requests = db()->query("SELECT od.*, e.name AS emp_name, e.employee_id AS emp_code,
    u.name AS reviewer_name
    FROM od_requests od
    JOIN employees e ON od.employee_id = e.id
    LEFT JOIN users u ON od.reviewed_by = u.id
    $where
    ORDER BY od.requested_at DESC")->fetchAll(PDO::FETCH_ASSOC);

$employees = $isEmployee ? [] : db()->query("SELECT id, CONCAT(name,' (',employee_id,')') AS label FROM employees WHERE status='Active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'On Duty Requests';
include '../../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title">On Duty (OD) Requests</h1>
        <p class="page-subtitle">Manage on-duty travel and field work requests</p>
    </div>
    <div class="page-actions">
        <button class="btn btn-primary" onclick="openModal('newODModal')" data-key="N"><u>N</u>ew OD Request</button>
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
                    <th>From</th>
                    <th>To</th>
                    <th>Days</th>
                    <th>Place</th>
                    <th>Reason</th>
                    <th>Requested</th>
                    <th>Status</th>
                    <?php if (can('attendance', 'edit') && !$isEmployee): ?><th>Action</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $r):
                    $days = (new DateTime($r['from_date']))->diff(new DateTime($r['to_date']))->days + 1;
                ?>
                <tr>
                    <td><?= $r['id'] ?></td>
                    <?php if (!$isEmployee): ?><td><strong><?= h($r['emp_code']) ?></strong><br><small><?= h($r['emp_name']) ?></small></td><?php endif; ?>
                    <td><?= date_fmt($r['from_date']) ?></td>
                    <td><?= date_fmt($r['to_date']) ?></td>
                    <td><?= $days ?></td>
                    <td><?= h($r['place']) ?></td>
                    <td><?= h($r['reason']) ?></td>
                    <td><?= date_fmt($r['requested_at'], 'd M Y') ?></td>
                    <td>
                        <?php
                        $cls = ['Pending'=>'pill-warn','Approved'=>'pill-success','Rejected'=>'pill-danger'];
                        echo '<span class="pill ' . ($cls[$r['status']]??'') . '">' . $r['status'] . '</span>';
                        ?>
                    </td>
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

<!-- New OD Modal -->
<div class="modal-overlay" id="newODModal" style="display:none">
    <div class="modal-box">
        <div class="modal-header">
            <h3>New OD Request</h3>
            <button class="modal-close" onclick="closeModal('newODModal')">&times;</button>
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
                <div class="form-row">
                    <div class="form-group col-6">
                        <label class="form-label">From Date</label>
                        <input type="date" name="from_date" class="form-control" required>
                    </div>
                    <div class="form-group col-6">
                        <label class="form-label">To Date</label>
                        <input type="date" name="to_date" class="form-control" required>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Place / Location</label>
                    <input type="text" name="place" class="form-control" placeholder="City / Client location">
                </div>
                <div class="form-group">
                    <label class="form-label">Purpose / Reason</label>
                    <textarea name="reason" class="form-control" rows="3" required></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary">Submit OD Request</button>
                <button type="button" class="btn btn-secondary" onclick="closeModal('newODModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
addLocalShortcut('n', () => openModal('newODModal'));
addLocalShortcut('b', () => location.href = 'index.php');
</script>
<?php include '../../includes/footer.php'; ?>
