<?php
/**
 * Download an issued letter as a Word-compatible (.doc) document.
 *
 * Dependency-free: emits an HTML document with an application/msword MIME type
 * and an attachment Content-Disposition so the browser saves it. Word / Google
 * Docs / LibreOffice open it formatted and ready to print.
 *
 * Only Issued / Acknowledged letters may be downloaded (Drafts cannot).
 * All processing happens before any output so headers send cleanly.
 */
require_once '../../includes/bootstrap.php';
require_login();
require_permission('letters', 'view');

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/letters/index.php');

$letter = db()->query("SELECT l.*, e.name AS emp_name, e.employee_id AS emp_code,
    e.join_date, d.name AS dept_name, des.name AS designation,
    u.name AS issued_by_name,
    ent.name AS entity_name, ent.address AS entity_address, ent.city AS entity_city,
    ent.state AS entity_state, ent.pincode AS entity_pincode,
    ent.email AS entity_email, ent.phone AS entity_phone, ent.logo AS entity_logo
    FROM letters l
    JOIN employees e ON l.employee_id = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN designations des ON e.designation_id = des.id
    LEFT JOIN entities ent ON ent.id = e.entity_id
    LEFT JOIN users u ON l.issued_by = u.id
    WHERE l.id=$id")->fetch(PDO::FETCH_ASSOC);

if (!$letter) redirect(BASE_URL . '/modules/letters/index.php');

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
$co_logo  = (!empty($letter['entity_logo']) && file_exists(BASE_PATH . '/storage/entities/' . $letter['entity_logo']))
    ? BASE_URL . '/storage/entities/' . $letter['entity_logo']
    : null;

// Only letters that have been issued can be downloaded.
if (!in_array($letter['status'], ['Issued', 'Acknowledged'], true)) {
    flash('error', 'Only issued letters can be downloaded.');
    redirect(BASE_URL . '/modules/letters/view.php?id=' . $id);
}

// Build a filesystem-safe filename: e.g. Offer_Letter_EMP001_2026-06-16.doc
$safeRef  = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)($letter['reference'] ?: $letter['emp_code']));
$filename = $letter['type'] . '_Letter_' . $safeRef . '.doc';

header('Content-Type: application/msword; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');
?>
<!DOCTYPE html>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="utf-8">
<title><?= h($letter['type']) ?> Letter</title>
<!--[if gte mso 9]><xml><w:WordDocument><w:View>Print</w:View><w:Zoom>100</w:Zoom></w:WordDocument></xml><![endif]-->
<style>
    @page { size: A4; margin: 2.5cm 3cm; }
    body { font-family: 'Calibri', 'Arial', sans-serif; font-size: 11pt; color: #1a1a1a; line-height: 1.6; }
    .company { text-align: left; margin-bottom: 18pt; }
    .company h2 { color: #1e3a8a; margin: 0 0 4pt; font-size: 18pt; }
    .company p { margin: 1pt 0; color: #555; font-size: 9.5pt; }
    .meta { margin-bottom: 14pt; font-size: 10pt; }
    .meta div { margin: 2pt 0; }
    hr { border: none; border-top: 1px solid #cccccc; margin: 12pt 0; }
    .to p { margin: 2pt 0; }
    .subject { font-weight: bold; margin: 16pt 0; text-decoration: underline; }
    .bodytext { margin: 14pt 0; white-space: pre-wrap; }
    .signature { margin-top: 36pt; }
    .signature p { margin: 2pt 0; }
    .ack { margin-top: 42pt; padding-top: 10pt; border-top: 1px dashed #cccccc; font-size: 9.5pt; color: #555; }
</style>
</head>
<body>
    <div class="company">
        <?php if ($co_logo): ?><img src="<?= h($co_logo) ?>" alt="<?= h($co_name) ?>" style="max-height:70px;max-width:220px;margin-bottom:8pt;"><br><?php endif; ?>
        <h2><?= h($co_name) ?></h2>
        <p><?= h($co_addr) ?></p>
        <?php if ($co_email || $co_phone): ?><p><?= h(trim($co_email . ($co_email && $co_phone ? ' | ' : '') . $co_phone)) ?></p><?php endif; ?>
    </div>

    <div class="meta">
        <div><strong>Ref No:</strong> <?= h($letter['reference']) ?></div>
        <div><strong>Date:</strong> <?= date_fmt($letter['issued_date']) ?></div>
    </div>

    <hr>

    <div class="to">
        <p>To,</p>
        <p><strong><?= h($letter['emp_name']) ?></strong></p>
        <p><?= h($letter['designation']) ?></p>
        <p><?= h($letter['dept_name']) ?></p>
        <p>Employee ID: <?= h($letter['emp_code']) ?></p>
    </div>

    <div class="subject">Sub: <?= h($letter['type']) ?> Letter</div>

    <div class="bodytext"><?= h((string)$letter['content']) ?></div>

    <div class="signature">
        <p>Yours sincerely,</p>
        <br><br>
        <p><strong>_______________________</strong></p>
        <p><strong><?= h($co_name) ?></strong></p>
        <p>Authorized Signatory</p>
        <p style="font-size:9pt;color:#777;margin-top:10pt">Issued on: <?= date_fmt($letter['issued_date']) ?><?= $letter['issued_by_name'] ? ' by ' . h($letter['issued_by_name']) : '' ?></p>
    </div>

    <div class="ack">
        <p><strong>Acknowledgement:</strong></p>
        <p>I, <?= h($letter['emp_name']) ?>, hereby acknowledge receipt of this letter.</p>
        <br>
        <p>Signature: _______________________ &nbsp;&nbsp; Date: _____________</p>
    </div>
</body>
</html>
