<?php
/**
 * Salary Slip PDF Download
 * Streams a PDF using TCPDF (from phpMyAdmin vendor bundle).
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
            d.name AS dept_name, des.name AS desig_name
     FROM salary_slips ss
     JOIN employees e ON e.id = ss.employee_id
     LEFT JOIN departments d   ON d.id = e.department_id
     LEFT JOIN designations des ON des.id = e.designation_id
     WHERE ss.id = ?'
);
$stmt->execute([$id]);
$s = $stmt->fetch();

if (!$s) {
    flash('error', 'Salary slip not found.');
    redirect(BASE_URL . '/modules/payroll/index.php');
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
    // Build allowances from fixed columns for batch slips
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

// ─── Load TCPDF ───────────────────────────────────────────────────────────────
$tcpdfPath = 'C:/xampp8/phpMyAdmin/vendor/tecnickcom/tcpdf/tcpdf.php';
if (!file_exists($tcpdfPath)) {
    // Fallback: render HTML and suggest printing
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>PDF not available</title></head><body style="font-family:sans-serif;padding:40px">';
    echo '<h2>PDF Library Not Found</h2>';
    echo '<p>TCPDF was not found at the expected path. Please use the browser print function instead.</p>';
    echo '<a href="' . BASE_URL . '/modules/payroll/slip.php?id=' . $id . '">← Back to slip</a>';
    echo '</body></html>';
    exit;
}
require_once $tcpdfPath;

// ─── Build PDF ────────────────────────────────────────────────────────────────
$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('MagDyn HRMS');
$pdf->SetAuthor(COMPANY_NAME);
$pdf->SetTitle('Salary Slip — ' . $s['emp_name'] . ' — ' . $monthLabel);
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(true, 15);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->AddPage();
$pdf->SetFont('helvetica', '', 10);

// ─── HTML content for PDF ─────────────────────────────────────────────────────
ob_start();
?>
<style>
    body { font-family: helvetica; font-size: 10pt; color: #222; }
    h1   { font-size: 16pt; font-weight: bold; margin: 0; color: #1a56a0; }
    h2   { font-size: 11pt; font-weight: bold; margin: 6px 0 0; }
    table { width: 100%; border-collapse: collapse; }
    .info-table td { padding: 5px 8px; font-size: 9pt; border: 1px solid #ddd; }
    .section-head { background: #1a56a0; color: #fff; font-weight: bold; padding: 5px 8px; font-size: 9pt; }
    .earn-head  { background: #1a7a40; color: #fff; }
    .ded-head   { background: #b92020; color: #fff; }
    .row-line td { padding: 4px 8px; font-size: 9pt; border-bottom: 1px solid #eee; }
    .total-row td { padding: 5px 8px; font-weight: bold; border-top: 2px solid #aaa; font-size: 9pt; background: #f5f5f5; }
    .net-row td { padding: 8px 12px; font-size: 13pt; font-weight: bold; background: #1a56a0; color: #fff; }
    .label { color: #888; }
    .divider { border-top: 2px solid #1a56a0; margin: 10px 0; }
</style>

<table>
    <tr>
        <td style="width:70%">
            <h1><?= htmlspecialchars(COMPANY_NAME) ?></h1>
            <div style="font-size:9pt;color:#666"><?= htmlspecialchars(COMPANY_ADDRESS) ?></div>
        </td>
        <td style="text-align:right;width:30%">
            <h2>SALARY SLIP</h2>
            <div style="font-size:9pt;color:#555"><?= htmlspecialchars($monthLabel) ?></div>
        </td>
    </tr>
</table>

<div class="divider"></div>

<!-- Employee Info -->
<table class="info-table" style="margin-bottom:10px">
    <tr>
        <td class="label" style="width:25%">Employee Name</td>
        <td style="width:25%"><strong><?= htmlspecialchars($s['emp_name']) ?></strong></td>
        <td class="label" style="width:25%">Employee ID</td>
        <td style="width:25%"><?= htmlspecialchars($s['emp_code']) ?></td>
    </tr>
    <tr>
        <td class="label">Department</td>
        <td><?= htmlspecialchars($s['dept_name'] ?? '—') ?></td>
        <td class="label">Designation</td>
        <td><?= htmlspecialchars($s['desig_name'] ?? '—') ?></td>
    </tr>
    <tr>
        <td class="label">Pay Period</td>
        <td><?= htmlspecialchars($monthLabel) ?></td>
        <td class="label">Working Days</td>
        <td><?= (int)$s['working_days'] ?></td>
    </tr>
    <?php if ($s['pan_number']): ?>
    <tr>
        <td class="label">PAN Number</td>
        <td><?= htmlspecialchars($s['pan_number']) ?></td>
        <td class="label">UAN Number</td>
        <td><?= htmlspecialchars($s['uan_number'] ?? '—') ?></td>
    </tr>
    <?php endif; ?>
</table>

<!-- Earnings & Deductions -->
<table style="margin-bottom:10px">
    <tr>
        <td style="width:50%;vertical-align:top;padding-right:6px">
            <table style="width:100%">
                <tr><td colspan="2" class="section-head earn-head">Earnings</td></tr>
                <?php foreach ($allowances as $label => $amount): if ($amount <= 0) continue; ?>
                <tr class="row-line">
                    <td><?= htmlspecialchars($label) ?></td>
                    <td style="text-align:right"><?= money($amount) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td>Total Earnings</td>
                    <td style="text-align:right"><?= money($s['gross_earnings']) ?></td>
                </tr>
            </table>
        </td>
        <td style="width:50%;vertical-align:top;padding-left:6px">
            <table style="width:100%">
                <tr><td colspan="2" class="section-head ded-head">Deductions</td></tr>
                <?php foreach ($deductions as $label => $amount): if ($amount <= 0) continue; ?>
                <tr class="row-line">
                    <td><?= htmlspecialchars($label) ?></td>
                    <td style="text-align:right"><?= money($amount) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty(array_filter($deductions))): ?>
                <tr class="row-line"><td colspan="2" style="color:#999;font-style:italic">No deductions</td></tr>
                <?php endif; ?>
                <tr class="total-row">
                    <td>Total Deductions</td>
                    <td style="text-align:right"><?= money($s['total_deductions']) ?></td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<!-- Net Pay -->
<table style="margin-bottom:10px">
    <tr class="net-row">
        <td>NET SALARY (Take Home)</td>
        <td style="text-align:right;font-size:16pt"><?= money($s['net_pay']) ?></td>
    </tr>
</table>

<?php if (!empty($attSummary)): ?>
<!-- Attendance Summary -->
<table class="info-table" style="margin-bottom:8px">
    <tr>
        <td colspan="6" class="section-head">Attendance Summary</td>
    </tr>
    <tr>
        <td class="label">Working Days</td>
        <td><strong><?= $attSummary['total_working_days'] ?? '—' ?></strong></td>
        <td class="label">Present</td>
        <td style="color:#1a7a40"><strong><?= $attSummary['present_days'] ?? '—' ?></strong></td>
        <td class="label">Absent</td>
        <td style="color:#b92020"><strong><?= number_format((float)($attSummary['absent_days'] ?? 0), 1) ?></strong></td>
    </tr>
    <tr>
        <td class="label">Half Days</td>
        <td><?= $attSummary['half_days'] ?? 0 ?></td>
        <td class="label">On Leave</td>
        <td><?= $attSummary['leave_days'] ?? 0 ?></td>
        <td class="label">Late Days</td>
        <td><?= $attSummary['late_days'] ?? 0 ?></td>
    </tr>
    <?php if (($attSummary['ot_hours'] ?? 0) > 0): ?>
    <tr>
        <td class="label">OT Hours</td>
        <td><?= number_format((float)$attSummary['ot_hours'], 2) ?> hrs</td>
        <td class="label">OT Amount</td>
        <td style="color:#1a7a40"><?= money($attSummary['ot_amount'] ?? 0) ?></td>
        <td></td><td></td>
    </tr>
    <?php endif; ?>
</table>
<?php endif; ?>

<!-- Employer contributions -->
<table class="info-table">
    <tr>
        <td class="label" style="width:40%">Employer PF Contribution</td>
        <td style="width:20%"><?= money($s['pf_employer']) ?></td>
        <td class="label" style="width:20%">Employer ESI</td>
        <td><?= money($s['esi_employer']) ?></td>
    </tr>
</table>

<br>
<div style="font-size:8pt;color:#999;text-align:center">
    This is a computer-generated salary slip. No signature is required.
    For queries contact <?= htmlspecialchars(COMPANY_EMAIL) ?>.
</div>
<?php
$html = ob_get_clean();
$pdf->writeHTML($html, true, false, true, false, '');

$filename = 'salary-slip-' . $s['emp_code'] . '-' . str_replace('-', '', $s['payroll_month']) . '.pdf';
$pdf->Output($filename, 'D');
exit;
