<?php
/**
 * Settings tab: Departments — CRUD.
 * Included by ../index.php for both the JSON phase (?ajax=1) and the view phase.
 *
 * Ported to match the reference Employee_Management Departments module:
 * modal add/edit (name + status), status badge, per-column search, SweetAlert
 * confirm/toast UX, and activity logging on every create/update/delete.
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
            $st = $db->prepare('SELECT name FROM departments WHERE id = ?');
            $st->execute([$id]);
            $deptName = $st->fetchColumn();
            if ($deptName === false) {
                echo json_encode(['success' => false, 'message' => 'Department not found.']);
                exit;
            }
            // Employees are FK-RESTRICT — block rather than orphan them.
            $ec = $db->prepare('SELECT COUNT(*) FROM employees WHERE department_id = ?');
            $ec->execute([$id]);
            if ((int)$ec->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'Cannot delete — employees are assigned to this department.']);
                exit;
            }
            // Detach designations (mirrors the reference nullOnDelete).
            $db->prepare('UPDATE designations SET department_id = NULL WHERE department_id = ?')->execute([$id]);
            $db->prepare('DELETE FROM departments WHERE id = ?')->execute([$id]);
            activity_log('deleted', 'Department', 'Deleted department: ' . $deptName);
            echo json_encode(['success' => true, 'message' => 'Department deleted.']);
            exit;
        }

        $name   = trim($_POST['name'] ?? '');
        $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

        $errors = [];
        if ($name === '')           $errors[] = 'Department name is required.';
        if (mb_strlen($name) > 100) $errors[] = 'Name must be 100 characters or fewer.';
        if ($errors) { echo json_encode(['success' => false, 'message' => implode(' ', $errors)]); exit; }

        if ($action === 'create') {
            $st = $db->prepare('INSERT INTO departments (name, status) VALUES (?, ?)');
            $st->execute([$name, $status]);
            activity_log('created', 'Department', 'Created department: ' . $name);
            echo json_encode(['success' => true, 'message' => 'Department created.']);
            exit;
        }
        if ($action === 'update' && $id) {
            $db->prepare('UPDATE departments SET name = ?, status = ? WHERE id = ?')->execute([$name, $status, $id]);
            activity_log('updated', 'Department', 'Updated department: ' . $name);
            echo json_encode(['success' => true, 'message' => 'Department updated.']);
            exit;
        }
    }

    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

// ─── VIEW ────────────────────────────────────────────────────────────────────
$rows = $db->query(
    'SELECT d.*, (SELECT COUNT(*) FROM employees e WHERE e.department_id = d.id) AS emp_count
     FROM departments d ORDER BY d.name'
)->fetchAll();
?>
<div class="card page-card">
    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
        <h5 class="mb-0 fw-semibold"><i class="fa fa-building me-2 text-primary"></i>Departments</h5>
        <button class="btn btn-primary btn-sm" id="btnAddDept"><i class="fa fa-plus me-1"></i>Add Department</button>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-bordered align-middle mb-0" id="deptTable">
                <thead class="table-dark">
                    <tr><th style="width:60px">#</th><th>Department Name</th><th class="text-center" style="width:120px">Employees</th><th class="text-center" style="width:110px">Status</th><th class="text-center" style="width:130px">Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $i => $r): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td class="fw-semibold"><?= h($r['name']) ?></td>
                        <td class="text-center"><?= (int)$r['emp_count'] ?></td>
                        <td class="text-center"><span class="badge bg-<?= $r['status'] === 'active' ? 'success' : 'danger' ?>"><?= ucfirst($r['status']) ?></span></td>
                        <td class="text-center text-nowrap">
                            <button class="btn btn-sm btn-outline-primary btn-edit-dept"
                                    data-id="<?= $r['id'] ?>" data-name="<?= h($r['name']) ?>" data-status="<?= h($r['status']) ?>"><i class="fa fa-pen"></i></button>
                            <button class="btn btn-sm btn-outline-danger btn-del-dept" data-id="<?= $r['id'] ?>" data-name="<?= h($r['name']) ?>"><i class="fa fa-trash"></i></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="dept-filter-row">
                        <th></th>
                        <th><input type="text" class="form-control form-control-sm" placeholder="Search name"></th>
                        <th><input type="text" class="form-control form-control-sm" placeholder="#"></th>
                        <th><input type="text" class="form-control form-control-sm" placeholder="active / inactive"></th>
                        <th></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="deptModal" tabindex="-1">
    <div class="modal-dialog">
        <form id="deptForm" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-semibold" id="deptModalTitle">Add Department</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="deptErr" class="alert alert-danger d-none"></div>
                <input type="hidden" id="deptId">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Department Name <span class="text-danger">*</span></label>
                    <input type="text" id="deptName" class="form-control" maxlength="100" placeholder="e.g. Human Resources">
                </div>
                <div class="mb-1">
                    <label class="form-label fw-semibold">Status</label>
                    <select id="deptStatus" class="form-select">
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
#deptTable tfoot th { padding: 6px 8px; }
#deptTable tfoot input { width: 100%; font-weight: 400; }
</style>

<script>
window.SET_URL_DEPT = '<?= BASE_URL ?>/modules/settings/index.php?ajax=1&tab=departments';
window.SET_CSRF     = '<?= h(csrf_token()) ?>';
</script>
<?php $page_scripts = <<<'JS'
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
$(function () {
    document.body.appendChild(document.getElementById('deptModal'));
    var modal = new bootstrap.Modal(document.getElementById('deptModal'));

    if ($.fn.DataTable) {
        $('#deptTable').DataTable({
            pageLength: 25,
            order: [[1, 'asc']],
            columnDefs: [{ orderable: false, targets: [0, 4] }],
            orderCellsTop: true,
            language: { emptyTable: 'No departments defined.' },
            initComplete: function () {
                // Per-column search boxes (Name / Employees / Status).
                this.api().columns([1, 2, 3]).every(function () {
                    var col = this, input = $('input', col.footer());
                    if (!input.length) return;
                    input.on('keyup change clear', function () {
                        if (col.search() !== this.value) col.search(this.value).draw();
                    });
                });
            }
        });
    }

    function showErr(msg) { $('#deptErr').html(msg).removeClass('d-none'); }
    function toast(msg) { Swal.fire({ icon: 'success', title: msg, timer: 1400, showConfirmButton: false }); }

    $('#btnAddDept').on('click', function () {
        $('#deptModalTitle').text('Add Department');
        $('#deptId').val(''); $('#deptName').val(''); $('#deptStatus').val('active');
        $('#deptErr').addClass('d-none'); modal.show();
    });

    $(document).on('click', '.btn-edit-dept', function () {
        var b = $(this);
        $('#deptModalTitle').text('Edit Department');
        $('#deptId').val(b.data('id')); $('#deptName').val(b.data('name')); $('#deptStatus').val(b.data('status'));
        $('#deptErr').addClass('d-none'); modal.show();
    });

    $('#deptForm').on('submit', function (e) {
        e.preventDefault();
        var id = $('#deptId').val();
        var action = id ? 'update' : 'create';
        $('#deptErr').addClass('d-none');
        $.post(window.SET_URL_DEPT + '&action=' + action + (id ? '&id=' + id : ''), {
            csrf_token: window.SET_CSRF, name: $('#deptName').val().trim(), status: $('#deptStatus').val()
        }).done(function (res) {
            if (res.success) {
                modal.hide();
                Swal.fire({ icon: 'success', title: res.message, timer: 1400, showConfirmButton: false })
                    .then(function () { location.reload(); });
            } else showErr(res.message || 'Save failed.');
        }).fail(function (x) { showErr(x.responseJSON && x.responseJSON.message || 'Save failed.'); });
    });

    $(document).on('click', '.btn-del-dept', function () {
        var b = $(this);
        Swal.fire({
            title: 'Delete Department?',
            text: 'Delete "' + b.data('name') + '"? This cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e74a3b',
            confirmButtonText: 'Yes, delete!'
        }).then(function (r) {
            if (!r.isConfirmed) return;
            $.post(window.SET_URL_DEPT + '&action=delete&id=' + b.data('id'), { csrf_token: window.SET_CSRF })
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
