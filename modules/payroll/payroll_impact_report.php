<?php
/**
 * Reports → Payroll Impact
 *
 * Combined per-employee view for a payroll month:
 *   • Real salary-slip figures — gross earnings, total deductions, net pay,
 *     present/working/LOP days (from salary_slips for that payroll_month).
 *   • The Laravel "impact" extras on top of payroll — active benefits
 *     (employee_benefits) + approved bonuses (employee_bonuses) for the month.
 *
 * Total Impact = Gross Earnings + Benefits + Bonuses (the company's payroll
 * outlay on earnings plus extras for that employee/month).
 *
 * Filters: employee search, department, month, year.
 * Exports: CSV (Excel), PDF (FPDF) and Print — run before any HTML output.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('payroll', 'view');

$db = db();

// ── Filters ──────────────────────────────────────────────────────────────────
$month  = (int)($_GET['month'] ?? date('n'));
$year   = (int)($_GET['year']  ?? date('Y'));
if ($month < 1 || $month > 12)    $month = (int)date('n');
if ($year < 2000 || $year > 2100) $year  = (int)date('Y');
$deptId = (int)($_GET['department'] ?? 0);
$search = trim((string)($_GET['search'] ?? ''));
if (mb_strlen($search) > 100) $search = mb_substr($search, 0, 100);
$export = (string)($_GET['export'] ?? '');

$monthName = date('F', mktime(0, 0, 0, $month, 1));
$pm = sprintf('%04d-%02d', $year, $month);

// Shared employee-filter fragment (applies to every source).
$empWhere = '';
$empParams = [];
if ($search !== '') { $empWhere .= ' AND (e.name LIKE ? OR e.employee_id LIKE ?)'; $empParams[] = "%$search%"; $empParams[] = "%$search%"; }
if ($deptId)        { $empWhere .= ' AND e.department_id = ?'; $empParams[] = $deptId; }

/** @var array<int,array> keyed by employee_id */
$map = [];
$ref = function (int $id, array $base) use (&$map) {
    if (!isset($map[$id])) {
        $map[$id] = array_merge([
            'emp_name' => '', 'emp_code' => '', 'dept' => null,
            'present' => null, 'working' => null, 'lop' => null,
            'gross' => 0.0, 'deductions' => 0.0, 'net' => 0.0,
            'benefits' => 0.0, 'bonuses' => 0.0,
        ], $base);
    }
    return $id;
};

// ── Source 1: salary slips (real payroll) ────────────────────────────────────
$sql = "SELECT ss.employee_id, ss.gross_earnings, ss.total_deductions, ss.net_pay,
               ss.present_days, ss.working_days, ss.lop_days,
               e.name AS emp_name, e.employee_id AS emp_code, d.name AS dept_name
        FROM salary_slips ss
        JOIN employees e        ON e.id = ss.employee_id
        LEFT JOIN departments d ON d.id = e.department_id
        WHERE ss.payroll_month = ?" . $empWhere . "
        ORDER BY ss.id";
$stmt = $db->prepare($sql);
$stmt->execute(array_merge([$pm], $empParams));
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $id = (int)$r['employee_id'];
    $ref($id, ['emp_name' => $r['emp_name'], 'emp_code' => $r['emp_code'], 'dept' => $r['dept_name']]);
    // One slip per employee per month; if duplicated, latest row wins.
    $map[$id]['present']    = (int)$r['present_days'];
    $map[$id]['working']    = (int)$r['working_days'];
    $map[$id]['lop']        = (int)$r['lop_days'];
    $map[$id]['gross']      = (float)$r['gross_earnings'];
    $map[$id]['deductions'] = (float)$r['total_deductions'];
    $map[$id]['net']        = (float)$r['net_pay'];
}

// ── Source 2: active benefits for the month ──────────────────────────────────
$sql = "SELECT eb.employee_id, eb.amount,
               e.name AS emp_name, e.employee_id AS emp_code, d.name AS dept_name
        FROM employee_benefits eb
        JOIN employees e        ON e.id = eb.employee_id
        LEFT JOIN departments d ON d.id = e.department_id
        WHERE eb.status = 'active' AND MONTH(eb.effective_month) = ? AND YEAR(eb.effective_month) = ?" . $empWhere;
$stmt = $db->prepare($sql);
$stmt->execute(array_merge([$month, $year], $empParams));
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $id = (int)$r['employee_id'];
    $ref($id, ['emp_name' => $r['emp_name'], 'emp_code' => $r['emp_code'], 'dept' => $r['dept_name']]);
    $map[$id]['benefits'] += (float)$r['amount'];
}

