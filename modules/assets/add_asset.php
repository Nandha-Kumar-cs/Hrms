<?php
$page_title = 'Add Asset';
require_once __DIR__ . '/../../includes/header.php';
require_permission('assets', 'assign');

$db         = db();
$categories = $db->query('SELECT id, name FROM asset_categories ORDER BY name')->fetchAll();

// Suggest next asset code automatically
$lastCode = $db->query(
    "SELECT asset_code FROM assets ORDER BY id DESC LIMIT 1"
)->fetchColumn();
$nextCode = '';
if ($lastCode && preg_match('/(\d+)$/', $lastCode, $m)) {
    $num      = (int)$m[1] + 1;
    $prefix   = preg_replace('/\d+$/', '', $lastCode);
    $nextCode = $prefix . str_pad($num, strlen($m[1]), '0', STR_PAD_LEFT);
} else {
    $nextCode = 'AST-001';
}

// Repopulate on validation failure
$old = $_SESSION['form_old'] ?? [];
unset($_SESSION['form_old']);
?>

<div class="row justify-content-center">
<div class="col-xl-7 col-lg-9">

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2">
        <a href="<?= BASE_URL ?>/modules/assets/index.php"
           class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-arrow-left"></i>
        </a>
        <h5 class="mb-0 fw-semibold">Add New Asset</h5>
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
            <input type="hidden" name="action" value="add">

            <div class="row g-3">

                <!-- Asset Name -->
                <div class="col-md-8">
                    <label class="form-label">Asset Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control"
                           value="<?= h($old['name'] ?? '') ?>"
                           placeholder="e.g. Dell Laptop XPS 15" required>
                </div>

                <!-- Category -->
                <div class="col-md-4">
                    <label class="form-label">Category <span class="text-danger">*</span></label>
                    <select name="category_id" class="form-select" required>
                        <option value="">Select category</option>
                        <?php foreach ($categories as $c): ?>
                        <option value="<?= $c['id'] ?>"
                            <?= (($old['category_id'] ?? '') == $c['id']) ? 'selected' : '' ?>>
                            <?= h($c['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Asset Code -->
                <div class="col-md-4">
                    <label class="form-label">Asset Code <span class="text-danger">*</span></label>
                    <input type="text" name="asset_code" class="form-control"
                           value="<?= h($old['asset_code'] ?? $nextCode) ?>"
                           placeholder="AST-001" required>
                </div>

                <!-- Serial Number -->
                <div class="col-md-4">
                    <label class="form-label">Serial Number</label>
                    <input type="text" name="serial_no" class="form-control"
                           value="<?= h($old['serial_no'] ?? '') ?>"
                           placeholder="SN123456">
                </div>

                <!-- Condition -->
                <div class="col-md-4">
                    <label class="form-label">Condition</label>
                    <select name="condition" class="form-select">
                        <?php foreach (['New', 'Good', 'Fair', 'Poor'] as $c): ?>
                        <option value="<?= $c ?>"
                            <?= (($old['condition'] ?? 'New') === $c) ? 'selected' : '' ?>>
                            <?= $c ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Brand -->
                <div class="col-md-6">
                    <label class="form-label">Brand</label>
                    <input type="text" name="brand" class="form-control"
                           value="<?= h($old['brand'] ?? '') ?>"
                           placeholder="Dell, HP, Apple…">
                </div>

                <!-- Model -->
                <div class="col-md-6">
                    <label class="form-label">Model</label>
                    <input type="text" name="model" class="form-control"
                           value="<?= h($old['model'] ?? '') ?>"
                           placeholder="XPS 15, ThinkPad X1…">
                </div>

                <!-- Purchase Date -->
                <div class="col-md-6">
                    <label class="form-label">Purchase Date</label>
                    <input type="date" name="purchase_date" class="form-control"
                           value="<?= h($old['purchase_date'] ?? '') ?>">
                </div>

                <!-- Purchase Cost -->
                <div class="col-md-6">
                    <label class="form-label">Purchase Cost (<?= PAYROLL_CURRENCY_SYMBOL ?>)</label>
                    <input type="number" name="purchase_cost" class="form-control"
                           step="0.01" min="0"
                           value="<?= h($old['purchase_cost'] ?? '') ?>"
                           placeholder="0.00">
                </div>

                <!-- Notes -->
                <div class="col-12">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="2"
                              placeholder="Any additional information…"><?= h($old['notes'] ?? '') ?></textarea>
                </div>

            </div><!-- /.row -->

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-save me-1"></i> Save Asset
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
