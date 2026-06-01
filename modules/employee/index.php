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
.emp-list-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 18px;
    border-bottom: 1px solid var(--border);
    flex-wrap: wrap;
    gap: 10px;
}
.emp-list-title {
    font-size: 17px;
    font-weight: 700;
    flex: 1;
    text-align: center;
}
.emp-list-show {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: var(--muted);
    white-space: nowrap;
}
.emp-list-show select {
    padding: 3px 6px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    font-size: 13px;
    background: var(--bg);
    color: var(--text);
}
.emp-list-actions { display: flex; gap: 8px; align-items: center; }
.emp-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.emp-table thead tr { background: #1e2a3a; color: #fff; }
.emp-table thead th {
    padding: 10px 12px;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .05em;
    white-space: nowrap;
    cursor: pointer;
    user-select: none;
    border: none;
}
.emp-table thead th .sort-icon {
    display: inline-flex;
    flex-direction: column;
    margin-left: 5px;
    vertical-align: middle;
    gap: 1px;
}
.emp-table thead th .sort-icon span { width: 0; height: 0; display: block; }
.emp-table thead th .sort-icon .up   { border-left: 4px solid transparent; border-right: 4px solid transparent; border-bottom: 4px solid rgba(255,255,255,.4); }
.emp-table thead th .sort-icon .down { border-left: 4px solid transparent; border-right: 4px solid transparent; border-top:    4px solid rgba(255,255,255,.4); }
.emp-table thead th.sort-asc  .sort-icon .up   { border-bottom-color: #fff; }
.emp-table thead th.sort-desc .sort-icon .down { border-top-color:    #fff; }
.emp-table tbody tr { border-bottom: 1px solid var(--border); }
.emp-table tbody tr:hover { background: var(--bg-subtle); }
.emp-table tbody td { padding: 9px 12px; vertical-align: middle; }
.emp-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 10px 18px;
    font-size: 12px;
    color: var(--muted);
    border-top: 1px solid var(--border);
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
.emp-status-pill {
    display: inline-block;
    padding: 3px 11px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 700;
}
.esp-active     { background: #d4edda; color: #1a7a40; }
.esp-onleave    { background: #fff3cd; color: #856404; }
.esp-resigned   { background: #f8d7da; color: #842029; }
.esp-terminated { background: #e2e3e5; color: #444; }
.esp-default    { background: #e2e3e5; color: #444; }
.btn-excel {
    border: 1px solid #1a7a40;
    color: #1a7a40;
    background: #fff;
    padding: 6px 14px;
    border-radius: var(--radius);
    font-size: 13px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
    text-decoration: none;
}
.btn-excel:hover { background: #f0faf3; }
.emp-col-filter {
    width: 100%;
    padding: 4px 7px;
    font-size: 11px;
    border: 1px solid rgba(255,255,255,.25);
    border-radius: 3px;
    background: rgba(255,255,255,.1);
    color: #fff;
    box-sizing: border-box;
    outline: none;
}
.emp-col-filter::placeholder { color: rgba(255,255,255,.45); }
.emp-col-filter:focus { border-color: rgba(255,255,255,.6); background: rgba(255,255,255,.18); }
</style>

<div class="page-head">
    <div>
        <h1>Employees</h1>
        <p class="muted"><?= count($employees) ?> records</p>
    </div>
</div>

<?= render_flash() ?>

<div class="card" style="overflow:visible;margin-top:18px">

    <!-- Toolbar -->
    <div class="emp-list-toolbar">
        <div class="emp-list-show">
            Show
            <select id="pageSizeSelect" onchange="empSetPageSize(this.value)">
                <option value="10">10</option>
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="100">100</option>
            </select>
            entries
        </div>

        <div class="emp-list-title">Employees List</div>

        <div class="emp-list-actions">
            <?php if (can('employee','create')): ?>
            <button type="button" class="btn-excel" onclick="openModal('importModal')">
                <i class="fa fa-file-excel"></i> Import Excel
            </button>
            <a href="add_form.php" class="btn btn-primary" accesskey="n">
                + Add Employee
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Table -->
    <div style="overflow-x:auto">
    <table class="emp-table" id="empTable">
        <thead>
        <tr>
            <th onclick="empSort(0)">CODE <span class="sort-icon"><span class="up"></span><span class="down"></span></span></th>
            <th onclick="empSort(1)">NAME <span class="sort-icon"><span class="up"></span><span class="down"></span></span></th>
            <th onclick="empSort(2)">EMAIL <span class="sort-icon"><span class="up"></span><span class="down"></span></span></th>
            <th onclick="empSort(3)">PHONE <span class="sort-icon"><span class="up"></span><span class="down"></span></span></th>
            <th onclick="empSort(4)">DEPARTMENT <span class="sort-icon"><span class="up"></span><span class="down"></span></span></th>
            <th onclick="empSort(5)">DESIGNATION <span class="sort-icon"><span class="up"></span><span class="down"></span></span></th>
            <th onclick="empSort(6)" style="text-align:right">CTC <span class="sort-icon"><span class="up"></span><span class="down"></span></span></th>
            <th onclick="empSort(7)">STATUS <span class="sort-icon"><span class="up"></span><span class="down"></span></span></th>
            <th style="text-align:right;cursor:default">ACTIONS</th>
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
            <td style="color:var(--primary)"><?= h($e['email']) ?></td>
            <td><?= h($e['phone'] ?? '—') ?></td>
            <td><?= h($e['dept_name'] ?? '-') ?></td>
            <td><?= h($e['desig_name'] ?? '-') ?></td>
            <td style="text-align:right;<?= (float)($e['fixed_salary'] ?? 0) > 0 ? 'color:#e6a817;font-weight:600' : '' ?>">
                <?= $ctc ?>
            </td>
            <td><span class="emp-status-pill <?= $sc ?>"><?= h($e['status']) ?></span></td>
            <td style="text-align:right">
                <div class="dropdown">
                    <button class="btn btn-sm dropdown-toggle"
                            type="button"
                            data-bs-toggle="dropdown"
                            aria-expanded="false"
                            style="font-size:12px;padding:4px 12px;background:#1e2a3a;color:#fff;border:none;border-radius:var(--radius)">
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
        <tfoot>
        <tr style="background:#1e2a3a">
            <th><input class="emp-col-filter" data-col="0" type="text" placeholder="Search…"></th>
            <th><input class="emp-col-filter" data-col="1" type="text" placeholder="Search…"></th>
            <th><input class="emp-col-filter" data-col="2" type="text" placeholder="Search…"></th>
            <th><input class="emp-col-filter" data-col="3" type="text" placeholder="Search…"></th>
            <th><input class="emp-col-filter" data-col="4" type="text" placeholder="Search…"></th>
            <th><input class="emp-col-filter" data-col="5" type="text" placeholder="Search…"></th>
            <th><input class="emp-col-filter" data-col="6" type="text" placeholder="Search…" style="text-align:right"></th>
            <th><input class="emp-col-filter" data-col="7" type="text" placeholder="Search…"></th>
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

<!-- Import Modal -->
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

(function () {
    var allRows = Array.from(document.querySelectorAll('#empTbody tr'));
    var filtered = allRows.slice();
    var pageSize = 10;
    var curPage  = 1;
    var sortCol  = -1;
    var sortDir  = 'asc';
    var colFilters = {}; // col index → lowercase string

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

        function btn(label, page, disabled, active) {
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

        container.appendChild(btn('‹', curPage - 1, curPage === 1, false));
        var lo = Math.max(1, curPage - 2), hi = Math.min(pages, curPage + 2);
        for (var p = lo; p <= hi; p++) {
            container.appendChild(btn(p, p, false, p === curPage));
        }
        container.appendChild(btn('›', curPage + 1, curPage === pages, false));
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

        document.querySelectorAll('.emp-col-filter').forEach(function (inp) {
            inp.addEventListener('input', function () {
                var col = this.getAttribute('data-col');
                colFilters[col] = this.value.trim().toLowerCase();
                applyFilters();
            });
        });

        render();
        reinitDropdowns();
    });
}());
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
