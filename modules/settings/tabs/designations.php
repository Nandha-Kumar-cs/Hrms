<?php
/**
 * Settings tab: Designations — CRUD (optionally linked to a department).
 * Included by ../index.php for the JSON phase (?ajax=1) and the view phase.
 *
 * Ported to match the reference Employee_Management Designations module:
 * modal add/edit (name + department + status), department/status badges,
 * per-column search, SweetAlert confirm/toast UX, and activity logging
 * (with the "(Dept: …)" suffix) on every create/update/delete.
 */
if (!defined('IN_SETTINGS')) { http_response_code(403); exit('Forbidden'); }

$db = db();

/** Department name for a given id, or null. */
$deptName = function (?int $id) use ($db): ?string {
    if (!$id) return null;
    $s = $db->prepare('SELECT name FROM departments WHERE id = ?');
    $s->execute([$id]);
    $n = $s->fetchColumn();
    return $n === false ? null : $n;
};

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
            $st = $db->prepare('SELECT name FROM designations WHERE id = ?');
            $st->execute([$id]);
            $name = $st->fetchColumn();
            if ($name === false) {
                echo json_encode(['success' => false, 'message' => 'Designation not found.']);
                exit;
            }
            $ec = $db->prepare('SELECT COUNT(*) FROM employees WHERE designation_id = ?');
            $ec->execute([$id]);
            if ((int)$ec->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'Cannot delete — employees hold this designation.']);
                exit;
            }
            $db->prepare('DELETE FROM designations WHERE id = ?')->execute([$id]);
            activity_log('deleted', 'Designation', 'Deleted designation: ' . $name);
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

        $dn = $deptName($deptId);

        if ($action === 'create') {
            $st = $db->prepare('INSERT INTO designations (name, department_id, status) VALUES (?, ?, ?)');
            $st->execute([$name, $deptId, $status]);
            activity_log('created', 'Designation', 'Created designation: ' . $name . ($dn ? " (Dept: $dn)" : ''));
            echo json_encode(['success' => true, 'message' => 'Designation created.']);
            exit;
        }
        if ($action === 'update' && $id) {
            $db->prepare('UPDATE designations SET name = ?, department_id = ?, status = ? WHERE id = ?')
               ->execute([$name, $deptId, $status, $id]);
            activity_log('updated', 'Designation', 'Updated designation: ' . $name . ($dn ? " (Dept: $dn)" : ''));
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
                        <td class="text-center"><span class="badge bg-<?= $r['status'] === 'active' ? 'success' : 'danger' ?>"><?= ucfirst($r['status']) ?></span></td>
                        <td class="text-center text-nowrap">
                            <button class="btn btn-sm btn-outline-primary btn-edit-desig"
                                    data-id="<?= $r['id'] ?>" data-name="<?= h($r['name']) ?>"
                                    data-dept="<?= (int)$r['department_id'] ?>" data-status="<?= h($r['status']) ?>"><i class="fa fa-pen"></i></button>
                            <button class="btn btn-sm btn-outline-danger btn-del-desig" data-id="<?= $r['id'] ?>" data-name="<?= h($r['name']) ?>"><i class="fa fa-trash"></i></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="desig-filter-row">
                        <th></th>
                        <th><input type="text" class="form-control form-control-sm" placeholder="Search name"></th>
                        <th><input type="text" class="form-control form-control-sm" placeholder="Search dept"></th>
                        <th><input type="text" class="form-control form-control-sm" placeholder="#"></th>
                        <th><input type="text" class="form-control form-control-sm" placeholder="active / inactive"></th>
                        <th></th>
                    </tr>
                </tfoot>
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

<style>
#desigTable tfoot th { padding: 6px 8px; }
#desigTable tfoot input { width: 100%; font-weight: 400; }
</style>

<script>
window.SET_URL_DESIG = '<?= BASE_URL ?>/modules/settings/index.php?ajax=1&tab=designations';
window.SET_CSRF      = '<?= h(csrf_token()) ?>';
</script>
<?php $page_scripts = <<<'JS'
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
$(function () {
    document.body.appendChild(document.getElementById('desigModal'));
    var modal = new bootstrap.Modal(document.getElementById('desigModal'));

    if ($.fn.DataTable) {
        $('#desigTable').DataTable({
            pageLength: 25,
            order: [[1, 'asc']],
            columnDefs: [{ orderable: false, targets: [0, 5] }],
            orderCellsTop: true,
            language: { emptyTable: 'No designations defined.' },
            initComplete: function () {
                // Per-column search boxes (Name / Department / Employees / Status).
                this.api().columns([1, 2, 3, 4]).every(function () {
                    var col = this, input = $('input', col.footer());
                    if (!input.length) return;
                    input.on('keyup change clear', function () {
                        if (col.search() !== this.value) col.search(this.value).draw();
                    });
                });
            }
        });
    }

    function showErr(msg) { $('#desigErr').html(msg).removeClass('d-none'); }

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
        $('#desigErr').addClass('d-none');
        $.post(window.SET_URL_DESIG + '&action=' + action + (id ? '&id=' + id : ''), {
            csrf_token: window.SET_CSRF, name: $('#desigName').val().trim(),
            department_id: $('#desigDept').val(), status: $('#desigStatus').val()
        }).done(function (res) {
            if (res.success) {
                modal.hide();
                Swal.fire({ icon: 'success', title: res.message, timer: 1400, showConfirmButton: false })
                    .then(function () { location.reload(); });
            } else showErr(res.message || 'Save failed.');
        }).fail(function (x) { showErr(x.responseJSON && x.responseJSON.message || 'Save failed.'); });
    });

    $(document).on('click', '.btn-del-desig', function () {
        var b = $(this);
        Swal.fire({
            title: 'Delete Designation?',
            text: 'Delete "' + b.data('name') + '"? This cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e74a3b',
            confirmButtonText: 'Yes, delete!'
        }).then(function (r) {
            if (!r.isConfirmed) return;
            $.post(window.SET_URL_DESIG + '&action=delete&id=' + b.data('id'), { csrf_token: window.SET_CSRF })
                .done(function (res) {
                    if (res.success) {
                        Swal.fire({ icon: 'success', title: res.message, timer: 1200, showConfirmButton: false })
                            .then(function () { location.reload(); });
                    } else {
                        Swal.fire({ icon: 'error', title: 'Cannot delete', text: res.message || 'Delete failed.' });
                    }
                })
                .fail(function (x) {
                    Swal.fire({ icon: 'error', title: 'Error', text: (x.responseJSON && x.responseJSON.message) || 'Delete failed.' });
                });
        });
    });
});
</script>
JS;
?>
