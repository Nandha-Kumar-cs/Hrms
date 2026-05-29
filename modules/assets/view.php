<?php
require_once '../../includes/bootstrap.php';
require_login();
require_permission('assets', 'view');

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/assets/index.php');

$asset = db()->query("SELECT a.*, ac.name AS category_name,
    e.name AS emp_name, e.employee_id AS emp_code
    FROM assets a
    LEFT JOIN asset_categories ac ON a.category_id = ac.id
    LEFT JOIN employees e ON a.assigned_to = e.id
    WHERE a.id=$id")->fetch(PDO::FETCH_ASSOC);

if (!$asset) redirect(BASE_URL . '/modules/assets/index.php');

$assignHistory = db()->query("SELECT aa.*, e.name AS emp_name, e.employee_id AS emp_code,
    u.name AS assigned_by_name
    FROM asset_assignments aa
    JOIN employees e ON aa.employee_id = e.id
    LEFT JOIN users u ON aa.assigned_by = u.id
    WHERE aa.asset_id=$id ORDER BY aa.assigned_date DESC")->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Asset Details';
include '../../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Asset Details</h1>
        <p class="page-subtitle"><?= h($asset['asset_name']) ?> &mdash; <?= h($asset['serial_number']) ?></p>
    </div>
    <div class="page-actions">
        <?php if (can('assets', 'edit') && $asset['status'] === 'Available'): ?>
            <button class="btn btn-primary" onclick="openModal('assignModal')" data-key="A"><u>A</u>ssign</button>
        <?php endif; ?>
        <a href="index.php" class="btn btn-secondary" data-key="B"><u>B</u>ack</a>
    </div>
</div>

<?php render_flash(); ?>

<div class="row">
    <div class="col-5">
        <div class="card mb-4">
            <div class="card-header"><h3 class="card-title">Asset Information</h3></div>
            <div class="card-body">
                <table class="table table-sm">
                    <tr><td class="text-muted">Asset Name</td><td><strong><?= h($asset['asset_name']) ?></strong></td></tr>
                    <tr><td class="text-muted">Category</td><td><?= h($asset['category_name']) ?></td></tr>
                    <tr><td class="text-muted">Serial Number</td><td><?= h($asset['serial_number']) ?></td></tr>
                    <tr><td class="text-muted">Asset Tag</td><td><?= h($asset['asset_tag']) ?></td></tr>
                    <tr><td class="text-muted">Purchase Date</td><td><?= date_fmt($asset['purchase_date']) ?></td></tr>
                    <tr><td class="text-muted">Purchase Cost</td><td><?= $asset['purchase_cost'] ? money($asset['purchase_cost']) : '—' ?></td></tr>
                    <tr><td class="text-muted">Warranty Until</td><td><?= $asset['warranty_until'] ? date_fmt($asset['warranty_until']) : '—' ?></td></tr>
                    <tr><td class="text-muted">Condition</td><td><?= h($asset['condition_status']) ?></td></tr>
                    <tr>
                        <td class="text-muted">Status</td>
                        <td>
                            <?php
                            $cls = ['Available'=>'pill-success','Assigned'=>'pill-primary','Under Repair'=>'pill-warn','Retired'=>'pill-danger'];
                            echo '<span class="pill ' . ($cls[$asset['status']]??'') . '">' . $asset['status'] . '</span>';
                            ?>
                        </td>
                    </tr>
                    <?php if ($asset['location']): ?><tr><td class="text-muted">Location</td><td><?= h($asset['location']) ?></td></tr><?php endif; ?>
                    <?php if ($asset['notes']): ?><tr><td class="text-muted">Notes</td><td><?= h($asset['notes']) ?></td></tr><?php endif; ?>
                </table>
            </div>
        </div>

        <?php if ($asset['assigned_to']): ?>
        <div class="card">
            <div class="card-header"><h3 class="card-title">Currently Assigned To</h3></div>
            <div class="card-body">
                <div class="d-flex align-items-center gap-3">
                    <div class="emp-avatar"><?= strtoupper(substr($asset['emp_name'],0,2)) ?></div>
                    <div>
                        <div><strong><?= h($asset['emp_name']) ?></strong></div>
                        <div class="text-muted"><?= h($asset['emp_code']) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-7">
        <div class="card">
            <div class="card-header"><h3 class="card-title">Assignment History</h3></div>
            <div class="card-body">
                <?php if ($assignHistory): ?>
                <table class="table datatable">
                    <thead>
                        <tr><th>Employee</th><th>Assigned Date</th><th>Return Date</th><th>Condition on Return</th><th>By</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assignHistory as $h): ?>
                        <tr>
                            <td><strong><?= h($h['emp_code']) ?></strong><br><small><?= h($h['emp_name']) ?></small></td>
                            <td><?= date_fmt($h['assigned_date']) ?></td>
                            <td><?= $h['return_date'] ? date_fmt($h['return_date']) : '<span class="pill pill-primary">Active</span>' ?></td>
                            <td><?= h($h['condition_on_return'] ?? '—') ?></td>
                            <td><?= h($h['assigned_by_name'] ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                    <p class="text-muted">No assignment history.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Assign Modal -->
<?php if (can('assets', 'edit')): ?>
<div class="modal-overlay" id="assignModal" style="display:none">
    <div class="modal-box">
        <div class="modal-header">
            <h3>Assign Asset</h3>
            <button class="modal-close" onclick="closeModal('assignModal')">&times;</button>
        </div>
        <form action="assign.php" method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="asset_id" value="<?= $id ?>">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Assign To Employee</label>
                    <select name="employee_id" class="form-control" required>
                        <option value="">Select Employee</option>
                        <?php
                        $emps = db()->query("SELECT id, CONCAT(name,' (',employee_id,')') AS label FROM employees WHERE status='Active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($emps as $e): ?>
                            <option value="<?= $e['id'] ?>"><?= h($e['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Assignment Date</label>
                    <input type="date" name="assigned_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="form-group">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-primary">Assign Asset</button>
                <button type="button" class="btn btn-secondary" onclick="closeModal('assignModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
addLocalShortcut('a', () => openModal('assignModal'));
addLocalShortcut('b', () => location.href='index.php');
</script>
<?php include '../../includes/footer.php'; ?>
