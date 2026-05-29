<?php
require_once '../../includes/bootstrap.php';
require_login();
require_permission('payroll_view');

$user = current_user();

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf($_POST['csrf_token'] ?? '');
    require_permission('payroll_edit');

    $empId      = (int)$_POST['employee_id'];
    $basic      = (float)$_POST['basic'];
    $hra        = (float)$_POST['hra'];
    $conveyance = (float)$_POST['conveyance'];
    $medical    = (float)$_POST['medical'];
    $special    = (float)$_POST['special'];
    $other      = (float)$_POST['other_allowance'];
    $effDate    = $_POST['effective_date'] ?? date('Y-m-d');

    // Mark old active as inactive
    db()->prepare("UPDATE salary_structures SET is_active=0 WHERE employee_id=:eid")
         ->execute([':eid'=>$empId]);

    $stmt = db()->prepare("INSERT INTO salary_structures
        (employee_id,basic,hra,conveyance,medical,special_allowance,other_allowance,effective_date,is_active,created_at)
        VALUES (:eid,:b,:h,:c,:m,:s,:o,:ed,1,NOW())");
    $stmt->execute([':eid'=>$empId,':b'=>$basic,':h'=>$hra,':c'=>$conveyance,':m'=>$medical,':s'=>$special,':o'=>$other,':ed'=>$effDate]);

    flash('success','Salary structure saved.');
    redirect(BASE_URL . '/modules/payroll/salary_structure.php?employee_id=' . $empId);
}

$empId = (int)($_GET['employee_id'] ?? 0);
$employees = db()->query("SELECT id, CONCAT(first_name,' ',last_name,' (',employee_id,')') AS name FROM employees WHERE status='Active' ORDER BY first_name")->fetchAll(PDO::FETCH_ASSOC);

$current = null;
$history = [];
if ($empId) {
    $current = db()->query("SELECT * FROM salary_structures WHERE employee_id=$empId AND is_active=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $history = db()->query("SELECT * FROM salary_structures WHERE employee_id=$empId ORDER BY effective_date DESC")->fetchAll(PDO::FETCH_ASSOC);
}

$page_title = 'Salary Structure';
include '../../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Salary Structure</h1>
        <p class="page-subtitle">Manage employee salary components</p>
    </div>
    <div class="page-actions">
        <a href="index.php" class="btn btn-secondary" data-key="B"><u>B</u>ack</a>
    </div>
</div>

<?php render_flash(); ?>

<div class="row">
    <div class="col-5">
        <!-- Employee selector -->
        <div class="card mb-4">
            <div class="card-header"><h3 class="card-title">Select Employee</h3></div>
            <div class="card-body">
                <form method="GET">
                    <div class="form-group">
                        <select name="employee_id" class="form-control" onchange="this.form.submit()">
                            <option value="">-- Select Employee --</option>
                            <?php foreach ($employees as $e): ?>
                                <option value="<?= $e['id'] ?>" <?= $empId==$e['id']?'selected':'' ?>><?= h($e['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($empId): ?>
        <!-- Current structure & edit form -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><?= $current ? 'Update Structure' : 'Add Salary Structure' ?></h3>
            </div>
            <form method="POST" id="salaryForm">
                <?= csrf_field() ?>
                <input type="hidden" name="employee_id" value="<?= $empId ?>">
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label">Effective Date</label>
                        <input type="date" name="effective_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-6">
                            <label class="form-label">Basic Salary</label>
                            <input type="number" name="basic" class="form-control salary-input" value="<?= $current['basic'] ?? '' ?>" min="0" step="0.01" required id="basic">
                        </div>
                        <div class="form-group col-6">
                            <label class="form-label">HRA</label>
                            <input type="number" name="hra" class="form-control salary-input" value="<?= $current['hra'] ?? '' ?>" min="0" step="0.01" id="hra">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-6">
                            <label class="form-label">Conveyance</label>
                            <input type="number" name="conveyance" class="form-control salary-input" value="<?= $current['conveyance'] ?? '' ?>" min="0" step="0.01" id="conveyance">
                        </div>
                        <div class="form-group col-6">
                            <label class="form-label">Medical</label>
                            <input type="number" name="medical" class="form-control salary-input" value="<?= $current['medical'] ?? '' ?>" min="0" step="0.01" id="medical">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-6">
                            <label class="form-label">Special Allowance</label>
                            <input type="number" name="special_allowance" class="form-control salary-input" value="<?= $current['special_allowance'] ?? '' ?>" min="0" step="0.01" id="special">
                        </div>
                        <div class="form-group col-6">
                            <label class="form-label">Other Allowance</label>
                            <input type="number" name="other_allowance" class="form-control salary-input" value="<?= $current['other_allowance'] ?? '' ?>" min="0" step="0.01" id="other">
                        </div>
                    </div>
                    <div class="card mb-3" style="background:var(--bg-secondary)">
                        <div class="card-body py-2">
                            <div class="d-flex justify-content-between">
                                <strong>Gross Monthly (CTC):</strong>
                                <strong id="grossDisplay" class="text-success">₹0.00</strong>
                            </div>
                            <div class="d-flex justify-content-between mt-1">
                                <span class="text-muted">LOP Per Day:</span>
                                <span id="lopDisplay" class="text-muted">₹0.00</span>
                            </div>
                        </div>
                    </div>
                </div>
                <?php if (can('payroll_edit')): ?>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary" data-key="S"><u>S</u>ave Structure</button>
                </div>
                <?php endif; ?>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-7">
        <?php if ($empId && $history): ?>
        <div class="card">
            <div class="card-header"><h3 class="card-title">Salary History</h3></div>
            <div class="card-body">
                <table class="table datatable">
                    <thead>
                        <tr>
                            <th>Effective Date</th>
                            <th>Basic</th>
                            <th>HRA</th>
                            <th>Conveyance</th>
                            <th>Medical</th>
                            <th>Special</th>
                            <th>Gross</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history as $h): ?>
                        <tr>
                            <td><?= date_fmt($h['effective_date']) ?></td>
                            <td><?= money($h['basic']) ?></td>
                            <td><?= money($h['hra']) ?></td>
                            <td><?= money($h['conveyance']) ?></td>
                            <td><?= money($h['medical']) ?></td>
                            <td><?= money($h['special_allowance']) ?></td>
                            <td><strong><?= money($h['gross_salary']) ?></strong></td>
                            <td><?= $h['is_active'] ? '<span class="pill pill-success">Active</span>' : '<span class="pill pill-secondary">Old</span>' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php elseif ($empId): ?>
            <div class="card"><div class="card-body text-muted">No salary structure found for this employee.</div></div>
        <?php else: ?>
            <div class="card"><div class="card-body text-muted text-center py-5">
                <p>Select an employee to view or edit their salary structure.</p>
            </div></div>
        <?php endif; ?>
    </div>
</div>

<script>
const WORKING_DAYS = <?= PAYROLL_WORKING_DAYS ?>;
function calcGross() {
    const fields = ['basic','hra','conveyance','medical','special','other'];
    let gross = 0;
    fields.forEach(id => {
        gross += parseFloat(document.getElementById(id)?.value || 0);
    });
    document.getElementById('grossDisplay').textContent = '₹' + gross.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');
    document.getElementById('lopDisplay').textContent = '₹' + (gross/WORKING_DAYS).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');
}
document.querySelectorAll('.salary-input').forEach(el => el.addEventListener('input', calcGross));
calcGross();
addLocalShortcut('s', () => document.getElementById('salaryForm')?.submit());
addLocalShortcut('b', () => location.href='index.php');
</script>
<?php include '../../includes/footer.php'; ?>
