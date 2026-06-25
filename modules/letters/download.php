<?php
/**
 * Download an issued letter as a PDF document.
 *
 * The same HTML/CSS letter template (unchanged) is rendered to PDF using mPDF
 * (best CSS fidelity), with TCPDF and a printable-HTML last resort as
 * fallbacks — mirroring modules/payroll/slip_pdf.php. This keeps the exact
 * formatting, alignment, fonts and content while producing a true PDF instead
 * of a Word (.doc) file.
 *
 * Only Issued / Acknowledged letters may be downloaded (Drafts cannot).
 * All processing happens before any output so headers send cleanly.
 */
require_once '../../includes/bootstrap.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/letters/index.php');

$letter = db()->query("SELECT l.*, e.name AS emp_name, e.employee_id AS emp_code,
    e.join_date, e.gender, e.fixed_salary, e.variable_salary,
    d.name AS dept_name, des.name AS designation,
    u.name AS issued_by_name,
    ent.name AS entity_name, ent.address AS entity_address, ent.city AS entity_city,
    ent.state AS entity_state, ent.pincode AS entity_pincode,
    ent.email AS entity_email, ent.phone AS entity_phone, ent.logo AS entity_logo,
    ent.signature AS entity_signature, ent.signatory_title AS entity_signatory_title
    FROM letters l
    JOIN employees e ON l.employee_id = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN designations des ON e.designation_id = des.id
    LEFT JOIN entities ent ON ent.id = e.entity_id
    LEFT JOIN users u ON l.issued_by = u.id
    WHERE l.id=$id")->fetch(PDO::FETCH_ASSOC);

if (!$letter) redirect(BASE_URL . '/modules/letters/index.php');

// Sub-menu permission: the user must have access to this letter's type.
require_permission('letters', strtolower($letter['type']));

// Company identity for the letterhead: prefer the employee's linked entity.
$co_name  = $letter['entity_name'] ?: COMPANY_NAME;
$co_addr  = $letter['entity_name']
    ? trim(implode(', ', array_filter([
        $letter['entity_address'],
        trim(implode(' ', array_filter([$letter['entity_city'], $letter['entity_state'], $letter['entity_pincode']]))),
      ])))
    : COMPANY_ADDRESS;
$co_email = $letter['entity_name'] ? ($letter['entity_email'] ?: '') : COMPANY_EMAIL;
$co_phone = $letter['entity_name'] ? ($letter['entity_phone'] ?: '') : COMPANY_PHONE;
// Embed the logo as a base64 data URI so it renders inside the PDF (a remote
// URL is unreliable for mPDF/TCPDF). The MIME is sniffed from the actual bytes
// because uploaded files are often mis-named (e.g. a JPEG saved as ".png").
$co_logo = null;
if (!empty($letter['entity_logo'])) {
    $logoPath = BASE_PATH . '/storage/entities/' . $letter['entity_logo'];
    if (is_file($logoPath)) {
        $info = @getimagesize($logoPath);
        $mime = $info['mime'] ?? null;
        if (!$mime) {
            $ext  = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
            $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'svg' => 'image/svg+xml'][$ext] ?? 'image/png';
        }
        $co_logo = 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($logoPath));
    }
}
// Signature image — embedded as a base64 data URI (same reason as the logo).
$co_signature = null;
if (!empty($letter['entity_signature'])) {
    $sigPath = BASE_PATH . '/storage/entities/' . $letter['entity_signature'];
    if (is_file($sigPath)) {
        $sinfo = @getimagesize($sigPath);
        $smime = $sinfo['mime'] ?? null;
        if (!$smime) {
            $sext  = strtolower(pathinfo($sigPath, PATHINFO_EXTENSION));
            $smime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'svg' => 'image/svg+xml'][$sext] ?? 'image/png';
        }
        $co_signature = 'data:' . $smime . ';base64,' . base64_encode((string)file_get_contents($sigPath));
    }
}

// Only letters that have been issued can be downloaded.
if (!in_array($letter['status'], ['Issued', 'Acknowledged'], true)) {
    flash('error', 'Only issued letters can be downloaded.');
    redirect(BASE_URL . '/modules/letters/view.php?id=' . $id);
}

// Meaningful PDF filename, e.g. Offer_Letter_Bhavatharani.pdf
$safeName = preg_replace('/[^A-Za-z0-9._-]+/', '_', trim((string)($letter['emp_name'] ?: $letter['emp_code'])));
$safeName = trim($safeName, '_') ?: (string)$letter['emp_code'];
$filename = $letter['type'] . '_Letter_' . $safeName . '.pdf';

