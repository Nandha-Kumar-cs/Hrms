<?php
/**
 * On Duty (OD) — assign model, ported from Employee_Management OnDutyController.
 *
 * Admin/HR assigns OD to one or many employees for a single date; attendance is
 * auto-marked 'OD' (counted Present by payroll). Removing an OD reverts the
 * attendance mark. No approval workflow — assignment is immediate.
 *
 * Two-column UI: left = OD records for the selected month grouped by date,
 * right = "Assign On Duty" form (date + multi-employee checkboxes + reason).
 *
 * Writes run before any output (PRG). Exports (CSV/PDF) emit headers and exit.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('od', 'view');

$db        = db();
$user      = current_user();
$canCreate = can('od', 'create');
$canDelete = can('od', 'delete');
$self      = BASE_URL . '/modules/attendance/od_requests.php';

/** Mark a date as 'OD' in attendance for an employee (upsert; clears times). */
function od_mark_attendance(int $emp, string $date, string $reason, int $by): void {
    db()->prepare(
        'INSERT INTO attendance (employee_id, att_date, status, in_time, out_time, remarks, marked_by)
         VALUES (?,?,"OD",NULL,NULL,?,?)
         ON DUPLICATE KEY UPDATE status="OD", in_time=NULL, out_time=NULL, remarks=VALUES(remarks), marked_by=VALUES(marked_by)'
    )->execute([$emp, $date, 'On Duty' . ($reason !== '' ? ': ' . $reason : ''), $by]);
}

/** Remove the 'OD' attendance mark for an employee on a date. */
function od_unmark_attendance(int $emp, string $date): void {
    db()->prepare('DELETE FROM attendance WHERE employee_id=? AND att_date=? AND status="OD"')->execute([$emp, $date]);
}

// ── POST: assign OD ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign'])) {
    verify_csrf($_POST['csrf_token'] ?? '');
    if (!$canCreate) { http_response_code(403); exit('Forbidden'); }

    $date   = sanitize($_POST['date'] ?? '');
    $reason = trim((string)($_POST['reason'] ?? ''));
    if (mb_strlen($reason) > 255) $reason = mb_substr($reason, 0, 255);
    $empIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['employee_ids'] ?? [])))));
    $year   = (int)($_POST['year'] ?? date('Y'));
    $month  = (int)($_POST['month'] ?? date('n'));
    $back   = $self . '?month=' . $month . '&year=' . $year;

    $d = DateTime::createFromFormat('Y-m-d', $date);
    $errors = [];
    if (!$date || !$d || $d->format('Y-m-d') !== $date) $errors[] = 'A valid date is required.';
    if (!$empIds) $errors[] = 'Select at least one employee.';
    if ($errors) { flash('error', implode(' ', $errors)); redirect($back); }

    $saved = 0; $skipped = 0;
    $exists = $db->prepare('SELECT 1 FROM on_duties WHERE employee_id=? AND od_date=?');
    $ins    = $db->prepare('INSERT INTO on_duties (employee_id, od_date, reason, created_by) VALUES (?,?,?,?)');
    foreach ($empIds as $eid) {
        // only active employees
        $ok = $db->prepare('SELECT 1 FROM employees WHERE id=? AND status="Active"');
        $ok->execute([$eid]);
        if (!$ok->fetchColumn()) { continue; }
        $exists->execute([$eid, $date]);
        if ($exists->fetchColumn()) { $skipped++; continue; }
        $ins->execute([$eid, $date, $reason ?: null, (int)$user['id']]);
        od_mark_attendance($eid, $date, $reason, (int)$user['id']);
        $saved++;
    }

    $msg = "OD assigned to {$saved} employee(s) for " . date_fmt($date) . '.';
    if ($skipped) $msg .= " {$skipped} already had OD for this date.";
    if ($saved) activity_log('created', 'OD', $msg);
    flash($saved ? 'success' : 'error', $saved ? $msg : 'No OD assigned. ' . ($skipped ? 'All selected already had OD.' : ''));
    redirect($back);
}

