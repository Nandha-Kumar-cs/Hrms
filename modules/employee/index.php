<?php
$page_title = 'Employees';
require_once __DIR__ . '/../../includes/header.php';
require_permission('employee');

$db = db();
$employees = $db->query(
    'SELECT e.*, d.name AS dept_name, des.name AS desig_name
     FROM employees e
     LEFT JOIN departments d ON d.id = e.department_id
     LEFT JOIN designations des ON des.id = e.designation_id
     ORDER BY e.name'
)->fetchAll();

// Pre-fetch letter/slip existence for action dropdown (avoids N+1)
$hasOffer = array_flip($db->query(
    "SELECT DISTINCT employee_id FROM letters WHERE type='Offer'"
)->fetchAll(PDO::FETCH_COLUMN));

$hasConfirm = array_flip($db->query(
    "SELECT DISTINCT employee_id FROM letters WHERE type='Confirmation'"
)->fetchAll(PDO::FETCH_COLUMN));

$hasSlip = array_flip($db->query(
    "SELECT DISTINCT employee_id FROM salary_slips"
)->fetchAll(PDO::FETCH_COLUMN));

$hasIncrement = array_flip($db->query(
    "SELECT DISTINCT employee_id FROM letters WHERE type='Increment'"
)->fetchAll(PDO::FETCH_COLUMN));
?>

<div class="page-head">
    <div>
        <h1>Employees</h1>
        <p class="muted"><?= count($employees) ?> records</p>
    </div>
    <div class="head-actions">
        <?php if (can('employee','create')): ?>
        <button type="button" class="btn" onclick="openModal('importModal')">
            <i class="fa fa-file-import"></i> Import
        </button>
        <a href="add_form.php" class="btn btn-primary" accesskey="n">
            + New Employee
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="card" style="overflow:visible">
    <div class="card-head">
        <h3>All Employees</h3>
        <div class="search">
            <input type="search" placeholder="Filter..." id="tableSearch"
                   oninput="filterTable(this.value)">
        </div>
    </div>
    <div style="overflow-x:auto;min-height:420px">
    <table class="data-table" id="empTable">
        <thead><tr>
            <th>Employee</th>
            <th>ID</th>
            <th>Department</th>
            <th>Designation</th>
            <th>Join Date</th>
            <th>Type</th>
            <th>Status</th>
            <th class="r">Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($employees as $e):
            $eid = $e['id'];
            $sc = ['Active'=>'pill-success','On Leave'=>'pill-warn','Resigned'=>'pill-danger','Terminated'=>'pill-cancelled'];
        ?>
        <tr>
            <td>
                <div style="display:flex;align-items:center;gap:8px">
                    <?php if ($e['photo']): ?>
                    <img src="<?= BASE_URL ?>/uploads/photos/<?= h($e['photo']) ?>"
                         style="width:34px;height:34px;border-radius:50%;object-fit:cover;flex-shrink:0">
                    <?php else: ?>
                    <div class="emp-avatar" style="flex-shrink:0"><?= strtoupper(mb_substr($e['name'],0,1)) ?></div>
                    <?php endif; ?>
                    <div>
                        <div style="font-weight:500"><?= h($e['name']) ?></div>
                        <div class="small muted"><?= h($e['email']) ?></div>
                    </div>
                </div>
            </td>
            <td><code><?= h($e['employee_id']) ?></code></td>
            <td><?= h($e['dept_name'] ?? '—') ?></td>
            <td><?= h($e['desig_name'] ?? '—') ?></td>
            <td><?= $e['join_date'] ? date_fmt($e['join_date']) : '—' ?></td>
            <td><span class="pill pill-info"><?= h($e['employment_type'] ?? '—') ?></span></td>
            <td><span class="pill <?= $sc[$e['status']] ?? 'pill-neutral' ?>"><?= h($e['status']) ?></span></td>
            <td class="r">
                <!-- Bootstrap 5 dropdown (BS5 JS loaded in footer) -->
                <div class="dropdown">
                    <button class="btn btn-sm dropdown-toggle"
                            type="button"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                            style="font-size:12px;padding:4px 10px">
                        Options
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">

                        <li>
                            <a class="dropdown-item" href="view.php?id=<?= $eid ?>">
                                <i class="fa fa-user me-2 text-secondary"></i>View Profile
                            </a>
                        </li>

                        <?php if (can('employee','edit')): ?>
                        <li>
                            <a class="dropdown-item" href="edit.php?id=<?= $eid ?>">
                                <i class="fa fa-pen-to-square me-2 text-primary"></i>Edit Employee
                            </a>
                        </li>
                        <?php endif; ?>

                        <?php if (can('employee','delete')): ?>
                        <li>
                            <button class="dropdown-item text-danger btn-delete"
                                    data-id="<?= $eid ?>"
                                    data-name="<?= h($e['name']) ?>">
                                <i class="fa fa-trash me-2"></i>Delete Employee
                            </button>
                        </li>
                        <?php endif; ?>

                        <li><hr class="dropdown-divider"></li>

                        <?php if (can('letters','create')): ?>
                        <li>
                            <?php if (isset($hasOffer[$eid])): ?>
                            <a class="dropdown-item" href="<?= BASE_URL ?>/modules/letters/view.php?employee_id=<?= $eid ?>&type=offer">
                                <i class="fa fa-envelope-open-text me-2 text-info"></i>View Offer Letter
                            </a>
                            <?php else: ?>
                            <a class="dropdown-item" href="<?= BASE_URL ?>/modules/letters/create.php?employee_id=<?= $eid ?>&type=offer">
                                <i class="fa fa-plus me-2 text-success"></i>Create Offer Letter
                            </a>
                            <?php endif; ?>
                        </li>
                        <li>
                            <?php if (isset($hasConfirm[$eid])): ?>
                            <a class="dropdown-item" href="<?= BASE_URL ?>/modules/letters/view.php?employee_id=<?= $eid ?>&type=confirmation">
                                <i class="fa fa-circle-check me-2 text-info"></i>View Confirmation Letter
                            </a>
                            <?php else: ?>
                            <a class="dropdown-item" href="<?= BASE_URL ?>/modules/letters/create.php?employee_id=<?= $eid ?>&type=confirmation">
                                <i class="fa fa-plus me-2 text-success"></i>Create Confirmation Letter
                            </a>
                            <?php endif; ?>
                        </li>
                        <?php endif; ?>

                        <?php if (can('payroll','view')): ?>
                        <li>
                            <?php if (isset($hasSlip[$eid])): ?>
                            <a class="dropdown-item" href="<?= BASE_URL ?>/modules/payroll/slip.php?employee_id=<?= $eid ?>">
                                <i class="fa fa-money-bill-wave me-2 text-info"></i>View Salary Slip
                            </a>
                            <?php else: ?>
                            <a class="dropdown-item" href="<?= BASE_URL ?>/modules/payroll/process.php?employee_id=<?= $eid ?>">
                                <i class="fa fa-plus me-2 text-success"></i>Create Salary Slip
                            </a>
                            <?php endif; ?>
                        </li>
                        <?php endif; ?>

                        <?php if (can('letters','create')): ?>
                        <li>
                            <?php if (isset($hasIncrement[$eid])): ?>
                            <a class="dropdown-item" href="<?= BASE_URL ?>/modules/letters/view.php?employee_id=<?= $eid ?>&type=increment">
                                <i class="fa fa-arrow-trend-up me-2 text-info"></i>View Increment Letter
                            </a>
                            <?php else: ?>
                            <a class="dropdown-item" href="<?= BASE_URL ?>/modules/letters/create.php?employee_id=<?= $eid ?>&type=increment">
                                <i class="fa fa-plus me-2 text-success"></i>Create Increment Letter
                            </a>
                            <?php endif; ?>
                        </li>
                        <?php endif; ?>

                    </ul>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- Import Modal (custom modal system) -->
