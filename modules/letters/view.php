<?php
require_once '../../includes/bootstrap.php';
require_login();
require_permission('letters', 'view');

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/letters/index.php');

$letter = db()->query("SELECT l.*, e.name AS emp_name, e.employee_id AS emp_code,
    e.join_date, d.name AS dept_name, des.name AS designation,
    u.name AS created_by_name
    FROM letters l
    JOIN employees e ON l.employee_id = e.id
    LEFT JOIN departments d ON e.department_id = d.id
    LEFT JOIN designations des ON e.designation_id = des.id
    LEFT JOIN users u ON l.created_by = u.id
    WHERE l.id=$id")->fetch(PDO::FETCH_ASSOC);

if (!$letter) redirect(BASE_URL . '/modules/letters/index.php');

$page_title = $letter['type'] . ' Letter';
include '../../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title"><?= h($letter['type']) ?> Letter</h1>
        <p class="page-subtitle">Ref: <?= h($letter['reference_number']) ?> &mdash; <?= h($letter['emp_name']) ?></p>
    </div>
    <div class="page-actions">
        <?php if (can('letters', 'edit') && $letter['status'] === 'Draft'): ?>
            <a href="issue.php?id=<?= $id ?>" class="btn btn-success" data-key="I"><u>I</u>ssue Letter</a>
        <?php endif; ?>
        <button onclick="window.print()" class="btn btn-secondary" data-key="P"><u>P</u>rint</button>
        <a href="index.php" class="btn btn-secondary" data-key="B"><u>B</u>ack</a>
    </div>
</div>

<?php render_flash(); ?>

<div class="letter-wrapper" id="letterContent">
    <div class="letter-paper">
        <!-- Company Header -->
        <div class="letter-header">
            <div class="letter-company">
                <h2><?= h(COMPANY_NAME) ?></h2>
                <p><?= h(COMPANY_ADDRESS) ?></p>
                <?php if (COMPANY_EMAIL): ?><p><?= h(COMPANY_EMAIL) ?> | <?= h(COMPANY_PHONE) ?></p><?php endif; ?>
            </div>
            <div class="letter-meta">
                <div><strong>Ref No:</strong> <?= h($letter['reference_number']) ?></div>
                <div><strong>Date:</strong> <?= date_fmt($letter['letter_date']) ?></div>
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
        <div class="letter-body">
            <?= nl2br(h($letter['body'])) ?>
        </div>

        <!-- Signature -->
        <div class="letter-signature">
            <p>Yours sincerely,</p>
            <br><br><br>
            <p><strong>_______________________</strong></p>
            <p><strong><?= h(COMPANY_NAME) ?></strong></p>
            <p>Authorized Signatory</p>
            <?php if ($letter['status'] === 'Issued'): ?>
                <p class="mt-3 text-muted" style="font-size:.8rem">Issued on: <?= date_fmt($letter['issued_at'], 'd M Y H:i') ?> by <?= h($letter['created_by_name']) ?></p>
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

<style>
@media print {
    .sidebar, .page-header, .topbar, .shortcut-bar, .btn { display:none !important; }
    .main-content { margin:0; padding:0; }
    .letter-wrapper { padding:0; }
}
.letter-wrapper { max-width:800px; margin:0 auto; }
.letter-paper { background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:2.5rem 3rem; }
.letter-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:1.5rem; }
.letter-company h2 { color:var(--primary); margin:0 0 .25rem; }
.letter-company p { margin:.1rem 0; color:var(--text-muted); font-size:.85rem; }
.letter-meta { text-align:right; font-size:.85rem; }
.letter-divider { border-color:#e2e8f0; margin:1rem 0; }
.letter-to { margin:1.5rem 0; font-size:.9rem; line-height:1.8; }
.letter-subject { margin:1rem 0; font-size:.95rem; }
.letter-body { margin:1.5rem 0; font-size:.9rem; line-height:1.9; color:#374151; }
.letter-signature { margin:2rem 0; font-size:.9rem; }
.letter-ack { margin-top:3rem; padding-top:1rem; border-top:1px dashed #e2e8f0; font-size:.85rem; color:var(--text-muted); }
</style>

<script>
addLocalShortcut('p', () => window.print());
addLocalShortcut('b', () => location.href='index.php');
</script>
<?php include '../../includes/footer.php'; ?>