// ── POST: remove OD ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
    verify_csrf($_POST['csrf_token'] ?? '');
    if (!$canDelete) { http_response_code(403); exit('Forbidden'); }
    $id    = (int)($_POST['id'] ?? 0);
    $year  = (int)($_POST['year'] ?? date('Y'));
    $month = (int)($_POST['month'] ?? date('n'));
    $back  = $self . '?month=' . $month . '&year=' . $year;

    $row = $db->prepare('SELECT od.*, e.name emp_name, e.employee_id emp_code FROM on_duties od JOIN employees e ON e.id=od.employee_id WHERE od.id=?');
    $row->execute([$id]);
    $od = $row->fetch();
    if ($od) {
        od_unmark_attendance((int)$od['employee_id'], $od['od_date']);
        $db->prepare('DELETE FROM on_duties WHERE id=?')->execute([$id]);
        activity_log('deleted', 'OD', 'Removed OD for ' . $od['emp_name'] . ' (' . $od['emp_code'] . ') on ' . date_fmt($od['od_date']));
        flash('success', 'OD record removed.');
    } else {
        flash('error', 'OD record not found.');
    }
    redirect($back);
}

// ── Filters + data ───────────────────────────────────────────────────────────
$month = (int)($_GET['month'] ?? date('n'));
$year  = (int)($_GET['year']  ?? date('Y'));
if ($month < 1 || $month > 12)    $month = (int)date('n');
if ($year < 2000 || $year > 2100) $year  = (int)date('Y');
$start = sprintf('%04d-%02d-01', $year, $month);
$end   = date('Y-m-t', strtotime($start));
$monthName = date('F', mktime(0, 0, 0, $month, 1));
$export = (string)($_GET['export'] ?? '');

// Self-scoped employees (logged in as themselves) only see their own OD records.
$scopeEmp = scope_employee_id();
$recSql = 'SELECT od.id, od.od_date, od.reason, od.created_by,
            e.name AS emp_name, e.employee_id AS emp_code,
            u.name AS assigned_by
     FROM on_duties od
     JOIN employees e   ON e.id = od.employee_id
     LEFT JOIN users u  ON u.id = od.created_by
     WHERE od.od_date BETWEEN ? AND ?';
$recParams = [$start, $end];
if ($scopeEmp) { $recSql .= ' AND od.employee_id = ?'; $recParams[] = $scopeEmp; }
$recSql .= ' ORDER BY od.od_date ASC, e.name ASC';
$recStmt = $db->prepare($recSql);
$recStmt->execute($recParams);
$records = $recStmt->fetchAll(PDO::FETCH_ASSOC);

// Group by date.
$byDate = [];
foreach ($records as $r) $byDate[$r['od_date']][] = $r;
$totalCount = count($records);

// ── CSV export ───────────────────────────────────────────────────────────────
if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="on_duty_' . $year . '_' . str_pad((string)$month, 2, '0', STR_PAD_LEFT) . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['On Duty (OD) — ' . $monthName . ' ' . $year]);
    fputcsv($out, []);
    fputcsv($out, ['Date', 'Day', 'Emp Code', 'Employee', 'Reason', 'Assigned By']);
    foreach ($records as $r) {
        fputcsv($out, [date_fmt($r['od_date']), date_fmt($r['od_date'], 'l'), $r['emp_code'], $r['emp_name'], $r['reason'] ?? '', $r['assigned_by'] ?? 'System']);
    }
    fputcsv($out, []);
    fputcsv($out, ['', '', '', 'Total OD records', $totalCount, '']);
    fclose($out);
    exit;
}

