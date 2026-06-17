<?php
/**
 * Reports → Employee History
 *
 * A unified, date-sorted change-history timeline assembled from the real
 * structured history HRMS keeps:
 *   • employee_increments  → salary changes (previous_salary → new_salary)
 *   • employee_promotions  → designation changes (previous → new designation)
 *
 * Each row shows: change date, employee, change type, old value → new value,
 * remarks. "Changed By" is shown as "—" because HRMS does not record a
 * causer/user for these changes (no audit-log table exists).
 *
 * Filters: employee search, department, designation (current), date range,
 * change type. Exports: CSV (Excel), PDF (FPDF) and Print.
 *
 * Exports run BEFORE any HTML output (they emit their own headers and exit).
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('employee', 'view');

$db = db();

// ── Filters ──────────────────────────────────────────────────────────────────
$search   = trim((string)($_GET['search'] ?? ''));
if (mb_strlen($search) > 100) $search = mb_substr($search, 0, 100);
$deptId   = (int)($_GET['department'] ?? 0);
$desigId  = (int)($_GET['designation'] ?? 0);
$from     = trim((string)($_GET['from'] ?? ''));
$to       = trim((string)($_GET['to'] ?? ''));
$validDate = fn ($d) => $d !== '' && DateTime::createFromFormat('Y-m-d', $d) && DateTime::createFromFormat('Y-m-d', $d)->format('Y-m-d') === $d;
if (!$validDate($from)) $from = '';
if (!$validDate($to))   $to = '';
$ctype    = (string)($_GET['ctype'] ?? '');   // '', 'salary', 'promotion'
if (!in_array($ctype, ['salary', 'promotion'], true)) $ctype = '';
$export   = (string)($_GET['export'] ?? '');

// Shared employee/date WHERE fragments + params.
$empWhere = '';
$empParams = [];
if ($search !== '') { $empWhere .= ' AND (e.name LIKE ? OR e.employee_id LIKE ?)'; $empParams[] = "%$search%"; $empParams[] = "%$search%"; }
if ($deptId)        { $empWhere .= ' AND e.department_id = ?';  $empParams[] = $deptId; }
if ($desigId)       { $empWhere .= ' AND e.designation_id = ?'; $empParams[] = $desigId; }
$dateWhere = '';
$dateParams = [];
if ($from) { $dateWhere .= ' AND %COL% >= ?'; $dateParams[] = $from; }
if ($to)   { $dateWhere .= ' AND %COL% <= ?'; $dateParams[] = $to; }

$events = [];

// ── Source 1: salary increments ──────────────────────────────────────────────
if ($ctype === '' || $ctype === 'salary') {
    $sql = "SELECT i.id, i.effective_date, i.created_at, i.previous_salary, i.new_salary, i.remarks,
                   e.name AS emp_name, e.employee_id AS emp_code, d.name AS dept_name
            FROM employee_increments i
            JOIN employees e        ON e.id = i.employee_id
            LEFT JOIN departments d ON d.id = e.department_id
            WHERE 1=1" . $empWhere . str_replace('%COL%', 'i.effective_date', $dateWhere);
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge($empParams, $dateParams));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $events[] = [
            'date'     => $r['effective_date'],
            'created'  => $r['created_at'],
            'emp_name' => $r['emp_name'],
            'emp_code' => $r['emp_code'],
            'dept'     => $r['dept_name'],
            'type'     => 'salary',
            'type_label' => 'Salary Increment',
            'old'      => money((float)$r['previous_salary']),
            'new'      => money((float)$r['new_salary']),
            'old_raw'  => number_format((float)$r['previous_salary'], 2),
            'new_raw'  => number_format((float)$r['new_salary'], 2),
            'remarks'  => $r['remarks'] ?? '',
        ];
    }
}

// ── Source 2: promotions / designation changes ───────────────────────────────
if ($ctype === '' || $ctype === 'promotion') {
    $sql = "SELECT p.id, p.effective_date, p.created_at, p.remarks,
                   e.name AS emp_name, e.employee_id AS emp_code, d.name AS dept_name,
                   pd.name AS prev_desig, nd.name AS new_desig
            FROM employee_promotions p
            JOIN employees e         ON e.id = p.employee_id
            LEFT JOIN departments d  ON d.id = e.department_id
            LEFT JOIN designations pd ON pd.id = p.previous_designation_id
            LEFT JOIN designations nd ON nd.id = p.new_designation_id
            WHERE 1=1" . $empWhere . str_replace('%COL%', 'p.effective_date', $dateWhere);
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge($empParams, $dateParams));
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $events[] = [
            'date'     => $r['effective_date'],
            'created'  => $r['created_at'],
            'emp_name' => $r['emp_name'],
            'emp_code' => $r['emp_code'],
            'dept'     => $r['dept_name'],
            'type'     => 'promotion',
            'type_label' => 'Promotion',
            'old'      => $r['prev_desig'] ?: '—',
            'new'      => $r['new_desig'] ?: '—',
            'old_raw'  => $r['prev_desig'] ?: '-',
            'new_raw'  => $r['new_desig'] ?: '-',
            'remarks'  => $r['remarks'] ?? '',
        ];
    }
}

// Sort by change date (desc), then created (desc).
usort($events, fn ($a, $b) => ($b['date'] <=> $a['date']) ?: (($b['created'] ?? '') <=> ($a['created'] ?? '')));

$totalCount = count($events);
$salaryCount = count(array_filter($events, fn ($e) => $e['type'] === 'salary'));
$promoCount  = $totalCount - $salaryCount;
$empAffected = count(array_unique(array_column($events, 'emp_code')));

$deptName  = $deptId  ? (string)$db->query('SELECT name FROM departments WHERE id=' . $deptId)->fetchColumn() : '';
$desigName = $desigId ? (string)$db->query('SELECT name FROM designations WHERE id=' . $desigId)->fetchColumn() : '';
$rangeLabel = ($from || $to) ? (($from ? date_fmt($from) : '…') . ' – ' . ($to ? date_fmt($to) : '…')) : 'All dates';

// ── CSV (Excel) export ───────────────────────────────────────────────────────
if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="employee_history.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Employee History Report']);
    $fl = 'Range: ' . $rangeLabel;
    if ($ctype)    $fl .= ' | Type: ' . ($ctype === 'salary' ? 'Salary Increment' : 'Promotion');
    if ($deptName) $fl .= ' | Department: ' . $deptName;
    if ($desigName)$fl .= ' | Designation: ' . $desigName;
    if ($search !== '') $fl .= ' | Search: ' . $search;
    fputcsv($out, [$fl]);
    fputcsv($out, []);
    fputcsv($out, ['Date', 'Emp Code', 'Employee', 'Department', 'Change Type', 'Old Value', 'New Value', 'Remarks', 'Changed By']);
    foreach ($events as $e) {
        fputcsv($out, [
            date_fmt($e['date']), $e['emp_code'], $e['emp_name'], $e['dept'] ?? '—',
            $e['type_label'], $e['old_raw'], $e['new_raw'], $e['remarks'], '—',
        ]);
    }
    fputcsv($out, []);
    fputcsv($out, ['', '', '', '', 'Total Changes', $totalCount, '', '', '']);
    fclose($out);
    exit;
}

// ── PDF export (FPDF) ────────────────────────────────────────────────────────
if ($export === 'pdf') {
    require_once __DIR__ . '/../../lib/fpdf/fpdf.php';
    $t = fn ($s) => iconv('UTF-8', 'windows-1252//TRANSLIT', (string)$s) ?: (string)$s;

    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetTitle('Employee History Report');
    $pdf->SetAutoPageBreak(true, 12);
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 15);
    $pdf->Cell(0, 8, $t('Employee History Report'), 0, 1, 'C');
    $pdf->SetFont('Arial', 'I', 9);
    $fl = 'Range: ' . $rangeLabel;
    if ($ctype)     $fl .= '   Type: ' . ($ctype === 'salary' ? 'Salary Increment' : 'Promotion');
    if ($deptName)  $fl .= '   Department: ' . $deptName;
    if ($desigName) $fl .= '   Designation: ' . $desigName;
    if ($search !== '') $fl .= '   Search: ' . $search;
    $pdf->Cell(0, 5, $t($fl), 0, 1, 'C');
    $pdf->SetFont('Arial', '', 10);
    $pdf->Cell(0, 6, $t('Total Changes: ' . $totalCount . '    Salary: ' . $salaryCount . '    Promotions: ' . $promoCount . '    Employees: ' . $empAffected), 0, 1, 'C');
    $pdf->Ln(2);

    $w = [22, 42, 28, 34, 34, 30]; // Date / Employee / Type / Old / New / Remarks = 190
    $head = function () use ($pdf, $w, $t) {
        $pdf->SetFont('Arial', 'B', 9);
        $pdf->SetFillColor(33, 37, 41);
        $pdf->SetTextColor(255);
        $pdf->Cell($w[0], 7, $t('Date'), 1, 0, 'C', true);
        $pdf->Cell($w[1], 7, $t('Employee'), 1, 0, 'L', true);
        $pdf->Cell($w[2], 7, $t('Type'), 1, 0, 'L', true);
        $pdf->Cell($w[3], 7, $t('Old Value'), 1, 0, 'L', true);
        $pdf->Cell($w[4], 7, $t('New Value'), 1, 0, 'L', true);
        $pdf->Cell($w[5], 7, $t('Remarks'), 1, 1, 'L', true);
        $pdf->SetTextColor(0);
        $pdf->SetFont('Arial', '', 8);
    };
    $head();
    if (!$events) {
        $pdf->SetFont('Arial', 'I', 11);
        $pdf->Cell(0, 10, $t('No employee history found for the selected filters.'), 0, 1, 'C');
    }
    foreach ($events as $e) {
        if ($pdf->GetY() > 280) { $pdf->AddPage(); $head(); }
        $pdf->Cell($w[0], 6, $t(date_fmt($e['date'])), 1, 0, 'C');
        $pdf->Cell($w[1], 6, $t($e['emp_code'] . ' ' . $e['emp_name']), 1, 0, 'L');
        $pdf->Cell($w[2], 6, $t($e['type_label']), 1, 0, 'L');
        $pdf->Cell($w[3], 6, $t($e['old_raw']), 1, 0, 'L');
        $pdf->Cell($w[4], 6, $t($e['new_raw']), 1, 0, 'L');
        $pdf->Cell($w[5], 6, $t($e['remarks'] !== '' ? $e['remarks'] : '—'), 1, 1, 'L');
    }
    $pdf->Output('D', 'employee_history.pdf');
    exit;
}

// ── Screen ───────────────────────────────────────────────────────────────────
$departments  = $db->query('SELECT id, name FROM departments ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$designations = $db->query('SELECT id, name FROM designations ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
$exportQs = http_build_query([
    'search' => $search, 'department' => $deptId ?: '', 'designation' => $desigId ?: '',
    'from' => $from, 'to' => $to, 'ctype' => $ctype,
]);
$hasFilters = ($search !== '' || $deptId || $desigId || $from || $to || $ctype !== '');

$page_title = 'Employee History';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header no-print">
    <div>
        <h1 class="page-title">Employee History</h1>
        <p class="page-subtitle">Salary &amp; designation change timeline · <?= $totalCount ?> change<?= $totalCount === 1 ? '' : 's' ?></p>
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
                    <option value="">All</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $deptId === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-2">
                <label class="form-label fw-semibold mb-1">Designation</label>
                <select name="designation" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($designations as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $desigId === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-2">
                <label class="form-label fw-semibold mb-1">Change Type</label>
                <select name="ctype" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="salary" <?= $ctype === 'salary' ? 'selected' : '' ?>>Salary Increment</option>
                    <option value="promotion" <?= $ctype === 'promotion' ? 'selected' : '' ?>>Promotion</option>
                </select>
            </div>
            <div class="col-sm-3 d-flex gap-2">
                <div>
                    <label class="form-label fw-semibold mb-1">From</label>
                    <input type="date" name="from" class="form-control form-control-sm" value="<?= h($from) ?>">
                </div>
                <div>
                    <label class="form-label fw-semibold mb-1">To</label>
                    <input type="date" name="to" class="form-control form-control-sm" value="<?= h($to) ?>">
                </div>
            </div>
            <div class="col-12 d-flex gap-2 mt-2">
                <button type="submit" class="btn btn-primary btn-sm px-3"><i class="fa fa-filter me-1"></i>Run</button>
                <?php if ($hasFilters): ?>
                <a href="?" class="btn btn-outline-secondary btn-sm px-3"><i class="fa fa-times me-1"></i>Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="print-only" style="display:none">
    <h3 style="margin:0">Employee History Report</h3>
    <div style="font-size:12px;color:#555"><?= h($rangeLabel) ?></div>
</div>

<!-- Summary cards -->
<div class="row g-3 mb-3">
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body text-center py-3">
        <small class="text-muted">Total Changes</small>
        <h3 class="text-primary fw-bold mb-0"><?= $totalCount ?></h3>
    </div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body text-center py-3">
        <small class="text-muted">Salary Changes</small>
        <h3 class="text-success fw-bold mb-0"><?= $salaryCount ?></h3>
    </div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body text-center py-3">
        <small class="text-muted">Promotions</small>
        <h3 class="text-info fw-bold mb-0"><?= $promoCount ?></h3>
    </div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body text-center py-3">
        <small class="text-muted">Employees Affected</small>
        <h3 class="text-warning fw-bold mb-0"><?= $empAffected ?></h3>
    </div></div></div>
</div>

<!-- Timeline table -->
<div class="card page-card">
    <div class="card-header bg-white py-3"><h6 class="mb-0 fw-semibold"><i class="fa fa-clock-rotate-left me-2 text-primary"></i>Change Timeline</h6></div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Employee</th>
                        <th>Change Type</th>
                        <th>Old Value</th>
                        <th></th>
                        <th>New Value</th>
                        <th>Remarks</th>
                        <th>Changed By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($events as $e):
                        $badge = $e['type'] === 'salary' ? 'success' : 'info';
                    ?>
                    <tr>
                        <td class="nowrap"><?= date_fmt($e['date']) ?></td>
                        <td class="fw-semibold"><?= h($e['emp_name']) ?><div class="small text-muted"><?= h($e['emp_code']) ?><?= $e['dept'] ? ' · ' . h($e['dept']) : '' ?></div></td>
                        <td><span class="badge bg-<?= $badge ?>"><?= h($e['type_label']) ?></span></td>
                        <td><span class="badge" style="background:#fee2e2;color:#991b1b;font-weight:500"><?= h($e['old']) ?></span></td>
                        <td class="text-muted"><i class="fa fa-arrow-right-long"></i></td>
                        <td><span class="badge" style="background:#dcfce7;color:#166534;font-weight:500"><?= h($e['new']) ?></span></td>
                        <td class="small text-muted"><?= h($e['remarks'] ?? '') ?: '—' ?></td>
                        <td class="small text-muted">—</td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$events): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No employee history found<?= $hasFilters ? ' for the selected filters' : '' ?>.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<p class="text-muted small mt-2 no-print"><i class="fa fa-circle-info me-1"></i>“Changed By” is not recorded in HRMS for these changes, so it shows “—”. History covers salary increments and promotions/designation changes.</p>

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
