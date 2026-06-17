<?php
/**
 * Reports → Bonus Report
 *
 * Ported from Employee_Management's BenefitReportController::bonuses.
 * Lists employee bonuses/incentives for a chosen payroll month/year, grouped by
 * type, with per-type subtotals and a grand total. Supports department /
 * employee / type / status filters and CSV (Excel), PDF (FPDF) and Print exports.
 *
 * Data source: employee_bonuses (type ENUM bonus/incentive/commission, amount,
 * reason, payroll_month, payroll_year, status). Month is matched on the
 * payroll_month + payroll_year integers (exact), like the Laravel original.
 * Defaults to approved bonuses (Laravel parity); a status filter is provided.
 *
 * Exports run BEFORE any HTML output (they emit their own headers and exit).
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('payroll', 'view');

$db = db();

$TYPE_LABELS = ['bonus' => 'Bonus', 'incentive' => 'Incentive', 'commission' => 'Commission'];
$TYPE_COLORS = ['bonus' => 'primary', 'incentive' => 'success', 'commission' => 'info'];
$palette = ['primary', 'success', 'info', 'warning', 'danger', 'dark'];

// ── Filters (validated) ──────────────────────────────────────────────────────
$month  = (int)($_GET['month'] ?? date('n'));
$year   = (int)($_GET['year']  ?? date('Y'));
if ($month < 1 || $month > 12)    $month = (int)date('n');
if ($year < 2000 || $year > 2100) $year  = (int)date('Y');
$deptId = (int)($_GET['department'] ?? 0);
$search = trim((string)($_GET['search'] ?? ''));
if (mb_strlen($search) > 100) $search = mb_substr($search, 0, 100);
$type   = (string)($_GET['type'] ?? '');
if (!isset($TYPE_LABELS[$type])) $type = '';
$status = (string)($_GET['status'] ?? 'approved');
if (!in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) $status = 'approved';
$export = (string)($_GET['export'] ?? '');

$monthName = date('F', mktime(0, 0, 0, $month, 1));

// ── Query ────────────────────────────────────────────────────────────────────
$sql = "SELECT eb.id, eb.type, eb.amount, eb.reason, eb.payroll_month, eb.payroll_year, eb.status,
               e.name AS emp_name, e.employee_id AS emp_code, e.department_id,
               d.name AS dept_name
        FROM employee_bonuses eb
        JOIN employees e        ON e.id = eb.employee_id
        LEFT JOIN departments d ON d.id = e.department_id
        WHERE eb.payroll_month = ? AND eb.payroll_year = ?";
$params = [$month, $year];
if ($status !== 'all') { $sql .= ' AND eb.status = ?';       $params[] = $status; }
if ($type !== '')      { $sql .= ' AND eb.type = ?';         $params[] = $type; }
if ($deptId)           { $sql .= ' AND e.department_id = ?'; $params[] = $deptId; }
if ($search !== '')    { $sql .= ' AND (e.name LIKE ? OR e.employee_id LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
$sql .= ' ORDER BY eb.type ASC, e.name ASC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Group by type ────────────────────────────────────────────────────────────
$groups = [];
$gi = 0;
foreach ($rows as $r) {
    $key = $r['type'];
    if (!isset($groups[$key])) {
        $groups[$key] = [
            'name'  => $TYPE_LABELS[$key] ?? ucfirst((string)$key),
            'color' => $TYPE_COLORS[$key] ?? $palette[$gi % count($palette)],
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
$recCount   = count($rows);
$typeCount  = count($groups);

$deptName = $deptId ? (string)$db->query('SELECT name FROM departments WHERE id=' . $deptId)->fetchColumn() : '';
$rowMonth = fn ($r) => date('M Y', mktime(0, 0, 0, (int)$r['payroll_month'], 1, (int)$r['payroll_year']));

// ── CSV (Excel) export ───────────────────────────────────────────────────────
if ($export === 'csv') {
    $fn = 'bonus_report_' . $year . '_' . str_pad((string)$month, 2, '0', STR_PAD_LEFT) . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Bonus Report — ' . $monthName . ' ' . $year]);
    $fl = 'Status: ' . ucfirst($status);
    if ($type !== '')   $fl .= ' | Type: ' . ($TYPE_LABELS[$type] ?? $type);
    if ($deptName)      $fl .= ' | Department: ' . $deptName;
    if ($search !== '') $fl .= ' | Search: ' . $search;
    fputcsv($out, [$fl]);
    fputcsv($out, []);
    fputcsv($out, ['Type', 'Emp Code', 'Employee', 'Department', 'Month', 'Reason', 'Amount', 'Status']);
    foreach ($groups as $g) {
        foreach ($g['items'] as $it) {
            fputcsv($out, [
                $g['name'], $it['emp_code'], $it['emp_name'], $it['dept_name'] ?? '—',
                $rowMonth($it), $it['reason'] ?? '',
                number_format((float)$it['amount'], 2), ucfirst($it['status']),
            ]);
        }
        fputcsv($out, ['', '', '', '', '', $g['name'] . ' — Subtotal', number_format($g['total'], 2), '']);
    }
    fputcsv($out, []);
    fputcsv($out, ['', '', '', '', '', 'GRAND TOTAL', number_format($grandTotal, 2), '']);
    fputcsv($out, ['', '', '', '', '', 'Total Records', $recCount, '']);
    fclose($out);
    exit;
}

// ── PDF export (FPDF) ────────────────────────────────────────────────────────
if ($export === 'pdf') {
    require_once __DIR__ . '/../../lib/fpdf/fpdf.php';
    $t = fn ($s) => iconv('UTF-8', 'windows-1252//TRANSLIT', (string)$s) ?: (string)$s;

    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetTitle('Bonus Report ' . $monthName . ' ' . $year);
    $pdf->SetAutoPageBreak(true, 12);
    $pdf->AddPage();

    $pdf->SetFont('Arial', 'B', 15);
    $pdf->Cell(0, 8, $t('Bonus Report'), 0, 1, 'C');
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 6, $t($monthName . ' ' . $year), 0, 1, 'C');
    $filters = 'Status: ' . ucfirst($status);
    if ($type !== '')   $filters .= '   Type: ' . ($TYPE_LABELS[$type] ?? $type);
    if ($deptName)      $filters .= '   Department: ' . $deptName;
    if ($search !== '') $filters .= '   Search: ' . $search;
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->Cell(0, 5, $t($filters), 0, 1, 'C');
    $pdf->Ln(1);
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 6, $t('Total Bonus: Rs. ' . number_format($grandTotal, 2)
        . '    Records: ' . $recCount . '    Types: ' . $typeCount), 0, 1, 'C');
    $pdf->Ln(2);

    $w = [10, 55, 38, 24, 33, 30]; // # / Employee / Department / Month / Amount / Status = 190mm
    $head = function () use ($pdf, $w, $t) {
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetFillColor(235, 235, 235);
        $pdf->Cell($w[0], 6, '#', 1, 0, 'C', true);
        $pdf->Cell($w[1], 6, $t('Employee'), 1, 0, 'L', true);
        $pdf->Cell($w[2], 6, $t('Department'), 1, 0, 'L', true);
        $pdf->Cell($w[3], 6, $t('Month'), 1, 0, 'C', true);
        $pdf->Cell($w[4], 6, $t('Amount (Rs.)'), 1, 0, 'R', true);
        $pdf->Cell($w[5], 6, $t('Status'), 1, 1, 'C', true);
        $pdf->SetFont('Arial', '', 9);
    };
    foreach ($groups as $g) {
        if ($pdf->GetY() > 250) $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->SetFillColor(33, 37, 41);
        $pdf->SetTextColor(255);
        $pdf->Cell(0, 7, $t('  ' . $g['name'] . '   (' . $g['count'] . ' record(s)  ·  Rs. ' . number_format($g['total'], 2) . ')'), 0, 1, 'L', true);
        $pdf->SetTextColor(0);
        $head();
        $i = 1;
        foreach ($g['items'] as $it) {
            if ($pdf->GetY() > 280) { $pdf->AddPage(); $head(); }
            $pdf->Cell($w[0], 6, (string)$i++, 1, 0, 'C');
            $pdf->Cell($w[1], 6, $t($it['emp_code'] . '  ' . $it['emp_name']), 1, 0, 'L');
            $pdf->Cell($w[2], 6, $t($it['dept_name'] ?? '—'), 1, 0, 'L');
            $pdf->Cell($w[3], 6, $t($rowMonth($it)), 1, 0, 'C');
            $pdf->Cell($w[4], 6, number_format((float)$it['amount'], 2), 1, 0, 'R');
            $pdf->Cell($w[5], 6, $t(ucfirst($it['status'])), 1, 1, 'C');
        }
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->Cell($w[0] + $w[1] + $w[2] + $w[3], 6, $t('Subtotal'), 1, 0, 'R');
        $pdf->Cell($w[4], 6, number_format($g['total'], 2), 1, 0, 'R');
        $pdf->Cell($w[5], 6, '', 1, 1);
        $pdf->Ln(3);
    }
    if (!$rows) {
        $pdf->SetFont('Arial', 'I', 11);
        $pdf->Cell(0, 10, $t('No ' . ($status === 'all' ? '' : $status . ' ') . 'bonuses found for ' . $monthName . ' ' . $year . '.'), 0, 1, 'C');
    } else {
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 8, $t('GRAND TOTAL:  Rs. ' . number_format($grandTotal, 2)), 0, 1, 'R');
    }
    $fn = 'bonus_report_' . $year . '_' . str_pad((string)$month, 2, '0', STR_PAD_LEFT) . '.pdf';
    $pdf->Output('D', $fn);
    exit;
}

// ── Screen ───────────────────────────────────────────────────────────────────
$departments = $db->query('SELECT id, name FROM departments ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$exportQs = http_build_query([
    'month' => $month, 'year' => $year,
    'department' => $deptId ?: '', 'search' => $search,
    'type' => $type, 'status' => $status,
]);
$hasFilters = ($search !== '' || $deptId || $type !== '' || $status !== 'approved');

$page_title = 'Bonus Report';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header no-print">
    <div>
        <h1 class="page-title">Bonus Report</h1>
        <p class="page-subtitle"><?= h($monthName) ?> <?= $year ?> · <?= $recCount ?> record<?= $recCount === 1 ? '' : 's' ?></p>
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
            <div class="col-sm-3">
                <label class="form-label fw-semibold mb-1">Search Employee</label>
                <input type="text" name="search" class="form-control form-control-sm" placeholder="Name or code…" value="<?= h($search) ?>">
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
                <label class="form-label fw-semibold mb-1">Type</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    <?php foreach ($TYPE_LABELS as $tk => $tl): ?>
                    <option value="<?= $tk ?>" <?= $type === $tk ? 'selected' : '' ?>><?= $tl ?></option>
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
            <div class="col-sm-1">
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
                    <?php foreach (['approved' => 'Approved', 'pending' => 'Pending', 'rejected' => 'Rejected', 'all' => 'All'] as $sv => $sl): ?>
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

<div class="print-only" style="display:none">
    <h3 style="margin:0">Bonus Report — <?= h($monthName) ?> <?= $year ?></h3>
</div>

<!-- Summary cards -->
<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body text-center py-3">
        <small class="text-muted">Total Bonus Amount</small>
        <h3 class="text-success fw-bold mb-0"><?= money($grandTotal) ?></h3>
    </div></div></div>
    <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body text-center py-3">
        <small class="text-muted">Total Records</small>
        <h3 class="text-primary fw-bold mb-0"><?= $recCount ?></h3>
    </div></div></div>
    <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body text-center py-3">
        <small class="text-muted">Distinct Types</small>
        <h3 class="text-info fw-bold mb-0"><?= $typeCount ?></h3>
    </div></div></div>
</div>

<!-- Per-type sections -->
<?php if ($groups): ?>
    <?php foreach ($groups as $g): ?>
    <div class="card page-card mb-3">
        <div class="card-header bg-<?= h($g['color']) ?> text-white d-flex justify-content-between align-items-center">
            <strong><?= h($g['name']) ?></strong>
            <span><?= $g['count'] ?> record(s) · <?= money($g['total']) ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width:50px">#</th>
                            <th>Employee</th>
                            <th>Department</th>
                            <th>Month</th>
                            <th>Reason</th>
                            <th class="text-end">Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($g['items'] as $i => $it):
                            $sb = ['approved' => 'success', 'pending' => 'warning', 'rejected' => 'danger'][$it['status']] ?? 'secondary';
                        ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td class="fw-semibold"><?= h($it['emp_name']) ?><div class="small text-muted"><?= h($it['emp_code']) ?></div></td>
                            <td><?= h($it['dept_name'] ?? '') ?: '—' ?></td>
                            <td><?= $rowMonth($it) ?></td>
                            <td class="small"><?= h($it['reason'] ?? '') ?: '—' ?></td>
                            <td class="text-end text-success fw-semibold"><?= money((float)$it['amount']) ?></td>
                            <td><span class="badge bg-<?= $sb ?>"><?= ucfirst($it['status']) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <th colspan="5" class="text-end">Subtotal</th>
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
        No <?= $status === 'all' ? '' : h($status) . ' ' ?>bonuses found for <?= h($monthName) ?> <?= $year ?><?= $hasFilters ? ' with the selected filters' : '' ?>.
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