// ── PDF export ───────────────────────────────────────────────────────────────
if ($export === 'pdf') {
    require_once __DIR__ . '/../../lib/fpdf/fpdf.php';
    $t = function ($s) { $s = str_replace(['₹', '—', '→'], ['Rs.', '-', '->'], (string)$s); $r = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s); return $r !== false ? $r : $s; };
    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetTitle('On Duty ' . $monthName . ' ' . $year);
    $pdf->SetAutoPageBreak(true, 12);
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 15);
    $pdf->Cell(0, 8, $t('On Duty (OD) Report'), 0, 1, 'C');
    $pdf->SetFont('Arial', '', 11);
    $pdf->Cell(0, 6, $t($monthName . ' ' . $year . '  -  ' . $totalCount . ' record(s)'), 0, 1, 'C');
    $pdf->Ln(2);
    $w = [28, 26, 50, 56, 30];
    $head = function () use ($pdf, $w, $t) {
        $pdf->SetFont('Arial', 'B', 9); $pdf->SetFillColor(8, 145, 178); $pdf->SetTextColor(255);
        $pdf->Cell($w[0], 7, $t('Date'), 1, 0, 'L', true);
        $pdf->Cell($w[1], 7, $t('Day'), 1, 0, 'L', true);
        $pdf->Cell($w[2], 7, $t('Employee'), 1, 0, 'L', true);
        $pdf->Cell($w[3], 7, $t('Reason'), 1, 0, 'L', true);
        $pdf->Cell($w[4], 7, $t('Assigned By'), 1, 1, 'L', true);
        $pdf->SetTextColor(0); $pdf->SetFont('Arial', '', 8);
    };
    $head();
    foreach ($records as $r) {
        if ($pdf->GetY() > 280) { $pdf->AddPage(); $head(); }
        $pdf->Cell($w[0], 6, $t(date_fmt($r['od_date'])), 1, 0, 'L');
        $pdf->Cell($w[1], 6, $t(date_fmt($r['od_date'], 'D')), 1, 0, 'L');
        $pdf->Cell($w[2], 6, $t($r['emp_code'] . ' ' . $r['emp_name']), 1, 0, 'L');
        $pdf->Cell($w[3], 6, $t($r['reason'] ?? '-'), 1, 0, 'L');
        $pdf->Cell($w[4], 6, $t($r['assigned_by'] ?? 'System'), 1, 1, 'L');
    }
    if (!$records) { $pdf->SetFont('Arial', 'I', 11); $pdf->Cell(0, 10, $t('No OD records for ' . $monthName . ' ' . $year . '.'), 0, 1, 'C'); }
    $pdf->Output('D', 'on_duty_' . $year . '_' . str_pad((string)$month, 2, '0', STR_PAD_LEFT) . '.pdf');
    exit;
}

