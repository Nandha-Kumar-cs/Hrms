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
     ORDER BY e.employee_id'
)->fetchAll();

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

<style>
/* Toolbar */
.emp-list-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1rem;
    flex-wrap: wrap;
    gap: 10px;
}
.emp-list-show {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: var(--muted);
}
.emp-list-title { font-size: 17px; font-weight: 700; flex: 1; text-align: center; }
.emp-list-actions { display: flex; gap: 8px; align-items: center; }

/* Sort icons on thead th */
#empTable thead th { cursor: pointer; user-select: none; white-space: nowrap; }
#empTable thead th .sort-icon {
    display: inline-flex;
    flex-direction: column;
    margin-left: 5px;
    vertical-align: middle;
    gap: 1px;
}
#empTable thead th .sort-icon span { width: 0; height: 0; display: block; }
#empTable thead th .sort-icon .up   { border-left: 4px solid transparent; border-right: 4px solid transparent; border-bottom: 4px solid rgba(255,255,255,.4); }
#empTable thead th .sort-icon .down { border-left: 4px solid transparent; border-right: 4px solid transparent; border-top:    4px solid rgba(255,255,255,.4); }
#empTable thead th.sort-asc  .sort-icon .up   { border-bottom-color: #fff; }
#empTable thead th.sort-desc .sort-icon .down { border-top-color:    #fff; }
#empTable thead th:last-child { cursor: default; }

