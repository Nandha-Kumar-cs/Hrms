<?php
$page_title = 'No-Due Clearance';
require_once __DIR__ . '/../../includes/header.php';
require_permission('assets');

$db = db();
$emp_id = (int)($_GET['emp_id'] ?? 0);
$employees = $db->query('SELECT id, name, employee_id, status FROM employees ORDER BY name')->fetchAll();

$clearanceData = null;
if ($emp_id) {
    $emp = $db->prepare('SELECT e.*, d.name AS dept, des.name AS desig FROM employees e LEFT JOIN departments d ON d.id=e.department_id LEFT JOIN designations des ON des.id=e.designation_id WHERE e.id=?');
    $emp->execute([$emp_id]);
    $empData = $emp->fetch();

    // All assets ever assigned
    $assigned = $db->prepare(
        'SELECT aa.*, a.name AS asset_name, a.asset_code, a.serial_no, c.name AS cat_name
         FROM asset_assignments aa
         JOIN assets a ON a.id=aa.asset_id
         JOIN asset_categories c ON c.id=a.category_id
         WHERE aa.employee_id=?
         ORDER BY aa.is_returned ASC, aa.assigned_date'
    );
    $assigned->execute([$emp_id]);
    $assignments = $assigned->fetchAll();

    $pending = array_filter($assignments, fn($a) => !$a['is_returned']);
    $returned = array_filter($assignments, fn($a) => $a['is_returned']);
    $clearanceData = compact('empData','assignments','pending','returned');
}

// Handle return action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['return_asset'])) {
    if (!csrf_verify()) { flash('error','Invalid request'); redirect('.'); }
    $asgn_id = (int)$_POST['assignment_id'];
    $cond = sanitize($_POST['condition_return']);
    $db->prepare('UPDATE asset_assignments SET is_returned=1, returned_date=CURDATE(), condition_at_return=? WHERE id=?')
       ->execute([$cond, $asgn_id]);
    $db->prepare('UPDATE assets SET status="Available", `condition`=? WHERE id=(SELECT asset_id FROM asset_assignments WHERE id=?)')
       ->execute([$cond, $asgn_id]);
    flash('success','Asset returned.');
    redirect('clearance.php?emp_id=' . $emp_id);
}
?>

<div class="page-head">
    <div>
        <h1>No-Due Clearance</h1>
        <p class="muted">Verify all assets returned before exit</p>
    </div>
    <div class="head-actions">
        <?php if ($clearanceData && !$clearanceData['pending']): ?>
        <button onclick="window.print()" class="btn btn-success no-print" accesskey="p" data-shortcut data-key="P">
            ⎙ <u>P</u>rint Certificate
        </button>
        <?php endif; ?>
        <a href="index.php" class="btn btn-ghost" accesskey="b" data-shortcut data-key="B"><u>B</u>ack</a>
    </div>
</div>