<div class="modal" id="importModal">
    <div class="modal-content" style="max-width:620px;width:92%">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
            <h3 style="margin:0;font-size:16px;font-weight:700">
                <i class="fa fa-file-import" style="margin-right:8px;color:var(--primary)"></i>Import Employees
            </h3>
            <button type="button" onclick="closeModal('importModal')"
                    style="background:none;border:none;font-size:20px;line-height:1;cursor:pointer;color:var(--text-muted);padding:0 4px">&times;</button>
        </div>

        <div style="background:var(--bg-secondary);border:1px solid var(--border);border-radius:var(--radius);padding:10px 14px;font-size:13px;margin-bottom:14px">
            <i class="fa fa-circle-info" style="margin-right:6px;color:var(--primary)"></i>
            Upload a <strong>CSV</strong> or <strong>XLSX</strong> file.
            Rows matching an existing <code>employee_id</code> or email are <strong>updated</strong>; others are <strong>inserted</strong>.
            New employees get a login with password <code>Hrms@</code> + last 4 chars of code.
        </div>

        <div style="display:flex;gap:20px;margin-bottom:14px;flex-wrap:wrap">
            <div>
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:5px">Required</div>
                <div style="display:flex;gap:5px">
                    <span class="pill pill-danger">name</span>
                    <span class="pill pill-danger">email</span>
                </div>
            </div>
            <div>
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:5px">Optional</div>
                <div style="display:flex;flex-wrap:wrap;gap:4px">
                    <?php foreach (['employee_id','phone','gender','dob','department','designation','join_date','employment_type','pan_number','aadhaar_number','uan_number'] as $col): ?>
                    <span class="pill pill-neutral"><?= $col ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <form method="POST" enctype="multipart/form-data"
              action="<?= BASE_URL ?>/modules/employee/import.php" id="importForm">
            <?= csrf_field() ?>
            <div style="margin-bottom:16px">
                <label style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);display:block;margin-bottom:6px">
                    Select File <span style="color:var(--danger)">*</span>
                </label>
                <input type="file" name="import_file"
                       style="display:block;width:100%;padding:8px 11px;border:1px solid var(--border-strong);border-radius:var(--radius);font-size:13.5px;background:white;box-sizing:border-box"
                       accept=".csv,.xlsx" required>
                <div style="font-size:12px;color:var(--text-muted);margin-top:4px">CSV or XLSX, max 5 MB</div>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;padding-top:14px;border-top:1px solid var(--border)">
                <button type="button" class="btn" onclick="closeModal('importModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-upload" style="margin-right:5px"></i>Upload &amp; Import
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete form -->
<form id="deleteForm" method="POST"
      action="<?= BASE_URL ?>/modules/employee/create.php"
      style="display:none">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="deleteId">
</form>

<script>
window.BASE_URL = '<?= BASE_URL ?>';
document.addEventListener('DOMContentLoaded', function () {
    // Delete confirm
    document.querySelectorAll('.btn-delete').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (confirm('Delete employee "' + this.dataset.name + '"?\nThis will also remove their user account.')) {
                document.getElementById('deleteId').value = this.dataset.id;
                document.getElementById('deleteForm').submit();
            }
        });
    });

    // Re-init dropdowns with fixed strategy so they escape overflow containers
    if (typeof bootstrap !== 'undefined') {
        document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(function (el) {
            new bootstrap.Dropdown(el, { popperConfig: { strategy: 'fixed' } });
        });
    }
});

// Client-side table filter
function filterTable(q) {
    var rows = document.querySelectorAll('#empTable tbody tr');
    q = q.toLowerCase();
    rows.forEach(function (tr) {
        tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
