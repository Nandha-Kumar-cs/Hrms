<?php
/**
 * Salary Slip PDF Download.
 *
 * Renders with mPDF (full CSS / ₹ support) to match the reference
 * Employee_Management payslip design; falls back to TCPDF, then to a printable
 * HTML page if no PDF engine is available.
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('payroll', 'view');

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    flash('error', 'Invalid slip ID.');
    redirect(BASE_URL . '/modules/payroll/index.php');
}

$db   = db();
$user = current_user();

$stmt = $db->prepare(
    'SELECT ss.*, e.name AS emp_name, e.employee_id AS emp_code,
            e.pan_number, e.uan_number, e.bank_account, e.bank_name, e.bank_ifsc,
            d.name AS dept_name, des.name AS desig_name,
            ent.name AS entity_name, ent.address AS entity_address,
            ent.city AS entity_city, ent.state AS entity_state, ent.pincode AS entity_pincode,
            ent.logo AS entity_logo
     FROM salary_slips ss
     JOIN employees e ON e.id = ss.employee_id
     LEFT JOIN departments d   ON d.id = e.department_id
     LEFT JOIN designations des ON des.id = e.designation_id
     LEFT JOIN entities ent    ON ent.id = e.entity_id
     WHERE ss.id = ?'
);
$stmt->execute([$id]);
$s = $stmt->fetch();

if (!$s) {
    flash('error', 'Salary slip not found.');
    redirect(BASE_URL . '/modules/payroll/index.php');
}

// Company header from the employee's selected entity (fallback to constants).
$companyName    = $s['entity_name'] ?: COMPANY_NAME;
$companyAddress = COMPANY_ADDRESS;
if ($s['entity_name']) {
    $cityLine = trim(implode(' ', array_filter([$s['entity_city'], $s['entity_state'], $s['entity_pincode']])));
    $companyAddress = trim(implode(', ', array_filter([$s['entity_address'], $cityLine])));
}

// Company logo — embedded as a base64 data URI so it renders inside the PDF.
// Prefer the employee's entity logo, else any configured entity logo.
$logoData = null;   // base64 data URI — works in mPDF, TCPDF and the HTML fallback
$logoFile = $s['entity_logo'] ?? null;
if (!$logoFile) {
    try {
        $logoFile = $db->query("SELECT logo FROM entities WHERE logo IS NOT NULL AND logo <> '' LIMIT 1")->fetchColumn() ?: null;
    } catch (Throwable $e) { /* ignore */ }
}
if ($logoFile) {
    $logoPath = dirname(__DIR__, 2) . '/storage/entities/' . $logoFile;
    if (is_file($logoPath)) {
        // Sniff the ACTUAL image type — uploaded files are often mis-named
        // (e.g. a JPEG saved as ".png"); trusting the extension would emit a
        // wrong MIME and break decoding in browsers/TCPDF.
        $info = @getimagesize($logoPath);
        $mime = $info['mime'] ?? null;
        if (!$mime) {
            $ext  = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
            $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'svg' => 'image/svg+xml'][$ext] ?? 'image/png';
        }
        $logoData = 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($logoPath));
    }
}

// Employees can only access their own slips
if ($user['role_name'] === 'Employee') {
    $myEmp = $db->prepare('SELECT id FROM employees WHERE email = ? LIMIT 1');
    $myEmp->execute([$user['email']]);
    $myId  = $myEmp->fetchColumn();
    if ($s['employee_id'] != $myId) {
        http_response_code(403);
        die('Access denied.');
    }
}

// ─── Parse slip data ──────────────────────────────────────────────────────────
$monthLabel   = date('F Y', strtotime($s['payroll_month'] . '-01'));
$allowances   = $s['allowances']   ? json_decode($s['allowances'], true)   : [];
$deductions   = $s['deductions_json'] ? json_decode($s['deductions_json'], true) : [];
$attSummary   = $s['attendance_summary'] ? json_decode($s['attendance_summary'], true) : [];
$isIndividual = ($s['slip_type'] ?? 'batch') === 'individual';

if (!$isIndividual || empty($allowances)) {
    $allowances = array_filter([
        'Basic Salary'      => (float)$s['basic'],
        'HRA'               => (float)$s['hra'],
        'Conveyance'        => (float)$s['conveyance'],
        'Medical Allowance' => (float)$s['medical'],
        'Special Allowance' => (float)$s['special_allow'],
        'Other Allowance'   => (float)$s['other_allow'],
    ]);
}
if (!$isIndividual || empty($deductions)) {
    $deductions = array_filter([
        'Provident Fund (Employee)' => (float)$s['pf_employee'],
        'ESI (Employee)'            => (float)$s['esi_employee'],
        'TDS'                       => (float)$s['tds'],
        'Other Deductions'          => (float)$s['other_deductions'],
    ]);
}

