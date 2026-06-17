<?php
/**
 * Settings tab: Benefit Fund Types — CRUD (name + description + colour + status).
 * Included by ../index.php for the JSON phase (?ajax=1) and the view phase.
 */
if (!defined('IN_SETTINGS')) { http_response_code(403); exit('Forbidden'); }

$db = db();

$COLORS = ['primary','secondary','success','danger','warning','info','dark'];

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
            $cur = $db->prepare('SELECT name FROM benefit_fund_types WHERE id = ?');
            $cur->execute([$id]);
            $name = $cur->fetchColumn();
            if ($name !== false) {
                $st = $db->prepare('SELECT COUNT(*) FROM employee_benefits WHERE fund_type = ?');
                $st->execute([$name]);
                if ((int)$st->fetchColumn() > 0) {
                    echo json_encode(['success' => false, 'message' => 'Cannot delete — employees hold this benefit fund.']);
                    exit;
                }
            }
            $db->prepare('DELETE FROM benefit_fund_types WHERE id = ?')->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Benefit fund type deleted.']);
            exit;
        }

        $name   = trim($_POST['name'] ?? '');
        $desc   = trim($_POST['description'] ?? '');
        $color  = in_array($_POST['color'] ?? '', $COLORS, true) ? $_POST['color'] : 'primary';
        $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

        $errors = [];
        if ($name === '')             $errors[] = 'Name is required.';
        if (mb_strlen($name) > 100)   $errors[] = 'Name must be 100 characters or fewer.';
        if (mb_strlen($desc) > 1000)  $errors[] = 'Description must be 1000 characters or fewer.';
        if (!$errors) {
            $u = $db->prepare('SELECT COUNT(*) FROM benefit_fund_types WHERE name = ? AND id <> ?');
            $u->execute([$name, $id]);
            if ((int)$u->fetchColumn() > 0) $errors[] = 'A benefit fund type with this name already exists.';
        }
        if ($errors) { echo json_encode(['success' => false, 'message' => implode(' ', $errors)]); exit; }

        if ($action === 'create') {
            $st = $db->prepare('INSERT INTO benefit_fund_types (name, description, color, status) VALUES (?, ?, ?, ?)');
            $st->execute([$name, $desc, $color, $status]);
            echo json_encode(['success' => true, 'message' => 'Benefit fund type created.']);
            exit;
        }
        if ($action === 'update' && $id) {
            $db->prepare('UPDATE benefit_fund_types SET name = ?, description = ?, color = ?, status = ? WHERE id = ?')
               ->execute([$name, $desc, $color, $status, $id]);
            echo json_encode(['success' => true, 'message' => 'Benefit fund type updated.']);
            exit;
        }
    }

    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