// Active employees for the assign checkbox list.
$employees = $canCreate ? $db->query("SELECT id, name, employee_id FROM employees WHERE status='Active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) : [];
$exportQs = http_build_query(['month' => $month, 'year' => $year]);

$page_title = 'On Duty (OD)';
include '../../includes/header.php';
?>
<div class="d-flex align-items-center justify-content-between mb-3 no-print">
    <div>
        <h4 class="mb-0 fw-semibold">On Duty (OD)</h4>
        <p class="text-muted small mb-0"><?= h($monthName) ?> <?= $year ?> · <?= $totalCount ?> record<?= $totalCount === 1 ? '' : 's' ?></p>
    </div>
    <div class="d-flex gap-2">
        <a href="?export=csv&<?= h($exportQs) ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-file-csv me-1"></i>Excel</a>
        <a href="?export=pdf&<?= h($exportQs) ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-file-pdf me-1"></i>PDF</a>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fa fa-print me-1"></i>Print</button>
    </div>
</div>

<?php render_flash(); ?>

<div class="row g-4">
    <!-- Left: OD records for the month -->
    <div class="col-lg-8">
        <div class="card page-card">
            <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
                <h5 class="mb-0 fw-semibold"><i class="fa fa-briefcase me-2" style="color:#0891b2"></i>On Duty Records</h5>
                <form method="GET" class="d-flex gap-2 align-items-center no-print">
                    <select name="month" class="form-select form-select-sm">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>><?= date('F', mktime(0, 0, 0, $m, 1)) ?></option>
                        <?php endfor; ?>
                    </select>
                    <select name="year" class="form-select form-select-sm" style="width:90px">
                        <?php for ($y = (int)date('Y') + 1; $y >= (int)date('Y') - 2; $y--): ?>
                        <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                    <button type="submit" class="btn btn-sm btn-primary">Go</button>
                </form>
            </div>
            <div class="card-body">
                <div class="alert alert-info border-info d-flex align-items-start gap-2 py-2 mb-3 small">
                    <i class="fa fa-circle-info mt-1 text-info flex-shrink-0"></i>
                    <div>
                        <strong>On Duty (OD)</strong> is for employees working at a client site, field, or off-site location.
                        OD days are treated as <strong>Present</strong> — no salary deduction applies.
                        Attendance is auto-marked as <span class="badge" style="background:#0891b2">OD</span> on the assigned date.
                    </div>
                </div>

                <?php if (!$byDate): ?>
                    <div class="text-center py-5 text-muted">
                        <i class="fa fa-briefcase fa-3x d-block mb-3 opacity-25"></i>
                        <p class="mb-0">No OD records for <?= h($monthName) ?> <?= $year ?>.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($byDate as $dateStr => $group): ?>
                    <div class="mb-4">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <span class="badge py-2 px-3" style="background:#0891b2;font-size:.82rem">
                                <i class="fa fa-calendar me-1"></i><?= date_fmt($dateStr) ?> — <?= date_fmt($dateStr, 'l') ?>
                            </span>
                            <span class="badge bg-primary"><?= count($group) ?> employee(s)</span>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-bordered table-sm align-middle mb-0" style="font-size:.88rem">
                                <thead class="table-light">
                                    <tr>
                                        <th>Employee</th>
                                        <th>Reason / Remarks</th>
                                        <th class="text-center" style="width:120px">Assigned By</th>
                                        <?php if ($canDelete): ?><th class="text-center" style="width:70px">Action</th><?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($group as $od): ?>
                                    <tr>
                                        <td><strong><?= h($od['emp_name']) ?></strong> <small class="text-muted ms-1"><?= h($od['emp_code']) ?></small></td>
                                        <td class="text-muted"><?= h($od['reason'] ?? '') ?: '—' ?></td>
                                        <td class="text-center text-muted small"><?= h($od['assigned_by'] ?? 'System') ?: 'System' ?></td>
                                        <?php if ($canDelete): ?>
                                        <td class="text-center">
                                            <form method="POST" class="d-inline"
                                                  onsubmit="return confirm('Remove OD for <?= h($od['emp_name']) ?> on <?= date_fmt($od['od_date']) ?>?')">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="delete" value="1">
                                                <input type="hidden" name="id" value="<?= $od['id'] ?>">
                                                <input type="hidden" name="month" value="<?= $month ?>">
                                                <input type="hidden" name="year" value="<?= $year ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Remove"><i class="fa fa-trash"></i></button>
                                            </form>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right: assign form -->
    <div class="col-lg-4 no-print">
        <?php if ($canCreate): ?>
        <div class="card page-card">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-semibold"><i class="fa fa-plus-circle me-2" style="color:#0891b2"></i>Assign On Duty</h6>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="assign" value="1">
                    <input type="hidden" name="month" value="<?= $month ?>">
                    <input type="hidden" name="year" value="<?= $year ?>">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Date <span class="text-danger">*</span></label>
                        <input type="date" name="date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label fw-semibold mb-0">Employee(s) <span class="text-danger">*</span></label>
                            <div class="d-flex gap-2">
                                <a href="#" id="selectAllEmp" class="small text-decoration-none">All</a>
                                <span class="text-muted small">|</span>
                                <a href="#" id="clearAllEmp" class="small text-decoration-none">Clear</a>
                            </div>
                        </div>
                        <div class="border rounded p-2" style="max-height:260px;overflow-y:auto">
                            <?php foreach ($employees as $emp): ?>
                            <div class="form-check">
                                <input class="form-check-input emp-check" type="checkbox" name="employee_ids[]"
                                       value="<?= $emp['id'] ?>" id="emp_<?= $emp['id'] ?>">
                                <label class="form-check-label" for="emp_<?= $emp['id'] ?>" style="font-size:.88rem">
                                    <?= h($emp['name']) ?> <small class="text-muted"><?= h($emp['employee_id']) ?></small>
                                </label>
                            </div>
                            <?php endforeach; ?>
                            <?php if (!$employees): ?><div class="text-muted small">No active employees.</div><?php endif; ?>
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Reason / Remarks</label>
                        <input type="text" name="reason" class="form-control" placeholder="e.g. Client site visit, Field work" maxlength="255">
                    </div>

                    <button type="submit" class="btn w-100 text-white fw-semibold" style="background:#0891b2">
                        <i class="fa fa-check me-1"></i>Assign OD
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <div class="card page-card mt-3">
            <div class="card-body py-3">
                <div class="small fw-semibold mb-2 text-muted">How OD affects records:</div>
                <ul class="small text-muted mb-0 ps-3">
                    <li>Attendance marked as <span class="badge" style="background:#0891b2;font-size:.7rem">OD</span> on the date</li>
                    <li>Counted as <strong>Present</strong> in attendance report</li>
                    <li>No salary deduction in payroll</li>
                    <li>Shown distinctly in the monthly attendance grid</li>
                    <li>Removing OD also removes the attendance record</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php $page_scripts = <<<'JS'
<script>
$(function () {
    $('#selectAllEmp').on('click', function (e) { e.preventDefault(); $('.emp-check').prop('checked', true); });
    $('#clearAllEmp').on('click', function (e) { e.preventDefault(); $('.emp-check').prop('checked', false); });
});
</script>
JS;
include '../../includes/footer.php';
?>
