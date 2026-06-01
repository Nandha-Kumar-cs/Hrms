<?php
/**
 * Salary Slips — Index / List page
 *
 * Mirrors Laravel salary-slips.index (index.blade.php).
 * Supports filters: search (name/code), department, month, year.
 * Manual pagination (20 per page) replacing Eloquent paginate().
 */

$page_title = 'Salary Slips';
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('payroll');

$db = db();

// ─── Filters ────────────────────────────────────────────────────────────────
$search   = trim($_GET['search']      ?? '');
$deptId   = (int)($_GET['department'] ?? 0);
$fMonth   = (int)($_GET['month']      ?? 0);
$fYear    = (int)($_GET['year']       ?? 0);
$perPage  = 20;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $perPage;
$hasFilter = ($search || $deptId || $fMonth || $fYear);

// ─── WHERE clause ────────────────────────────────────────────────────────────
$where  = ['1=1'];
$params = [];

if ($search) {
    $where[]  = '(e.name LIKE ? OR e.employee_id LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($deptId) {
    $where[]  = 'e.department_id = ?';
    $params[] = $deptId;
}
if ($fMonth > 0 && $fMonth <= 12) {
    $where[]  = 'MONTH(CONCAT(ss.payroll_month, "-01")) = ?';
    $params[] = $fMonth;
}
if ($fYear >= 2000) {
    $where[]  = 'YEAR(CONCAT(ss.payroll_month, "-01")) = ?';
    $params[] = $fYear;
}
$whereStr = implode(' AND ', $where);

// Count
$countSt = $db->prepare(
    "SELECT COUNT(*) FROM salary_slips ss
     JOIN employees e ON e.id = ss.employee_id
     WHERE {$whereStr}"
);
$countSt->execute($params);
$total = (int)$countSt->fetchColumn();

// Slips
$slipSt = $db->prepare(
    "SELECT ss.*, e.name AS emp_name, e.employee_id AS emp_code,
            d.name AS dept_name
     FROM salary_slips ss
     JOIN employees e ON e.id = ss.employee_id
     LEFT JOIN departments d ON d.id = e.department_id
     WHERE {$whereStr}
     ORDER BY ss.payroll_month DESC, e.name
     LIMIT {$perPage} OFFSET {$offset}"
);
$slipSt->execute($params);
$slips = $slipSt->fetchAll();

// Departments for filter dropdown
$departments = $db->query('SELECT id, name FROM departments ORDER BY name')->fetchAll();