// ── Earnings: base + benefits (each) + a single combined Bonus line ───────────
// (mirrors modules/payroll/slip.php exactly so the PDF matches the on-screen slip)
$earnList = []; $bonusTotal = 0.0;
foreach ($allowances as $name => $amt) {
    if ($amt <= 0) continue;
    if (str_starts_with($name, '[BENEFIT]'))    $earnList[trim(substr($name, 9))] = $amt;
    elseif (str_starts_with($name, '[BONUS]'))   $bonusTotal += (float)$amt;
    else                                         $earnList[$name] = $amt;
}
if ($bonusTotal > 0) $earnList['Bonus'] = $bonusTotal;

// ── Deductions: each on its own line (Absent, Late, Short Hours shown separately).
//    Strip "(N days …)" detail; merge multiple loans (#id) into one "Loan Deduction".
$dedList = [];
foreach ($deductions as $name => $amt) {
    if ($amt <= 0) continue;
    $clean = preg_replace('/\s*\(\s*\d+\s*day.*?\)/i', '', $name);   // drop "(N days …)"
    $clean = preg_replace('/\s*#\d+/', '', $clean);                  // merge loans → "Loan Deduction"
    $dedList[$clean] = ($dedList[$clean] ?? 0) + (float)$amt;
}

$eLabels = array_keys($earnList); $eItems = array_values($earnList);
$dLabels = array_keys($dedList);  $dItems = array_values($dedList);
$maxRows = max(count($eLabels), count($dLabels), 1);

$totalEarnings   = (float)$s['gross_earnings'];
$totalDeductions = (float)$s['total_deductions'];

$rs = '&#8377;';   // ₹
$nf = fn ($a) => number_format((float)$a, 2);
$h  = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