// ── Derive structured fields from the generated content. The labels below are
//    produced verbatim by modules/letters/create.php, so parsing is reliable;
//    this only READS the content — it does not change how letters are made.
$content   = (string)$letter['content'];
$rawnum    = fn($v) => (float)preg_replace('/[^0-9.]/', '', (string)$v);
$money     = fn($v) => number_format($rawnum($v), 2);
$pick      = function (string $label) use ($content): string {
    return preg_match('/' . preg_quote($label, '/') . '\s*:?\s*(.+)/i', $content, $m) ? trim($m[1]) : '';
};
$co_line   = trim($co_name . ($co_addr ? ' &bull; ' . $co_addr : '')) . ' &bull; Confidential';

// Effective date (used by Increment); falls back to the letter's issued date.
$effRaw = preg_match('/with effect from\s+(.+?)[\.\n]/i', $content, $m) ? trim($m[1]) : '';
$effTs  = $effRaw ? strtotime($effRaw) : strtotime((string)$letter['issued_date']);
$effDate = $effTs ? date('d F Y', $effTs) : date_fmt($letter['issued_date']);

// Signatory — the name on the line after "Yours sincerely," (else HR Department).
$signName = 'HR Department';
if (preg_match('/Yours sincerely,\s*\n+\s*(.+)/i', $content, $m)) {
    $cand = trim($m[1]);
    if ($cand !== '' && strcasecmp($cand, $co_name) !== 0) $signName = $cand;
}

// Shared letterhead block (logo or coloured company name, address, contact).
ob_start(); ?>
    <div class="header">
        <div class="header-left">
            <?php if ($co_logo): ?>
                <img src="<?= h($co_logo) ?>" class="logo-img" alt="<?= h($co_name) ?>">
                <div class="company-sub"><?= h($co_addr) ?></div>
            <?php else: ?>
                <div class="company-name"><?= h($co_name) ?></div>
                <div class="company-sub"><?= h($co_addr) ?></div>
            <?php endif; ?>
            <?php $contact = trim($co_email . ($co_email && $co_phone ? ' | ' : '') . $co_phone);
                  if ($contact): ?><div class="company-sub"><?= h($contact) ?></div><?php endif; ?>
        </div>
        <div class="header-right">
            <div class="ref">Date: <?= h(date_fmt($letter['issued_date'])) ?></div>
            <div class="ref">Ref: <?= h($letter['reference']) ?></div>
        </div>
    </div>
<?php $headerHtml = ob_get_clean();

