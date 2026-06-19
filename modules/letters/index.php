<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

// Sub-menu permission: each letter type (Offer / Confirmation / Increment /
// Promotion) is granted independently. The "All" list needs the general view.
$type_filter = sanitize($_GET['type'] ?? '');
if ($type_filter) $type_filter = ucfirst(strtolower($type_filter));
require_permission('letters', $type_filter !== '' ? strtolower($type_filter) : 'view');

$page_title = 'Letters';
require_once __DIR__ . '/../../includes/header.php';

$db = db();

// Self-scoped employees (e.g. an Employee viewing their own letters) only ever
// see their own records, regardless of type filter.
$scopeEmp = scope_employee_id();

$sql = 'SELECT l.*, e.name, e.employee_id AS emp_code FROM letters l JOIN employees e ON e.id=l.employee_id';
$conds = []; $params = [];
if ($type_filter) { $conds[] = 'l.type=?';        $params[] = $type_filter; }
if ($scopeEmp)    { $conds[] = 'l.employee_id=?'; $params[] = $scopeEmp; }
if ($conds) $sql .= ' WHERE ' . implode(' AND ', $conds);
$sql .= ' ORDER BY l.issued_date DESC, l.created_at DESC';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$letters = $stmt->fetchAll();

// Counts per type (scoped to own records for self-scoped users).
if ($scopeEmp) {
    $cs = $db->prepare('SELECT type, COUNT(*) cnt FROM letters WHERE employee_id=? GROUP BY type');
    $cs->execute([$scopeEmp]);
    $counts = $cs->fetchAll(PDO::FETCH_KEY_PAIR);
} else {
    $counts = $db->query('SELECT type, COUNT(*) cnt FROM letters GROUP BY type')->fetchAll(PDO::FETCH_KEY_PAIR);
}
?>

<div class="page-head">
    <div>
        <h1>Letters</h1>
        <p class="muted"><?= count($letters) ?> records<?= $type_filter ? ' · filtered: '.$type_filter : '' ?></p>
    </div>
    <div class="head-actions">
        <?php if (can('letters','create')): ?>
        <a href="create.php" class="btn btn-primary" accesskey="n" data-shortcut data-key="N">
            + <u>N</u>ew Letter
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Type filter tabs (only the types this user may access) -->
<div class="tabs" style="margin-bottom:18px">
    <?php if (can('letters', 'view')): ?>
    <a class="tab <?= !$type_filter?'active':'' ?>" href="index.php">All</a>
    <?php endif; ?>
    <?php foreach (['Offer','Confirmation','Increment','Promotion'] as $t): ?>
    <?php if (!can('letters', strtolower($t))) continue; ?>
    <a class="tab <?= $type_filter===$t?'active':'' ?>" href="index.php?type=<?= $t ?>">
        <?= $t ?> <span class="pill pill-neutral" style="margin-left:4px"><?= $counts[$t] ?? 0 ?></span>
    </a>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-head">
        <h3>Letters</h3>
        <div class="search"><input type="search" placeholder="Search..." data-search></div>
    </div>
    <div class="card-body">
    <table class="data-table" id="tbl-letters">
        <thead><tr>
            <th>Employee</th>
            <th>Type</th>
            <th>Reference</th>
            <th>Date</th>
            <th>Status</th>
            <th class="r">Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($letters as $l): ?>
        <tr>
            <td>
                <a href="../employee/view.php?id=<?= $l['employee_id'] ?>" style="font-weight:500"><?= h($l['name']) ?></a>
                <div class="small muted"><?= h($l['emp_code']) ?></div>
            </td>
            <td>
                <?php
                $typeIcons = ['Offer'=>'📩','Confirmation'=>'✅','Increment'=>'📈','Promotion'=>'⭐'];
                $typePills = ['Offer'=>'pill-info','Confirmation'=>'pill-success','Increment'=>'pill-warn','Promotion'=>'pill-active'];
                echo '<span class="pill '.($typePills[$l['type']]??'pill-neutral').'">';
                echo ($typeIcons[$l['type']]??'📄').' '.h($l['type']).'</span>';
                ?>
            </td>
            <td><code class="small"><?= h($l['reference'] ?? '—') ?></code></td>
            <td><?= date_fmt($l['issued_date']) ?></td>
            <td>
                <?php
                $sp = ['Draft'=>'pill-draft','Issued'=>'pill-active','Acknowledged'=>'pill-success'];
                echo '<span class="pill '.($sp[$l['status']]??'pill-neutral').'">'.h($l['status']).'</span>';
                ?>
            </td>
            <td class="r nowrap">
                <a href="view.php?id=<?= $l['id'] ?>" class="btn btn-sm">View</a>
                <?php if ($l['status'] === 'Draft' && can('letters','create')): ?>
                <a href="issue.php?id=<?= $l['id'] ?>" class="btn btn-sm btn-success">Issue</a>
                <?php endif; ?>
                <?php if (in_array($l['status'], ['Issued','Acknowledged'], true)): ?>
                <a href="download.php?id=<?= $l['id'] ?>" class="btn btn-sm btn-primary">↓ Download</a>
                <?php endif; ?>
                <?php if (is_admin() || ($l['status'] === 'Draft' && can('letters','delete'))): ?>
                <?php $delMsg = $l['status'] === 'Draft' ? 'Delete this letter?' : 'This letter has been issued. Delete it permanently?'; ?>
                <form method="POST" action="delete.php" class="d-inline"
                      onsubmit="return confirm('<?= h($delMsg) ?>')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $l['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-danger">Del</button>
                </form>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$letters): ?>
        <!-- <tr><td colspan="6" class="empty">No letters found.</td></tr> -->
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<script>
window.BASE_URL = '<?= BASE_URL ?>';
window.PAGE_SHORTCUTS = {
    'n': () => window.location.href = '<?= BASE_URL ?>/modules/letters/create.php'
};
</script>
<?php $page_scripts = <<<'JS'
<script>
$(document).ready(function () {
    $('#tbl-letters').DataTable({
        pageLength: 25,
        order: [],
        language: {
            search: '', searchPlaceholder: 'Search...',
            lengthMenu: 'Show _MENU_ rows',
            paginate: { previous: '‹', next: '›' }
        },
        dom: '<"dt-top"lBf>rt<"dt-bottom"ip>',
        buttons: [
            { extend: 'excelHtml5', text: '↓ Excel', className: 'btn btn-sm' },
            { extend: 'print',      text: '⎙ Print', className: 'btn btn-sm' }
        ],
        columnDefs: [
            { orderable: false, targets: [5] }
        ]
    });
});
</script>
JS; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
