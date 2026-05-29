<?php
$page_title = 'Asset Management';
require_once __DIR__ . '/../../includes/header.php';
require_permission('assets');

$db = db();
$tab = sanitize($_GET['tab'] ?? 'assets');

$assets = $db->query(
    'SELECT a.*, c.name AS cat_name,
            (SELECT e.name FROM asset_assignments aa JOIN employees e ON e.id=aa.employee_id
             WHERE aa.asset_id=a.id AND aa.is_returned=0 LIMIT 1) AS assigned_to
     FROM assets a
     LEFT JOIN asset_categories c ON c.id=a.category_id
     ORDER BY a.asset_code'
)->fetchAll();

$categories = $db->query('SELECT * FROM asset_categories ORDER BY name')->fetchAll();

$statusCount = [];
foreach ($assets as $a) $statusCount[$a['status']] = ($statusCount[$a['status']] ?? 0) + 1;
?>

<div class="page-head">
    <div>
        <h1>Assets</h1>
        <p class="muted"><?= count($assets) ?> total assets</p>
    </div>
    <div class="head-actions">
        <?php if (can('assets','assign')): ?>
        <button class="btn btn-primary" onclick="openModal('addAssetModal')" accesskey="n" data-shortcut data-key="N">
            + <u>N</u>ew Asset
        </button>
        <a href="assign.php" class="btn" accesskey="a" data-shortcut data-key="A">
            <u>A</u>ssign
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Stats -->
<div class="stat-grid" style="grid-template-columns:repeat(4,1fr)">
    <?php foreach (['Available'=>'stat-success','Assigned'=>'stat-info','Under Repair'=>'stat-warn','Retired'=>''] as $s => $c): ?>
    <div class="stat-card <?= $c ?>">
        <div class="stat-label"><?= $s ?></div>
        <div class="stat-value"><?= $statusCount[$s] ?? 0 ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Assets table -->
<div class="card">
    <div class="card-head">
        <h3>All Assets</h3>
        <div class="search"><input type="search" placeholder="Search..." data-search></div>
    </div>
    <table class="data-table datatable">
        <thead><tr>
            <th>Asset Code</th>
            <th>Name</th>
            <th>Category</th>
            <th>Serial No</th>
            <th>Status</th>
            <th>Assigned To</th>
            <th>Condition</th>
            <th class="r">Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($assets as $a): ?>
        <tr>
            <td><code><?= h($a['asset_code']) ?></code></td>
            <td>
                <div style="font-weight:500"><?= h($a['name']) ?></div>
                <div class="small muted"><?= h($a['brand']??'') ?> <?= h($a['model']??'') ?></div>
            </td>
            <td><?= h($a['cat_name']) ?></td>
            <td class="mono small"><?= h($a['serial_no'] ?? '—') ?></td>
            <td>
                <?php
                $sp = ['Available'=>'pill-success','Assigned'=>'pill-info','Under Repair'=>'pill-warn','Retired'=>'pill-cancelled'];
                echo '<span class="pill '.($sp[$a['status']]??'pill-neutral').'">'.h($a['status']).'</span>';
                ?>
            </td>
            <td><?= h($a['assigned_to'] ?? '—') ?></td>
            <td><span class="pill pill-<?= strtolower($a['condition']) === 'new' ? 'success' : (strtolower($a['condition']) === 'poor' ? 'danger' : 'neutral') ?>"><?= h($a['condition']) ?></span></td>
            <td class="r nowrap">
                <a href="view.php?id=<?= $a['id'] ?>" class="btn btn-sm">View</a>
                <?php if ($a['status'] === 'Available' && can('assets','assign')): ?>
                <a href="assign.php?asset_id=<?= $a['id'] ?>" class="btn btn-sm btn-success">Assign</a>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- No-due clearance section -->
<div class="card" style="margin-top:18px">
    <div class="card-head">
        <h3>No-Due Clearance</h3>
        <a href="clearance.php" class="link">Manage →</a>
    </div>
    <div style="padding:14px 18px;color:var(--text-muted);font-size:13px">
        Generate no-due clearance certificates verifying all assets have been returned by departing employees.
        <a href="clearance.php" class="btn btn-sm" style="margin-left:12px">View Clearance</a>
    </div>
</div>

<!-- Add Asset Modal -->
<div class="modal" id="addAssetModal">
    <div class="modal-content" style="max-width:560px">
        <h3>Add New Asset</h3>
        <form method="POST" action="save_asset.php">
            <?= csrf_field() ?>
            <div class="form-grid-2" style="margin-top:12px">
                <div class="field">
                    <label>Asset Name *</label>
                    <input type="text" name="name" required placeholder="Dell Laptop XPS 15">
                </div>
                <div class="field">
                    <label>Category</label>
                    <select name="category_id">
                        <?php foreach ($categories as $c): ?>
                        <option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Asset Code *</label>
                    <input type="text" name="asset_code" required placeholder="AST-001">
                </div>
                <div class="field">
                    <label>Serial Number</label>
                    <input type="text" name="serial_no" placeholder="SN123456">
                </div>
                <div class="field">
                    <label>Brand</label>
                    <input type="text" name="brand" placeholder="Dell">
                </div>
                <div class="field">
                    <label>Model</label>
                    <input type="text" name="model" placeholder="XPS 15">
                </div>
                <div class="field">
                    <label>Purchase Date</label>
                    <input type="date" name="purchase_date">
                </div>
                <div class="field">
                    <label>Purchase Cost</label>
                    <input type="number" name="purchase_cost" step="0.01" placeholder="0.00">
                </div>
                <div class="field">
                    <label>Condition</label>
                    <select name="condition">
                        <?php foreach (['New','Good','Fair','Poor'] as $c): ?><option><?= $c ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field span-3" style="margin-top:8px">
                <label>Notes</label>
                <textarea name="notes" rows="2"></textarea>
            </div>
            <div style="display:flex;gap:8px;margin-top:16px">
                <button type="submit" class="btn btn-primary">Save Asset</button>
                <button type="button" class="btn btn-ghost" onclick="closeModal('addAssetModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
window.BASE_URL = '<?= BASE_URL ?>';
window.PAGE_SHORTCUTS = {
    'n': () => openModal('addAssetModal'),
    'a': () => window.location.href = BASE_URL + '/modules/assets/assign.php'
};
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
