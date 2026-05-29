<?php
$page_title = 'Employee Profile';
require_once __DIR__ . '/../../includes/header.php';
require_permission('employee');

$id = (int)($_GET['id'] ?? 0);
$db = db();
$emp = $db->prepare(
    'SELECT e.*, d.name AS dept_name, des.name AS desig_name, m.name AS manager_name
     FROM employees e
     LEFT JOIN departments d ON d.id=e.department_id
     LEFT JOIN designations des ON des.id=e.designation_id
     LEFT JOIN employees m ON m.id=e.manager_id
     WHERE e.id=?'
);
$emp->execute([$id]);
$e = $emp->fetch();
if (!$e) { redirect(BASE_URL . '/modules/employee/index.php'); }

// Salary structure
$sal = $db->prepare('SELECT * FROM salary_structures WHERE employee_id=? AND is_current=1 ORDER BY effective_from DESC LIMIT 1');
$sal->execute([$id]);
$salary = $sal->fetch();

// Recent attendance (last 7)
$att = $db->prepare('SELECT * FROM attendance WHERE employee_id=? ORDER BY att_date DESC LIMIT 7');
$att->execute([$id]);
$att_rows = $att->fetchAll();

// Active assets
$assets = $db->prepare(
    'SELECT aa.*, a.name AS asset_name, a.asset_code, c.name AS cat_name
     FROM asset_assignments aa
     JOIN assets a ON a.id=aa.asset_id
     JOIN asset_categories c ON c.id=a.category_id
     WHERE aa.employee_id=? AND aa.is_returned=0'
);
$assets->execute([$id]);
$asset_rows = $assets->fetchAll();

// Letters
$letters = $db->prepare('SELECT * FROM letters WHERE employee_id=? ORDER BY issued_date DESC LIMIT 5');
$letters->execute([$id]);
$letter_rows = $letters->fetchAll();
?>

<div class="page-head">
    <div>
        <h1><?= h($e['name']) ?></h1>
        <p class="muted"><?= h($e['employee_id']) ?> · <?= h($e['desig_name'] ?? '—') ?> · <?= h($e['dept_name'] ?? '—') ?></p>
    </div>
    <div class="head-actions">
        <?php if (can('employee','edit')): ?>
        <a href="edit.php?id=<?= $id ?>" class="btn btn-primary" accesskey="e" data-shortcut data-key="E"><u>E</u>dit</a>
        <?php endif; ?>
        <?php if (can('letters','create')): ?>
        <a href="../letters/create.php?emp_id=<?= $id ?>" class="btn" accesskey="l" data-shortcut data-key="L">Issue <u>L</u>etter</a>
        <?php endif; ?>
        <a href="index.php" class="btn btn-ghost" accesskey="b" data-shortcut data-key="B"><u>B</u>ack</a>
    </div>
</div>

