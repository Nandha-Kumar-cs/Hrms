<?php
/**
 * No-Due Clearance Certificate PDF Download.
 *
 * Renders with mPDF (best CSS fidelity); falls back to TCPDF, then to a
 * printable HTML page if no PDF engine is available. Mirrors slip_pdf.php.
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('assets');

$db     = db();
$emp_id = (int)($_GET['emp_id'] ?? 0);
if (!$emp_id) {
    flash('error', 'Invalid employee.');
    redirect(BASE_URL . '/modules/assets/clearance.php');
}

$empStmt = $db->prepare(
    'SELECT e.*, d.name AS dept, des.name AS desig,
            ent.name AS entity_name, ent.address AS entity_address,
            ent.city AS entity_city, ent.state AS entity_state, ent.pincode AS entity_pincode
       FROM employees e
       LEFT JOIN departments d   ON d.id = e.department_id
       LEFT JOIN designations des ON des.id = e.designation_id
       LEFT JOIN entities ent    ON ent.id = e.entity_id
      WHERE e.id = ?'
);
$empStmt->execute([$emp_id]);
$ed = $empStmt->fetch();
if (!$ed) {
    flash('error', 'Employee not found.');
    redirect(BASE_URL . '/modules/assets/clearance.php');
}

// Company header from the employee's entity (fallback to a configured entity, then constants).
$entRow = $ed;
if (empty($entRow['entity_name'])) {
    $entRow = $db->query("SELECT name AS entity_name, address AS entity_address, city AS entity_city, state AS entity_state, pincode AS entity_pincode FROM entities ORDER BY id LIMIT 1")->fetch() ?: [];
}
$companyName = $entRow['entity_name'] ?? COMPANY_NAME;
if (!empty($entRow['entity_name'])) {
    $cityLine       = trim(implode(' ', array_filter([$entRow['entity_city'] ?? '', $entRow['entity_state'] ?? '', $entRow['entity_pincode'] ?? ''])));
    $companyAddress = trim(implode(', ', array_filter([$entRow['entity_address'] ?? '', $cityLine])));
} else {
    $companyAddress = COMPANY_ADDRESS;
}

$asgnStmt = $db->prepare(
    'SELECT aa.*, a.name AS asset_name, a.asset_code, a.serial_no, c.name AS cat_name
       FROM asset_assignments aa
       JOIN assets a ON a.id = aa.asset_id
       JOIN asset_categories c ON c.id = a.category_id
      WHERE aa.employee_id = ?
      ORDER BY aa.is_returned ASC, aa.assigned_date'
);
$asgnStmt->execute([$emp_id]);
$assignments = $asgnStmt->fetchAll();
$pending  = array_filter($assignments, fn($a) => !$a['is_returned']);
$returned = array_filter($assignments, fn($a) => $a['is_returned']);

// Clearance can only be issued when nothing is pending.
if ($pending) {
    flash('error', 'Cannot generate certificate — ' . count($pending) . ' asset(s) still pending return.');
    redirect(BASE_URL . '/modules/assets/clearance.php?emp_id=' . $emp_id);
}

$h  = fn ($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$df = fn ($d) => $d ? date('d M Y', strtotime($d)) : '—';

// ─── Build HTML ───────────────────────────────────────────────────────────────
ob_start();
?>
<style>
    body { font-family: dejavusans, sans-serif; font-size: 10pt; color: #1e293b; }
    .company-name { font-size: 17pt; font-weight: bold; color: #166534; }
    .company-sub  { font-size: 8.5pt; color: #64748b; }
    .cert-title   { font-size: 13pt; font-weight: bold; letter-spacing: 1px; }
    table.info { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    table.info td { border: 1px solid #cbd5e1; padding: 7px 10px; font-size: 9.5pt; width: 50%; }
    .lbl { color: #64748b; }
    .section-title { font-size: 9pt; font-weight: bold; text-transform: uppercase; letter-spacing: .08em; color: #475569; margin: 0 0 6px; }
    table.assets { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    table.assets th { background: #166534; color: #ffffff; font-size: 8.5pt; padding: 6px 8px; text-align: left; }
    table.assets td { border: 1px solid #cbd5e1; font-size: 9pt; padding: 6px 8px; vertical-align: top; }
    table.cert { width: 100%; border-collapse: collapse; margin-top: 16px; }
    table.cert td.box { border: 2px solid #166534; background: #f0fdf4; padding: 14px 16px; }
    .cert-head { font-weight: bold; color: #166534; font-size: 11pt; }
    .sign td { border-top: 1px solid #166534; padding-top: 8px; font-size: 8pt; color: #64748b; }
    .footer { font-size: 7.5pt; color: #94a3b8; text-align: center; margin-top: 16px; border-top: 1px solid #e2e8f0; padding-top: 6px; }
</style>

<!-- Header (centred) -->
<div style="text-align:center"><span class="company-name"><?= $h($companyName) ?></span></div>
<div style="text-align:center"><span class="company-sub"><?= $h($companyAddress) ?></span></div>
<div style="text-align:center; margin-top:10px"><span class="cert-title">NO-DUE CLEARANCE CERTIFICATE</span></div>
<div style="border-bottom:3px solid #166534; margin:8px 0 14px; font-size:1pt">&nbsp;</div>

<!-- Employee info -->
<table class="info">
    <tr><td><span class="lbl">Name: </span><strong><?= $h($ed['name']) ?></strong></td>
        <td><span class="lbl">Employee ID: </span><strong><?= $h($ed['employee_id']) ?></strong></td></tr>
    <tr><td><span class="lbl">Department: </span><strong><?= $h($ed['dept'] ?? '—') ?: '—' ?></strong></td>
        <td><span class="lbl">Designation: </span><strong><?= $h($ed['desig'] ?? '—') ?: '—' ?></strong></td></tr>
    <tr><td><span class="lbl">Join Date: </span><strong><?= $h($df($ed['join_date'])) ?></strong></td>
        <td><span class="lbl">Status: </span><strong><?= $h($ed['status']) ?></strong></td></tr>
</table>

<!-- Returned assets table -->
<div class="section-title">Asset Details (Returned)</div>
<?php if ($returned): ?>
<table class="assets">
    <tr>
        <th style="width:40%">Asset</th>
        <th style="width:18%">Code</th>
        <th style="width:22%">Returned On</th>
        <th style="width:20%">Condition</th>
    </tr>
    <?php foreach ($returned as $a): ?>
    <tr>
        <td>&#10003; <?= $h($a['asset_name']) ?></td>
        <td><?= $h($a['asset_code']) ?></td>
        <td><?= $h($df($a['returned_date'])) ?></td>
        <td><?= $h($a['condition_at_return'] ?? '—') ?: '—' ?></td>
    </tr>
    <?php endforeach; ?>
</table>
<?php else: ?>
<div style="font-size:9pt; color:#64748b; margin-bottom:14px">No assets were assigned to this employee.</div>
<?php endif; ?>

<!-- Certificate box -->
<table class="cert">
    <tr><td class="box">
        <div class="cert-head">&#10003; NO-DUE CERTIFICATE</div>
        <div style="font-size:9.5pt; margin-top:6px">This is to certify that <strong><?= $h($ed['name']) ?></strong> (<?= $h($ed['employee_id']) ?>) has returned all company assets and there are no dues pending as of <?= $h(date('d M Y')) ?>.</div>
        <table style="width:100%; margin-top:32px" class="sign">
            <tr>
                <td style="width:46%">Employee Signature &amp; Date</td>
                <td style="width:8%; border-top:0">&nbsp;</td>
                <td style="width:46%">HR Manager Signature &amp; Date</td>
            </tr>
        </table>
    </td></tr>
</table>

<div class="footer">
    Computer-generated clearance certificate &bull; <?= $h($companyName) ?> &bull; <?= date('d M Y H:i') ?>
</div>
<?php
$html = ob_get_clean();

$filename = 'no-due-clearance-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$ed['employee_id']) . '.pdf';

// ─── Render: mPDF → TCPDF → printable HTML ────────────────────────────────────
$xamppRoot = dirname(__DIR__, 4);   // e.g. C:/xampp8.2

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
            'margin_left' => 12, 'margin_right' => 12, 'margin_top' => 14, 'margin_bottom' => 12,
            'tempDir' => is_dir($tmp) ? $tmp : sys_get_temp_dir(),
        ]);
        $mpdf->SetTitle('No-Due Clearance - ' . $ed['name']);
        $mpdf->WriteHTML($html);
        $mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);
        exit;
    } catch (Throwable $e) {
        // fall through to TCPDF
    }
}

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
    $pdf->SetTitle('No-Due Clearance - ' . $ed['name']);
    $pdf->SetMargins(12, 14, 12);
    $pdf->SetAutoPageBreak(true, 12);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();
    $pdf->SetFont('dejavusans', '', 10);
    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output($filename, 'D');
    exit;
}

// Last resort — printable HTML.
header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>No-Due Clearance</title></head><body onload="window.print()">';
echo $html;
echo '</body></html>';
exit;