<!-- Employee selector -->
<div class="card form-card" style="margin-bottom:18px">
    <form method="GET" style="display:flex;gap:12px;align-items:flex-end">
        <div class="field" style="margin-bottom:0;flex:1;max-width:400px">
            <label>Select Employee</label>
            <select name="emp_id" onchange="this.form.submit()">
                <option value="">— Select Employee —</option>
                <?php foreach ($employees as $e): ?>
                <option value="<?= $e['id'] ?>" <?= $emp_id==$e['id']?'selected':'' ?>>
                    <?= h($e['name']) ?> (<?= h($e['employee_id']) ?>) — <?= h($e['status']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>

<?php if ($clearanceData): $ed = $clearanceData['empData']; ?>

<!-- Clearance Status -->
<?php if ($clearanceData['pending']): ?>
<div class="alert alert-warn">
    ⚠ <strong><?= count($clearanceData['pending']) ?> asset(s) pending return.</strong>
    Clearance cannot be issued until all assets are returned.
</div>
<?php else: ?>
<div class="alert alert-success">
    ✓ <strong>All clear!</strong> No pending assets. Clearance certificate can be generated.
</div>
<?php endif; ?>

<!-- Clearance Certificate (printable) -->
<div class="card form-card" id="clearanceCert">
    <!-- Header (shown when printing) -->
    <div class="slip-header">
        <h2><?= h(COMPANY_NAME) ?></h2>
        <p class="muted small"><?= h(COMPANY_ADDRESS) ?></p>
        <h3 style="margin-top:12px;text-transform:uppercase;letter-spacing:.06em">No-Due Clearance Certificate</h3>
    </div>

    <!-- Employee info -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0;border:1px solid var(--border);border-radius:var(--radius);margin-bottom:18px">
        <?php
        $info = [
            'Name'        => $ed['name'],
            'Employee ID' => $ed['employee_id'],
            'Department'  => $ed['dept'] ?? '—',
            'Designation' => $ed['desig'] ?? '—',
            'Join Date'   => date_fmt($ed['join_date']),
            'Status'      => $ed['status'],
        ];
        $i = 0;
        foreach ($info as $k => $v): $last = $i >= count($info)-2; ?>
        <div style="padding:8px 12px;<?= !$last?'border-bottom:1px solid var(--border)':'' ?>;font-size:13px<?= $i%2===1?';border-left:1px solid var(--border)':'' ?>">
            <span class="muted"><?= h($k) ?>: </span><strong><?= h($v) ?></strong>
        </div>
        <?php $i++; endforeach; ?>
    </div>

    <!-- Asset list -->
    <div class="section-title">Asset Details</div>

    <?php if ($clearanceData['pending']): ?>
    <div style="margin-bottom:14px">
        <div style="font-weight:600;font-size:13px;color:var(--danger);margin-bottom:8px">Pending Returns</div>
        <?php foreach ($clearanceData['pending'] as $a): ?>
        <div class="clearance-step pending">
            <span style="font-size:20px">⚠</span>
            <div style="flex:1">
                <div style="font-weight:600"><?= h($a['asset_name']) ?></div>
                <div class="small muted"><?= h($a['asset_code']) ?> · <?= h($a['cat_name']) ?> · Assigned: <?= date_fmt($a['assigned_date']) ?></div>
            </div>
            <?php if (can('assets','return')): ?>
            <button class="btn btn-sm btn-success no-print" onclick="openReturnModal(<?= $a['id'] ?>, '<?= h($a['asset_name']) ?>')">Return</button>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($clearanceData['returned']): ?>
    <div>
        <div style="font-weight:600;font-size:13px;color:var(--success);margin-bottom:8px">Returned Assets</div>
        <?php foreach ($clearanceData['returned'] as $a): ?>
        <div class="clearance-step cleared">
            <span style="font-size:20px">✓</span>
            <div style="flex:1">
                <div style="font-weight:600;text-decoration:line-through;color:var(--text-muted)"><?= h($a['asset_name']) ?></div>
                <div class="small muted"><?= h($a['asset_code']) ?> · Returned: <?= date_fmt($a['returned_date']) ?> · Condition: <?= h($a['condition_at_return'] ?? '—') ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!$clearanceData['assignments']): ?>
    <p class="muted small">No assets assigned to this employee.</p>
    <?php endif; ?>

    <?php if (!$clearanceData['pending']): ?>
    <div style="margin-top:24px;padding:16px;border:2px solid var(--success);border-radius:var(--radius);background:var(--success-bg)">
        <p style="font-weight:600;color:var(--success);margin-bottom:4px">✓ NO-DUE CERTIFICATE</p>
        <p style="font-size:13px">This is to certify that <strong><?= h($ed['name']) ?></strong> (<?= h($ed['employee_id']) ?>) has returned all company assets and there are no dues pending as of <?= date_fmt(date('Y-m-d')) ?>.</p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:20px">
            <div>
                <div style="border-top:1px solid var(--success);padding-top:8px;font-size:12px;color:var(--text-muted)">Employee Signature & Date</div>
            </div>
            <div>
                <div style="border-top:1px solid var(--success);padding-top:8px;font-size:12px;color:var(--text-muted)">HR Manager Signature & Date</div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Return Modal -->
<div class="modal" id="returnModal">
    <div class="modal-content">
        <h3>Return Asset</h3>
        <p id="returnAssetName" class="muted"></p>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="return_asset" value="1">
            <input type="hidden" name="assignment_id" id="return_asgn_id">
            <label>Condition at Return</label>
            <select name="condition_return" style="width:100%;padding:8px;border:1px solid var(--border-strong);border-radius:var(--radius)">
                <?php foreach (['New','Good','Fair','Poor'] as $c): ?><option><?= $c ?></option><?php endforeach; ?>
            </select>
            <div style="display:flex;gap:8px;margin-top:16px">
                <button type="submit" class="btn btn-success">Confirm Return</button>
                <button type="button" class="btn btn-ghost" onclick="closeModal('returnModal')">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
window.BASE_URL = '<?= BASE_URL ?>';
function openReturnModal(id, name) {
    document.getElementById('return_asgn_id').value = id;
    document.getElementById('returnAssetName').textContent = 'Asset: ' + name;
    openModal('returnModal');
}
window.PAGE_SHORTCUTS = {
    'p': () => window.print(),
    'b': () => window.location.href = BASE_URL + '/modules/assets/index.php'
};
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
