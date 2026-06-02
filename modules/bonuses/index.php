<?php
$page_title = 'Bonuses & Incentives';
require_once __DIR__ . '/../../includes/header.php';
require_permission('employee');

$db = db();

$db->exec('CREATE TABLE IF NOT EXISTS employee_bonuses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    type ENUM(\'bonus\',\'incentive\',\'commission\') DEFAULT \'bonus\',
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    reason TEXT NULL,
    payroll_month TINYINT NOT NULL,
    payroll_year SMALLINT NOT NULL,
    status ENUM(\'pending\',\'approved\',\'rejected\') DEFAULT \'pending\',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$filterEmp    = (int)($_GET['employee_id'] ?? 0);
$filterType   = trim($_GET['type']   ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$filterMonth  = (int)($_GET['month'] ?? 0);
$filterYear   = (int)($_GET['year']  ?? 0);

$sql    = 'SELECT eb.*, e.name AS emp_name, e.employee_id AS emp_code
           FROM employee_bonuses eb
           JOIN employees e ON e.id = eb.employee_id
           WHERE 1=1';
$params = [];
if ($filterEmp)    { $sql .= ' AND eb.employee_id = ?';    $params[] = $filterEmp; }
if ($filterType)   { $sql .= ' AND eb.type = ?';           $params[] = $filterType; }
if ($filterStatus) { $sql .= ' AND eb.status = ?';         $params[] = $filterStatus; }
if ($filterMonth)  { $sql .= ' AND eb.payroll_month = ?';  $params[] = $filterMonth; }
if ($filterYear)   { $sql .= ' AND eb.payroll_year = ?';   $params[] = $filterYear; }
$sql .= ' ORDER BY eb.payroll_year DESC, eb.payroll_month DESC, eb.created_at DESC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$bonuses = $stmt->fetchAll();

$employees = $db->query('SELECT id, name, employee_id AS emp_code FROM employees ORDER BY name')->fetchAll();

$typeColors  = ['bonus' => 'primary', 'incentive' => 'success', 'commission' => 'info'];
$typeLabels  = ['bonus' => 'Bonus',   'incentive' => 'Incentive', 'commission' => 'Commission'];
$statColors  = ['pending' => 'warning', 'approved' => 'success', 'rejected' => 'danger'];
$months      = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
        <h5 class="mb-0 fw-semibold"><i class="fa fa-trophy me-2 text-warning"></i>Bonuses &amp; Incentives</h5>
        <?php if (can('employee','edit')): ?>
        <a href="<?= BASE_URL ?>/modules/bonuses/create.php" class="btn btn-sm btn-primary">
            <i class="fa fa-plus me-1"></i>Add Bonus / Incentive
        </a>
        <?php endif; ?>
    </div>

    <!-- Filters -->
    <div class="card-body border-bottom pb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-semibold mb-1">Employee</label>
                <select name="employee_id" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($employees as $emp): ?>
                    <option value="<?= $emp['id'] ?>" <?= $filterEmp == $emp['id'] ? 'selected' : '' ?>>
                        <?= h($emp['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Type</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="bonus"      <?= $filterType === 'bonus'      ? 'selected' : '' ?>>Bonus</option>
                    <option value="incentive"  <?= $filterType === 'incentive'  ? 'selected' : '' ?>>Incentive</option>
                    <option value="commission" <?= $filterType === 'commission' ? 'selected' : '' ?>>Commission</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="pending"  <?= $filterStatus === 'pending'  ? 'selected' : '' ?>>Pending</option>
                    <option value="approved" <?= $filterStatus === 'approved' ? 'selected' : '' ?>>Approved</option>
                    <option value="rejected" <?= $filterStatus === 'rejected' ? 'selected' : '' ?>>Rejected</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Month</label>
                <select name="month" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= $filterMonth == $m ? 'selected' : '' ?>><?= date('F', mktime(0,0,0,$m,1)) ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label small fw-semibold mb-1">Year</label>
                <select name="year" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php for ($y = (int)date('Y') + 1; $y >= (int)date('Y') - 4; $y--): ?>
                    <option value="<?= $y ?>" <?= $filterYear == $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-auto d-flex gap-2">
                <button class="btn btn-sm btn-primary"><i class="fa fa-filter me-1"></i>Filter</button>
                <a href="?" class="btn btn-sm btn-outline-secondary">Reset</a>
            </div>
        </form>
    </div>

    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-bordered align-middle mb-0">
                <thead class="table-dark">
                    <tr>
                        <th>#</th>
                        <th>Employee</th>
                        <th>Type</th>
                        <th class="text-end">Amount</th>
                        <th>Reason</th>
                        <th>Payroll Month</th>
                        <th>Status</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($bonuses)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No bonus / incentive records found.</td></tr>
                    <?php else: ?>
                    <?php foreach ($bonuses as $i => $b): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td>
                            <div class="fw-semibold"><?= h($b['emp_name']) ?></div>
                            <small class="text-muted"><?= h($b['emp_code']) ?></small>
                        </td>
                        <td>
                            <span class="badge bg-<?= $typeColors[$b['type']] ?? 'secondary' ?>">
                                <?= $typeLabels[$b['type']] ?? h($b['type']) ?>
                            </span>
                        </td>
                        <td class="text-end fw-semibold text-success"><?= money($b['amount']) ?></td>
                        <td class="small"><?= h($b['reason'] ?? '—') ?></td>
                        <td><?= $months[$b['payroll_month'] - 1] . ' ' . $b['payroll_year'] ?></td>
                        <td>
                            <span class="badge bg-<?= $statColors[$b['status']] ?? 'secondary' ?> <?= $b['status'] === 'pending' ? 'text-dark' : '' ?>">
                                <?= ucfirst($b['status']) ?>
                            </span>
                        </td>
                        <td class="text-center text-nowrap">
                            <a href="<?= BASE_URL ?>/modules/employee/view.php?id=<?= $b['employee_id'] ?>#bonuses"
                               class="btn btn-xs btn-outline-primary" title="View Employee"><i class="fa fa-eye"></i></a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
