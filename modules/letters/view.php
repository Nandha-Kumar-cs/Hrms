<?php
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

// Company identity for the letterhead: prefer the employee's linked entity,
// fall back to the global company constants when none is assigned.
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
$co_signature = (!empty($letter['entity_signature']) && file_exists(BASE_PATH . '/storage/entities/' . $letter['entity_signature']))
    ? BASE_URL . '/storage/entities/' . $letter['entity_signature']
    : null;

$page_title = $letter['type'] . ' Letter';
include '../../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= h($letter['type']) ?> Letter</h1>
        <p class="page-subtitle">Ref: <?= h($letter['reference']) ?> &mdash; <?= h($letter['emp_name']) ?></p>
    </div>
    <div class="page-actions">
        <?php if (can('letters', 'edit') && $letter['status'] === 'Draft'): ?>
            <a href="issue.php?id=<?= $id ?>" class="btn btn-success" data-key="I"><u>I</u>ssue Letter</a>
        <?php endif; ?>
        <?php if (in_array($letter['status'], ['Issued', 'Acknowledged'], true)): ?>
            <a href="download.php?id=<?= $id ?>" class="btn btn-primary" data-key="D"><u>D</u>ownload</a>
        <?php endif; ?>
        <button onclick="window.print()" class="btn btn-secondary" data-key="P"><u>P</u>rint</button>
        <?php if (is_admin() || ($letter['status'] === 'Draft' && can('letters','delete'))): ?>
            <?php $delMsg = $letter['status'] === 'Draft' ? 'Delete this letter?' : 'This letter has been issued. Delete it permanently?'; ?>
            <form method="POST" action="delete.php" class="d-inline" onsubmit="return confirm('<?= h($delMsg) ?>')">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $id ?>">
                <button type="submit" class="btn btn-danger">Delete</button>
            </form>
        <?php endif; ?>
        <a href="index.php" class="btn btn-secondary" data-key="B"><u>B</u>ack</a>
    </div>
</div>

<?php render_flash(); ?>

<?php if ($letter['type'] === 'Offer'):
    // Offer letter: render the exact same 2-page design as the PDF.
    require_once '../../includes/offer_letter.php';
    $offerData = offer_letter_data(db(), $letter);
?>
<div class="letter-wrapper" id="letterContent">
    <?= offer_letter_html($letter, ['name' => $co_name, 'addr' => $co_addr, 'logo' => $co_logo, 'signature' => $co_signature, 'signatory_title' => $letter['entity_signatory_title'] ?? ''], $offerData, ['screen' => true, 'inline_footer' => true]) ?>
</div>
<?php elseif ($letter['type'] === 'Increment'):
    // Increment letter: render the same info-box design as the PDF.
    require_once '../../includes/increment_letter.php';
    $incData = increment_letter_data($letter);
?>
<div class="letter-wrapper" id="letterContent">
    <?= increment_letter_html($letter, ['name' => $co_name, 'addr' => $co_addr, 'email' => $co_email, 'phone' => $co_phone, 'logo' => $co_logo], $incData, ['screen' => true]) ?>
</div>
<?php elseif ($letter['type'] === 'Confirmation'):
    // Confirmation letter: render the same design as the PDF.
    require_once '../../includes/confirmation_letter.php';
    $confData = confirmation_letter_data($letter);
?>
<div class="letter-wrapper" id="letterContent">
    <?= confirmation_letter_html($letter, ['name' => $co_name, 'addr' => $co_addr, 'logo' => $co_logo], $confData, ['screen' => true, 'inline_footer' => true]) ?>
</div>
<?php else: ?>

