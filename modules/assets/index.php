<?php
$page_title = 'Assets';
require_once __DIR__ . '/../../includes/header.php';
require_permission('assets');

$db = db();

// ── Status counts for the 4 metric cards ─────────────────────────────────────
$counts = $db->query(
    "SELECT
        SUM(status = 'Available')    AS available,
        SUM(status = 'Assigned')     AS assigned,
        SUM(status = 'Under Repair') AS under_repair,
        SUM(status = 'Retired')      AS retired
     FROM assets"
)->fetch();

// ── Full asset list with category + currently assigned employee ───────────────
$assets = $db->query(
    "SELECT a.*,
            c.name  AS cat_name,
            e.name  AS assigned_to,
            e.employee_id AS emp_code,
            aa.assigned_date AS issued_date
     FROM assets a
     LEFT JOIN asset_categories c  ON c.id = a.category_id
     LEFT JOIN asset_assignments aa ON aa.asset_id = a.id AND aa.is_returned = 0
     LEFT JOIN employees e          ON e.id = aa.employee_id
     ORDER BY a.asset_code"
)->fetchAll();
?>

<!-- Page header -->
<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h4 class="mb-0 fw-semibold">Company Assets</h4>
        <p class="text-muted small mb-0"><?= count($assets) ?> total assets registered</p>
    </div>
    <?php if (can('assets', 'assign')): ?>
    <a href="<?= BASE_URL ?>/modules/assets/add_asset.php" class="btn btn-primary">
        <i class="fa fa-plus me-1"></i> Add Asset
    </a>
    <?php endif; ?>
</div>

<!-- ── 4 Status Metric Cards ─────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">

    <div class="col-xl-3 col-md-6">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small fw-semibold text-uppercase mb-1">Available</div>
                    <div class="h3 mb-0 fw-bold text-success"><?= (int)($counts['available'] ?? 0) ?></div>
                </div>
                <div style="width:50px;height:50px;border-radius:50%;background:rgba(25,135,84,.12);
                            color:#198754;display:flex;align-items:center;justify-content:center;font-size:1.3rem">
                    <i class="fa fa-circle-check"></i>
                </div>
            </div>
            <div class="card-footer bg-transparent border-0 pt-0">
                <small class="text-success">Ready to assign</small>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small fw-semibold text-uppercase mb-1">Assigned</div>
                    <div class="h3 mb-0 fw-bold text-primary"><?= (int)($counts['assigned'] ?? 0) ?></div>
                </div>
                <div style="width:50px;height:50px;border-radius:50%;background:rgba(13,110,253,.12);
                            color:#0d6efd;display:flex;align-items:center;justify-content:center;font-size:1.3rem">
                    <i class="fa fa-user-check"></i>
                </div>
            </div>
            <div class="card-footer bg-transparent border-0 pt-0">
                <small class="text-primary">Currently in use</small>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small fw-semibold text-uppercase mb-1">Under Repair</div>
                    <div class="h3 mb-0 fw-bold text-warning"><?= (int)($counts['under_repair'] ?? 0) ?></div>
                </div>
                <div style="width:50px;height:50px;border-radius:50%;background:rgba(255,193,7,.12);
                            color:#ffc107;display:flex;align-items:center;justify-content:center;font-size:1.3rem">
                    <i class="fa fa-screwdriver-wrench"></i>
                </div>
            </div>
            <div class="card-footer bg-transparent border-0 pt-0">
                <small class="text-warning">Being serviced</small>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small fw-semibold text-uppercase mb-1">Retired</div>
                    <div class="h3 mb-0 fw-bold text-secondary"><?= (int)($counts['retired'] ?? 0) ?></div>
                </div>
                <div style="width:50px;height:50px;border-radius:50%;background:rgba(108,117,125,.12);
                            color:#6c757d;display:flex;align-items:center;justify-content:center;font-size:1.3rem">
                    <i class="fa fa-box-archive"></i>
                </div>
            </div>
            <div class="card-footer bg-transparent border-0 pt-0">
                <small class="text-secondary">Decommissioned</small>
            </div>
        </div>
    </div>

</div>

