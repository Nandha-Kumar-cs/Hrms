<?php
$page_title = 'Increment History';
require_once __DIR__ . '/../../includes/header.php';
require_permission('employee');

$db = db();

$db->exec('CREATE TABLE IF NOT EXISTS employee_increments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    effective_date DATE NOT NULL,
    previous_salary DECIMAL(10,2) NOT NULL DEFAULT 0,
    new_salary DECIMAL(10,2) NOT NULL DEFAULT 0,
    remarks TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$filterEmp = (int)($_GET['employee_id'] ?? 0);

$sql    = 'SELECT ei.*, e.name AS emp_name, e.employee_id AS emp_code,
                  e.department_id, d.name AS dept_name
           FROM employee_increments ei
           JOIN employees e ON e.id = ei.employee_id
           LEFT JOIN departments d ON d.id = e.department_id
           WHERE 1=1';
$params = [];
if ($filterEmp) { $sql .= ' AND ei.employee_id = ?'; $params[] = $filterEmp; }
$sql .= ' ORDER BY ei.effective_date DESC, ei.created_at DESC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$increments = $stmt->fetchAll();

$employees = $db->query('SELECT id, name, employee_id AS emp_code FROM employees ORDER BY name')->fetchAll();
?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
        <h5 class="mb-0 fw-semibold">Increment History</h5>
        <?php if (can('employee','edit')): ?>
        <a href="<?= BASE_URL ?>/modules/increments/create.php" class="btn btn-sm btn-primary">
            <i class="fa fa-plus me-1"></i>Add Increment
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
                        <th>Department</th>
                        <th>Effective Date</th>
                        <th class="text-end">Previous Salary</th>
                        <th class="text-end">New Salary</th>
                        <th class="text-end">Increment</th>
                        <th class="text-center">%</th>
                        <th>Remarks</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($increments)): ?>
                    <tr><td colspan="10" class="text-center text-muted py-4">No increment records found.</td></tr>
                    <?php else: ?>
                    <?php foreach ($increments as $i => $inc):
                        $diff = (float)$inc['new_salary'] - (float)$inc['previous_salary'];
                        $pct  = $inc['previous_salary'] > 0
                              ? round($diff / $inc['previous_salary'] * 100, 2) : 0;
                    ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td>
                            <div class="fw-semibold"><?= h($inc['emp_name']) ?></div>
                            <small class="text-muted"><?= h($inc['emp_code']) ?></small>
                        </td>
                        <td><?= h($inc['dept_name'] ?? '—') ?></td>
                        <td><?= date('d M Y', strtotime($inc['effective_date'])) ?></td>
                        <td class="text-end"><?= money($inc['previous_salary']) ?></td>
                        <td class="text-end fw-semibold"><?= money($inc['new_salary']) ?></td>
                        <td class="text-end text-success fw-semibold">+<?= money($diff) ?></td>
                        <td class="text-center"><span class="badge bg-success"><?= $pct ?>%</span></td>
                        <td class="small text-muted"><?= h($inc['remarks'] ?? '—') ?></td>
                        <td class="text-center">
                            <a href="<?= BASE_URL ?>/modules/employee/view.php?id=<?= $inc['employee_id'] ?>#increments"
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
