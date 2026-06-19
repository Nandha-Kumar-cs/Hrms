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

// ── Split allowances into base earnings / benefits / bonuses ──────────────────
$earnAllow = []; $benefits = []; $bonuses = [];
foreach ($allowances as $name => $amt) {
    if ($amt <= 0) continue;
    if (str_starts_with($name, '[BENEFIT]'))    $benefits[trim(substr($name, 9))] = $amt;
    elseif (str_starts_with($name, '[BONUS]'))   $bonuses[trim(substr($name, 7))]  = $amt;
    else                                         $earnAllow[$name] = $amt;
}
$allowanceTotal  = array_sum($earnAllow);
$benefitsTotal   = array_sum($benefits);
$bonusesTotal    = array_sum($bonuses);
$additionalTotal = $benefitsTotal + $bonusesTotal;
$totalEarnings   = (float)$s['gross_earnings'];
$totalDeductions = (float)$s['total_deductions'];

$earnsRows = [];
foreach ($earnAllow as $n => $a) $earnsRows[] = [$n, $a, str_starts_with($n, 'Overtime') ? 'ot' : ''];
foreach ($benefits as $n => $a)  $earnsRows[] = [$n, $a, 'benefit'];
foreach ($bonuses  as $n => $a)  $earnsRows[] = [$n, $a, 'bonus'];

$dedsRows = [];
foreach ($deductions as $n => $a) {
    if ($a <= 0) continue;
    $type = (stripos($n, 'PF') !== false || stripos($n, 'Provident') !== false || stripos($n, 'ESI') !== false) ? 'stat'
          : (stripos($n, 'Late') !== false ? 'late' : (stripos($n, 'Absent') !== false ? 'abs' : ''));
    $dedsRows[] = [$n, $a, $type];
}
$maxRows = max(count($earnsRows), count($dedsRows), 1);

$rs = '&#8377;';   // ₹
$nf = fn ($a) => number_format((float)$a, 2);
$h  = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