<div class="letter-wrapper" id="letterContent">
    <div class="letter-paper">
        <!-- Company Header -->
        <div class="letter-header">
            <div class="letter-company">
                <?php if ($co_logo): ?><img src="<?= h($co_logo) ?>" alt="<?= h($co_name) ?>" class="letter-logo"><?php endif; ?>
                <h2><?= h($co_name) ?></h2>
                <p><?= h($co_addr) ?></p>
                <?php if ($co_email || $co_phone): ?><p><?= h(trim($co_email . ($co_email && $co_phone ? ' | ' : '') . $co_phone)) ?></p><?php endif; ?>
            </div>
            <div class="letter-meta">
                <div><strong>Ref No:</strong> <?= h($letter['reference']) ?></div>
                <div><strong>Date:</strong> <?= date_fmt($letter['issued_date']) ?></div>
                <div class="mt-2">
                    <span class="pill <?= $letter['status']==='Issued'?'pill-success':'pill-warn' ?>"><?= $letter['status'] ?></span>
                </div>
            </div>
        </div>

        <hr class="letter-divider">

        <!-- Addressee -->
        <div class="letter-to">
            <p>To,</p>
            <p><strong><?= h($letter['emp_name']) ?></strong></p>
            <p><?= h($letter['designation']) ?></p>
            <p><?= h($letter['dept_name']) ?></p>
            <p>Employee ID: <?= h($letter['emp_code']) ?></p>
        </div>

        <!-- Subject -->
        <div class="letter-subject">
            <strong>Sub: <?= h($letter['type']) ?> Letter</strong>
        </div>

        <!-- Body -->
        <div class="letter-body"><?= h((string)$letter['content']) ?></div>

        <!-- Signature -->
        <div class="letter-signature">
            <p>Yours sincerely,</p>
            <br><br><br>
            <p><strong>_______________________</strong></p>
            <p><strong><?= h($co_name) ?></strong></p>
            <p>Authorized Signatory</p>
            <?php if ($letter['status'] === 'Issued'): ?>
                <p class="mt-3 text-muted" style="font-size:.8rem">Issued on: <?= date_fmt($letter['issued_date']) ?><?= $letter['issued_by_name'] ? ' by ' . h($letter['issued_by_name']) : '' ?></p>
            <?php endif; ?>
        </div>

        <!-- Acknowledgement -->
        <div class="letter-ack">
            <p><strong>Acknowledgement:</strong></p>
            <p>I, <?= h($letter['emp_name']) ?>, hereby acknowledge receipt of this letter.</p>
            <br>
            <p>Signature: _______________________ &nbsp;&nbsp; Date: _____________</p>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
@media print {
    .sidebar, .page-header, .topbar, .shortcut-bar, .btn { display:none !important; }
    .main-content { margin:0; padding:0; }
    .letter-wrapper { padding:0; }
}
.letter-wrapper { max-width:800px; margin:0 auto; }
.letter-paper { background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:2.5rem 3rem; }
.letter-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:1.5rem; }
.letter-logo { max-height:70px; max-width:220px; margin-bottom:.5rem; display:block; }
.letter-company h2 { color:var(--primary); margin:0 0 .25rem; }
.letter-company p { margin:.1rem 0; color:var(--text-muted); font-size:.85rem; }
.letter-meta { text-align:right; font-size:.85rem; }
.letter-divider { border-color:#e2e8f0; margin:1rem 0; }
.letter-to { margin:1.5rem 0; font-size:.9rem; line-height:1.8; }
.letter-subject { margin:1rem 0; font-size:.95rem; }
.letter-body { margin:1.5rem 0; font-size:.9rem; line-height:1.9; color:#374151; white-space:pre-wrap; }
.letter-signature { margin:2rem 0; font-size:.9rem; }
.letter-ack { margin-top:3rem; padding-top:1rem; border-top:1px dashed #e2e8f0; font-size:.85rem; color:var(--text-muted); }
</style>

<script>
addLocalShortcut('p', () => window.print());
addLocalShortcut('b', () => location.href='index.php');
<?php if (in_array($letter['status'], ['Issued', 'Acknowledged'], true)): ?>
addLocalShortcut('d', () => location.href='download.php?id=<?= $id ?>');
<?php endif; ?>
</script>
<?php include '../../includes/footer.php'; ?>