<div class="grid-2">
    <!-- Profile Card -->
    <div class="card form-card">
        <div style="display:flex;gap:18px;align-items:flex-start;margin-bottom:18px">
            <?php if ($e['photo']): ?>
            <img src="<?= BASE_URL ?>/uploads/photos/<?= h($e['photo']) ?>" style="width:72px;height:72px;border-radius:50%;object-fit:cover;border:2px solid var(--border)">
            <?php else: ?>
            <div class="emp-avatar" style="width:72px;height:72px;font-size:28px;border-radius:50%"><?= strtoupper(substr($e['name'],0,1)) ?></div>
            <?php endif; ?>
            <div>
                <div style="font-size:18px;font-weight:700"><?= h($e['name']) ?></div>
                <div class="muted"><?= h($e['email']) ?></div>
                <div style="margin-top:6px">
                    <?php
                    $sc = ['Active'=>'pill-success','On Leave'=>'pill-warn','Resigned'=>'pill-danger','Terminated'=>'pill-cancelled'];
                    echo '<span class="pill '.($sc[$e['status']]??'pill-neutral').'">'.h($e['status']).'</span>';
                    echo ' <span class="pill pill-info">'.h($e['employment_type']).'</span>';
                    ?>
                </div>
            </div>
        </div>

        <div class="section-title">Personal Details</div>
        <?php
        $fields = [
            'Phone'          => $e['phone'],
            'Date of Birth'  => $e['dob'] ? date_fmt($e['dob']) . ' (' . age($e['dob']) . ' yrs)' : '—',
            'Gender'         => $e['gender'],
            'Address'        => implode(', ', array_filter([$e['address'], $e['city'], $e['state'], $e['pincode']])) ?: '—',
        ];
        foreach ($fields as $lbl => $val): ?>
        <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--border);font-size:13px">
            <span class="muted"><?= h($lbl) ?></span>
            <span style="font-weight:500"><?= h($val ?: '—') ?></span>
        </div>
        <?php endforeach; ?>

        <div class="section-title" style="margin-top:14px">Employment</div>
        <?php
        $efields = [
            'Join Date'  => $e['join_date'] ? date_fmt($e['join_date']) . ' (' . tenure($e['join_date']) . ')' : '—',
            'Manager'    => $e['manager_name'] ?? '—',
            'Department' => $e['dept_name'] ?? '—',
            'Designation'=> $e['desig_name'] ?? '—',
        ];
        foreach ($efields as $lbl => $val): ?>
        <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--border);font-size:13px">
            <span class="muted"><?= h($lbl) ?></span>
            <span style="font-weight:500"><?= h($val ?: '—') ?></span>
        </div>
        <?php endforeach; ?>

        <div class="section-title" style="margin-top:14px">Statutory</div>
        <?php
        $sfields = [
            'PAN'     => $e['pan_number'],
            'Aadhaar' => $e['aadhaar_number'] ? '****' . substr($e['aadhaar_number'],-4) : '—',
            'UAN'     => $e['uan_number'],
        ];
        foreach ($sfields as $lbl => $val): ?>
        <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--border);font-size:13px">
            <span class="muted"><?= h($lbl) ?></span>
            <span class="mono" style="font-weight:500"><?= h($val ?: '—') ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Right -->
    <div>
        <!-- Salary -->
        <div class="card" style="margin-bottom:18px">
            <div class="card-head">
                <h3>Current Salary</h3>
                <?php if (can('payroll','process')): ?>
                <a href="../payroll/salary_structure.php?employee_id=<?= $id ?>" class="link">Edit →</a>
                <?php endif; ?>
            </div>
            <div style="padding:14px 18px">
            <?php if ($salary): ?>
                <?php
                $items = ['Basic'=>$salary['basic'],'HRA'=>$salary['hra'],'Conveyance'=>$salary['conveyance'],
                          'Medical'=>$salary['medical'],'Special Allowance'=>$salary['special_allow'],'Other Allowance'=>$salary['other_allow']];
                foreach ($items as $lbl => $amt): if (!$amt) continue; ?>
                <div style="display:flex;justify-content:space-between;padding:5px 0;font-size:13px;border-bottom:1px solid var(--border)">
                    <span class="muted"><?= h($lbl) ?></span><span><?= money($amt) ?></span>
                </div>
                <?php endforeach; ?>
                <div style="display:flex;justify-content:space-between;padding:8px 0;font-weight:700;font-size:14px;margin-top:4px">
                    <span>Gross CTC</span><span><?= money($salary['gross']) ?></span>
                </div>
                <div class="small muted" style="margin-top:2px">Effective: <?= date_fmt($salary['effective_from']) ?></div>
            <?php else: ?>
                <p class="muted small">No salary structure defined.</p>
                <?php if (can('payroll','process')): ?>
                <a href="../payroll/salary_structure.php?employee_id=<?= $id ?>" class="btn btn-sm btn-primary" style="margin-top:8px">Set Salary</a>
                <?php endif; ?>
            <?php endif; ?>
            </div>
        </div>

        <!-- Recent Attendance -->
        <div class="card" style="margin-bottom:18px">
            <div class="card-head">
                <h3>Recent Attendance</h3>
                <a href="../attendance/index.php?emp_id=<?= $id ?>" class="link">View all →</a>
            </div>
            <table class="data-table">
                <thead><tr><th>Date</th><th>Status</th><th>In</th><th>Out</th></tr></thead>
                <tbody>
                <?php foreach ($att_rows as $r): ?>
                <tr>
                    <td><?= date_fmt($r['att_date'], 'd M') ?></td>
                    <td><?php
                        $pillMap = ['On Time'=>'pill-on-time','Late'=>'pill-late','Absent'=>'pill-absent',
                                    'OD'=>'pill-od','Comp Off'=>'pill-comp-off','Half Day'=>'pill-half'];
                        $pc = $pillMap[$r['status']] ?? 'pill-neutral';
                        echo '<span class="pill '.$pc.'">'.h($r['status']).'</span>';
                    ?></td>
                    <td><?= $r['in_time'] ? date('h:i A', strtotime($r['in_time'])) : '—' ?></td>
                    <td><?= $r['out_time'] ? date('h:i A', strtotime($r['out_time'])) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$att_rows): ?>
                <tr><td colspan="4" class="empty">No attendance records</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Assets -->
        <div class="card" style="margin-bottom:18px">
            <div class="card-head">
                <h3>Assigned Assets</h3>
                <?php if (can('assets','assign')): ?>
                <a href="../assets/assign.php?emp_id=<?= $id ?>" class="link">Assign →</a>
                <?php endif; ?>
            </div>
            <div style="padding:0 4px">
            <?php foreach ($asset_rows as $a): ?>
            <div class="checklist-item">
                <span>💻</span>
                <div style="flex:1">
                    <div style="font-weight:500;font-size:13px"><?= h($a['asset_name']) ?></div>
                    <div class="small muted"><?= h($a['asset_code']) ?> · <?= h($a['cat_name']) ?></div>
                </div>
                <span class="small muted"><?= date_fmt($a['assigned_date']) ?></span>
            </div>
            <?php endforeach; ?>
            <?php if (!$asset_rows): ?>
            <div style="padding:16px;text-align:center;color:var(--text-muted);font-size:13px">No assets assigned</div>
            <?php endif; ?>
            </div>
        </div>

        <!-- Letters -->
        <div class="card">
            <div class="card-head">
                <h3>Letters</h3>
                <?php if (can('letters','create')): ?>
                <a href="../letters/create.php?emp_id=<?= $id ?>" class="link">Issue →</a>
                <?php endif; ?>
            </div>
            <table class="data-table">
                <thead><tr><th>Type</th><th>Date</th><th>Status</th><th class="r">Action</th></tr></thead>
                <tbody>
                <?php foreach ($letter_rows as $l): ?>
                <tr>
                    <td><?= h($l['type']) ?></td>
                    <td><?= date_fmt($l['issued_date']) ?></td>
                    <td><span class="pill pill-<?= strtolower($l['status']) ?>"><?= h($l['status']) ?></span></td>
                    <td class="r"><a href="../letters/view.php?id=<?= $l['id'] ?>" class="btn btn-sm">View</a></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$letter_rows): ?>
                <tr><td colspan="4" class="empty">No letters issued</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
window.BASE_URL = '<?= BASE_URL ?>';
window.PAGE_SHORTCUTS = {
    'e': () => window.location.href = 'edit.php?id=<?= $id ?>',
    'l': () => window.location.href = '../letters/create.php?emp_id=<?= $id ?>',
    'b': () => window.location.href = 'index.php'
};
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
