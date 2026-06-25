<?php
/**
 * Settings tab: Leave Types — CRUD.
 * Included by ../index.php for the JSON phase (?ajax=1) and the view phase.
 *
 * Ported to match the reference Employee_Management Leave Types module:
 * modal add/edit (name, days/year, paid, carry-forward, comp-off, status),
 * status & flag badges, per-column search, SweetAlert confirm/toast UX, and
 * activity logging on every create/update/delete.
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
            $st = $db->prepare('SELECT name FROM leave_types WHERE id = ?');
            $st->execute([$id]);
            $typeName = $st->fetchColumn();
            if ($typeName === false) {
                echo json_encode(['success' => false, 'message' => 'Leave type not found.']);
                exit;
            }
            // leave_requests/leave_balances FK is ON DELETE CASCADE — deleting a
            // referenced type would silently wipe leave history, so block it.
            $rc = $db->prepare('SELECT COUNT(*) FROM leave_requests WHERE leave_type_id = ?');
            $rc->execute([$id]);
            if ((int)$rc->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'Cannot delete — leave requests exist for this type.']);
                exit;
            }
            $db->prepare('DELETE FROM leave_types WHERE id = ?')->execute([$id]);
            activity_log('deleted', 'Leave Type', 'Deleted leave type: ' . $typeName);
            echo json_encode(['success' => true, 'message' => 'Leave type deleted.']);
            exit;
        }

        $name    = trim($_POST['name'] ?? '');
        $days    = (int)($_POST['days_allowed'] ?? 0);
        $isPaid  = !empty($_POST['is_paid']) ? 1 : 0;
        $carry   = !empty($_POST['carry_forward']) ? 1 : 0;
        $isComp  = !empty($_POST['is_comp_off']) ? 1 : 0;
        $status  = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

        $errors = [];
        if ($name === '')           $errors[] = 'Name is required.';
        if (mb_strlen($name) > 100) $errors[] = 'Name must be 100 characters or fewer.';
        if ($days < 0 || $days > 365) $errors[] = 'Days allowed must be between 0 and 365.';

        // Unique name (case-insensitive), excluding self on update
        if (!$errors) {
            $u = $db->prepare('SELECT COUNT(*) FROM leave_types WHERE name = ? AND id <> ?');
            $u->execute([$name, $id]);
            if ((int)$u->fetchColumn() > 0) $errors[] = 'A leave type with this name already exists.';
        }
        if ($errors) { echo json_encode(['success' => false, 'message' => implode(' ', $errors)]); exit; }

        if ($action === 'create') {
            $st = $db->prepare('INSERT INTO leave_types (name, days_allowed, is_paid, carry_forward, is_comp_off, status) VALUES (?,?,?,?,?,?)');
            $st->execute([$name, $days, $isPaid, $carry, $isComp, $status]);
            activity_log('created', 'Leave Type', 'Created leave type: ' . $name);
            echo json_encode(['success' => true, 'message' => 'Leave type created.']);
            exit;
        }
        if ($action === 'update' && $id) {
            $db->prepare('UPDATE leave_types SET name=?, days_allowed=?, is_paid=?, carry_forward=?, is_comp_off=?, status=? WHERE id=?')
               ->execute([$name, $days, $isPaid, $carry, $isComp, $status, $id]);
            activity_log('updated', 'Leave Type', 'Updated leave type: ' . $name);
            echo json_encode(['success' => true, 'message' => 'Leave type updated.']);
            exit;
        }
    }

    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

// ─── VIEW ────────────────────────────────────────────────────────────────────
$rows = $db->query('SELECT * FROM leave_types ORDER BY name')->fetchAll();
?>
<div class="card page-card">
    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
        <h5 class="mb-0 fw-semibold"><i class="fa fa-plane-departure me-2 text-primary"></i>Leave Types</h5>
        <button class="btn btn-primary btn-sm" id="btnAddLeave"><i class="fa fa-plus me-1"></i>Add Leave Type</button>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-bordered align-middle mb-0" id="leaveTable">
                <thead class="table-dark">
                    <tr><th style="width:60px">#</th><th>Name</th><th class="text-center" style="width:110px">Days/Year</th><th class="text-center" style="width:100px">Paid</th><th class="text-center" style="width:130px">Carry Forward</th><th class="text-center" style="width:100px">Comp Off</th><th class="text-center" style="width:110px">Status</th><th class="text-center" style="width:130px">Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $i => $r): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td class="fw-semibold"><?= h($r['name']) ?></td>
                        <td class="text-center"><?= (int)$r['days_allowed'] ?></td>
                        <td class="text-center"><?= $r['is_paid'] ? '<span class="badge bg-success">Paid</span>' : '<span class="badge bg-secondary">Unpaid</span>' ?></td>
                        <td class="text-center"><?= $r['carry_forward'] ? '<span class="badge bg-info">Yes</span>' : '<span class="badge bg-light text-dark border">No</span>' ?></td>
                        <td class="text-center"><?= !empty($r['is_comp_off']) ? '<span class="badge bg-primary">Comp Off</span>' : '<span class="badge bg-light text-dark border">No</span>' ?></td>
                        <td class="text-center"><span class="badge bg-<?= $r['status'] === 'active' ? 'success' : 'danger' ?>"><?= ucfirst($r['status']) ?></span></td>
                        <td class="text-center text-nowrap">
                            <button class="btn btn-sm btn-outline-primary btn-edit-leave"
                                    data-id="<?= $r['id'] ?>" data-name="<?= h($r['name']) ?>" data-days="<?= (int)$r['days_allowed'] ?>"
                                    data-paid="<?= (int)$r['is_paid'] ?>" data-carry="<?= (int)$r['carry_forward'] ?>" data-comp="<?= (int)($r['is_comp_off'] ?? 0) ?>" data-status="<?= h($r['status']) ?>"><i class="fa fa-pen"></i></button>
                            <button class="btn btn-sm btn-outline-danger btn-del-leave" data-id="<?= $r['id'] ?>" data-name="<?= h($r['name']) ?>"><i class="fa fa-trash"></i></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="leave-filter-row">
                        <th></th>
                        <th><input type="text" class="form-control form-control-sm" placeholder="Search name"></th>
                        <th><input type="text" class="form-control form-control-sm" placeholder="Days"></th>
                        <th><input type="text" class="form-control form-control-sm" placeholder="paid / unpaid"></th>
                        <th><input type="text" class="form-control form-control-sm" placeholder="yes / no"></th>
                        <th><input type="text" class="form-control form-control-sm" placeholder="comp off"></th>
                        <th><input type="text" class="form-control form-control-sm" placeholder="active / inactive"></th>
                        <th></th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="leaveModal" tabindex="-1">
    <div class="modal-dialog">
        <form id="leaveForm" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-semibold" id="leaveModalTitle">Add Leave Type</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="leaveErr" class="alert alert-danger d-none"></div>
                <input type="hidden" id="leaveId">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                    <input type="text" id="leaveName" class="form-control" maxlength="100" placeholder="e.g. Casual Leave">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Days Allowed Per Year <span class="text-danger">*</span></label>
                    <input type="number" id="leaveDays" class="form-control" min="0" max="365" value="0">
                </div>
                <div class="row mb-3">
                    <div class="col">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="leavePaid" checked>
                            <label class="form-check-label" for="leavePaid">Paid Leave</label>
                        </div>
                    </div>
                    <div class="col">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="leaveCarry">
                            <label class="form-check-label" for="leaveCarry">Carry Forward</label>
                        </div>
                    </div>
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="leaveComp">
                    <label class="form-check-label" for="leaveComp">Comp Off type <span class="text-muted small">(used for comp-off balance)</span></label>
                </div>
                <div class="mb-1">
                    <label class="form-label fw-semibold">Status</label>
                    <select id="leaveStatus" class="form-select">
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
#leaveTable tfoot th { padding: 6px 8px; }
#leaveTable tfoot input { width: 100%; font-weight: 400; }
</style>

<script>
window.SET_URL_LEAVE = '<?= BASE_URL ?>/modules/settings/index.php?ajax=1&tab=leave-types';
window.SET_CSRF      = '<?= h(csrf_token()) ?>';
</script>
<?php $page_scripts = <<<'JS'
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
$(function () {
    document.body.appendChild(document.getElementById('leaveModal'));
    var modal = new bootstrap.Modal(document.getElementById('leaveModal'));

    if ($.fn.DataTable) {
        $('#leaveTable').DataTable({
            pageLength: 25,
            order: [[1, 'asc']],
            columnDefs: [{ orderable: false, targets: [0, 7] }],
            orderCellsTop: true,
            language: { emptyTable: 'No leave types defined.' },
            initComplete: function () {
                // Per-column search boxes (Name / Days / Paid / Carry / Comp Off / Status).
                this.api().columns([1, 2, 3, 4, 5, 6]).every(function () {
                    var col = this, input = $('input', col.footer());
                    if (!input.length) return;
                    input.on('keyup change clear', function () {
                        if (col.search() !== this.value) col.search(this.value).draw();
                    });
                });
            }
        });
    }

    function showErr(msg){ $('#leaveErr').html(msg).removeClass('d-none'); }

    $('#btnAddLeave').on('click', function () {
        $('#leaveModalTitle').text('Add Leave Type');
        $('#leaveId').val(''); $('#leaveName').val(''); $('#leaveDays').val(0);
        $('#leavePaid').prop('checked', true); $('#leaveCarry').prop('checked', false); $('#leaveComp').prop('checked', false); $('#leaveStatus').val('active');
        $('#leaveErr').addClass('d-none'); modal.show();
    });

    $(document).on('click', '.btn-edit-leave', function () {
        var b = $(this);
        $('#leaveModalTitle').text('Edit Leave Type');
        $('#leaveId').val(b.data('id')); $('#leaveName').val(b.data('name')); $('#leaveDays').val(b.data('days'));
        $('#leavePaid').prop('checked', b.data('paid') == 1); $('#leaveCarry').prop('checked', b.data('carry') == 1);
        $('#leaveComp').prop('checked', b.data('comp') == 1);
        $('#leaveStatus').val(b.data('status'));
        $('#leaveErr').addClass('d-none'); modal.show();
    });

    $('#leaveForm').on('submit', function (e) {
        e.preventDefault();
        var id = $('#leaveId').val();
        var action = id ? 'update' : 'create';
        $('#leaveErr').addClass('d-none');
        $.post(window.SET_URL_LEAVE + '&action=' + action + (id ? '&id=' + id : ''), {
            csrf_token: window.SET_CSRF, name: $('#leaveName').val().trim(), days_allowed: $('#leaveDays').val(),
            is_paid: $('#leavePaid').is(':checked') ? 1 : 0, carry_forward: $('#leaveCarry').is(':checked') ? 1 : 0,
            is_comp_off: $('#leaveComp').is(':checked') ? 1 : 0,
            status: $('#leaveStatus').val()
        }).done(function (res) {
            if (res.success) {
                modal.hide();
                Swal.fire({ icon: 'success', title: res.message, timer: 1400, showConfirmButton: false })
                    .then(function () { location.reload(); });
            } else showErr(res.message || 'Save failed.');
        }).fail(function (x) { showErr(x.responseJSON && x.responseJSON.message || 'Save failed.'); });
    });

    $(document).on('click', '.btn-del-leave', function () {
        var b = $(this);
        Swal.fire({
            title: 'Delete Leave Type?',
            text: 'Delete "' + b.data('name') + '"? This cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e74a3b',
            confirmButtonText: 'Yes, delete!'
        }).then(function (r) {
            if (!r.isConfirmed) return;
            $.post(window.SET_URL_LEAVE + '&action=delete&id=' + b.data('id'), { csrf_token: window.SET_CSRF })
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
