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
            e.pan_number, e.uan_number, e.esic_number, e.bank_account, e.bank_name, e.bank_ifsc,
            d.name AS dept_name, des.name AS desig_name,
            ent.name AS entity_name, ent.name_font AS entity_name_font, ent.address AS entity_address,
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

// A self-scoped user can only download their OWN slip (role self_scope flag, not
// a hard-coded role name — closes the IDOR for any non-"Employee" scoped role).
if (is_self_scoped() && (int)$s['employee_id'] !== current_employee_id()) {
    http_response_code(403);
    die('Access denied.');
}

// ─── Build the payslip ────────────────────────────────────────────────────────
// Layout lives in includes/payslip_render.php so this download, the employee
// QR-portal view and the portal PDF are all the same document.
$monthLabel = date("F Y", strtotime($s["payroll_month"] . "-01"));
$html       = payslip_html($s);



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
