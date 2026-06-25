<?php
/**
 * Reports → Monthly Benefits
 *
 * Ported from Employee_Management's BenefitReportController::monthlyBenefits.
 * Lists active employee benefits for a chosen month/year, grouped by fund type,
 * with per-type subtotals and grand totals. Supports department / employee /
 * status filters and CSV (Excel), PDF (FPDF) and Print exports.
 *
 * Data source: employee_benefits (HRMS uses a free-text `fund_type`, not an FK,
 * so grouping is by that string; colour is looked up from benefit_fund_types by
 * name when it matches, otherwise a palette colour is assigned).
 *
 * Exports run BEFORE any HTML output (they emit their own headers and exit).
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('report_benefits', 'view');
// Self-scoped employees see only their own row in this report (not blocked).
$scopeEmp = scope_employee_id();

$db = db();

// ── Filters (validated) ──────────────────────────────────────────────────────
$month  = (int)($_GET['month'] ?? date('n'));
$year   = (int)($_GET['year']  ?? date('Y'));
if ($month < 1 || $month > 12)    $month = (int)date('n');
if ($year < 2000 || $year > 2100) $year  = (int)date('Y');
$deptId = (int)($_GET['department'] ?? 0);
$empId  = (int)($_GET['emp_id'] ?? 0);
$status = (string)($_GET['status'] ?? 'active');
if (!in_array($status, ['active', 'inactive', 'all'], true)) $status = 'active';
$export = (string)($_GET['export'] ?? '');
if ($export !== '' && !can('report_benefits', 'export')) { http_response_code(403); exit('You do not have permission to export this report.'); }

$monthName = date('F', mktime(0, 0, 0, $month, 1));

// ── Query ────────────────────────────────────────────────────────────────────
$sql = "SELECT eb.id, eb.fund_type, eb.amount, eb.effective_month, eb.status,
               e.name AS emp_name, e.employee_id AS emp_code, e.department_id,
               d.name AS dept_name, bt.color AS type_color
        FROM employee_benefits eb
        JOIN employees e        ON e.id = eb.employee_id
        LEFT JOIN departments d ON d.id = e.department_id
        LEFT JOIN benefit_fund_types bt
               ON LOWER(bt.name) COLLATE utf8mb4_general_ci = LOWER(eb.fund_type) COLLATE utf8mb4_general_ci
        WHERE MONTH(eb.effective_month) = ? AND YEAR(eb.effective_month) = ?";
$params = [$month, $year];
if ($scopeEmp)         { $sql .= ' AND e.id = ?';            $params[] = $scopeEmp; }
if ($status !== 'all') { $sql .= ' AND eb.status = ?';        $params[] = $status; }
if ($deptId)           { $sql .= ' AND e.department_id = ?';  $params[] = $deptId; }
if ($empId)            { $sql .= ' AND e.id = ?';             $params[] = $empId; }
$sql .= ' ORDER BY eb.fund_type ASC, e.name ASC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Group by fund type ───────────────────────────────────────────────────────
$palette = ['primary', 'success', 'info', 'warning', 'danger', 'dark'];
$groups  = [];
$gi = 0;
foreach ($rows as $r) {
    $key = $r['fund_type'];
    if (!isset($groups[$key])) {
        $groups[$key] = [
            'name'  => ($key !== '' ? $key : 'Unspecified'),
            'color' => $r['type_color'] ?: $palette[$gi % count($palette)],
            'count' => 0,
            'total' => 0.0,
            'items' => [],
        ];
        $gi++;
    }
    $groups[$key]['items'][] = $r;
    $groups[$key]['count']++;
    $groups[$key]['total'] += (float)$r['amount'];
}
$grandTotal = array_sum(array_column($groups, 'total'));
$empCount   = count($rows);
$typeCount  = count($groups);

$deptName = $deptId ? (string)$db->query('SELECT name FROM departments WHERE id=' . $deptId)->fetchColumn() : '';

// Selected-employee label for export headers.
$empLabel = '';
if ($empId) {
    $el = $db->prepare('SELECT CONCAT(name, " (", employee_id, ")") FROM employees WHERE id = ?');
    $el->execute([$empId]);
    $empLabel = (string)($el->fetchColumn() ?: '');
}

// ── CSV (Excel) export ───────────────────────────────────────────────────────
if ($export === 'csv') {
    $fn = 'monthly_benefits_' . $year . '_' . str_pad((string)$month, 2, '0', STR_PAD_LEFT) . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Monthly Benefits Report — ' . $monthName . ' ' . $year]);
    $filterLine = 'Status: ' . ucfirst($status);
    if ($deptName) $filterLine .= ' | Department: ' . $deptName;
    if ($empLabel !== '') $filterLine .= ' | Employee: ' . $empLabel;
    fputcsv($out, [$filterLine]);
    fputcsv($out, []);
    fputcsv($out, ['Fund Type', 'Emp Code', 'Employee', 'Department', 'Effective Month', 'Amount', 'Status']);
    foreach ($groups as $g) {
        foreach ($g['items'] as $it) {
            fputcsv($out, [
                $g['name'], $it['emp_code'], $it['emp_name'], $it['dept_name'] ?? '—',
                date('M Y', strtotime($it['effective_month'])),
                number_format((float)$it['amount'], 2), ucfirst($it['status']),
            ]);
        }
        fputcsv($out, ['', '', '', '', $g['name'] . ' — Subtotal', number_format($g['total'], 2), '']);
    }
    fputcsv($out, []);
    fputcsv($out, ['', '', '', '', 'GRAND TOTAL', number_format($grandTotal, 2), '']);
    fputcsv($out, ['', '', '', '', 'Employees Covered', $empCount, '']);
    fclose($out);
    exit;
}

// ── PDF export (FPDF) ────────────────────────────────────────────────────────
if ($export === 'pdf') {
    require_once __DIR__ . '/../../lib/fpdf/fpdf.php';
    $t = fn ($s) => iconv('UTF-8', 'windows-1252//TRANSLIT', (string)$s) ?: (string)$s;

    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetTitle('Monthly Benefits ' . $monthName . ' ' . $year);
    $pdf->SetAutoPageBreak(true, 12);
    $pdf->AddPage();

    $pdf->SetFont('Arial', 'B', 15);
    $pdf->Cell(0, 8, $t('Monthly Benefits Report'), 0, 1, 'C');
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 6, $t($monthName . ' ' . $year), 0, 1, 'C');
    $filters = 'Status: ' . ucfirst($status);
    if ($deptName)        $filters .= '   Department: ' . $deptName;
    if ($empLabel !== '') $filters .= '   Employee: ' . $empLabel;
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->Cell(0, 5, $t($filters), 0, 1, 'C');
    $pdf->Ln(1);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 6, $t('Total Benefits: Rs. ' . number_format($grandTotal, 2)
        . '    Employees Covered: ' . $empCount . '    Fund Types: ' . $typeCount), 0, 1, 'C');
    $pdf->Ln(2);

    $w = [10, 72, 43, 30, 35]; // # / Employee / Department / Eff.Month / Amount = 190mm
    foreach ($groups as $g) {
        if ($pdf->GetY() > 250) $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->SetFillColor(33, 37, 41);
        $pdf->SetTextColor(255);
        $pdf->Cell(0, 7, $t('  ' . $g['name'] . '   (' . $g['count'] . ' employee(s)  ·  Rs. ' . number_format($g['total'], 2) . ')'), 0, 1, 'L', true);
        $pdf->SetTextColor(0);

        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetFillColor(235, 235, 235);
        $pdf->Cell($w[0], 6, '#', 1, 0, 'C', true);
        $pdf->Cell($w[1], 6, $t('Employee'), 1, 0, 'L', true);
        $pdf->Cell($w[2], 6, $t('Department'), 1, 0, 'L', true);
        $pdf->Cell($w[3], 6, $t('Eff. Month'), 1, 0, 'C', true);
        $pdf->Cell($w[4], 6, $t('Amount (Rs.)'), 1, 1, 'R', true);

        $pdf->SetFont('Arial', '', 9);
        $i = 1;
        foreach ($g['items'] as $it) {
            if ($pdf->GetY() > 280) {
                $pdf->AddPage();
                $pdf->SetFont('Arial', 'B', 9);
                $pdf->SetFillColor(235, 235, 235);
                $pdf->Cell($w[0], 6, '#', 1, 0, 'C', true);
                $pdf->Cell($w[1], 6, $t('Employee'), 1, 0, 'L', true);
                $pdf->Cell($w[2], 6, $t('Department'), 1, 0, 'L', true);
                $pdf->Cell($w[3], 6, $t('Eff. Month'), 1, 0, 'C', true);
                $pdf->Cell($w[4], 6, $t('Amount (Rs.)'), 1, 1, 'R', true);
                $pdf->SetFont('Arial', '', 9);
            }
            $pdf->Cell($w[0], 6, (string)$i++, 1, 0, 'C');
            $pdf->Cell($w[1], 6, $t($it['emp_code'] . '  ' . $it['emp_name']), 1, 0, 'L');
            $pdf->Cell($w[2], 6, $t($it['dept_name'] ?? '—'), 1, 0, 'L');
            $pdf->Cell($w[3], 6, $t(date('M Y', strtotime($it['effective_month']))), 1, 0, 'C');
            $pdf->Cell($w[4], 6, number_format((float)$it['amount'], 2), 1, 1, 'R');
        }
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell($w[0] + $w[1] + $w[2] + $w[3], 6, $t('Subtotal'), 1, 0, 'R');
        $pdf->Cell($w[4], 6, number_format($g['total'], 2), 1, 1, 'R');
        $pdf->Ln(3);
    }

    if (!$rows) {
        $pdf->SetFont('Arial', 'I', 11);
        $pdf->Cell(0, 10, $t('No ' . ($status === 'all' ? '' : $status . ' ') . 'benefits found for ' . $monthName . ' ' . $year . '.'), 0, 1, 'C');
    } else {
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 8, $t('GRAND TOTAL:  Rs. ' . number_format($grandTotal, 2)), 0, 1, 'R');
    }

    $fn = 'monthly_benefits_' . $year . '_' . str_pad((string)$month, 2, '0', STR_PAD_LEFT) . '.pdf';
    $pdf->Output('D', $fn);
    exit;
}

// ── Screen ───────────────────────────────────────────────────────────────────
$departments = $db->query('SELECT id, name FROM departments ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

// Employee list for the selector (self-scoped users only see themselves).
$empSql = 'SELECT id, name, employee_id FROM employees';
$empParams = [];
if ($scopeEmp) { $empSql .= ' WHERE id = ?'; $empParams[] = $scopeEmp; }
$empSql .= ' ORDER BY name';
$empStmt = $db->prepare($empSql);
$empStmt->execute($empParams);
$employees = $empStmt->fetchAll(PDO::FETCH_ASSOC);

$exportQs = http_build_query([
    'month' => $month, 'year' => $year,
    'department' => $deptId ?: '', 'emp_id' => $empId ?: '',
    'status' => $status,
]);
$hasFilters = ($empId || $deptId || $status !== 'active');

$page_title = 'Monthly Benefits Report';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header no-print">
    <div>
        <h1 class="page-title">Monthly Benefits</h1>
        <p class="page-subtitle"><?= h($monthName) ?> <?= $year ?> · <?= $empCount ?> benefit record<?= $empCount === 1 ? '' : 's' ?></p>
    </div>
    <div class="page-actions">
        <?php if (can('report_benefits', 'export')): ?>
        <a href="?export=csv&<?= h($exportQs) ?>" class="btn btn-secondary"><i class="fa fa-file-csv me-1"></i>Excel</a>
        <a href="?export=pdf&<?= h($exportQs) ?>" class="btn btn-secondary"><i class="fa fa-file-pdf me-1"></i>PDF</a>
        <?php endif; ?>
        <button type="button" class="btn btn-secondary" onclick="window.print()"><i class="fa fa-print me-1"></i>Print</button>
    </div>
</div>

<?php render_flash(); ?>

<!-- Filters -->
<div class="card page-card mb-3 no-print">
    <div class="card-body py-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-sm-3">
                <label class="form-label fw-semibold mb-1">Employee</label>
                <select name="emp_id" class="form-select form-select-sm">
                    <option value="">All Employees</option>
                    <?php foreach ($employees as $e): ?>
                    <option value="<?= $e['id'] ?>" <?= $empId === (int)$e['id'] ? 'selected' : '' ?>>
                        <?= h($e['name']) ?> (<?= h($e['employee_id']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-2">
                <label class="form-label fw-semibold mb-1">Department</label>
                <select name="department" class="form-select form-select-sm">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $deptId === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-2">
                <label class="form-label fw-semibold mb-1">Month</label>
                <select name="month" class="form-select form-select-sm">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-sm-2">
                <label class="form-label fw-semibold mb-1">Year</label>
                <select name="year" class="form-select form-select-sm">
                    <?php for ($y = (int)date('Y') + 1; $y >= (int)date('Y') - 4; $y--): ?>
                    <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-sm-2">
                <label class="form-label fw-semibold mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'all' => 'All'] as $sv => $sl): ?>
                    <option value="<?= $sv ?>" <?= $status === $sv ? 'selected' : '' ?>><?= $sl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-auto d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm px-3"><i class="fa fa-filter me-1"></i>Run</button>
                <?php if ($hasFilters): ?>
                <a href="?month=<?= $month ?>&year=<?= $year ?>" class="btn btn-outline-secondary btn-sm px-3"><i class="fa fa-times me-1"></i>Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Print header (only visible when printing) -->
<div class="print-only" style="display:none">
    <h3 style="margin:0">Monthly Benefits Report — <?= h($monthName) ?> <?= $year ?></h3>
</div>

<!-- Summary cards -->
<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm"><div class="card-body text-center py-3">
            <small class="text-muted">Total Benefits</small>
            <h3 class="text-success fw-bold mb-0"><?= money($grandTotal) ?></h3>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm"><div class="card-body text-center py-3">
            <small class="text-muted">Employees Covered</small>
            <h3 class="text-primary fw-bold mb-0"><?= $empCount ?></h3>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm"><div class="card-body text-center py-3">
            <small class="text-muted">Fund Types</small>
            <h3 class="text-info fw-bold mb-0"><?= $typeCount ?></h3>
        </div></div>
    </div>
</div>

<!-- Per-fund-type sections -->
<?php if ($groups): ?>
    <?php foreach ($groups as $g): ?>
    <div class="card page-card mb-3">
        <div class="card-header bg-<?= h($g['color']) ?> text-white d-flex justify-content-between align-items-center">
            <strong><?= h($g['name']) ?></strong>
            <span><?= $g['count'] ?> employee(s) · <?= money($g['total']) ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width:50px">#</th>
                            <th>Employee</th>
                            <th>Department</th>
                            <th>Effective Month</th>
                            <th class="text-end">Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($g['items'] as $i => $it): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td class="fw-semibold"><?= h($it['emp_name']) ?><div class="small text-muted"><?= h($it['emp_code']) ?></div></td>
                            <td><?= h($it['dept_name'] ?? '') ?: '—' ?></td>
                            <td><?= date('M Y', strtotime($it['effective_month'])) ?></td>
                            <td class="text-end text-success fw-semibold"><?= money((float)$it['amount']) ?></td>
                            <td><span class="badge bg-<?= $it['status'] === 'active' ? 'success' : 'secondary' ?>"><?= ucfirst($it['status']) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="4" class="text-end">Subtotal</th>
                            <th class="text-end text-success"><?= money($g['total']) ?></th>
                            <th></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="card page-card">
        <div class="card-body d-flex justify-content-between align-items-center">
            <strong class="fs-6">Grand Total (<?= h($monthName) ?> <?= $year ?>)</strong>
            <strong class="fs-5 text-success"><?= money($grandTotal) ?></strong>
        </div>
    </div>
<?php else: ?>
    <div class="alert alert-info">
        <i class="fa fa-circle-info me-2"></i>
        No <?= $status === 'all' ? '' : h($status) . ' ' ?>benefits found for <?= h($monthName) ?> <?= $year ?><?= $hasFilters ? ' with the selected filters' : '' ?>.
    </div>
<?php endif; ?>

<style>
@media print {
    #sidebar, #topbar, .no-print, .sidebar-overlay { display: none !important; }
    .main-wrapper, #mainContent { margin: 0 !important; padding: 0 !important; }
    .print-only { display: block !important; margin-bottom: 12px; }
    .card { break-inside: avoid; box-shadow: none !important; }
    a[href]:after { content: none !important; }
}
</style>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