<!-- ── Assets DataTable ───────────────────────────────────────────────────────── -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold"><i class="fa fa-laptop me-2 text-primary"></i>All Assets</h6>
        <a href="<?= BASE_URL ?>/modules/assets/clearance.php" class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-certificate me-1"></i> No-Due Clearance
        </a>
    </div>
    <div class="card-body">
        <table id="assetsTable" class="table table-hover align-middle" style="width:100%">
            <thead class="table-light">
                <tr>
                    <th>Asset Code</th>
                    <th>Name</th>
                    <th>Category</th>
                    <th>Serial No</th>
                    <th>Condition</th>
                    <th>Status</th>
                    <th>Assigned To</th>
                    <th>Issued Date</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($assets as $a):
                // Status badge classes
                $statusBadge = match($a['status']) {
                    'Available'    => 'success',
                    'Assigned'     => 'primary',
                    'Under Repair' => 'warning',
                    'Retired'      => 'secondary',
                    default        => 'light',
                };
                // Condition badge classes
                $condBadge = match($a['condition'] ?? '') {
                    'New'  => 'success',
                    'Good' => 'info',
                    'Fair' => 'warning',
                    'Poor' => 'danger',
                    default => 'secondary',
                };
            ?>
            <tr>
                <td><code class="text-primary"><?= h($a['asset_code']) ?></code></td>
                <td>
                    <div class="fw-semibold"><?= h($a['name']) ?></div>
                    <?php if ($a['brand'] || $a['model']): ?>
                    <div class="text-muted small"><?= h(trim($a['brand'] . ' ' . $a['model'])) ?></div>
                    <?php endif; ?>
                </td>
                <td><?= h($a['cat_name'] ?? '—') ?></td>
                <td class="font-monospace small"><?= h($a['serial_no'] ?? '—') ?></td>
                <td><span class="badge bg-<?= $condBadge ?>"><?= h($a['condition'] ?? '—') ?></span></td>
                <td><span class="badge bg-<?= $statusBadge ?>"><?= h($a['status']) ?></span></td>
                <td>
                    <?php if ($a['assigned_to']): ?>
                    <div><?= h($a['assigned_to']) ?></div>
                    <div class="text-muted small"><?= h($a['emp_code']) ?></div>
                    <?php else: ?>
                    <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td><?= $a['issued_date'] ? date_fmt($a['issued_date']) : '<span class="text-muted">—</span>' ?></td>
                <td class="text-center text-nowrap">
                    <?php if (can('assets', 'assign')): ?>
                    <a href="<?= BASE_URL ?>/modules/assets/edit_asset.php?id=<?= $a['id'] ?>"
                       class="btn btn-xs btn-outline-primary" title="Edit">
                        <i class="fa fa-pen"></i>
                    </a>
                    <?php if ($a['status'] === 'Available'): ?>
                    <a href="<?= BASE_URL ?>/modules/assets/assign.php?asset_id=<?= $a['id'] ?>"
                       class="btn btn-xs btn-outline-success" title="Assign to employee">
                        <i class="fa fa-user-plus"></i>
                    </a>
                    <?php endif; ?>
                    <?php if ($a['status'] === 'Assigned'): ?>
                    <a href="<?= BASE_URL ?>/modules/assets/view.php?id=<?= $a['id'] ?>"
                       class="btn btn-xs btn-outline-warning" title="Process return">
                        <i class="fa fa-rotate-left"></i>
                    </a>
                    <?php endif; ?>
                    <button type="button"
                            class="btn btn-xs btn-outline-danger btn-delete"
                            data-id="<?= $a['id'] ?>"
                            data-name="<?= h($a['name']) ?>"
                            title="Delete">
                        <i class="fa fa-trash"></i>
                    </button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Delete confirmation form (hidden, submitted by JS) -->
<form id="deleteForm" method="POST" action="<?= BASE_URL ?>/modules/assets/save_asset.php" style="display:none">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="deleteId">
</form>

<?php
$page_scripts = <<<'JS'
<script>
$(function () {

    // ── DataTables init ──────────────────────────────────────────────────────
    $('#assetsTable').DataTable({
        pageLength: 25,
        lengthMenu: [[10, 25, 50, 100, -1], ['10', '25', '50', '100', 'All']],
        order: [[0, 'asc']],
        columnDefs: [
            { orderable: false, targets: 8 }   // Actions column not sortable
        ],
        language: {
            search:         'Search assets:',
            lengthMenu:     'Show _MENU_ assets',
            info:           'Showing _START_ to _END_ of _TOTAL_ assets',
            infoEmpty:      'No assets found',
            emptyTable:     'No assets registered yet'
        }
    });

    // ── Delete confirmation ──────────────────────────────────────────────────
    $(document).on('click', '.btn-delete', function () {
        const id   = $(this).data('id');
        const name = $(this).data('name');
        if (confirm('Delete asset "' + name + '"?\nThis cannot be undone.')) {
            $('#deleteId').val(id);
            $('#deleteForm').submit();
        }
    });

});
</script>
JS;
?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