// ─── Build HTML (matches the reference payslip design) ────────────────────────
ob_start();
?>
<style>
    body { font-family: dejavusans, sans-serif; font-size: 9pt; color: #1e293b; }
    .company-name { font-size: 19pt; font-weight: bold; color: #2563eb; }
    .company-sub  { font-size: 7.5pt; color: #64748b; }
    .slip-title   { font-size: 13pt; font-weight: bold; }
    .slip-period  { font-size: 8.5pt; color: #64748b; }
    .hr { border-bottom: 3px solid #2563eb; height: 1px; margin: 4px 0 10px; }
    .emp-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 5px; padding: 8px 12px; }
    .emp-box td { padding: 4px 6px; }
    .lbl { font-size: 7pt; color: #64748b; font-weight: bold; }
    .val { font-size: 9.5pt; font-weight: bold; }
    table.dual { width: 100%; border-collapse: collapse; }
    table.dual td { padding: 5px 8px; border: 1px solid #e2e8f0; font-size: 8.5pt; }
    .th-earn { background: #166534; color: #ffffff; font-weight: bold; }
    .th-ded  { background: #991b1b; color: #ffffff; font-weight: bold; }
    .foot-earn { background: #dcfce7; color: #166534; font-weight: bold; }
    .foot-ded  { background: #fee2e2; color: #991b1b; font-weight: bold; }
    .row-ot   { background: #f0fdf4; }
    .row-late { background: #fffbeb; }
    .row-abs  { background: #fff5f5; }
    .badge { font-size: 6.5pt; color: #ffffff; padding: 1px 4px; border-radius: 3px; }
    .net-box td { background: #1e3a5f; color: #ffffff; padding: 9px 14px; }
    .att td { border: 1px solid #e2e8f0; text-align: center; padding: 5px 3px; }
    .att-val { font-size: 11pt; font-weight: bold; }
    .att-lbl { font-size: 6.5pt; color: #64748b; }
    .rates { font-size: 7pt; color: #64748b; }
    .footer { font-size: 7pt; color: #94a3b8; text-align: center; }
</style>

<!-- Header -->
<table style="width:100%">
    <tr>
        <td style="width:64%;vertical-align:bottom">
            <?php if ($logoData): ?><img src="<?= $logoData ?>" style="height:46px;margin-bottom:4px"><br><?php endif; ?>
            <span class="company-name"><?= $h($companyName) ?></span><br>
            <span class="company-sub"><?= $h($companyAddress) ?></span>
        </td>
        <td style="width:36%;vertical-align:bottom;text-align:right">
            <span class="slip-title">Salary Slip</span><br>
            <span class="slip-period"><?= $h($monthLabel) ?></span>
        </td>
    </tr>
</table>
<div class="hr"></div>

<!-- Employee Info -->
<table class="emp-box" style="width:100%;margin-bottom:10px">
    <tr><td style="width:50%"><span class="lbl">EMPLOYEE NAME</span><br><span class="val"><?= $h($s['emp_name']) ?></span></td>
        <td style="width:50%"><span class="lbl">EMPLOYEE CODE</span><br><span class="val"><?= $h($s['emp_code']) ?></span></td></tr>
    <tr><td><span class="lbl">DESIGNATION</span><br><span class="val"><?= $h($s['desig_name'] ?? 'N/A') ?></span></td>
        <td><span class="lbl">DEPARTMENT</span><br><span class="val"><?= $h($s['dept_name'] ?? 'N/A') ?></span></td></tr>
    <tr><td><span class="lbl">PAY PERIOD</span><br><span class="val"><?= $h($monthLabel) ?></span></td>
        <td><span class="lbl">WORKING DAYS</span><br><span class="val"><?= (int)$s['working_days'] ?></span></td></tr>
</table>

<!-- Earnings & Deductions -->
<table class="dual" style="margin-bottom:10px">
    <tr>
        <td class="th-earn" style="width:34%">Earnings</td>
        <td class="th-earn" style="width:16%;text-align:right">Amount (<?= $rs ?>)</td>
        <td class="th-ded" style="width:34%">Deductions</td>
        <td class="th-ded" style="width:16%;text-align:right">Amount (<?= $rs ?>)</td>
    </tr>
    <?php for ($i = 0; $i < $maxRows; $i++):
        $e = $earnsRows[$i] ?? ['', null, ''];
        $d = $dedsRows[$i]  ?? ['', null, ''];
        $rowCls = $e[2] === 'ot' ? 'row-ot' : ($d[2] === 'late' ? 'row-late' : ($d[2] === 'abs' ? 'row-abs' : ''));
    ?>
    <tr class="<?= $rowCls ?>">
        <td><?= $h($e[0]) ?><?php
            if ($e[2] === 'ot')          echo ' <span class="badge" style="background:#16a34a">OT</span>';
            elseif ($e[2] === 'benefit') echo ' <span class="badge" style="background:#0891b2">BENEFIT</span>';
            elseif ($e[2] === 'bonus')   echo ' <span class="badge" style="background:#d97706">BONUS</span>';
        ?></td>
        <td style="text-align:right"><?= $e[1] !== null ? $nf($e[1]) : '' ?></td>
        <td><?= $h($d[0]) ?><?php
            if ($d[2] === 'stat')      echo ' <span class="badge" style="background:#64748b">STAT</span>';
            elseif ($d[2] === 'late')  echo ' <span class="badge" style="background:#d97706">LATE</span>';
            elseif ($d[2] === 'abs')   echo ' <span class="badge" style="background:#dc2626">ABS</span>';
        ?></td>
        <td style="text-align:right"><?= $d[1] !== null ? $nf($d[1]) : '' ?></td>
    </tr>
    <?php endfor; ?>
    <tr>
        <td class="foot-earn">Total Earnings</td>
        <td class="foot-earn" style="text-align:right"><?= $nf($totalEarnings) ?></td>
        <td class="foot-ded">Total Deductions</td>
        <td class="foot-ded" style="text-align:right"><?= $nf($totalDeductions) ?></td>
    </tr>
</table>

<?php if ($additionalTotal > 0): $addRowSpan = 1 + ($benefitsTotal > 0 ? 1 : 0) + ($bonusesTotal > 0 ? 1 : 0); ?>
<!-- Additional earnings (benefits + bonuses) -->
<table class="dual" style="margin-bottom:10px">
    <tr>
        <td style="width:50%;background:#f0fdf4;font-weight:bold;border:1px solid #bbf7d0">Salary Sub-total</td>
        <td style="width:25%;background:#f0fdf4;text-align:right;border:1px solid #bbf7d0"><?= $rs ?> <?= $nf($allowanceTotal) ?></td>
        <td rowspan="<?= $addRowSpan ?>" style="width:25%;background:#1e3a5f;color:#ffffff;text-align:center;vertical-align:middle;padding:8px">
            <span style="font-size:7pt">Total Additional Earnings</span><br>
            <span style="font-size:13pt;font-weight:bold"><?= $rs ?> <?= $nf($additionalTotal) ?></span><br>
            <span style="font-size:6.5pt">Benefits + Bonuses</span>
        </td>
    </tr>
    <?php if ($benefitsTotal > 0): ?>
    <tr>
        <td style="background:#cffafe;font-weight:bold;border:1px solid #a5f3fc">Benefit Funds Total</td>
        <td style="background:#cffafe;text-align:right;border:1px solid #a5f3fc"><?= $rs ?> <?= $nf($benefitsTotal) ?></td>
    </tr>
    <?php endif; ?>
    <?php if ($bonusesTotal > 0): ?>
    <tr>
        <td style="background:#fef3c7;font-weight:bold;border:1px solid #fde68a">Bonuses &amp; Incentives Total</td>
        <td style="background:#fef3c7;text-align:right;border:1px solid #fde68a"><?= $rs ?> <?= $nf($bonusesTotal) ?></td>
    </tr>
    <?php endif; ?>
</table>
<?php endif; ?>

<!-- Net Pay -->
<table class="net-box" style="width:100%;border-collapse:collapse;margin-bottom:10px">
    <tr>
        <td style="font-size:11pt;font-weight:bold">Final Net Pay (Take Home)</td>
        <td style="text-align:right;font-size:14pt;font-weight:bold"><?= $rs ?> <?= $nf($s['net_pay']) ?></td>
    </tr>
</table>

<?php if (!empty($attSummary)): $att = $attSummary; ?>
<!-- Attendance summary -->
<div style="border:1px solid #e2e8f0;border-radius:5px;padding:6px 10px;margin-bottom:8px">
    <div style="font-size:8pt;font-weight:bold;color:#475569;border-bottom:1px solid #e2e8f0;padding-bottom:3px;margin-bottom:4px">
        Attendance Summary &mdash; <?= $h($monthLabel) ?>
    </div>
    <table class="att" style="width:100%;border-collapse:collapse">
        <tr>
            <td><span class="att-val" style="color:#2563eb"><?= $att['total_working_days'] ?? 0 ?></span><br><span class="att-lbl">Working Days</span></td>
            <td><span class="att-val" style="color:#16a34a"><?= $att['present_days'] ?? 0 ?></span><br><span class="att-lbl">Present</span></td>
            <td><span class="att-val" style="color:#d97706"><?= $att['half_days'] ?? 0 ?></span><br><span class="att-lbl">Half Day</span></td>
            <td><span class="att-val" style="color:#64748b"><?= $att['leave_days'] ?? 0 ?></span><br><span class="att-lbl">On Leave</span></td>
            <td><span class="att-val" style="color:#dc2626"><?= $nf($att['absent_days'] ?? 0) ?></span><br><span class="att-lbl">Absent</span></td>
            <td><span class="att-val" style="color:#d97706"><?= $att['late_days'] ?? 0 ?></span><br><span class="att-lbl">Late Days</span></td>
            <?php if (($att['ot_hours'] ?? 0) > 0): ?>
            <td><span class="att-val" style="color:#16a34a"><?= $nf($att['ot_hours']) ?></span><br><span class="att-lbl">OT Hours</span></td>
            <td><span class="att-val" style="color:#16a34a"><?= $rs ?> <?= $nf($att['ot_amount'] ?? 0) ?></span><br><span class="att-lbl">OT Pay</span></td>
            <?php endif; ?>
        </tr>
    </table>
    <div class="rates" style="border-top:1px solid #e2e8f0;padding-top:4px;margin-top:4px">
        <?php if (isset($att['ctc_per_month'])): ?>CTC/Month: <strong><?= $rs ?> <?= $nf($att['ctc_per_month']) ?></strong> &nbsp;&nbsp; <?php endif; ?>
        <?php if (isset($att['basic_salary'])): ?>Basic: <strong><?= $rs ?> <?= $nf($att['basic_salary']) ?></strong> &nbsp;&nbsp; <?php endif; ?>
        LOP/Day: <strong><?= $rs ?> <?= $nf($att['per_day_salary'] ?? 0) ?></strong>
        <?php if (isset($att['per_hour_rate'])): ?> &nbsp;&nbsp; OT Rate (2&times;): <strong><?= $rs ?> <?= $nf(($att['per_hour_rate'] ?? 0) * 2) ?></strong><?php endif; ?>
        <?php if ((float)($s['pf_employer'] ?? 0) > 0 || (float)($s['esi_employer'] ?? 0) > 0): ?>
        <br>Employer PF: <strong><?= $rs ?> <?= $nf($s['pf_employer']) ?></strong> &nbsp;&nbsp; Employer ESI: <strong><?= $rs ?> <?= $nf($s['esi_employer']) ?></strong>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="footer" style="border-top:1px solid #e2e8f0;padding-top:5px;margin-top:6px">
    This is a computer-generated salary slip and does not require a physical signature.
    &bull; Generated by <?= $h($companyName) ?> &bull; <?= date('d M Y H:i') ?>
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