// ── Source 3: approved bonuses for the month ─────────────────────────────────
$sql = "SELECT eb.employee_id, eb.amount,
               e.name AS emp_name, e.employee_id AS emp_code, d.name AS dept_name
        FROM employee_bonuses eb
        JOIN employees e        ON e.id = eb.employee_id
        LEFT JOIN departments d ON d.id = e.department_id
        WHERE eb.status = 'approved' AND eb.payroll_month = ? AND eb.payroll_year = ?" . $empWhere;
$stmt = $db->prepare($sql);
$stmt->execute(array_merge([$month, $year], $empParams));
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $id = (int)$r['employee_id'];
    $ref($id, ['emp_name' => $r['emp_name'], 'emp_code' => $r['emp_code'], 'dept' => $r['dept_name']]);
    $map[$id]['bonuses'] += (float)$r['amount'];
}

// ── Build rows: total impact + keep only employees with any activity ─────────
$rows = [];
foreach ($map as $r) {
    $r['impact'] = $r['gross'] + $r['benefits'] + $r['bonuses'];
    if ($r['gross'] > 0 || $r['benefits'] > 0 || $r['bonuses'] > 0) $rows[] = $r;
}
usort($rows, fn ($a, $b) => strcasecmp($a['emp_name'], $b['emp_name']));

$tot = ['gross' => 0.0, 'deductions' => 0.0, 'net' => 0.0, 'benefits' => 0.0, 'bonuses' => 0.0, 'impact' => 0.0];
foreach ($rows as $r) foreach ($tot as $k => $v) $tot[$k] += $r[$k];
$rowCount = count($rows);

$deptName = $deptId ? (string)$db->query('SELECT name FROM departments WHERE id=' . $deptId)->fetchColumn() : '';

// ── CSV (Excel) export ───────────────────────────────────────────────────────
if ($export === 'csv') {
    $fn = 'payroll_impact_' . $year . '_' . str_pad((string)$month, 2, '0', STR_PAD_LEFT) . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Payroll Impact Report — ' . $monthName . ' ' . $year]);
    $fl = '';
    if ($deptName)      $fl .= 'Department: ' . $deptName . '  ';
    if ($search !== '') $fl .= 'Search: ' . $search;
    if ($fl !== '') fputcsv($out, [trim($fl)]);
    fputcsv($out, []);
    fputcsv($out, ['Emp Code', 'Employee', 'Department', 'Present', 'Working', 'LOP',
        'Gross Earnings', 'Total Deductions', 'Net Pay', 'Benefits', 'Bonuses', 'Total Impact']);
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['emp_code'], $r['emp_name'], $r['dept'] ?? '—',
            $r['present'] ?? '—', $r['working'] ?? '—', $r['lop'] ?? '—',
            number_format($r['gross'], 2), number_format($r['deductions'], 2), number_format($r['net'], 2),
            number_format($r['benefits'], 2), number_format($r['bonuses'], 2), number_format($r['impact'], 2),
        ]);
    }
    fputcsv($out, []);
    fputcsv($out, ['', 'TOTALS', '', '', '', '',
        number_format($tot['gross'], 2), number_format($tot['deductions'], 2), number_format($tot['net'], 2),
        number_format($tot['benefits'], 2), number_format($tot['bonuses'], 2), number_format($tot['impact'], 2)]);
    fclose($out);
    exit;
}