// ── Build the PDF body. Offer letters are reconstructed as the reference 2-page
//    design (numbered terms + a salary breakup computed at render time from the
//    salary components) so the breakup is always correct — never ₹0. Other types
//    use the shared chrome below.
$pdfFooterHtml = null;   // when set, rendered as a repeating page footer (mPDF)
if ($letter['type'] === 'Offer') {
    // Reconstructed offer letter (2-page reference design + computed salary
    // breakup) — shared with the on-screen view via includes/offer_letter.php.
    require_once __DIR__ . '/../../includes/offer_letter.php';
    $offerData = offer_letter_data(db(), $letter);
    $offerBody = offer_letter_html($letter, ['name' => $co_name, 'addr' => $co_addr, 'logo' => $co_logo, 'signature' => $co_signature, 'signatory_title' => $letter['entity_signatory_title'] ?? ''], $offerData);
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $offerBody . '</body></html>';
    // Entity address as a repeating page footer on every page (mPDF).
    $pdfFooterHtml = '<div style="border-top:1px solid #aaa;padding-top:4px;text-align:center;font-size:8pt;color:#555">'
                   . h(trim($co_name . ($co_addr ? ' ' . $co_addr : ''))) . '</div>';
} elseif ($letter['type'] === 'Increment') {
    // Reconstructed increment letter (reference info-box design) — shared with
    // the on-screen view via includes/increment_letter.php.
    require_once __DIR__ . '/../../includes/increment_letter.php';
    $incData = increment_letter_data($letter);
    $incBody = increment_letter_html($letter, ['name' => $co_name, 'addr' => $co_addr, 'email' => $co_email, 'phone' => $co_phone, 'logo' => $co_logo], $incData);
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $incBody . '</body></html>';
} elseif ($letter['type'] === 'Confirmation') {
    // Reconstructed confirmation letter (reference design) — shared with the
    // on-screen view via includes/confirmation_letter.php.
    require_once __DIR__ . '/../../includes/confirmation_letter.php';
    $confData = confirmation_letter_data($letter);
    $confBody = confirmation_letter_html($letter, ['name' => $co_name, 'addr' => $co_addr, 'logo' => $co_logo], $confData);
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $confBody . '</body></html>';
    // Entity address as a repeating page footer on every page (mPDF).
    $pdfFooterHtml = '<div style="border-top:1px solid #aaa;padding-top:4px;text-align:center;font-size:8pt;color:#555">'
                   . h(trim($co_name . ($co_addr ? ' ' . $co_addr : ''))) . '</div>';
} else {
ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10pt; color: #222; }

    .header { display: table; width: 100%; border-bottom: 2px solid #3b82f6; padding-bottom: 10px; margin-bottom: 12px; }
    .header-left  { display: table-cell; vertical-align: middle; }
    .header-right { display: table-cell; vertical-align: middle; text-align: right; white-space: nowrap; padding-left: 10px; }
    .logo-img     { max-height: 50px; max-width: 150px; display: block; margin-bottom: 4px; }
    .company-name { font-size: 16pt; font-weight: bold; color: #3b82f6; }
    .company-sub  { font-size: 8pt; color: #666; margin-top: 2px; }
    .ref          { font-size: 9pt; color: #666; }

    h1 { text-align: center; font-size: 13pt; text-transform: uppercase; letter-spacing: 2px;
         color: #1e293b; margin: 10px 0; border-top: 1px solid #ddd; border-bottom: 1px solid #ddd; padding: 5px 0; }

    p { font-size: 9.5pt; line-height: 1.55; color: #444; margin-bottom: 8px; }

    .info-box { background: #f8f9fc; border: 1px solid #e2e8f0; border-radius: 4px; padding: 10px 14px; margin: 12px 0; }
    .info-row { display: table; width: 100%; margin-bottom: 5px; }
    .info-label { display: table-cell; width: 170px; color: #64748b; font-size: 8.5pt; font-weight: bold; vertical-align: middle; }
    .info-value { display: table-cell; font-weight: bold; font-size: 9.5pt; vertical-align: middle; }
    .old-sal { color: #999; text-decoration: line-through; }
    .new-sal { color: #16a34a; font-size: 11pt; }
    .pct-badge { background: #16a34a; color: #fff; padding: 1px 8px; border-radius: 3px; font-size: 9pt; }

    .signature-row { display: table; width: 100%; margin-top: 28px; }
    .sig-left  { display: table-cell; width: 50%; }
    .sig-right { display: table-cell; width: 50%; text-align: right; }
    .sig-box   { display: inline-block; width: 200px; border-top: 1px solid #333; padding-top: 5px; font-size: 8.5pt; }
    .sig-sub   { color: #888; font-size: 8pt; }

    .bodytext { font-size: 9.5pt; line-height: 1.6; color: #333; margin-bottom: 10px; }

    .footer { margin-top: 18px; border-top: 1px solid #e2e8f0; padding-top: 7px; font-size: 7.5pt; color: #999; text-align: center; }
</style>
</head>
<body>
<?= $headerHtml ?>

<?php
    // Confirmation / Promotion — render the existing letter wording beneath the
    // shared letterhead. Strip any duplicate company/date/ref header lines.
    $lines = preg_split('/\r\n|\r|\n/', $content);
    $started = false; $bodyLines = [];
    foreach ($lines as $ln) {
        if (!$started) {
            if (stripos(ltrim($ln), 'Dear ') === 0) $started = true;
            else continue;
        }
        $bodyLines[] = $ln;
    }
    $bodyText = $started ? implode("\n", $bodyLines) : $content;
?>
    <h1><?= h($letter['type']) ?> Letter</h1>
    <?php // Blank line → new paragraph; single newline → line break.
    foreach (preg_split('/\n[ \t]*\n/', trim($bodyText)) as $para):
        if (trim($para) === '') continue; ?>
        <div class="bodytext"><?= nl2br(h($para)) ?></div>
    <?php endforeach; ?>

    <div class="footer"><?= $co_line ?></div>
</body>
</html>
<?php
$html = ob_get_clean();
}

// ─── Render: mPDF (best CSS fidelity) → TCPDF → printable HTML ────────────────
$xamppRoot = dirname(__DIR__, 4);   // e.g. C:/xampp8.2
$docTitle  = $letter['type'] . ' Letter - ' . $letter['emp_name'];

// 1) mPDF — preserves the letter's CSS layout, tables and fonts.
$mpdfAutoloads = [
    $xamppRoot . '/htdocs/xibo/vendor/autoload.php',
    'C:/xampp8.2/htdocs/xibo/vendor/autoload.php',
    BASE_PATH . '/vendor/autoload.php',
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
            'margin_left' => 26, 'margin_right' => 26, 'margin_top' => 16,
            'margin_bottom' => !empty($pdfFooterHtml) ? 14 : 18,
            'margin_footer' => 6,
            'tempDir' => is_dir($tmp) ? $tmp : sys_get_temp_dir(),
        ]);
        $mpdf->SetTitle($docTitle);
        if (!empty($pdfFooterHtml)) $mpdf->SetHTMLFooter($pdfFooterHtml);
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
    $pdf->SetTitle($docTitle);
    $pdf->SetMargins(28, 24, 28);
    $pdf->SetAutoPageBreak(true, 24);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();
    $pdf->SetFont('dejavusans', '', 10);
    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->Output($filename, 'D');
    exit;
}

// 3) Last resort — printable HTML (user can "Save as PDF" from the print dialog).
header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . h($docTitle) . '</title></head><body onload="window.print()">';
echo $html;
echo '</body></html>';
exit;
