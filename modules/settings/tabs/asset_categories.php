<?php
/**
 * Settings tab: Asset Categories — CRUD (name only).
 * Drives the "Category" dropdown on the Add/Edit Asset pages.
 * Included by ../index.php for the JSON phase (?ajax=1) and the view phase.
 */
if (!defined('IN_SETTINGS')) { http_response_code(403); exit('Forbidden'); }

$db = db();

// ─── AJAX / JSON ─────────────────────────────────────────────────────────────
if (!empty($_GET['ajax'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? '';
    $id     = (int)($_GET['id'] ?? 0);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verify()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid or expired request.']);
            exit;
        }

        if ($action === 'delete' && $id) {
            $used = $db->prepare('SELECT COUNT(*) FROM assets WHERE category_id = ?');
            $used->execute([$id]);
            if ((int)$used->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'Cannot delete: this category is assigned to one or more assets. Reassign those assets first.']);
                exit;
            }
            $db->prepare('DELETE FROM asset_categories WHERE id = ?')->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Category deleted.']);
            exit;
        }

        $name = trim($_POST['name'] ?? '');

        $errors = [];
        if ($name === '')          $errors[] = 'Name is required.';
        if (mb_strlen($name) > 80) $errors[] = 'Name must be 80 characters or fewer.';
        if (!$errors) {
            $u = $db->prepare('SELECT COUNT(*) FROM asset_categories WHERE name = ? AND id <> ?');
            $u->execute([$name, $id]);
            if ((int)$u->fetchColumn() > 0) $errors[] = 'A category with this name already exists.';
        }
        if ($errors) { echo json_encode(['success' => false, 'message' => implode(' ', $errors)]); exit; }

        if ($action === 'create') {
            $db->prepare('INSERT INTO asset_categories (name) VALUES (?)')->execute([$name]);
            echo json_encode(['success' => true, 'message' => 'Category created.']);
            exit;
        }
        if ($action === 'update' && $id) {
            $db->prepare('UPDATE asset_categories SET name = ? WHERE id = ?')->execute([$name, $id]);
            echo json_encode(['success' => true, 'message' => 'Category updated.']);
            exit;
        }
    }

    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

// ─── VIEW ────────────────────────────────────────────────────────────────────
$rows = $db->query(
    'SELECT c.id, c.name, COUNT(a.id) AS asset_count
       FROM asset_categories c
       LEFT JOIN assets a ON a.category_id = c.id
      GROUP BY c.id, c.name
      ORDER BY c.name'
)->fetchAll();
?>
<div class="card page-card">
    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
        <h5 class="mb-0 fw-semibold"><i class="fa fa-boxes-stacked me-2 text-primary"></i>Asset Categories</h5>
        <button class="btn btn-primary btn-sm" id="btnAddAc"><i class="fa fa-plus me-1"></i>Add Category</button>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-bordered align-middle mb-0" id="acTable">
                <thead class="table-dark">
                    <tr><th style="width:60px">#</th><th>Category Name</th><th class="text-center" style="width:120px">Assets</th><th class="text-center" style="width:130px">Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $i => $r): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td class="fw-semibold"><?= h($r['name']) ?></td>
                        <td class="text-center"><span class="badge bg-secondary"><?= (int)$r['asset_count'] ?></span></td>
                        <td class="text-center text-nowrap">
                            <button class="btn btn-sm btn-outline-primary btn-edit-ac"
                                    data-id="<?= $r['id'] ?>" data-name="<?= h($r['name']) ?>"><i class="fa fa-pen"></i></button>
                            <button class="btn btn-sm btn-outline-danger btn-del-ac" data-id="<?= $r['id'] ?>" data-name="<?= h($r['name']) ?>"><i class="fa fa-trash"></i></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="acModal" tabindex="-1">
    <div class="modal-dialog">
        <form id="acForm" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-semibold" id="acModalTitle">Add Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="acErr" class="alert alert-danger d-none"></div>
                <input type="hidden" id="acId">
                <div class="mb-1">
                    <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                    <input type="text" id="acName" class="form-control" maxlength="80" placeholder="e.g. Printer">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Save</button>
            </div>
        </form>
    </div>
</div>

<script>
window.SET_URL_AC = '<?= BASE_URL ?>/modules/settings/index.php?ajax=1&tab=asset-categories';
window.SET_CSRF   = '<?= h(csrf_token()) ?>';
</script>
<?php $page_scripts = <<<'JS'
<script>
$(function () {
    document.body.appendChild(document.getElementById('acModal'));
    var modal = new bootstrap.Modal(document.getElementById('acModal'));
    if ($.fn.DataTable) $('#acTable').DataTable({ pageLength: 25, order: [[1,'asc']], columnDefs: [{ orderable:false, targets:[0,2,3] }], language: { emptyTable: 'No asset categories defined.' } });

    function showErr(msg){ $('#acErr').html(msg).removeClass('d-none'); }

    $('#btnAddAc').on('click', function () {
        $('#acModalTitle').text('Add Category');
        $('#acId').val(''); $('#acName').val('');
        $('#acErr').addClass('d-none'); modal.show();
    });

    $(document).on('click', '.btn-edit-ac', function () {
        var b = $(this);
        $('#acModalTitle').text('Edit Category');
        $('#acId').val(b.data('id')); $('#acName').val(b.data('name'));
        $('#acErr').addClass('d-none'); modal.show();
    });

    $('#acForm').on('submit', function (e) {
        e.preventDefault();
        var id = $('#acId').val();
        var action = id ? 'update' : 'create';
        $.post(window.SET_URL_AC + '&action=' + action + (id ? '&id=' + id : ''), {
            csrf_token: window.SET_CSRF, name: $('#acName').val().trim()
        }).done(function (res) {
            if (res.success) location.reload(); else showErr(res.message || 'Save failed.');
        }).fail(function (x) { showErr(x.responseJSON && x.responseJSON.message || 'Save failed.'); });
    });

    $(document).on('click', '.btn-del-ac', function () {
        var b = $(this);
        if (!confirm('Delete category "' + b.data('name') + '"?')) return;
        $.post(window.SET_URL_AC + '&action=delete&id=' + b.data('id'), { csrf_token: window.SET_CSRF })
            .done(function (res) { if (res.success) location.reload(); else alert(res.message || 'Delete failed.'); })
            .fail(function (x) { alert(x.responseJSON && x.responseJSON.message || 'Delete failed.'); });
    });
});
</script>
JS;
?>
