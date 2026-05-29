<?php
$page_title = 'Edit Asset';
require_once __DIR__ . '/../../includes/header.php';
require_permission('assets', 'assign');

$db  = db();
$id  = (int)($_GET['id'] ?? 0);

if (!$id) {
    flash('error', 'No asset specified.');
    redirect(BASE_URL . '/modules/assets/index.php');
}

$asset = $db->prepare(
    'SELECT a.*, c.name AS cat_name
     FROM assets a
     LEFT JOIN asset_categories c ON c.id = a.category_id
     WHERE a.id = ?'
);
$asset->execute([$id]);
$asset = $asset->fetch();

if (!$asset) {
    flash('error', 'Asset not found.');
    redirect(BASE_URL . '/modules/assets/index.php');
}

$categories = $db->query('SELECT id, name FROM asset_categories ORDER BY name')->fetchAll();

// Repopulate on validation failure
$old = $_SESSION['form_old'] ?? [];
unset($_SESSION['form_old']);

// Merge: $old values take priority (re-fill after error), otherwise use DB values
$v = array_merge($asset, $old);

// Status is read-only if asset is currently assigned via the assignment table
$isAssigned = ($asset['status'] === 'Assigned');
?>

<div class="row justify-content-center">
<div class="col-xl-7 col-lg-9">

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2">
        <a href="<?= BASE_URL ?>/modules/assets/index.php"
           class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-arrow-left"></i>
        </a>
        <h5 class="mb-0 fw-semibold">Edit Asset — <?= h($asset['name']) ?></h5>
    </div>
    <div class="card-body">

        <?php if (!empty($_SESSION['errors'])): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($_SESSION['errors'] as $e): ?>
                <li><?= h($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php unset($_SESSION['errors']); ?>
        <?php endif; ?>

        <form method="POST" action="<?= BASE_URL ?>/modules/assets/save_asset.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id"     value="<?= $id ?>">

            <div class="row g-3">

                <!-- Asset Name -->
                <div class="col-md-8">
                    <label class="form-label">Asset Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control"
                           value="<?= h($v['name'] ?? '') ?>" required>
                </div>

                <!-- Category -->
                <div class="col-md-4">
                    <label class="form-label">Category <span class="text-danger">*</span></label>
                    <select name="category_id" class="form-select" required>
                        <option value="">Select category</option>
                        <?php foreach ($categories as $c): ?>
                        <option value="<?= $c['id'] ?>"
                            <?= (($v['category_id'] ?? '') == $c['id']) ? 'selected' : '' ?>>
                            <?= h($c['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Asset Code -->
                <div class="col-md-4">
                    <label class="form-label">Asset Code <span class="text-danger">*</span></label>
                    <input type="text" name="asset_code" class="form-control"
                           value="<?= h($v['asset_code'] ?? '') ?>" required>
                </div>

                <!-- Serial Number -->
                <div class="col-md-4">
                    <label class="form-label">Serial Number</label>
                    <input type="text" name="serial_no" class="form-control"
                           value="<?= h($v['serial_no'] ?? '') ?>">
                </div>

                <!-- Status -->
                <div class="col-md-4">
                    <label class="form-label">Status</label>
                    <?php if ($isAssigned): ?>
                    <input type="text" class="form-control" value="Assigned" readonly>
                    <input type="hidden" name="status" value="Assigned">
                    <div class="form-text text-warning">
                        <i class="fa fa-info-circle me-1"></i>
                        Status is locked while asset is assigned. Process a return first.
                    </div>
                    <?php else: ?>
                    <select name="status" class="form-select">
                        <?php foreach (['Available', 'Under Repair', 'Retired'] as $s): ?>
                        <option value="<?= $s ?>"
                            <?= (($v['status'] ?? 'Available') === $s) ? 'selected' : '' ?>>
                            <?= $s ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>

                <!-- Condition -->
                <div class="col-md-4">
                    <label class="form-label">Condition</label>
                    <select name="condition" class="form-select">
                        <?php foreach (['New', 'Good', 'Fair', 'Poor'] as $c): ?>
                        <option value="<?= $c ?>"
                            <?= (($v['condition'] ?? 'Good') === $c) ? 'selected' : '' ?>>
                            <?= $c ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Brand -->
                <div class="col-md-4">
                    <label class="form-label">Brand</label>
                    <input type="text" name="brand" class="form-control"
                           value="<?= h($v['brand'] ?? '') ?>">
                </div>

                <!-- Model -->
                <div class="col-md-6">
                    <label class="form-label">Model</label>
                    <input type="text" name="model" class="form-control"
                           value="<?= h($v['model'] ?? '') ?>">
                </div>

                <!-- Purchase Date -->
                <div class="col-md-6">
                    <label class="form-label">Purchase Date</label>
                    <input type="date" name="purchase_date" class="form-control"
                           value="<?= h($v['purchase_date'] ?? '') ?>">
                </div>

                <!-- Purchase Cost -->
                <div class="col-md-6">
                    <label class="form-label">Purchase Cost (<?= PAYROLL_CURRENCY_SYMBOL ?>)</label>
                    <input type="number" name="purchase_cost" class="form-control"
                           step="0.01" min="0"
                           value="<?= h($v['purchase_cost'] ?? '') ?>">
                </div>

                <!-- Notes -->
                <div class="col-12">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="2"><?= h($v['notes'] ?? '') ?></textarea>
                </div>

            </div><!-- /.row -->

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-save me-1"></i> Update Asset
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
