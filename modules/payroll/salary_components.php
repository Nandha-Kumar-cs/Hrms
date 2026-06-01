<?php
/**
 * Salary Components — CRUD page
 *
 * Handles both the full HTML page and inline AJAX requests:
 *   ?ajax=1                          → DataTables server-side JSON
 *   ?ajax=1&action=edit&id=X         → fetch single component (modal pre-fill)
 *   POST ?ajax=1&action=create       → create component
 *   POST ?ajax=1&action=update&id=X  → update component
 *   POST ?ajax=1&action=delete&id=X  → delete component
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('payroll', 'process');

$db = db();

// ─── AJAX handlers ─────────────────────────────────────────────────────────
if (!empty($_GET['ajax'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'] ?? '';
    $id     = (int)($_GET['id'] ?? 0);

    // ── DataTables JSON ─────────────────────────────────────────────────────
    if (!$action) {
        $search  = '%' . sanitize($_GET['search']['value'] ?? '') . '%';
        $start   = (int)($_GET['start'] ?? 0);
        $length  = (int)($_GET['length'] ?? 25);
        $orderCol = (int)($_GET['order'][0]['column'] ?? 0);
        $orderDir = strtoupper($_GET['order'][0]['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';
        $cols     = ['id', 'name', 'type', 'calculation_type', 'value', 'value', 'id'];
        $orderBy  = $cols[$orderCol] ?? 'name';

        $total = (int)$db->query('SELECT COUNT(*) FROM salary_components')->fetchColumn();

        $countSt = $db->prepare('SELECT COUNT(*) FROM salary_components WHERE name LIKE ? OR type LIKE ? OR calculation_type LIKE ?');
        $countSt->execute([$search, $search, $search]);
        $filtered = (int)$countSt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT * FROM salary_components
              WHERE name LIKE ? OR type LIKE ? OR calculation_type LIKE ?
              ORDER BY {$orderBy} {$orderDir}
              LIMIT ? OFFSET ?"
        );
        $stmt->execute([$search, $search, $search, $length, $start]);
        $rows = $stmt->fetchAll();

        $data = [];
        $i    = $start + 1;
        foreach ($rows as $r) {
            $typeBadge = $r['type'] === 'allowance'
                ? '<span class="badge bg-success">Allowance</span>'
                : '<span class="badge bg-danger">Deduction</span>';

            $nameSlug = strtolower(str_replace(' ', '_', $r['name']));
            $formula = $r['calculation_type'] === 'percentage'
                ? h($nameSlug) . ' = ' . h($r['value']) . ' / 100 * ctc'
                : h($nameSlug) . ' = ' . h($r['value']) . ' (fixed)';

            $action_html = '
                <button class="btn btn-sm btn-warning btn-edit-comp me-1" data-id="' . $r['id'] . '" title="Edit">
                    <i class="fa fa-pen-to-square"></i>
                </button>
                <button class="btn btn-sm btn-danger btn-delete-comp" data-id="' . $r['id'] . '" data-name="' . h($r['name']) . '" title="Delete">
                    <i class="fa fa-trash"></i>
                </button>';

            $data[] = [
                $i++,
                h($r['name']),
                $typeBadge,
                ucfirst($r['calculation_type']),
                $r['calculation_type'] === 'percentage'
                    ? h($r['value']) . '%'
                    : '₹' . number_format((float)$r['value'], 2),
                $formula,
                $action_html,
            ];
        }
        echo json_encode(['draw' => (int)($_GET['draw'] ?? 1), 'recordsTotal' => $total, 'recordsFiltered' => $filtered, 'data' => $data]);
        exit;
    }

    // ── Fetch single for edit ────────────────────────────────────────────────
    if ($action === 'edit' && $id) {
        $st = $db->prepare('SELECT * FROM salary_components WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch();
        echo $row ? json_encode($row) : json_encode(['error' => 'Not found']);
        exit;
    }

    // ── Write actions require CSRF ───────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!csrf_verify()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
            exit;
        }

        // ── Delete branch — runs BEFORE field validation (no form fields needed) ──
        if ($action === 'delete' && $id) {
            $row = $db->prepare('SELECT name FROM salary_components WHERE id=?');
            $row->execute([$id]);
            $existing = $row->fetchColumn();
            if (!$existing) {
                echo json_encode(['success' => false, 'message' => 'Component not found.']);
                exit;
            }
            $db->prepare('DELETE FROM salary_components WHERE id=?')->execute([$id]);
            echo json_encode(['success' => true, 'message' => "Component '{$existing}' deleted."]);
            exit;
        }

        // ── Create / Update — require form fields ─────────────────────────────
        $name     = trim($_POST['name'] ?? '');
        $type     = $_POST['type'] ?? '';
        $calcType = $_POST['calculation_type'] ?? '';
        $value    = (float)($_POST['value'] ?? 0);

        // Validate
        $errors = [];
        if (!$name)                                    $errors[] = 'Component name is required.';
        if (strlen($name) > 255)                       $errors[] = 'Name must be 255 characters or less.';
        if (!in_array($type, ['allowance','deduction'])) $errors[] = 'Type must be allowance or deduction.';
        if (!in_array($calcType, ['percentage','fixed'])) $errors[] = 'Calculation type must be percentage or fixed.';
        if ($value < 0)                                $errors[] = 'Value must be 0 or greater.';

        if ($errors) {
            echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
            exit;
        }

        $nameSlug = strtolower(str_replace(' ', '_', $name));
        $formula = $calcType === 'percentage'
            ? "{$nameSlug} = {$value} / 100 * ctc"
            : "{$nameSlug} = {$value} (fixed)";

        if ($action === 'create') {
            $st = $db->prepare('INSERT INTO salary_components (name,type,calculation_type,value) VALUES (?,?,?,?)');
            $st->execute([$name, $type, $calcType, $value]);
            $newId = $db->lastInsertId();
            $row   = $db->prepare('SELECT * FROM salary_components WHERE id=?');
            $row->execute([$newId]);
            echo json_encode(['success' => true, 'message' => "Component '{$name}' created.", 'data' => $row->fetch(), 'formula' => $formula]);
            exit;
        }

        if ($action === 'update' && $id) {
            $st = $db->prepare('UPDATE salary_components SET name=?,type=?,calculation_type=?,value=?,updated_at=NOW() WHERE id=?');
            $st->execute([$name, $type, $calcType, $value, $id]);
            $row = $db->prepare('SELECT * FROM salary_components WHERE id=?');
            $row->execute([$id]);
            echo json_encode(['success' => true, 'message' => "Component '{$name}' updated.", 'data' => $row->fetch(), 'formula' => $formula]);
            exit;
        }

    }

    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

// ─── Full page ──────────────────────────────────────────────────────────────
$page_title = 'Salary Components';
$extra_head = '
<!-- SweetAlert2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<style>
#compsTable tfoot th { padding:6px 8px; }
#compsTable tfoot th input.form-control { font-size:12px; padding:4px 7px; height:30px; }

/* ── Self-contained modal (no Bootstrap modal JS dependency) ───────────── */
.comp-overlay {
    position: fixed; inset: 0;
    background: rgba(0,0,0,.5);
    z-index: 200000;                 /* above sidebar (1040) and everything else */
    display: none;
    align-items: flex-start; justify-content: center;
    padding: 60px 16px;
    overflow-y: auto;
}
.comp-overlay.show { display: flex; }
.comp-dialog {
    background: #fff; border-radius: 8px;
    width: 100%; max-width: 500px;
    box-shadow: 0 12px 48px rgba(0,0,0,.35);
    animation: compPop .15s ease;
}
@keyframes compPop { from { transform: translateY(-12px); opacity: 0; } to { transform: none; opacity: 1; } }
.comp-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 18px; border-bottom: 1px solid #e5e7eb;
}
.comp-header h5 { margin: 0; font-weight: 600; font-size: 1.05rem; }
.comp-x {
    background: none; border: none; font-size: 28px; line-height: 1;
    cursor: pointer; color: #6b7280; padding: 0 4px;
}
.comp-x:hover { color: #111827; }
.comp-body { padding: 18px; }
.comp-footer {
    display: flex; justify-content: flex-end; gap: 8px;
    padding: 12px 18px; border-top: 1px solid #e5e7eb;
}
</style>';
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="card page-card">
    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
        <button class="btn btn-primary btn-sm" onclick="openAddModal()">
            <i class="fa fa-plus me-1"></i> Add Component
        </button>
        <h5 class="mb-0 fw-semibold">Salary Details</h5>
        <a href="<?= BASE_URL ?>/modules/payroll/calculate.php" class="btn btn-sm btn-outline-secondary">
            Salary Calculation
        </a>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table id="compsTable" class="table table-striped table-hover table-bordered align-middle w-100">
                <thead class="table-dark">
                    <tr>
                        <th>#</th>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Calc. Type</th>
                        <th>Value</th>
                        <th>Formula</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody></tbody>
                <tfoot>
                    <tr><th></th><th></th><th></th><th></th><th></th><th></th><th></th></tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- Add / Edit Modal — self-contained overlay (no Bootstrap modal JS) -->
<div class="comp-overlay" id="compOverlay">
    <div class="comp-dialog" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
        <div class="comp-header">
            <h5 id="modalTitle">Add Salary Component</h5>
            <button type="button" class="comp-x" onclick="closeCompModal()" aria-label="Close">&times;</button>
        </div>
        <div class="comp-body">
            <div id="formErrors" class="alert alert-danger d-none"></div>
            <input type="hidden" id="compId">
            <div class="mb-3">
                <label class="form-label">Component Name <span class="text-danger">*</span></label>
                <input type="text" id="compName" class="form-control" placeholder="e.g. HRA, Basic, PF" maxlength="255">
            </div>
            <div class="mb-3">
                <label class="form-label">Component Type <span class="text-danger">*</span></label>
                <select id="compType" class="form-select">
                    <option value="allowance">Allowance</option>
                    <option value="deduction">Deduction</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Calculation Type <span class="text-danger">*</span></label>
                <select id="compCalcType" class="form-select" onchange="updateFormulaPreview()">
                    <option value="percentage">Percentage of CTC</option>
                    <option value="fixed">Fixed Amount</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label">Value <span class="text-danger">*</span></label>
                <input type="number" id="compValue" class="form-control" step="0.01" min="0" oninput="updateFormulaPreview()">
            </div>
            <div class="mb-3">
                <label class="form-label">Formula Preview</label>
                <div id="formulaPreview" class="form-control bg-light text-muted font-monospace" style="min-height:38px;line-height:1.8"></div>
            </div>
        </div>
        <div class="comp-footer">
            <button type="button" class="btn btn-light" onclick="closeCompModal()">Cancel</button>
            <button type="button" class="btn btn-primary" onclick="saveComponent()">
                <i class="fa fa-save me-1"></i> Save
            </button>
        </div>
    </div>
</div>

<!-- PHP constants exposed to JS before footer loads jQuery -->
<script>
const COMP_URL = '<?= BASE_URL ?>/modules/payroll/salary_components.php?ajax=1';
const CSRF     = '<?= h(csrf_token()) ?>';
</script>

<?php $page_scripts = <<<'PAGEJS'
<script>
var compTable;

// ── Self-contained modal controls ──────────────────────────────────────────
function openCompModal()  { document.getElementById('compOverlay').classList.add('show'); }
function closeCompModal() { document.getElementById('compOverlay').classList.remove('show'); }

$(function () {
    // Close on backdrop click (only when the overlay itself is the target)
    document.getElementById('compOverlay').addEventListener('click', function (e) {
        if (e.target === this) closeCompModal();
    });
    // Close on Escape
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeCompModal();
    });

    compTable = $('#compsTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: COMP_URL,
        dom: 'lfrtip',
        columns: [
            { data: 0 },
            { data: 1 },
            { data: 2, orderable: false },
            { data: 3 },
            { data: 4, className: 'text-end' },
            { data: 5, orderable: false, searchable: false },
            { data: 6, orderable: false, searchable: false, className: 'text-center',
              render: function (data) { return data; } }
        ],
        order: [[1, 'asc']],
        pageLength: 25,
        language: {
            lengthMenu: 'Show _MENU_ entries',
            paginate: { previous: '&laquo;', next: '&raquo;' }
        },
        initComplete: function () {
            this.api().columns([1, 2, 3]).every(function () {
                var col = this;
                $('<input type="text" class="form-control form-control-sm" placeholder="Search...">')
                    .appendTo($('#compsTable tfoot th').eq(col.index()))
                    .on('keyup change', function () { col.search(this.value).draw(); });
            });
        }
    });

    // Edit — delegate on document so it works after every DataTables redraw
    $(document).on('click', '.btn-edit-comp', function () {
        var id = $(this).data('id');
        $.get(COMP_URL + '&action=edit&id=' + id, function (d) {
            $('#modalTitle').text('Edit Salary Component');
            $('#compId').val(d.id);
            $('#compName').val(d.name);
            $('#compType').val(d.type);
            $('#compCalcType').val(d.calculation_type);
            $('#compValue').val(d.value);
            updateFormulaPreview();
            $('#formErrors').addClass('d-none');
            openCompModal();
        });
    });

    // Delete — delegate on document
    $(document).on('click', '.btn-delete-comp', function () {
        var id   = $(this).data('id');
        var name = $(this).data('name');
        Swal.fire({
            title: 'Delete Component?',
            html: 'Delete <strong>' + name + '</strong>?<br><small class="text-muted">Previously generated slips will not be affected.</small>',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e74a3b',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, delete!'
        }).then(function (r) {
            if (r.isConfirmed) {
                $.post(COMP_URL + '&action=delete&id=' + id, { csrf_token: CSRF }, function (res) {
                    compTable.ajax.reload();
                    Swal.fire({ icon: res.success ? 'success' : 'error', title: res.message, timer: 1500, showConfirmButton: false });
                });
            }
        });
    });

    $('#compName').on('input', updateFormulaPreview);
});

