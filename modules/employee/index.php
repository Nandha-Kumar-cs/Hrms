<?php
$page_title = 'Employees';
require_once __DIR__ . '/../../includes/header.php';
require_permission('employee');

$db = db();
$employees = $db->query(
    'SELECT e.*, d.name AS dept_name, des.name AS desig_name
     FROM employees e
     LEFT JOIN departments d ON d.id = e.department_id
     LEFT JOIN designations des ON des.id = e.designation_id
     ORDER BY e.name'
)->fetchAll();
?>

<div class="page-head">
    <div>
        <h1>Employees</h1>
        <p class="muted"><?= count($employees) ?> records</p>
    </div>
    <div class="head-actions">
        <?php if (can('employee','create')): ?>
        <a href="create.php" class="btn btn-primary" accesskey="n" data-shortcut data-key="N">
            + <u>N</u>ew Employee
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h3>All Employees</h3>
        <div class="search">
            <input type="search" placeholder="Filter..." data-search id="tableSearch" oninput="filterTable(this.value)">
        </div>
    </div>
    <div style="overflow-x:auto">
    <table class="data-table datatable" id="empTable">
        <thead><tr>
            <th>Employee</th>
            <th>ID</th>
            <th>Department</th>
            <th>Designation</th>
            <th>Join Date</th>
            <th>Type</th>
            <th>Status</th>
            <th class="r">Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($employees as $e): ?>
        <tr>
            <td>
                <div style="display:flex;align-items:center;gap:8px">
                    <?php if ($e['photo']): ?>
                    <img src="<?= BASE_URL ?>/uploads/photos/<?= h($e['photo']) ?>" style="width:34px;height:34px;border-radius:50%;object-fit:cover">
                    <?php else: ?>
                    <div class="emp-avatar"><?= strtoupper(substr($e['name'],0,1)) ?></div>
                    <?php endif; ?>
                    <div>
                        <div style="font-weight:500"><?= h($e['name']) ?></div>
                        <div class="small muted"><?= h($e['email']) ?></div>
                    </div>
                </div>
            </td>
            <td><code><?= h($e['employee_id']) ?></code></td>
            <td><?= h($e['dept_name'] ?? '—') ?></td>
            <td><?= h($e['desig_name'] ?? '—') ?></td>
            <td><?= date_fmt($e['join_date']) ?></td>
            <td><span class="pill pill-info"><?= h($e['employment_type']) ?></span></td>
            <td>
                <?php
                $sc = ['Active'=>'pill-success','On Leave'=>'pill-warn','Resigned'=>'pill-danger','Terminated'=>'pill-cancelled'];
                echo '<span class="pill '.($sc[$e['status']]??'pill-neutral').'">'.h($e['status']).'</span>';
                ?>
            </td>
            <td class="r nowrap">
                <a href="view.php?id=<?= $e['id'] ?>" class="btn btn-sm">View</a>
                <?php if (can('employee','edit')): ?>
                <a href="edit.php?id=<?= $e['id'] ?>" class="btn btn-sm">Edit</a>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<script>
window.BASE_URL = '<?= BASE_URL ?>';
window.PAGE_SHORTCUTS = {
    'n': () => window.location.href = '<?= BASE_URL ?>/modules/employee/create.php',
};
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
