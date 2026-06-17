<?php
/**
 * Working-Day Circular — printable A4 page (ported from the reference
 * holidays/circular.blade.php). HRMS ships no PDF library, so this renders a
 * clean print-styled HTML page; "Print / Save as PDF" uses the browser.
 *
 * Only holidays flagged is_working_day may produce a circular.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('holidays', 'view');

$id = (int)($_GET['id'] ?? 0);
$h  = db()->prepare(
    'SELECT h.*, e.name AS entity_name, e.address AS entity_address, e.city AS entity_city,
            e.state AS entity_state, e.pincode AS entity_pincode, e.phone AS entity_phone,
            e.email AS entity_email, e.website AS entity_website, e.logo AS entity_logo
     FROM holidays h LEFT JOIN entities e ON e.id = h.entity_id WHERE h.id = ?'
);
$h->execute([$id]);
$holiday = $h->fetch();

if (!$holiday || !(int)$holiday['is_working_day']) {
    http_response_code(404);
    exit('This holiday is not marked as a working day.');
}

// Fall back to the first entity if none was linked.
if (!$holiday['entity_name']) {
    $ent = db()->query('SELECT * FROM entities ORDER BY id LIMIT 1')->fetch();
    if ($ent) {
        $holiday['entity_name']    = $ent['name'];
        $holiday['entity_address'] = $ent['address'];
        $holiday['entity_city']    = $ent['city'];
        $holiday['entity_state']   = $ent['state'];
        $holiday['entity_pincode'] = $ent['pincode'];
        $holiday['entity_phone']   = $ent['phone'];
        $holiday['entity_email']   = $ent['email'];
        $holiday['entity_website'] = $ent['website'];
        $holiday['entity_logo']    = $ent['logo'];
    }
}

$entityName = $holiday['entity_name'] ?: 'Company Name';
$dateObj    = new DateTime($holiday['h_date']);

// Reference no. e.g. "MDPL/CIR/0007/2026"
$words    = array_filter(preg_split('/\s+/', trim($entityName)));
$initials = implode('', array_map(fn($w) => strtoupper(substr($w, 0, 1)), $words));
$circularRef = ($initials ?: 'CIR') . '/CIR/' . str_pad((string)$id, 4, '0', STR_PAD_LEFT) . '/' . $dateObj->format('Y');

$employees = db()->query(
    "SELECT e.name, e.employee_id AS emp_code, COALESCE(d.name,'—') AS dept
     FROM employees e LEFT JOIN departments d ON d.id = e.department_id
     WHERE e.status = 'Active' ORDER BY e.employee_id"
)->fetchAll();

// Logo: embed if the stored path resolves to a real file.
$logoSrc = null;
if ($holiday['entity_logo']) {
    foreach ([__DIR__ . '/../../' . ltrim($holiday['entity_logo'], '/'),
              __DIR__ . '/../../uploads/' . ltrim($holiday['entity_logo'], '/')] as $p) {
        if (is_file($p)) { $logoSrc = BASE_URL . '/' . ltrim(str_replace(__DIR__ . '/../../', '', $p), '/'); break; }
    }
}

$addr = implode(', ', array_filter([
    $holiday['entity_address'], $holiday['entity_city'], $holiday['entity_state'], $holiday['entity_pincode'],
]));
$contacts = implode('  |  ', array_filter([
    $holiday['entity_phone']   ? 'Ph: ' . $holiday['entity_phone'] : null,
    $holiday['entity_email']   ? 'Email: ' . $holiday['entity_email'] : null,
    $holiday['entity_website'] ?: null,
]));
$isPvt = (stripos($entityName, 'private') !== false || stripos($entityName, 'pvt') !== false);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Circular — <?= h($holiday['name']) ?></title>
<style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body { font-family:'Times New Roman', Times, serif; font-size:13pt; color:#000; background:#fff; padding:40px 50px 80px; line-height:1.6; }
    .toolbar { position:fixed; top:10px; right:14px; }
    .toolbar button, .toolbar a { font-family:Arial, sans-serif; font-size:11pt; padding:7px 14px; border:1px solid #555; background:#f3f4f6; border-radius:6px; cursor:pointer; text-decoration:none; color:#111; }
    .header { display:table; width:100%; border-bottom:2px solid #000; padding-bottom:14px; margin-bottom:18px; }
    .header-logo { display:table-cell; width:110px; vertical-align:middle; }
    .header-logo img { max-width:100px; max-height:70px; }
    .header-company { display:table-cell; vertical-align:middle; text-align:center; padding:0 10px; }
    .company-name { font-size:20pt; font-weight:bold; letter-spacing:1px; text-transform:uppercase; }
    .company-sub { font-size:12pt; font-style:italic; font-weight:bold; }
    .meta { text-align:right; font-size:11pt; margin-bottom:6px; }
    .meta .ref { font-weight:bold; text-decoration:underline; }
    .circular-title { text-align:center; font-weight:bold; text-decoration:underline; font-size:14pt; letter-spacing:2px; margin:16px 0 20px; }
    .body-text p { margin-bottom:14px; text-align:justify; }
    .sign-block { margin-top:40px; text-align:center; }
    .sign-block .by-order { font-size:12pt; letter-spacing:2px; margin-bottom:30px; }
    .sign-block .for-company { font-weight:bold; font-size:12pt; text-transform:uppercase; margin-bottom:50px; }
    .sign-block .authorised { font-size:11pt; }
    .ack-section { margin-top:36px; }
    .ack-title { font-size:12pt; font-weight:bold; text-decoration:underline; text-transform:uppercase; letter-spacing:1px; margin-bottom:10px; text-align:center; }
    .ack-note { font-size:10pt; font-style:italic; text-align:center; margin-bottom:12px; color:#333; }
    .ack-table { width:100%; border-collapse:collapse; font-size:10pt; }
    .ack-table thead tr { background:#f0f0f0; }
    .ack-table th { border:1px solid #555; padding:5px 7px; text-align:center; font-weight:bold; white-space:nowrap; }
    .ack-table td { border:1px solid #aaa; padding:4px 7px; vertical-align:middle; }
    .ack-table td.sno { text-align:center; width:38px; color:#444; }
    .ack-table td.emp-code { text-align:center; color:#444; }
    .ack-table td.sign-cell { height:28px; }
    .footer { margin-top:40px; border-top:1.5px solid #000; padding-top:8px; font-size:8.5pt; text-align:center; color:#222; line-height:1.5; }
    @media print { .toolbar { display:none; } body { padding:20px 30px; } }
</style>
</head>
<body>
<div class="toolbar">
    <button onclick="window.print()">🖨 Print / Save as PDF</button>
    <a href="<?= BASE_URL ?>/modules/holidays/index.php?year=<?= $dateObj->format('Y') ?>">Close</a>
</div>

<div class="header">
    <div class="header-logo"><?php if ($logoSrc): ?><img src="<?= h($logoSrc) ?>" alt="Logo"><?php endif; ?></div>
    <div class="header-company">
        <div class="company-name"><?= h($entityName) ?></div>
        <?php if (!$isPvt): ?><div class="company-sub">Private Limited</div><?php endif; ?>
    </div>
</div>

<div class="meta"><span class="ref"><?= h($circularRef) ?></span></div>
<div class="meta"><?= $dateObj->format('jS F Y') ?></div>

<div class="circular-title">C I R C U L A R</div>

<div class="body-text">
    <p>This is to inform all employees that
        <strong><?= $dateObj->format('l') ?>, <?= $dateObj->format('jS F Y') ?></strong>,
        will be a <strong>working day</strong>
        <?php if ($holiday['working_day_reason']): ?>due to <?= h($holiday['working_day_reason']) ?>.
        <?php else: ?>as per management decision.<?php endif; ?>
    </p>
    <p>Kindly note that this working day will be <strong>compensated with a holiday on another day</strong>.</p>
    <p>All employees are requested to take note of the above and attend duty accordingly.</p>
</div>

<div class="sign-block">
    <div class="by-order">- &nbsp; BY ORDER &nbsp; -</div>
    <div class="for-company">For <?= h(strtoupper($entityName)) ?></div>
    <div class="authorised">Authorised Signatory</div>
</div>

<?php if ($employees): ?>
<div class="ack-section">
    <div class="ack-title">Employee Acknowledgement</div>
    <div class="ack-note">I hereby acknowledge receipt of this circular and confirm my attendance on the above-mentioned working day.</div>
    <table class="ack-table">
        <thead><tr>
            <th style="width:38px">Sr.</th>
            <th style="width:34%">Employee Name</th>
            <th style="width:14%">Emp. Code</th>
            <th style="width:22%">Department</th>
            <th style="width:22%">Signature</th>
        </tr></thead>
        <tbody>
        <?php foreach ($employees as $i => $emp): ?>
            <tr>
                <td class="sno"><?= $i + 1 ?></td>
                <td><?= h($emp['name']) ?></td>
                <td class="emp-code"><?= h($emp['emp_code']) ?></td>
                <td><?= h($emp['dept']) ?></td>
                <td class="sign-cell">&nbsp;</td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php if ($addr || $contacts): ?>
<div class="footer">
    <?php if ($addr): ?><div><?= h($addr) ?></div><?php endif; ?>
    <?php if ($contacts): ?><div><?= h($contacts) ?></div><?php endif; ?>
</div>
<?php endif; ?>
</body>
</html>