function openAddModal() {
    $('#modalTitle').text('Add Salary Component');
    $('#compId').val('');
    $('#compName').val('');
    $('#compType').val('allowance');
    $('#compCalcType').val('percentage');
    $('#compValue').val('');
    $('#formulaPreview').text('');
    $('#formErrors').addClass('d-none');
    openCompModal();
}

function updateFormulaPreview() {
    var name = ($('#compName').val() || 'component').toLowerCase().replace(/\s+/g, '_');
    var val  = $('#compValue').val() || '0';
    var calc = $('#compCalcType').val();
    if (calc === 'percentage') {
        $('#formulaPreview').text(name + ' = ' + val + ' / 100 * ctc');
    } else {
        $('#formulaPreview').text(name + ' = ' + val + ' (fixed amount)');
    }
}

function saveComponent() {
    var id      = $('#compId').val();
    var action  = id ? 'update' : 'create';
    var idParam = id ? '&id=' + id : '';

    $.post(COMP_URL + '&action=' + action + idParam, {
        csrf_token:       CSRF,
        name:             $('#compName').val().trim(),
        type:             $('#compType').val(),
        calculation_type: $('#compCalcType').val(),
        value:            $('#compValue').val()
    }, function (res) {
        if (res.success) {
            closeCompModal();
            compTable.ajax.reload();
            Swal.fire({ icon: 'success', title: res.message, timer: 1500, showConfirmButton: false });
        } else {
            $('#formErrors').html(res.message).removeClass('d-none');
        }
    });
}
</script>
PAGEJS;
?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
