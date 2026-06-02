<?php
$page_title = 'Employee Benefits';
require_once __DIR__ . '/../../includes/header.php';
require_permission('employee');

$db = db();

$db->exec('CREATE TABLE IF NOT EXISTS employee_benefits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    fund_type VARCHAR(100) NOT NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    effective_month DATE NOT NULL,
    status ENUM(\'active\',\'inactive\') DEFAULT \'active\',
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$filterEmp    = (int)($_GET['employee_id'] ?? 0);
$filterStatus = trim($_GET['status'] ?? '');

$sql    = 'SELECT eb.*, e.name AS emp_name, e.employee_id AS emp_code
           FROM employee_benefits eb
           JOIN employees e ON e.id = eb.employee_id
           WHERE 1=1';
$params = [];
if ($filterEmp)    { $sql .= ' AND eb.employee_id = ?'; $params[] = $filterEmp; }
if ($filterStatus) { $sql .= ' AND eb.status = ?';      $params[] = $filterStatus; }
$sql .= ' ORDER BY eb.effective_month DESC, eb.created_at DESC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$benefits = $stmt->fetchAll();

$employees = $db->query('SELECT id, name, employee_id AS emp_code FROM employees ORDER BY name')->fetchAll();
?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
        <h5 class="mb-0 fw-semibold"><i class="fa fa-gift me-2 text-primary"></i>Employee Benefits</h5>
        <?php if (can('employee','edit')): ?>
        <a href="<?= BASE_URL ?>/modules/benefits/create.php" class="btn btn-sm btn-primary">
            <i class="fa fa-plus me-1"></i>Assign Benefit
        </a>
        <?php endif; ?>
    </div>

    <!-- Filters -->
    <div class="card-body border-bottom pb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small fw-semibold mb-1">Employee</label>
                <select name="employee_id" class="form-select form-select-sm">
                    <option value="">All Employees</option>
                    <?php foreach ($employees as $emp): ?>
                    <option value="<?= $emp['id'] ?>" <?= $filterEmp == $emp['id'] ? 'selected' : '' ?>>
                        <?= h($emp['name']) ?> (<?= h($emp['emp_code']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="active"   <?= $filterStatus === 'active'   ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
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
                        <th>Fund Type</th>
                        <th class="text-end">Amount</th>
                        <th>Effective Month</th>
                        <th>Status</th>
                        <th>Notes</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($benefits)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No benefit records found.</td></tr>
                    <?php else: ?>
                    <?php foreach ($benefits as $i => $b): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td>
                            <div class="fw-semibold"><?= h($b['emp_name']) ?></div>
                            <small class="text-muted"><?= h($b['emp_code']) ?></small>
                        </td>
                        <td><span class="badge bg-secondary"><?= h($b['fund_type']) ?></span></td>
                        <td class="text-end fw-semibold text-success"><?= money($b['amount']) ?></td>
                        <td><?= date('M Y', strtotime($b['effective_month'])) ?></td>
                        <td>
                            <span class="badge bg-<?= $b['status'] === 'active' ? 'success' : 'secondary' ?>">
                                <?= ucfirst($b['status']) ?>
                            </span>
                        </td>
                        <td class="small text-muted"><?= h($b['description'] ?? '—') ?></td>
                        <td class="text-center text-nowrap">
                            <a href="<?= BASE_URL ?>/modules/employee/view.php?id=<?= $b['employee_id'] ?>#benefits"
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
