<?php
/**
 * Settings tab: Designations — CRUD (optionally linked to a department).
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
            $st = $db->prepare('SELECT COUNT(*) FROM employees WHERE designation_id = ?');
            $st->execute([$id]);
            if ((int)$st->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'Cannot delete — employees hold this designation.']);
                exit;
            }
            $db->prepare('DELETE FROM designations WHERE id = ?')->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Designation deleted.']);
            exit;
        }

        $name   = trim($_POST['name'] ?? '');
        $deptId = (int)($_POST['department_id'] ?? 0) ?: null;
        $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

        $errors = [];
        if ($name === '')           $errors[] = 'Designation name is required.';
        if (mb_strlen($name) > 100) $errors[] = 'Name must be 100 characters or fewer.';
        if ($deptId !== null) {
            $chk = $db->prepare('SELECT COUNT(*) FROM departments WHERE id = ?');
            $chk->execute([$deptId]);
            if (!(int)$chk->fetchColumn()) $errors[] = 'Selected department does not exist.';
        }
        if ($errors) { echo json_encode(['success' => false, 'message' => implode(' ', $errors)]); exit; }

        if ($action === 'create') {
            $st = $db->prepare('INSERT INTO designations (name, department_id, status) VALUES (?, ?, ?)');
            $st->execute([$name, $deptId, $status]);
            echo json_encode(['success' => true, 'message' => 'Designation created.']);
            exit;
        }
        if ($action === 'update' && $id) {
            $db->prepare('UPDATE designations SET name = ?, department_id = ?, status = ? WHERE id = ?')
               ->execute([$name, $deptId, $status, $id]);
            echo json_encode(['success' => true, 'message' => 'Designation updated.']);
            exit;
        }
    }

    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

// ─── VIEW ────────────────────────────────────────────────────────────────────
$rows = $db->query(
    'SELECT des.*, d.name AS dept_name,
            (SELECT COUNT(*) FROM employees e WHERE e.designation_id = des.id) AS emp_count
     FROM designations des
     LEFT JOIN departments d ON d.id = des.department_id
     ORDER BY des.name'
)->fetchAll();
$depts = $db->query('SELECT id, name FROM departments ORDER BY name')->fetchAll();
?>
<div class="card page-card">
    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
        <h5 class="mb-0 fw-semibold"><i class="fa fa-id-badge me-2 text-primary"></i>Designations</h5>
        <button class="btn btn-primary btn-sm" id="btnAddDesig"><i class="fa fa-plus me-1"></i>Add Designation</button>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-bordered align-middle mb-0" id="desigTable">
                <thead class="table-dark">
                    <tr><th style="width:60px">#</th><th>Designation Name</th><th>Department</th><th class="text-center" style="width:110px">Employees</th><th class="text-center" style="width:110px">Status</th><th class="text-center" style="width:130px">Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $i => $r): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td class="fw-semibold"><?= h($r['name']) ?></td>
                        <td><?= h($r['dept_name'] ?? '—') ?></td>
                        <td class="text-center"><?= (int)$r['emp_count'] ?></td>
                        <td class="text-center"><span class="badge bg-<?= $r['status'] === 'active' ? 'success' : 'secondary' ?>"><?= ucfirst($r['status']) ?></span></td>
                        <td class="text-center text-nowrap">
                            <button class="btn btn-sm btn-outline-primary btn-edit-desig"
                                    data-id="<?= $r['id'] ?>" data-name="<?= h($r['name']) ?>"
                                    data-dept="<?= (int)$r['department_id'] ?>" data-status="<?= h($r['status']) ?>"><i class="fa fa-pen"></i></button>
                            <button class="btn btn-sm btn-outline-danger btn-del-desig" data-id="<?= $r['id'] ?>" data-name="<?= h($r['name']) ?>"><i class="fa fa-trash"></i></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="desigModal" tabindex="-1">
    <div class="modal-dialog">
        <form id="desigForm" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-semibold" id="desigModalTitle">Add Designation</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="desigErr" class="alert alert-danger d-none"></div>
                <input type="hidden" id="desigId">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Designation Name <span class="text-danger">*</span></label>
                    <input type="text" id="desigName" class="form-control" maxlength="100" placeholder="e.g. Software Engineer">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Department</label>
                    <select id="desigDept" class="form-select">
                        <option value="">— None —</option>
                        <?php foreach ($depts as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= h($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-1">
                    <label class="form-label fw-semibold">Status</label>
                    <select id="desigStatus" class="form-select">
                        <option value="active">Active</option>
                        <option value="inactive">Inactive</option>
                    </select>
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
window.SET_URL_DESIG = '<?= BASE_URL ?>/modules/settings/index.php?ajax=1&tab=designations';
window.SET_CSRF      = '<?= h(csrf_token()) ?>';
</script>
<?php $page_scripts = <<<'JS'
<script>
$(function () {
    document.body.appendChild(document.getElementById('desigModal'));
    var modal = new bootstrap.Modal(document.getElementById('desigModal'));
    if ($.fn.DataTable) $('#desigTable').DataTable({ pageLength: 25, order: [[1,'asc']], columnDefs: [{ orderable:false, targets:[0,5] }], language: { emptyTable: 'No designations defined.' } });

    function showErr(msg){ $('#desigErr').html(msg).removeClass('d-none'); }

    $('#btnAddDesig').on('click', function () {
        $('#desigModalTitle').text('Add Designation');
        $('#desigId').val(''); $('#desigName').val(''); $('#desigDept').val(''); $('#desigStatus').val('active');
        $('#desigErr').addClass('d-none'); modal.show();
    });

    $(document).on('click', '.btn-edit-desig', function () {
        var b = $(this);
        $('#desigModalTitle').text('Edit Designation');
        $('#desigId').val(b.data('id')); $('#desigName').val(b.data('name'));
        $('#desigDept').val(b.data('dept') || ''); $('#desigStatus').val(b.data('status'));
        $('#desigErr').addClass('d-none'); modal.show();
    });

    $('#desigForm').on('submit', function (e) {
        e.preventDefault();
        var id = $('#desigId').val();
        var action = id ? 'update' : 'create';
        $.post(window.SET_URL_DESIG + '&action=' + action + (id ? '&id=' + id : ''), {
            csrf_token: window.SET_CSRF, name: $('#desigName').val().trim(),
            department_id: $('#desigDept').val(), status: $('#desigStatus').val()
        }).done(function (res) {
            if (res.success) location.reload(); else showErr(res.message || 'Save failed.');
        }).fail(function (x) { showErr(x.responseJSON && x.responseJSON.message || 'Save failed.'); });
    });

    $(document).on('click', '.btn-del-desig', function () {
        var b = $(this);
        if (!confirm('Delete designation "' + b.data('name') + '"?')) return;
        $.post(window.SET_URL_DESIG + '&action=delete&id=' + b.data('id'), { csrf_token: window.SET_CSRF })
            .done(function (res) { if (res.success) location.reload(); else alert(res.message || 'Delete failed.'); })
            .fail(function (x) { alert(x.responseJSON && x.responseJSON.message || 'Delete failed.'); });
    });
});
</script>
JS;
?>