// ─── Build HTML (matches the reference payslip design) ────────────────────────
ob_start();
?>
<style>
    body { font-family: dejavusans, sans-serif; font-size: 9.5pt; color: #000; }
    .company-name { font-size: 17pt; font-weight: bold; color: #000; }
    .company-sub  { font-size: 8pt; color: #000; }
    .title-bar { font-size: 12pt; font-weight: bold; text-align: center; padding: 5px 0;
                 border-top: 1px solid #000; border-bottom: 1px solid #000; }
    table.info { width: 100%; border-collapse: collapse; margin: 12px 0; }
    table.info td { border: 1px solid #000; padding: 5px 8px; font-size: 9pt; width: 50%; }
    table.ed { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    table.ed th, table.ed td { border: 1px solid #000; padding: 5px 8px; font-size: 9pt; }
    table.ed th { text-align: left; }
    .net-box { border: 1.5px solid #000; padding: 9px 12px; margin-bottom: 6px; }
    .footer { font-size: 8pt; color: #000; text-align: center; border-top: 1px solid #000; padding-top: 6px; margin-top: 10px; }
</style>

<!-- Header (centred — only the logo is in colour) -->
<div style="text-align:center;margin-bottom:6px">
    <?php if ($logoData): ?><img src="<?= $logoData ?>" style="height:48px"><br><?php endif; ?>
    <span class="company-name"><?= $h($companyName) ?></span><br>
    <span class="company-sub"><?= $h($companyAddress) ?></span>
</div>
<div class="title-bar">Payslip for the month of <?= $h($monthLabel) ?></div>

<!-- Employee info -->
<table class="info">
    <tr><td><strong>Employee Name:</strong> <?= $h($s['emp_name']) ?></td>
        <td><strong>Employee ID:</strong> <?= $h($s['emp_code']) ?></td></tr>
    <tr><td><strong>Department:</strong> <?= $h($s['dept_name'] ?? '—') ?: '—' ?></td>
        <td><strong>Designation:</strong> <?= $h($s['desig_name'] ?? '—') ?: '—' ?></td></tr>
    <tr><td><strong>Pay Period:</strong> <?= $h($monthLabel) ?></td>
        <td><strong>PAN Number:</strong> <?= $h($s['pan_number'] ?? '—') ?: '—' ?></td></tr>
    <?php if (!empty($s['uan_number'])): ?>
    <tr><td><strong>UAN Number:</strong> <?= $h($s['uan_number']) ?></td>
        <td><strong>Bank Account:</strong> <?= $h($s['bank_account'] ?? '—') ?: '—' ?></td></tr>
    <?php endif; ?>
</table>

<!-- Earnings & Deductions (single combined table) -->
<table class="ed">
    <tr>
        <th style="width:35%">Earnings</th><th style="width:15%;text-align:right">Amount (<?= $rs ?>)</th>
        <th style="width:35%">Deductions</th><th style="width:15%;text-align:right">Amount (<?= $rs ?>)</th>
    </tr>
    <?php for ($i = 0; $i < $maxRows; $i++): ?>
    <tr>
        <td><?= isset($eLabels[$i]) ? $h($eLabels[$i]) : '' ?></td>
        <td style="text-align:right"><?= isset($eItems[$i]) ? $nf($eItems[$i]) : '' ?></td>
        <td><?= isset($dLabels[$i]) ? $h($dLabels[$i]) : '' ?></td>
        <td style="text-align:right"><?= isset($dItems[$i]) ? $nf($dItems[$i]) : '' ?></td>
    </tr>
    <?php endfor; ?>
    <tr style="font-weight:bold">
        <td>Total Earnings</td><td style="text-align:right"><?= $nf($totalEarnings) ?></td>
        <td>Total Deductions</td><td style="text-align:right"><?= $nf($totalDeductions) ?></td>
    </tr>
</table>

<!-- Net Pay -->
<div class="net-box">
    <span style="font-weight:bold;font-size:11pt">Net Pay for the month (Total Earnings &minus; Total Deductions): <?= $rs ?> <?= $nf($s['net_pay']) ?></span><br>
    <span style="font-size:8.5pt">(Rupees <?= $h(_inr_words((float)$s['net_pay'])) ?> Only)</span>
</div>
<div style="text-align:right;font-size:8pt;margin-bottom:6px">
    PF Employer: <?= $rs ?> <?= $nf($s['pf_employer']) ?> &nbsp;&nbsp; ESI Employer: <?= $rs ?> <?= $nf($s['esi_employer']) ?>
</div>

<div class="footer">
    This is a system generated payslip and does not require signature.<br>
    Print Date: <?= date('d M Y, h:i A') ?>
</div>
<?php
$html = ob_get_clean();

$filename = 'salary-slip-' . $s['emp_code'] . '-' . str_replace('-', '', $s['payroll_month']) . '.pdf';

// ─── Render: mPDF (best CSS fidelity) → TCPDF → printable HTML ─────────────────
$xamppRoot = dirname(__DIR__, 4);   // e.g. C:/xampp8.2

// 1) mPDF — matches the reference design (₹, rowspan, gradients).
$mpdfAutoloads = [
    $xamppRoot . '/htdocs/xibo/vendor/autoload.php',
    'C:/xampp8.2/htdocs/xibo/vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
];
foreach ($mpdfAutoloads as $al) {
    if (!is_file($al)) continue;
    require_once $al;
    if (!class_exists('\\Mpdf\\Mpdf')) continue;
    try {
        $tmp = sys_get_temp_dir() . '/mpdf_hrms';
        if (!is_dir($tmp)) @mkdir($tmp, 0777, true);
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8', 'format' => 'A4',
            'margin_left' => 10, 'margin_right' => 10, 'margin_top' => 10, 'margin_bottom' => 10,
            'tempDir' => is_dir($tmp) ? $tmp : sys_get_temp_dir(),
        ]);
        $mpdf->SetTitle('Salary Slip - ' . $s['emp_name'] . ' - ' . $monthLabel);
        $mpdf->WriteHTML($html);
        $mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);
        exit;
    } catch (Throwable $e) {
        // fall through to TCPDF
    }
}

// 2) TCPDF fallback — renders the same HTML (lower CSS fidelity).
$tcpdfCandidates = [
    $xamppRoot . '/phpMyAdmin/vendor/tecnickcom/tcpdf/tcpdf.php',
    'C:/xampp8.2/phpMyAdmin/vendor/tecnickcom/tcpdf/tcpdf.php',
    'C:/xampp8/phpMyAdmin/vendor/tecnickcom/tcpdf/tcpdf.php',
    'C:/xampp/phpMyAdmin/vendor/tecnickcom/tcpdf/tcpdf.php',
];
foreach ($tcpdfCandidates as $cand) {
    if (!is_file($cand)) continue;
    require_once $cand;
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('MagDyn HRMS');
    $pdf->SetTitle('Salary Slip - ' . $s['emp_name'] . ' - ' . $monthLabel);
    $pdf->SetMargins(10, 10, 10);
    $pdf->SetAutoPageBreak(true, 10);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();
    $pdf->SetFont('dejavusans', '', 9);
    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output($filename, 'D');
    exit;
}

// 3) Last resort — printable HTML.
header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Salary Slip</title></head><body onload="window.print()">';
echo $html;
echo '</body></html>';
exit;
