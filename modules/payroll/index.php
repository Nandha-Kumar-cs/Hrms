<?php
$page_title = 'Salary Slips';
require_once __DIR__ . '/../../includes/header.php';
require_permission('payroll');

$db = db();

// ─── Filters ────────────────────────────────────────────────────────────────
$search   = trim($_GET['search']     ?? '');
$deptId   = (int)($_GET['department'] ?? 0);
$fMonth   = (int)($_GET['month']      ?? 0);
$fYear    = (int)($_GET['year']       ?? 0);
$perPage  = 20;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $perPage;
$hasFilter = ($search || $deptId || $fMonth || $fYear);

// Build WHERE clause
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
$countSt = $db->prepare("SELECT COUNT(*) FROM salary_slips ss JOIN employees e ON e.id = ss.employee_id WHERE {$whereStr}");
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
$years       = range($currentYear - 3, $currentYear + 1);
$pages       = max(1, (int)ceil($total / $perPage));
?>

<div class="page-head">
    <div>
        <h1>Salary Slips</h1>
        <p class="muted">Individual employee payslips</p>
    </div>
    <div class="head-actions">
        <?php if (can('payroll','process')): ?>
        <a href="<?= BASE_URL ?>/modules/payroll/generate_slip.php" class="btn btn-primary">
            <i class="fa fa-plus"></i> Generate Slip
        </a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/modules/payroll/calculate.php" class="btn btn-ghost">Salary Calculation</a>
        <a href="<?= BASE_URL ?>/modules/payroll/salary_components.php" class="btn btn-ghost">Salary Components</a>
    </div>
</div>

<?= render_flash() ?>

<!-- Filters -->
<form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px">
    <div>
        <label class="form-label" style="font-size:12px">Search Employee</label>
        <input type="text" name="search" value="<?= h($search) ?>" class="form-control form-control-sm"
               placeholder="Name or code…" style="min-width:160px">
    </div>
    <div>
        <label class="form-label" style="font-size:12px">Department</label>
        <select name="department" class="form-select form-select-sm" style="min-width:150px">
            <option value="">All Departments</option>
            <?php foreach ($departments as $d): ?>
            <option value="<?= $d['id'] ?>" <?= $deptId === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label class="form-label" style="font-size:12px">Month</label>
        <select name="month" class="form-select form-select-sm" style="min-width:120px">
            <option value="">All Months</option>
            <?php for ($m = 1; $m <= 12; $m++): ?>
            <option value="<?= $m ?>" <?= $fMonth === $m ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
            <?php endfor; ?>
        </select>
    </div>
    <div>
        <label class="form-label" style="font-size:12px">Year</label>
        <select name="year" class="form-select form-select-sm" style="min-width:90px">
            <option value="">All Years</option>
            <?php foreach ($years as $y): ?>
            <option value="<?= $y ?>" <?= $fYear === $y ? 'selected' : '' ?>><?= $y ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div style="display:flex;gap:6px;align-self:flex-end">
        <button type="submit" class="btn btn-primary btn-sm"><i class="fa fa-search"></i> Filter</button>
        <?php if ($hasFilter): ?>
        <a href="<?= BASE_URL ?>/modules/payroll/index.php" class="btn btn-ghost btn-sm">Clear</a>
        <?php endif; ?>
    </div>
</form>

<!-- Results info -->
<div style="font-size:12px;color:var(--muted);margin-bottom:8px">
    <?php if ($total > 0): ?>
    Showing <?= $offset+1 ?>–<?= min($offset+$perPage,$total) ?> of <?= $total ?> slip<?= $total!=1?'s':'' ?>
    <?php if ($hasFilter): ?><span style="color:var(--primary)"> (filtered)</span><?php endif; ?>
    <?php else: ?>
    No salary slips found<?= $hasFilter ? ' matching your filters' : '' ?>.
    <?php endif; ?>
</div>