// ── PDF export (FPDF) ────────────────────────────────────────────────────────
if ($export === 'pdf') {
    require_once __DIR__ . '/../../lib/fpdf/fpdf.php';
    $t = fn ($s) => iconv('UTF-8', 'windows-1252//TRANSLIT', (string)$s) ?: (string)$s;

    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetTitle('Payroll Impact ' . $monthName . ' ' . $year);
    $pdf->SetAutoPageBreak(true, 12);
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 15);
    $pdf->Cell(0, 8, $t('Payroll Impact Report'), 0, 1, 'C');
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 6, $t($monthName . ' ' . $year), 0, 1, 'C');
    $sub = 'Employees: ' . $rowCount;
    if ($deptName)      $sub = 'Department: ' . $deptName . '    ' . $sub;
    if ($search !== '') $sub .= '    Search: ' . $search;
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->Cell(0, 5, $t($sub), 0, 1, 'C');
    $pdf->Ln(1);
    $pdf->SetFont('Arial', '', 9);
    $pdf->Cell(0, 5, $t('Gross: Rs. ' . number_format($tot['gross'], 2) . '   Deductions: Rs. ' . number_format($tot['deductions'], 2)
        . '   Net: Rs. ' . number_format($tot['net'], 2) . '   Extras: Rs. ' . number_format($tot['benefits'] + $tot['bonuses'], 2)
        . '   Total Impact: Rs. ' . number_format($tot['impact'], 2)), 0, 1, 'C');
    $pdf->Ln(2);

    $w = [46, 26, 26, 26, 22, 22, 22]; // Employee/Gross/Ded/Net/Ben/Bon/Impact = 190
    $head = function () use ($pdf, $w, $t) {
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetFillColor(33, 37, 41);
        $pdf->SetTextColor(255);
        $pdf->Cell($w[0], 7, $t('Employee'), 1, 0, 'L', true);
        $pdf->Cell($w[1], 7, $t('Gross'), 1, 0, 'R', true);
        $pdf->Cell($w[2], 7, $t('Deductions'), 1, 0, 'R', true);
        $pdf->Cell($w[3], 7, $t('Net Pay'), 1, 0, 'R', true);
        $pdf->Cell($w[4], 7, $t('Benefits'), 1, 0, 'R', true);
        $pdf->Cell($w[5], 7, $t('Bonuses'), 1, 0, 'R', true);
        $pdf->Cell($w[6], 7, $t('Impact'), 1, 1, 'R', true);
        $pdf->SetTextColor(0);
        $pdf->SetFont('Arial', '', 8);
    };
    $head();
    if (!$rows) {
        $pdf->SetFont('Arial', 'I', 11);
        $pdf->Cell(0, 10, $t('No payroll activity for ' . $monthName . ' ' . $year . '.'), 0, 1, 'C');
    }
    foreach ($rows as $r) {
        if ($pdf->GetY() > 280) { $pdf->AddPage(); $head(); }
        $pdf->Cell($w[0], 6, $t($r['emp_code'] . ' ' . $r['emp_name']), 1, 0, 'L');
        $pdf->Cell($w[1], 6, number_format($r['gross'], 2), 1, 0, 'R');
        $pdf->Cell($w[2], 6, number_format($r['deductions'], 2), 1, 0, 'R');
        $pdf->Cell($w[3], 6, number_format($r['net'], 2), 1, 0, 'R');
        $pdf->Cell($w[4], 6, number_format($r['benefits'], 2), 1, 0, 'R');
        $pdf->Cell($w[5], 6, number_format($r['bonuses'], 2), 1, 0, 'R');
        $pdf->Cell($w[6], 6, number_format($r['impact'], 2), 1, 1, 'R');
    }
    if ($rows) {
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->Cell($w[0], 7, $t('TOTALS'), 1, 0, 'R');
        $pdf->Cell($w[1], 7, number_format($tot['gross'], 2), 1, 0, 'R');
        $pdf->Cell($w[2], 7, number_format($tot['deductions'], 2), 1, 0, 'R');
        $pdf->Cell($w[3], 7, number_format($tot['net'], 2), 1, 0, 'R');
        $pdf->Cell($w[4], 7, number_format($tot['benefits'], 2), 1, 0, 'R');
        $pdf->Cell($w[5], 7, number_format($tot['bonuses'], 2), 1, 0, 'R');
        $pdf->Cell($w[6], 7, number_format($tot['impact'], 2), 1, 1, 'R');
    }
    $pdf->Output('D', 'payroll_impact_' . $year . '_' . str_pad((string)$month, 2, '0', STR_PAD_LEFT) . '.pdf');
    exit;
}