/* Status badges */
.emp-status-pill { display: inline-block; padding: 3px 11px; border-radius: 12px; font-size: 11px; font-weight: 700; }
.esp-active     { background: #d4edda; color: #1a7a40; }
.esp-onleave    { background: #fff3cd; color: #856404; }
.esp-resigned   { background: #f8d7da; color: #842029; }
.esp-terminated { background: #e2e3e5; color: #444; }
.esp-default    { background: #e2e3e5; color: #444; }

/* Pagination */
.emp-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 0 0;
    font-size: 12px;
    color: var(--muted);
    flex-wrap: wrap;
    gap: 8px;
}
.emp-pagination .pages { display: flex; gap: 4px; }
.emp-pagination .pages button {
    padding: 3px 9px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--bg);
    cursor: pointer;
    font-size: 12px;
    color: var(--text);
}
.emp-pagination .pages button.active { background: var(--primary); color: #fff; border-color: var(--primary); }
.emp-pagination .pages button:disabled { opacity: .4; cursor: default; }
</style>

<div class="page-head">
    <div>
        <h1>Employees</h1>
        <p class="muted"><?= count($employees) ?> records</p>
    </div>
</div>

<?= render_flash() ?>

<div class="card" style="overflow:visible;margin-top:18px">
    <div class="card-body" style="padding:18px">

        <!-- Toolbar -->
        <div class="emp-list-toolbar">
            <div class="emp-list-show">
                <label class="mb-0 text-muted small">Show</label>
                <select id="entriesSelect" class="form-select form-select-sm" style="width:auto" onchange="empSetPageSize(this.value)">
                    <option value="10">10</option>
                    <option value="25">25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
                <label class="mb-0 text-muted small">entries</label>
            </div>

            <div class="emp-list-title">Employees List</div>

            <div class="emp-list-actions">
                <?php if (can('employee','create')): ?>
                <button class="btn btn-outline-success btn-sm" onclick="openModal('importModal')">
                    <i class="fa fa-file-excel me-1"></i>Import Excel
                </button>
                <a href="add_form.php" class="btn btn-primary btn-sm" accesskey="n">
                    <i class="fa fa-plus me-1"></i> Add Employee
                </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Table -->
        <div class="table-responsive">
        <table id="empTable" class="table table-striped table-hover table-bordered align-middle w-100">
            <thead class="table-dark">
            <tr>
                <th onclick="empSort(0)">Code <span class="sort-icon"><span class="up"></span><span class="down"></span></span></th>
                <th onclick="empSort(1)">Name <span class="sort-icon"><span class="up"></span><span class="down"></span></span></th>
                <th onclick="empSort(2)">Email <span class="sort-icon"><span class="up"></span><span class="down"></span></span></th>
                <th onclick="empSort(3)">Phone <span class="sort-icon"><span class="up"></span><span class="down"></span></span></th>
                <th onclick="empSort(4)">Department <span class="sort-icon"><span class="up"></span><span class="down"></span></span></th>
                <th onclick="empSort(5)">Designation <span class="sort-icon"><span class="up"></span><span class="down"></span></span></th>
                <th onclick="empSort(6)">CTC <span class="sort-icon"><span class="up"></span><span class="down"></span></span></th>
                <th onclick="empSort(7)">Status <span class="sort-icon"><span class="up"></span><span class="down"></span></span></th>
                <th>Action</th>
            </tr>
            </thead>
            <tbody id="empTbody">
            <?php foreach ($employees as $e):
                $eid = $e['id'];
                $sc  = [
                    'Active'     => 'esp-active',
                    'On Leave'   => 'esp-onleave',
                    'Resigned'   => 'esp-resigned',
                    'Terminated' => 'esp-terminated',
                ][$e['status']] ?? 'esp-default';
                $ctc = number_format((float)($e['fixed_salary'] ?? 0), 2);
            ?>
            <tr>
                <td><code><?= h($e['employee_id']) ?></code></td>
                <td style="white-space:nowrap"><?= h($e['name']) ?></td>
                <td><?= h($e['email']) ?></td>
                <td><?= h($e['phone'] ?? '—') ?></td>
                <td><?= h($e['dept_name'] ?? '-') ?></td>
                <td><?= h($e['desig_name'] ?? '-') ?></td>
                <td class="<?= (float)($e['fixed_salary'] ?? 0) > 0 ? 'text-warning fw-semibold' : '' ?>">
                    <?= $ctc ?>
                </td>
                <td><span class="emp-status-pill <?= $sc ?>"><?= h($e['status']) ?></span></td>
                <td>
                    <div class="dropdown">
                        <button class="btn btn-primary btn-sm dropdown-toggle"
                                type="button"
                                data-bs-toggle="dropdown"
                                aria-expanded="false">
                            Options
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
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
            <tfoot>
            <tr>
                <th></th><th></th><th></th><th></th>
                <th></th><th></th><th></th><th></th>
                <th></th>
            </tr>
            </tfoot>
        </table>
        </div>

        <!-- Pagination -->
        <div class="emp-pagination">
            <span id="empPagInfo"></span>
            <div class="pages" id="empPagButtons"></div>
        </div>

    </div>
</div>

<!-- Import Modal -->
<div class="modal" id="importModal">
    <div class="modal-content" style="max-width:620px;width:92%">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px">
            <h3 style="margin:0;font-size:16px;font-weight:700">
                <i class="fa fa-file-excel me-2 text-success"></i>Import Employees from Excel
            </h3>
            <button type="button" onclick="closeModal('importModal')"
                    style="background:none;border:none;font-size:20px;line-height:1;cursor:pointer;color:var(--text-muted);padding:0 4px">&times;</button>
        </div>
        <div style="background:var(--bg-secondary);border:1px solid var(--border);border-radius:var(--radius);padding:10px 14px;font-size:13px;margin-bottom:14px">
            <i class="fa fa-circle-info me-1" style="color:var(--primary)"></i>
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
                <input type="file" name="import_file" class="form-control" accept=".csv,.xlsx,.xls" required>
                <div style="font-size:12px;color:var(--text-muted);margin-top:4px">CSV or XLSX, max 5 MB</div>
            </div>
            <div style="display:flex;gap:8px;justify-content:flex-end;padding-top:14px;border-top:1px solid var(--border)">
                <button type="button" class="btn btn-secondary btn-sm" onclick="closeModal('importModal')">Cancel</button>
                <button type="submit" class="btn btn-success btn-sm">
                    <i class="fa fa-upload me-1"></i>Import Now
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

(function () {
    var allRows    = Array.from(document.querySelectorAll('#empTbody tr'));
    var filtered   = allRows.slice();
    var pageSize   = 10;
    var curPage    = 1;
    var sortCol    = -1;
    var sortDir    = 'asc';
    var colFilters = {};

    // Inject search inputs into tfoot (matches Laravel DataTables initComplete pattern)
    // Searchable column indices: 0=Code,1=Name,2=Email,3=Phone,4=Dept,5=Desig,7=Status
    var searchableCols = [0, 1, 2, 3, 4, 5, 7];
    var tfootCells = document.querySelectorAll('#empTable tfoot th');
    searchableCols.forEach(function (colIdx) {
        var th = tfootCells[colIdx];
        if (!th) return;
        var inp = document.createElement('input');
        inp.type = 'text';
        inp.className = 'form-control form-control-sm';
        inp.placeholder = 'Search...';
        inp.setAttribute('data-col', colIdx);
        inp.addEventListener('keyup', function () {
            colFilters[colIdx] = this.value.trim().toLowerCase();
            applyFilters();
        });
        th.appendChild(inp);
    });

    function cellText(tr, col) {
        var td = tr.querySelectorAll('td')[col];
        return td ? td.textContent.trim() : '';
    }

    function applyFilters() {
        filtered = allRows.filter(function (tr) {
            return Object.keys(colFilters).every(function (col) {
                var q = colFilters[col];
                return !q || cellText(tr, parseInt(col, 10)).toLowerCase().indexOf(q) !== -1;
            });
        });
        curPage = 1;
        render();
        reinitDropdowns();
    }

    function render() {
        var total = filtered.length;
        var pages = Math.max(1, Math.ceil(total / pageSize));
        if (curPage > pages) curPage = pages;
        var start = (curPage - 1) * pageSize;
        var end   = Math.min(start + pageSize, total);

        allRows.forEach(function (r) { r.style.display = 'none'; });
        filtered.slice(start, end).forEach(function (r) { r.style.display = ''; });

        document.getElementById('empPagInfo').textContent =
            total === 0
                ? 'No entries found'
                : 'Showing ' + (start + 1) + ' to ' + end + ' of ' + total + ' entries';

        var container = document.getElementById('empPagButtons');
        container.innerHTML = '';

        function mkBtn(label, page, disabled, active) {
            var b = document.createElement('button');
            b.textContent = label;
            if (active)   b.classList.add('active');
            if (disabled) b.disabled = true;
            b.addEventListener('click', function () {
                curPage = page;
                render();
                reinitDropdowns();
            });
            return b;
        }

        container.appendChild(mkBtn('‹', curPage - 1, curPage === 1, false));
        var lo = Math.max(1, curPage - 2), hi = Math.min(pages, curPage + 2);
        for (var p = lo; p <= hi; p++) {
            container.appendChild(mkBtn(p, p, false, p === curPage));
        }
        container.appendChild(mkBtn('›', curPage + 1, curPage === pages, false));
    }

    window.empSetPageSize = function (v) {
        pageSize = parseInt(v, 10) || 10;
        curPage  = 1;
        render();
        reinitDropdowns();
    };

    window.empSort = function (col) {
        var ths = document.querySelectorAll('#empTable thead th');
        if (sortCol === col) {
            sortDir = sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            sortCol = col;
            sortDir = 'asc';
        }
        ths.forEach(function (th, i) {
            th.classList.remove('sort-asc', 'sort-desc');
            if (i === col) th.classList.add('sort-' + sortDir);
        });

        var numeric = (col === 6);
        filtered.sort(function (a, b) {
            var av = cellText(a, col);
            var bv = cellText(b, col);
            if (numeric) {
                av = parseFloat(av.replace(/,/g, '')) || 0;
                bv = parseFloat(bv.replace(/,/g, '')) || 0;
                return sortDir === 'asc' ? av - bv : bv - av;
            }
            var cmp = av.toLowerCase().localeCompare(bv.toLowerCase());
            return sortDir === 'asc' ? cmp : -cmp;
        });

        var tbody = document.getElementById('empTbody');
        filtered.forEach(function (r) { tbody.appendChild(r); });
        curPage = 1;
        render();
        reinitDropdowns();
    };

    function reinitDropdowns() {
        if (typeof bootstrap !== 'undefined') {
            document.querySelectorAll('[data-bs-toggle="dropdown"]').forEach(function (el) {
                if (!bootstrap.Dropdown.getInstance(el)) {
                    new bootstrap.Dropdown(el, { popperConfig: { strategy: 'fixed' } });
                }
            });
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.btn-delete').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (confirm('Delete employee "' + this.dataset.name + '"?\nThis will also remove their user account.')) {
                    document.getElementById('deleteId').value = this.dataset.id;
                    document.getElementById('deleteForm').submit();
                }
            });
        });

        render();
        reinitDropdowns();
    });
}());
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