<div class="card">
    <div style="overflow-x:auto">
        <table class="data-table" style="font-size:13px">
            <thead><tr>
                <th>#</th>
                <th>Employee</th>
                <th>Department</th>
                <th>Month / Year</th>
                <th class="text-end">CTC / Month</th>
                <th class="text-end">Net Salary</th>
                <th>Type</th>
                <th class="text-end">Actions</th>
            </tr></thead>
            <tbody>
            <?php if (!$slips): ?>
            <tr><td colspan="8" class="empty">
                No salary slips found<?= $hasFilter ? ' for the selected filters. <a href="'.BASE_URL.'/modules/payroll/index.php">Clear filters</a>' : '. <a href="'.BASE_URL.'/modules/payroll/generate_slip.php">Generate a slip →</a>' ?>.
            </td></tr>
            <?php endif; ?>
            <?php foreach ($slips as $i => $s):
                $pm = explode('-', $s['payroll_month']);
                $mName = date('F', mktime(0,0,0,(int)($pm[1]??1),1));
                $mYear = $pm[0] ?? '—';
                $ctc   = $s['fixed_salary'] > 0 ? $s['fixed_salary'] : $s['gross_earnings'];
                $isInd = ($s['slip_type'] ?? 'batch') === 'individual';
            ?>
            <tr>
                <td><?= $offset + $i + 1 ?></td>
                <td>
                    <div style="font-weight:500"><?= h($s['emp_name']) ?></div>
                    <div class="small muted"><?= h($s['emp_code']) ?></div>
                </td>
                <td><?= h($s['dept_name'] ?? '—') ?></td>
                <td><?= h($mName) ?> <?= $mYear ?></td>
                <td class="text-end"><?= money($ctc) ?></td>
                <td class="text-end" style="font-weight:700;color:var(--success)"><?= money($s['net_pay']) ?></td>
                <td>
                    <span class="badge <?= $isInd ? 'bg-primary' : 'bg-secondary' ?>" style="font-size:10px">
                        <?= $isInd ? 'Individual' : 'Batch' ?>
                    </span>
                </td>
                <td class="text-end" style="white-space:nowrap">
                    <a href="<?= BASE_URL ?>/modules/payroll/slip.php?id=<?= $s['id'] ?>"
                       class="btn btn-sm" style="border-color:var(--info);color:var(--info)" title="View">
                        <i class="fa fa-eye"></i>
                    </a>
                    <a href="<?= BASE_URL ?>/modules/payroll/slip_pdf.php?id=<?= $s['id'] ?>"
                       class="btn btn-sm" style="border-color:var(--danger);color:var(--danger)" title="PDF" target="_blank">
                        <i class="fa fa-file-pdf"></i>
                    </a>
                    <?php if (can('payroll','process')): ?>
                    <form method="POST" action="<?= BASE_URL ?>/modules/payroll/delete_slip.php"
                          style="display:inline"
                          onsubmit="return confirm('Delete salary slip for <?= h($s['emp_name']) ?> (<?= $mName ?> <?= $mYear ?>)?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="slip_id" value="<?= $s['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
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
</div>

<!-- Pagination -->
<?php if ($pages > 1): ?>
<div style="margin-top:12px;display:flex;gap:4px;justify-content:center;flex-wrap:wrap">
    <?php
    $baseUrl = BASE_URL . '/modules/payroll/index.php?' . http_build_query(array_filter([
        'search'     => $search,
        'department' => $deptId ?: '',
        'month'      => $fMonth ?: '',
        'year'       => $fYear  ?: '',
    ]));
    for ($p = 1; $p <= $pages; $p++):
        $sep = str_contains($baseUrl, '?') && strlen($baseUrl) > strlen(BASE_URL . '/modules/payroll/index.php?') ? '&' : '?';
    ?>
    <a href="<?= $baseUrl ?>&page=<?= $p ?>"
       class="btn btn-sm <?= $p === $page ? 'btn-primary' : 'btn-ghost' ?>"
       style="min-width:34px;text-align:center"><?= $p ?></a>
    <?php endfor; ?>
</div>
<div style="margin-top:6px;text-align:center;font-size:11px;color:var(--muted)">Page <?= $page ?> of <?= $pages ?></div>
<?php endif; ?>

<div style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap">
    <a href="<?= BASE_URL ?>/modules/payroll/salary_components.php" class="btn btn-ghost btn-sm">Salary Components</a>
    <a href="<?= BASE_URL ?>/modules/payroll/salary_structure.php"  class="btn btn-ghost btn-sm">Salary Structures</a>
    <a href="<?= BASE_URL ?>/modules/payroll/process.php"           class="btn btn-ghost btn-sm">Batch Process</a>
    <a href="<?= BASE_URL ?>/modules/payroll/history.php"           class="btn btn-ghost btn-sm">Payroll History</a>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