// ─── VIEW ────────────────────────────────────────────────────────────────────
$rows = $db->query('SELECT * FROM benefit_fund_types ORDER BY name')->fetchAll();
?>
<div class="card page-card">
    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
        <h5 class="mb-0 fw-semibold"><i class="fa fa-piggy-bank me-2 text-primary"></i>Benefit Fund Types</h5>
        <button class="btn btn-primary btn-sm" id="btnAddBft"><i class="fa fa-plus me-1"></i>Add Fund Type</button>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-bordered align-middle mb-0" id="bftTable">
                <thead class="table-dark">
                    <tr><th style="width:60px">#</th><th>Fund Type</th><th>Description</th><th style="width:130px">Colour</th><th class="text-center" style="width:110px">Status</th><th class="text-center" style="width:130px">Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $i => $r): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td class="fw-semibold"><span class="badge bg-<?= h($r['color']) ?> me-2">&nbsp;</span><?= h($r['name']) ?></td>
                        <td><?= h($r['description'] ?? '') ?></td>
                        <td><code><?= h($r['color']) ?></code></td>
                        <td class="text-center"><span class="badge bg-<?= $r['status'] === 'active' ? 'success' : 'secondary' ?>"><?= ucfirst($r['status']) ?></span></td>
                        <td class="text-center text-nowrap">
                            <button class="btn btn-sm btn-outline-primary btn-edit-bft"
                                    data-id="<?= $r['id'] ?>" data-name="<?= h($r['name']) ?>" data-desc="<?= h($r['description'] ?? '') ?>"
                                    data-color="<?= h($r['color']) ?>" data-status="<?= h($r['status']) ?>"><i class="fa fa-pen"></i></button>
                            <button class="btn btn-sm btn-outline-danger btn-del-bft" data-id="<?= $r['id'] ?>" data-name="<?= h($r['name']) ?>"><i class="fa fa-trash"></i></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="bftModal" tabindex="-1">
    <div class="modal-dialog">
        <form id="bftForm" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-semibold" id="bftModalTitle">Add Benefit Fund Type</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="bftErr" class="alert alert-danger d-none"></div>
                <input type="hidden" id="bftId">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                    <input type="text" id="bftName" class="form-control" maxlength="100" placeholder="e.g. Provident Fund">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Description</label>
                    <textarea id="bftDesc" class="form-control" rows="2" maxlength="1000" placeholder="Optional description"></textarea>
                </div>
                <div class="row">
                    <div class="col-md-7 mb-1">
                        <label class="form-label fw-semibold">Colour <span class="text-danger">*</span></label>
                        <select id="bftColor" class="form-select">
                            <option value="primary">Primary (Blue)</option>
                            <option value="secondary">Secondary (Gray)</option>
                            <option value="success">Success (Green)</option>
                            <option value="danger">Danger (Red)</option>
                            <option value="warning">Warning (Yellow)</option>
                            <option value="info">Info (Cyan)</option>
                            <option value="dark">Dark</option>
                        </select>
                        <div class="mt-2"><span class="badge bg-primary" id="bftPreview">Preview</span></div>
                    </div>
                    <div class="col-md-5 mb-1">
                        <label class="form-label fw-semibold">Status</label>
                        <select id="bftStatus" class="form-select">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
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
window.SET_URL_BFT = '<?= BASE_URL ?>/modules/settings/index.php?ajax=1&tab=benefit-fund-types';
window.SET_CSRF    = '<?= h(csrf_token()) ?>';
</script>
<?php $page_scripts = <<<'JS'
<script>
$(function () {
    document.body.appendChild(document.getElementById('bftModal'));
    var modal = new bootstrap.Modal(document.getElementById('bftModal'));
    if ($.fn.DataTable) $('#bftTable').DataTable({ pageLength: 25, order: [[1,'asc']], columnDefs: [{ orderable:false, targets:[0,5] }], language: { emptyTable: 'No benefit fund types defined.' } });

    function showErr(msg){ $('#bftErr').html(msg).removeClass('d-none'); }
    function preview(){ $('#bftPreview').attr('class', 'badge bg-' + $('#bftColor').val()); }

    $('#bftColor').on('change', preview);

    $('#btnAddBft').on('click', function () {
        $('#bftModalTitle').text('Add Benefit Fund Type');
        $('#bftId').val(''); $('#bftName').val(''); $('#bftDesc').val(''); $('#bftColor').val('primary'); $('#bftStatus').val('active'); preview();
        $('#bftErr').addClass('d-none'); modal.show();
    });

    $(document).on('click', '.btn-edit-bft', function () {
        var b = $(this);
        $('#bftModalTitle').text('Edit Benefit Fund Type');
        $('#bftId').val(b.data('id')); $('#bftName').val(b.data('name')); $('#bftDesc').val(b.data('desc'));
        $('#bftColor').val(b.data('color')); $('#bftStatus').val(b.data('status')); preview();
        $('#bftErr').addClass('d-none'); modal.show();
    });

    $('#bftForm').on('submit', function (e) {
        e.preventDefault();
        var id = $('#bftId').val();
        var action = id ? 'update' : 'create';
        $.post(window.SET_URL_BFT + '&action=' + action + (id ? '&id=' + id : ''), {
            csrf_token: window.SET_CSRF, name: $('#bftName').val().trim(), description: $('#bftDesc').val().trim(),
            color: $('#bftColor').val(), status: $('#bftStatus').val()
        }).done(function (res) {
            if (res.success) location.reload(); else showErr(res.message || 'Save failed.');
        }).fail(function (x) { showErr(x.responseJSON && x.responseJSON.message || 'Save failed.'); });
    });

    $(document).on('click', '.btn-del-bft', function () {
        var b = $(this);
        if (!confirm('Delete benefit fund type "' + b.data('name') + '"?')) return;
        $.post(window.SET_URL_BFT + '&action=delete&id=' + b.data('id'), { csrf_token: window.SET_CSRF })
            .done(function (res) { if (res.success) location.reload(); else alert(res.message || 'Delete failed.'); })
            .fail(function (x) { alert(x.responseJSON && x.responseJSON.message || 'Delete failed.'); });
    });
});
</script>
JS;
?>
