<?php
/**
 * MagDyn HRMS — Assign Asset to Employee
 * GET  : render the assignment form for the given asset.
 * POST : validate CSRF, mark the asset assigned and record the assignment.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('assets', 'assign');

$db = db();

// ── Handle assignment submission (POST) ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf($_POST['csrf_token'] ?? '');

    $assetId = (int)($_POST['asset_id'] ?? 0);
    $empId   = (int)($_POST['employee_id'] ?? 0);
    $date    = $_POST['assigned_date'] ?? date('Y-m-d');
    $notes   = trim($_POST['notes'] ?? '');

    if (!$assetId || !$empId) {
        flash('danger', 'Please select an employee.');
        redirect(BASE_URL . "/modules/assets/assign.php?asset_id=$assetId");
    }

    // Mark the asset as assigned. The employee link lives in asset_assignments.
    $db->prepare("UPDATE assets SET status='Assigned' WHERE id=:aid")
       ->execute([':aid' => $assetId]);

    // Record the assignment.
    $db->prepare(
        "INSERT INTO asset_assignments (asset_id, employee_id, assigned_date, assigned_by, notes, created_at)
         VALUES (:aid, :eid, :d, :uid, :n, NOW())"
    )->execute([
        ':aid' => $assetId,
        ':eid' => $empId,
        ':d'   => $date,
        ':uid' => current_user()['id'],
        ':n'   => $notes,
    ]);

    flash('success', 'Asset assigned successfully.');
    redirect(BASE_URL . '/modules/assets/index.php');
}

// ── Render assignment form (GET) ─────────────────────────────────────────────
$assetId = (int)($_GET['asset_id'] ?? 0);

$stmt = $db->prepare(
    "SELECT a.*, c.name AS cat_name
     FROM assets a
     LEFT JOIN asset_categories c ON c.id = a.category_id
     WHERE a.id = ?"
);
$stmt->execute([$assetId]);
$asset = $stmt->fetch();

if (!$asset) {
    flash('danger', 'Asset not found.');
    redirect(BASE_URL . '/modules/assets/index.php');
}

if ($asset['status'] !== 'Available') {
    flash('warning', 'This asset is not available for assignment.');
    redirect(BASE_URL . '/modules/assets/index.php');
}

$employees = $db->query(
    "SELECT id, CONCAT(name, ' (', employee_id, ')') AS label
     FROM employees
     WHERE status = 'Active'
     ORDER BY name"
)->fetchAll();

$page_title = 'Assign Asset';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row justify-content-center">
<div class="col-xl-6 col-lg-8">

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2">
        <a href="<?= BASE_URL ?>/modules/assets/index.php"
           class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-arrow-left"></i>
        </a>
        <h5 class="mb-0 fw-semibold">Assign Asset</h5>
    </div>
    <div class="card-body">

        <div class="alert alert-light border d-flex align-items-center gap-2 mb-4">
            <i class="fa fa-laptop text-primary"></i>
            <div>
                <code class="text-primary"><?= h($asset['asset_code']) ?></code>
                <span class="fw-semibold ms-1"><?= h($asset['name']) ?></span>
                <span class="text-muted small ms-1"><?= h($asset['cat_name'] ?? '') ?></span>
            </div>
        </div>

        <form method="POST" action="<?= BASE_URL ?>/modules/assets/assign.php">
            <?= csrf_field() ?>
            <input type="hidden" name="asset_id" value="<?= (int)$asset['id'] ?>">

            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label">Assign To Employee <span class="text-danger">*</span></label>
                    <select name="employee_id" class="form-select" required>
                        <option value="">Select employee</option>
                        <?php foreach ($employees as $e): ?>
                        <option value="<?= $e['id'] ?>"><?= h($e['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label">Assignment Date <span class="text-danger">*</span></label>
                    <input type="date" name="assigned_date" class="form-control"
                           value="<?= date('Y-m-d') ?>" required>
                </div>

                <div class="col-12">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="2"
                              placeholder="Any additional information…"></textarea>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-user-plus me-1"></i> Assign Asset
                </button>
                <a href="<?= BASE_URL ?>/modules/assets/index.php" class="btn btn-light">
                    Cancel
                </a>
            </div>
        </form>

    </div><!-- /.card-body -->
</div><!-- /.card -->

</div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