// Year range for filter
$currentYear = (int)date('Y');
$years       = range($currentYear + 1, $currentYear - 4);
$pages       = max(1, (int)ceil($total / $perPage));

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="card page-card">
    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <h5 class="mb-0 fw-semibold">
            <i class="fa fa-file-invoice-dollar me-2 text-primary"></i>Salary Slips
        </h5>
        <div class="d-flex gap-2">
            <a href="<?= BASE_URL ?>/modules/payroll/calculate.php" class="btn btn-outline-primary btn-sm">
                <i class="fa fa-calculator me-1"></i>Salary Calculation
            </a>
            <?php if (can('payroll', 'process')): ?>
            <a href="<?= BASE_URL ?>/modules/payroll/generate_slip.php" class="btn btn-primary btn-sm">
                <i class="fa fa-plus me-1"></i>Generate Slip
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Filters -->
    <div class="card-body border-bottom pb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-sm-3">
                <label class="form-label fw-semibold mb-1">Search Employee</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text"><i class="fa fa-search"></i></span>
                    <input type="text" name="search" class="form-control"
                           placeholder="Name or code…" value="<?= h($search) ?>">
                </div>
            </div>
            <div class="col-sm-2">
                <label class="form-label fw-semibold mb-1">Department</label>
                <select name="department" class="form-select form-select-sm">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $deptId === (int)$d['id'] ? 'selected' : '' ?>>
                        <?= h($d['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-2">
                <label class="form-label fw-semibold mb-1">Month</label>
                <select name="month" class="form-select form-select-sm">
                    <option value="">All Months</option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $fMonth === $m ? 'selected' : '' ?>>
                        <?= date('F', mktime(0, 0, 0, $m, 1)) ?>
                    </option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-sm-2">
                <label class="form-label fw-semibold mb-1">Year</label>
                <select name="year" class="form-select form-select-sm">
                    <option value="">All Years</option>
                    <?php foreach ($years as $y): ?>
                    <option value="<?= $y ?>" <?= $fYear === $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-auto d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm px-3">
                    <i class="fa fa-filter me-1"></i>Filter
                </button>
                <?php if ($hasFilter): ?>
                <a href="<?= BASE_URL ?>/modules/payroll/index.php" class="btn btn-outline-secondary btn-sm px-3">
                    <i class="fa fa-times me-1"></i>Clear
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <div class="card-body">
        <?= render_flash() ?>

        <!-- Result count -->
        <div class="text-muted small mb-2">
            <?php if ($total > 0): ?>
            Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $total) ?> of <?= $total ?> slip<?= $total !== 1 ? 's' : '' ?>
            <?php if ($hasFilter): ?>
                <span class="ms-1 text-primary">(filtered)</span>
            <?php endif; ?>
            <?php else: ?>
            No salary slips found<?= $hasFilter ? ' for the selected filters.' : '.' ?>
            <?php endif; ?>
        </div>

        <div class="table-responsive">
            <table class="table table-striped table-hover table-bordered align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>#</th>
                        <th>Employee</th>
                        <th>Department</th>
                        <th>Month</th>
                        <th>Year</th>
                        <th>CTC / Month</th>
                        <th>Net Salary</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$slips): ?>
                    <tr>
                        <td colspan="8" class="text-center text-muted py-4">
                            <i class="fa fa-file-invoice-dollar fa-2x d-block mb-2 opacity-25"></i>
                            No salary slips found<?= $hasFilter ? ' for the selected filters.' : '.' ?>
                        </td>
                    </tr>
                    <?php endif; ?>

                    <?php foreach ($slips as $i => $s):
                        $pm     = explode('-', $s['payroll_month']);
                        $mName  = date('F', mktime(0, 0, 0, (int)($pm[1] ?? 1), 1));
                        $mYear  = $pm[0] ?? '—';
                        $ctc    = (float)($s['fixed_salary'] > 0 ? $s['fixed_salary'] : $s['gross_earnings']);
                        $isInd  = ($s['slip_type'] ?? 'batch') === 'individual';
                    ?>
                    <tr>
                        <td><?= $offset + $i + 1 ?></td>
                        <td>
                            <div class="fw-semibold"><?= h($s['emp_name']) ?></div>
                            <small class="text-muted"><?= h($s['emp_code']) ?></small>
                        </td>
                        <td><small class="text-muted"><?= h($s['dept_name'] ?? '—') ?></small></td>
                        <td><?= h($mName) ?></td>
                        <td><?= h($mYear) ?></td>
                        <td>₹<?= number_format($ctc, 2) ?></td>
                        <td class="fw-semibold text-success">₹<?= number_format((float)$s['net_pay'], 2) ?></td>
                        <td class="text-center" style="white-space:nowrap">
                            <a href="<?= BASE_URL ?>/modules/payroll/slip.php?id=<?= $s['id'] ?>"
                               class="btn btn-sm btn-primary" title="View">
                                <i class="fa fa-eye"></i>
                            </a>
                            <a href="<?= BASE_URL ?>/modules/payroll/slip_pdf.php?id=<?= $s['id'] ?>"
                               class="btn btn-sm btn-danger ms-1" target="_blank" title="PDF">
                                <i class="fa fa-file-pdf"></i>
                            </a>
                            <?php if (can('payroll', 'process')): ?>
                            <form method="POST"
                                  action="<?= BASE_URL ?>/modules/payroll/delete_slip.php"
                                  class="d-inline"
                                  onsubmit="return confirm('Delete salary slip for <?= h(addslashes($s['emp_name'])) ?> (<?= $mName ?> <?= $mYear ?>)?\n\nThis cannot be undone.')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="slip_id" value="<?= $s['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger ms-1" title="Delete">
                                    <i class="fa fa-trash"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($pages > 1): ?>
        <nav class="mt-3 d-flex justify-content-between align-items-center">
            <small class="text-muted">Page <?= $page ?> of <?= $pages ?></small>
            <ul class="pagination pagination-sm mb-0">
                <?php
                $baseQuery = array_filter([
                    'search'     => $search,
                    'department' => $deptId ?: '',
                    'month'      => $fMonth ?: '',
                    'year'       => $fYear  ?: '',
                ]);
                for ($p = 1; $p <= $pages; $p++):
                    $q = http_build_query(array_merge($baseQuery, ['page' => $p]));
                ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a class="page-link"
                       href="<?= BASE_URL ?>/modules/payroll/index.php?<?= $q ?>"><?= $p ?></a>
                </li>
                <?php endfor; ?>
            </ul>
        </nav>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