// ── Screen ───────────────────────────────────────────────────────────────────
$departments = $db->query('SELECT id, name FROM departments ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$exportQs = http_build_query(['month' => $month, 'year' => $year, 'department' => $deptId ?: '', 'search' => $search]);
$hasFilters = ($search !== '' || $deptId);

$page_title = 'Payroll Impact';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header no-print">
    <div>
        <h1 class="page-title">Payroll Impact</h1>
        <p class="page-subtitle"><?= h($monthName) ?> <?= $year ?> · <?= $rowCount ?> employee<?= $rowCount === 1 ? '' : 's' ?></p>
    </div>
    <div class="page-actions">
        <a href="?export=csv&<?= h($exportQs) ?>" class="btn btn-secondary"><i class="fa fa-file-csv me-1"></i>Excel</a>
        <a href="?export=pdf&<?= h($exportQs) ?>" class="btn btn-secondary"><i class="fa fa-file-pdf me-1"></i>PDF</a>
        <button type="button" class="btn btn-secondary" onclick="window.print()"><i class="fa fa-print me-1"></i>Print</button>
    </div>
</div>

<?php render_flash(); ?>

<!-- Filters -->
<div class="card page-card mb-3 no-print">
    <div class="card-body py-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-sm-4">
                <label class="form-label fw-semibold mb-1">Search Employee</label>
                <input type="text" name="search" class="form-control form-control-sm" placeholder="Name or code…" value="<?= h($search) ?>">
            </div>
            <div class="col-sm-3">
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
            <div class="col-sm-auto d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm px-3"><i class="fa fa-filter me-1"></i>Run</button>
                <?php if ($hasFilters): ?>
                <a href="?month=<?= $month ?>&year=<?= $year ?>" class="btn btn-outline-secondary btn-sm px-3"><i class="fa fa-times me-1"></i>Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="print-only" style="display:none">
    <h3 style="margin:0">Payroll Impact Report — <?= h($monthName) ?> <?= $year ?></h3>
</div>

<!-- Summary cards -->
<div class="row g-2 mb-3">
    <?php
    $cards = [
        ['Gross Earnings', $tot['gross'], 'text-primary'],
        ['Total Deductions', $tot['deductions'], 'text-danger'],
        ['Net Pay', $tot['net'], 'text-success'],
        ['Benefits', $tot['benefits'], 'text-info'],
        ['Bonuses', $tot['bonuses'], 'text-warning'],
        ['Total Impact', $tot['impact'], 'text-dark'],
    ];
    foreach ($cards as [$label, $val, $cls]): ?>
    <div class="col-md-2 col-6">
        <div class="card border-0 shadow-sm h-100"><div class="card-body text-center py-3 px-2">
            <small class="text-muted d-block" style="font-size:.72rem"><?= $label ?></small>
            <span class="fw-bold <?= $cls ?>" style="font-size:1.05rem"><?= money($val) ?></span>
        </div></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Per-employee breakdown -->
<div class="card page-card">
    <div class="card-header bg-white py-3"><h6 class="mb-0 fw-semibold"><i class="fa fa-scale-balanced me-2 text-primary"></i>Per-Employee Breakdown — <?= h($monthName) ?> <?= $year ?></h6></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>#</th>
                        <th>Employee</th>
                        <th class="text-center">Days (P/W·LOP)</th>
                        <th class="text-end">Gross</th>
                        <th class="text-end">Deductions</th>
                        <th class="text-end">Net Pay</th>
                        <th class="text-end">Benefits</th>
                        <th class="text-end">Bonuses</th>
                        <th class="text-end">Total Impact</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $i => $r): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td class="fw-semibold"><?= h($r['emp_name']) ?><div class="small text-muted"><?= h($r['emp_code']) ?><?= $r['dept'] ? ' · ' . h($r['dept']) : '' ?></div></td>
                        <td class="text-center small">
                            <?php if ($r['working'] !== null): ?>
                                <?= (int)$r['present'] ?>/<?= (int)$r['working'] ?><?= (int)$r['lop'] > 0 ? ' <span class="text-danger">·' . (int)$r['lop'] . '</span>' : '' ?>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                        <td class="text-end"><?= $r['gross'] > 0 ? money($r['gross']) : '<span class="text-muted">—</span>' ?></td>
                        <td class="text-end text-danger"><?= $r['deductions'] > 0 ? money($r['deductions']) : '<span class="text-muted">—</span>' ?></td>
                        <td class="text-end text-success"><?= $r['gross'] > 0 ? money($r['net']) : '<span class="text-muted">—</span>' ?></td>
                        <td class="text-end text-info"><?= $r['benefits'] > 0 ? money($r['benefits']) : '<span class="text-muted">—</span>' ?></td>
                        <td class="text-end text-warning"><?= $r['bonuses'] > 0 ? money($r['bonuses']) : '<span class="text-muted">—</span>' ?></td>
                        <td class="text-end fw-bold"><?= money($r['impact']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$rows): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4">No payroll activity for <?= h($monthName) ?> <?= $year ?><?= $hasFilters ? ' with the selected filters' : '' ?>.</td></tr>
                    <?php endif; ?>
                </tbody>
                <?php if ($rows): ?>
                <tfoot class="table-light">
                    <tr class="fw-bold">
                        <td colspan="3" class="text-end">Totals</td>
                        <td class="text-end"><?= money($tot['gross']) ?></td>
                        <td class="text-end text-danger"><?= money($tot['deductions']) ?></td>
                        <td class="text-end text-success"><?= money($tot['net']) ?></td>
                        <td class="text-end text-info"><?= money($tot['benefits']) ?></td>
                        <td class="text-end text-warning"><?= money($tot['bonuses']) ?></td>
                        <td class="text-end"><?= money($tot['impact']) ?></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>
<p class="text-muted small mt-2 no-print"><i class="fa fa-circle-info me-1"></i>Gross / Deductions / Net come from generated salary slips for the month. Benefits (active) and Bonuses (approved) are the extra payroll cost. <strong>Total Impact = Gross + Benefits + Bonuses.</strong></p>

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
