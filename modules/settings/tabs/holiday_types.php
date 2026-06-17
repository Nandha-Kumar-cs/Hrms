<?php
/**
 * Settings tab: Holiday Types — CRUD (name + Bootstrap colour label).
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
            $db->prepare('DELETE FROM holiday_types WHERE id = ?')->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Holiday type deleted.']);
            exit;
        }

        $name  = trim($_POST['name'] ?? '');
        $color = in_array($_POST['color'] ?? '', $COLORS, true) ? $_POST['color'] : 'primary';

        $errors = [];
        if ($name === '')          $errors[] = 'Name is required.';
        if (mb_strlen($name) > 80) $errors[] = 'Name must be 80 characters or fewer.';
        if (!$errors) {
            $u = $db->prepare('SELECT COUNT(*) FROM holiday_types WHERE name = ? AND id <> ?');
            $u->execute([$name, $id]);
            if ((int)$u->fetchColumn() > 0) $errors[] = 'A holiday type with this name already exists.';
        }
        if ($errors) { echo json_encode(['success' => false, 'message' => implode(' ', $errors)]); exit; }

        if ($action === 'create') {
            $db->prepare('INSERT INTO holiday_types (name, color) VALUES (?, ?)')->execute([$name, $color]);
            echo json_encode(['success' => true, 'message' => 'Holiday type created.']);
            exit;
        }
        if ($action === 'update' && $id) {
            $db->prepare('UPDATE holiday_types SET name = ?, color = ? WHERE id = ?')->execute([$name, $color, $id]);
            echo json_encode(['success' => true, 'message' => 'Holiday type updated.']);
            exit;
        }
    }

    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

// ─── VIEW ────────────────────────────────────────────────────────────────────
$rows = $db->query('SELECT * FROM holiday_types ORDER BY name')->fetchAll();
?>
<div class="card page-card">
    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
        <h5 class="mb-0 fw-semibold"><i class="fa fa-tags me-2 text-primary"></i>Holiday Types</h5>
        <button class="btn btn-primary btn-sm" id="btnAddHt"><i class="fa fa-plus me-1"></i>Add Type</button>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-bordered align-middle mb-0" id="htTable">
                <thead class="table-dark">
                    <tr><th style="width:60px">#</th><th>Type Name</th><th style="width:160px">Colour</th><th class="text-center" style="width:130px">Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $i => $r): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td class="fw-semibold"><span class="badge bg-<?= h($r['color']) ?> me-2">&nbsp;</span><?= h($r['name']) ?></td>
                        <td><code><?= h($r['color']) ?></code></td>
                        <td class="text-center text-nowrap">
                            <button class="btn btn-sm btn-outline-primary btn-edit-ht"
                                    data-id="<?= $r['id'] ?>" data-name="<?= h($r['name']) ?>" data-color="<?= h($r['color']) ?>"><i class="fa fa-pen"></i></button>
                            <button class="btn btn-sm btn-outline-danger btn-del-ht" data-id="<?= $r['id'] ?>" data-name="<?= h($r['name']) ?>"><i class="fa fa-trash"></i></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="htModal" tabindex="-1">
    <div class="modal-dialog">
        <form id="htForm" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-semibold" id="htModalTitle">Add Holiday Type</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="htErr" class="alert alert-danger d-none"></div>
                <input type="hidden" id="htId">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                    <input type="text" id="htName" class="form-control" maxlength="80" placeholder="e.g. National">
                </div>
                <div class="mb-1">
                    <label class="form-label fw-semibold">Colour <span class="text-danger">*</span></label>
                    <select id="htColor" class="form-select">
                        <option value="primary">Primary (Blue)</option>
                        <option value="secondary">Secondary (Gray)</option>
                        <option value="success">Success (Green)</option>
                        <option value="danger">Danger (Red)</option>
                        <option value="warning">Warning (Yellow)</option>
                        <option value="info">Info (Cyan)</option>
                        <option value="dark">Dark</option>
                    </select>
                    <div class="mt-2"><span class="badge bg-primary" id="htPreview">Preview</span></div>
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
window.SET_URL_HT = '<?= BASE_URL ?>/modules/settings/index.php?ajax=1&tab=holiday-types';
window.SET_CSRF   = '<?= h(csrf_token()) ?>';
</script>
<?php $page_scripts = <<<'JS'
<script>
$(function () {
    document.body.appendChild(document.getElementById('htModal'));
    var modal = new bootstrap.Modal(document.getElementById('htModal'));
    if ($.fn.DataTable) $('#htTable').DataTable({ pageLength: 25, order: [[1,'asc']], columnDefs: [{ orderable:false, targets:[0,3] }], language: { emptyTable: 'No holiday types defined.' } });

    function showErr(msg){ $('#htErr').html(msg).removeClass('d-none'); }
    function preview(){ $('#htPreview').attr('class', 'badge bg-' + $('#htColor').val()); }

    $('#htColor').on('change', preview);

    $('#btnAddHt').on('click', function () {
        $('#htModalTitle').text('Add Holiday Type');
        $('#htId').val(''); $('#htName').val(''); $('#htColor').val('primary'); preview();
        $('#htErr').addClass('d-none'); modal.show();
    });

    $(document).on('click', '.btn-edit-ht', function () {
        var b = $(this);
        $('#htModalTitle').text('Edit Holiday Type');
        $('#htId').val(b.data('id')); $('#htName').val(b.data('name')); $('#htColor').val(b.data('color')); preview();
        $('#htErr').addClass('d-none'); modal.show();
    });

    $('#htForm').on('submit', function (e) {
        e.preventDefault();
        var id = $('#htId').val();
        var action = id ? 'update' : 'create';
        $.post(window.SET_URL_HT + '&action=' + action + (id ? '&id=' + id : ''), {
            csrf_token: window.SET_CSRF, name: $('#htName').val().trim(), color: $('#htColor').val()
        }).done(function (res) {
            if (res.success) location.reload(); else showErr(res.message || 'Save failed.');
        }).fail(function (x) { showErr(x.responseJSON && x.responseJSON.message || 'Save failed.'); });
    });

    $(document).on('click', '.btn-del-ht', function () {
        var b = $(this);
        if (!confirm('Delete holiday type "' + b.data('name') + '"?')) return;
        $.post(window.SET_URL_HT + '&action=delete&id=' + b.data('id'), { csrf_token: window.SET_CSRF })
            .done(function (res) { if (res.success) location.reload(); else alert(res.message || 'Delete failed.'); })
            .fail(function (x) { alert(x.responseJSON && x.responseJSON.message || 'Delete failed.'); });
    });
});
</script>
JS;
?>
