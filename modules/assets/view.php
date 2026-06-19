<?php
/**
 * Asset detail — information, current assignment, process return, reassign,
 * and assignment history.
 *
 * POST actions (handled before any output, PRG pattern):
 *   action=return    → close the active assignment, set asset Available
 *   action=reassign  → change the assigned employee / issued date of the
 *                      active assignment
 * Assigning an Available asset is handled by assign.php.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('assets', 'view');

$db = db();
$id = (int)($_GET['id'] ?? $_POST['asset_id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/assets/index.php');

// ── POST: process return / reassign ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf($_POST['csrf_token'] ?? '');
    $action = sanitize($_POST['action'] ?? '');
    $back   = BASE_URL . '/modules/assets/view.php?id=' . $id;

    // Current active assignment (if any).
    $act = $db->prepare('SELECT id FROM asset_assignments WHERE asset_id = ? AND is_returned = 0 ORDER BY id DESC LIMIT 1');
    $act->execute([$id]);
    $activeId = (int)$act->fetchColumn();

    // Self-scoped employees may only act on an asset currently assigned to them.
    if (is_self_scoped()) {
        $own = $db->prepare('SELECT 1 FROM asset_assignments WHERE asset_id = ? AND is_returned = 0 AND employee_id = ? LIMIT 1');
        $own->execute([$id, current_employee_id()]);
        if (!$own->fetchColumn()) { http_response_code(403); exit('Forbidden'); }
    }

    if ($action === 'return') {
        require_permission('assets', 'return');
        if (!$activeId) {
            flash('error', 'This asset has no active assignment to return.');
            redirect($back);
        }
        $retDate   = $_POST['returned_date'] ?: date('Y-m-d');
        $condRet   = in_array($_POST['condition_at_return'] ?? '', ['New','Good','Fair','Poor'], true)
                     ? $_POST['condition_at_return'] : 'Good';
        $retNotes  = sanitize($_POST['return_notes'] ?? '');

        $db->prepare('UPDATE asset_assignments
                      SET returned_date = ?, condition_at_return = ?, is_returned = 1,
                          notes = TRIM(CONCAT(COALESCE(notes, \'\'), ?))
                      WHERE id = ?')
           ->execute([$retDate, $condRet, ($retNotes ? "\nReturn: " . $retNotes : ''), $activeId]);

        // Free the asset and reflect the returned condition.
        $db->prepare("UPDATE assets SET status = 'Available', `condition` = ? WHERE id = ?")
           ->execute([$condRet, $id]);

        flash('success', 'Asset returned and marked Available.');
        redirect(BASE_URL . '/modules/assets/index.php');
    }

    if ($action === 'reassign') {
        require_permission('assets', 'assign');
        if (!$activeId) {
            flash('error', 'This asset has no active assignment to edit.');
            redirect($back);
        }
        $empId = (int)($_POST['employee_id'] ?? 0);
        $date  = $_POST['assigned_date'] ?: date('Y-m-d');
        if (!$empId) {
            flash('error', 'Please select an employee.');
            redirect($back);
        }
        $db->prepare('UPDATE asset_assignments SET employee_id = ?, assigned_date = ? WHERE id = ?')
           ->execute([$empId, $date, $activeId]);
        flash('success', 'Assignment updated.');
        redirect($back);
    }

    flash('error', 'Unknown action.');
    redirect($back);
}

// ── Load asset (live schema columns) ─────────────────────────────────────────
$stmt = $db->prepare(
    'SELECT a.*, c.name AS cat_name
     FROM assets a
     LEFT JOIN asset_categories c ON c.id = a.category_id
     WHERE a.id = ?'
);
$stmt->execute([$id]);
$asset = $stmt->fetch();
if (!$asset) redirect(BASE_URL . '/modules/assets/index.php');

// Current active assignment.
$cur = $db->prepare(
    'SELECT aa.*, e.name AS emp_name, e.employee_id AS emp_code
     FROM asset_assignments aa
     JOIN employees e ON e.id = aa.employee_id
     WHERE aa.asset_id = ? AND aa.is_returned = 0
     ORDER BY aa.id DESC LIMIT 1'
);
$cur->execute([$id]);
$current = $cur->fetch();

// Self-scoped employees may only view an asset currently assigned to them.
if (is_self_scoped() && (!$current || (int)$current['employee_id'] !== current_employee_id())) {
    http_response_code(403);
    include BASE_PATH . '/includes/403.php';
    exit;
}

// Assignment history (all rows).
$hist = $db->prepare(
    'SELECT aa.*, e.name AS emp_name, e.employee_id AS emp_code, u.name AS assigned_by_name
     FROM asset_assignments aa
     JOIN employees e ON e.id = aa.employee_id
     LEFT JOIN users u ON u.id = aa.assigned_by
     WHERE aa.asset_id = ?
     ORDER BY aa.assigned_date DESC, aa.id DESC'
);
$hist->execute([$id]);
$history = $hist->fetchAll();

// Active employees for assign / reassign dropdowns.
$employees = $db->query(
    "SELECT id, CONCAT(name, ' (', employee_id, ')') AS label
     FROM employees WHERE status = 'Active' ORDER BY name"
)->fetchAll();

$statusBadge = ['Available'=>'success','Assigned'=>'primary','Under Repair'=>'warning','Retired'=>'secondary'][$asset['status']] ?? 'light';
$canAssign = can('assets', 'assign');
$canReturn = can('assets', 'return');

$page_title = 'Asset Details';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h4 class="mb-0 fw-semibold"><?= h($asset['name']) ?></h4>
        <p class="text-muted small mb-0">
            <code class="text-primary"><?= h($asset['asset_code']) ?></code>
            <?= $asset['serial_no'] ? ' · SN ' . h($asset['serial_no']) : '' ?>
        </p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($canAssign && $asset['status'] === 'Available'): ?>
            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#assignModal">
                <i class="fa fa-user-plus me-1"></i> Assign
            </button>
        <?php endif; ?>
        <?php if ($current): ?>
            <?php if ($canReturn): ?>
            <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#returnModal">
                <i class="fa fa-rotate-left me-1"></i> Process Return
            </button>
            <?php endif; ?>
            <?php if ($canAssign): ?>
            <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#reassignModal">
                <i class="fa fa-user-pen me-1"></i> Edit Assignment
            </button>
            <?php endif; ?>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/modules/assets/index.php" class="btn btn-outline-secondary">
            <i class="fa fa-arrow-left me-1"></i> Back
        </a>
    </div>
</div>

<?php render_flash(); ?>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3"><h6 class="mb-0 fw-semibold">Asset Information</h6></div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><td class="text-muted">Asset Name</td><td class="fw-semibold"><?= h($asset['name']) ?></td></tr>
                    <tr><td class="text-muted">Category</td><td><?= h($asset['cat_name'] ?? '—') ?></td></tr>
                    <tr><td class="text-muted">Asset Code</td><td><?= h($asset['asset_code']) ?></td></tr>
                    <tr><td class="text-muted">Serial No</td><td><?= h($asset['serial_no'] ?: '—') ?></td></tr>
                    <tr><td class="text-muted">Brand / Model</td><td><?= h(trim(($asset['brand'] ?? '') . ' ' . ($asset['model'] ?? '')) ?: '—') ?></td></tr>
                    <tr><td class="text-muted">Purchase Date</td><td><?= $asset['purchase_date'] ? date_fmt($asset['purchase_date']) : '—' ?></td></tr>
                    <tr><td class="text-muted">Purchase Cost</td><td><?= $asset['purchase_cost'] ? money($asset['purchase_cost']) : '—' ?></td></tr>
                    <tr><td class="text-muted">Condition</td><td><?= h($asset['condition'] ?? '—') ?></td></tr>
                    <tr><td class="text-muted">Status</td><td><span class="badge bg-<?= $statusBadge ?>"><?= h($asset['status']) ?></span></td></tr>
                    <?php if (!empty($asset['notes'])): ?>
                    <tr><td class="text-muted">Notes</td><td><?= h($asset['notes']) ?></td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <?php if ($current): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3"><h6 class="mb-0 fw-semibold">Currently Assigned To</h6></div>
            <div class="card-body">
                <div class="fw-semibold"><?= h($current['emp_name']) ?></div>
                <div class="text-muted small mb-2"><?= h($current['emp_code']) ?></div>
                <div class="small text-muted">Issued on <?= date_fmt($current['assigned_date']) ?></div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3"><h6 class="mb-0 fw-semibold">Assignment History</h6></div>
            <div class="card-body">
                <?php if ($history): ?>
                <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr><th>Employee</th><th>Issued Date</th><th>Return Date</th><th>Condition on Return</th><th>By</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $hrow): ?>
                        <tr>
                            <td><span class="fw-semibold"><?= h($hrow['emp_code']) ?></span><br><small class="text-muted"><?= h($hrow['emp_name']) ?></small></td>
                            <td><?= date_fmt($hrow['assigned_date']) ?></td>
                            <td><?= $hrow['returned_date'] ? date_fmt($hrow['returned_date']) : '<span class="badge bg-primary">Active</span>' ?></td>
                            <td><?= h($hrow['condition_at_return'] ?? '—') ?></td>
                            <td><?= h($hrow['assigned_by_name'] ?? '—') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php else: ?>
                    <p class="text-muted mb-0">No assignment history.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($canAssign && $asset['status'] === 'Available'): ?>
<!-- Assign modal -->
<div class="modal fade" id="assignModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" action="<?= BASE_URL ?>/modules/assets/assign.php">
            <?= csrf_field() ?>
            <input type="hidden" name="asset_id" value="<?= $id ?>">
            <div class="modal-header"><h5 class="modal-title">Assign Asset</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Assign To Employee <span class="text-danger">*</span></label>
                    <select name="employee_id" class="form-select" required>
                        <option value="">Select employee</option>
                        <?php foreach ($employees as $e): ?>
                        <option value="<?= $e['id'] ?>"><?= h($e['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Issued Date <span class="text-danger">*</span></label>
                    <input type="date" name="assigned_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="mb-1">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Assign Asset</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($current && $canReturn): ?>
<!-- Return modal -->
<div class="modal fade" id="returnModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" action="<?= BASE_URL ?>/modules/assets/view.php?id=<?= $id ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="return">
            <input type="hidden" name="asset_id" value="<?= $id ?>">
            <div class="modal-header"><h5 class="modal-title">Process Return</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <p class="text-muted small">Returning from <strong><?= h($current['emp_name']) ?></strong> (issued <?= date_fmt($current['assigned_date']) ?>).</p>
                <div class="mb-3">
                    <label class="form-label">Return Date <span class="text-danger">*</span></label>
                    <input type="date" name="returned_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Condition on Return</label>
                    <select name="condition_at_return" class="form-select">
                        <?php foreach (['New','Good','Fair','Poor'] as $c): ?>
                        <option value="<?= $c ?>" <?= ($asset['condition'] ?? 'Good') === $c ? 'selected' : '' ?>><?= $c ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-1">
                    <label class="form-label">Notes</label>
                    <textarea name="return_notes" class="form-control" rows="2" placeholder="Any remarks about the returned asset…"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-warning">Confirm Return</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($current && $canAssign): ?>
<!-- Reassign / edit assignment modal -->
<div class="modal fade" id="reassignModal" tabindex="-1">
    <div class="modal-dialog">
        <form class="modal-content" method="POST" action="<?= BASE_URL ?>/modules/assets/view.php?id=<?= $id ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reassign">
            <input type="hidden" name="asset_id" value="<?= $id ?>">
            <div class="modal-header"><h5 class="modal-title">Edit Assignment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Assigned To Employee <span class="text-danger">*</span></label>
                    <select name="employee_id" class="form-select" required>
                        <?php foreach ($employees as $e): ?>
                        <option value="<?= $e['id'] ?>" <?= (int)$current['employee_id'] === (int)$e['id'] ? 'selected' : '' ?>>
                            <?= h($e['label']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-1">
                    <label class="form-label">Issued Date <span class="text-danger">*</span></label>
                    <input type="date" name="assigned_date" class="form-control" value="<?= h($current['assigned_date']) ?>" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
