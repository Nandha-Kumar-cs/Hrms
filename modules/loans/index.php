<?php
$page_title = 'Loans & Advances';
require_once __DIR__ . '/../../includes/header.php';
require_permission('loans', 'view');
require_once __DIR__ . '/../../includes/loan_history.php';

$db = db();

$db->exec('CREATE TABLE IF NOT EXISTS employee_loans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    type ENUM(\'loan\',\'advance\') DEFAULT \'loan\',
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    interest_rate DECIMAL(5,2) DEFAULT 0,
    date_given DATE NOT NULL,
    monthly_deduction DECIMAL(10,2) DEFAULT 0,
    returned_amount DECIMAL(10,2) DEFAULT 0,
    status ENUM(\'active\',\'closed\') DEFAULT \'active\',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$filterEmp    = (int)($_GET['employee_id'] ?? 0);
 if (is_self_scoped()) $filterEmp = current_employee_id();   // self-scope: own records only
$filterType   = trim($_GET['type']   ?? '');
$filterStatus = trim($_GET['status'] ?? '');

$sql    = 'SELECT el.*, e.name AS emp_name, e.employee_id AS emp_code
           FROM employee_loans el
           JOIN employees e ON e.id = el.employee_id
           WHERE 1=1';
$params = [];
if ($filterEmp)    { $sql .= ' AND el.employee_id = ?'; $params[] = $filterEmp; }
if ($filterType)   { $sql .= ' AND el.type = ?';        $params[] = $filterType; }
if ($filterStatus) { $sql .= ' AND el.status = ?';      $params[] = $filterStatus; }
$sql .= ' ORDER BY el.date_given DESC, el.created_at DESC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$loans = $stmt->fetchAll();

$employees = scope_employees_for_dropdown($db);
?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
        <h5 class="mb-0 fw-semibold"><i class="fa fa-hand-holding-dollar me-2 text-primary"></i>Loans &amp; Advances</h5>
        <?php if (can('loans','create')): ?>
        <a href="<?= BASE_URL ?>/modules/loans/create.php" class="btn btn-sm btn-primary">
            <i class="fa fa-plus me-1"></i>Add Loan / Advance
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
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Type</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="loan"    <?= $filterType === 'loan'    ? 'selected' : '' ?>>Loan</option>
                    <option value="advance" <?= $filterType === 'advance' ? 'selected' : '' ?>>Advance</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="closed" <?= $filterStatus === 'closed' ? 'selected' : '' ?>>Closed</option>
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
            <table class="table table-hover table-bordered align-middle mb-0" style="font-size:.87rem">
                <thead class="table-dark">
                    <tr>
                        <th>#</th>
                        <th>Employee</th>
                        <th>Type</th>
                        <th class="text-end">Principal</th>
                        <th class="text-end">Interest</th>
                        <th class="text-end">Total Due</th>
                        <th class="text-end">Monthly EMI</th>
                        <th class="text-end">Returned</th>
                        <th class="text-end">Pending</th>
                        <th>Status</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($loans)): ?>
                    <tr><td colspan="11" class="text-center text-muted py-4">No loan / advance records found.</td></tr>
                    <?php else: ?>
                    <?php foreach ($loans as $i => $loan):
                        // Returned/Pending/Status derived from the actual salary-slip
                        // deductions (and lazily synced back to the loan row).
                        $fig         = loan_figures($db, $loan);
                        $interest    = $fig['interest'];
                        $totalDue    = $fig['total_due'];
                        $returned    = $fig['returned'];
                        $pending     = $fig['pending'];
                        $loanStatus  = $fig['status'];
                        $statusBadge = ['active' => 'success', 'completed' => 'primary'][$loanStatus] ?? 'secondary';
                    ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td>
                            <div class="fw-semibold"><?= h($loan['emp_name']) ?></div>
                            <small class="text-muted"><?= h($loan['emp_code']) ?></small>
                        </td>
                        <td>
                            <span class="badge bg-<?= $loan['type'] === 'loan' ? 'primary' : 'info' ?>">
                                <?= ucfirst($loan['type']) ?>
                            </span>
                        </td>
                        <td class="text-end"><?= money($loan['amount']) ?></td>
                        <td class="text-end"><?= $interest > 0 ? '<span class="text-warning">' . money($interest) . '</span>' : '<span class="text-muted">—</span>' ?></td>
                        <td class="text-end fw-semibold"><?= money($totalDue) ?></td>
                        <td class="text-end"><?= money($loan['monthly_deduction']) ?></td>
                        <td class="text-end text-success fw-semibold"><?= money($returned) ?></td>
                        <td class="text-end <?= $pending > 0 ? 'text-danger fw-semibold' : 'text-success' ?>">
                            <?= money($pending) ?>
                        </td>
                        <td>
                            <span class="badge bg-<?= $statusBadge ?>">
                                <?= ucfirst($loanStatus) ?>
                            </span>
                        </td>
                        <td class="text-center text-nowrap">
                            <a href="<?= BASE_URL ?>/modules/loans/show.php?id=<?= $loan['id'] ?>"
                               class="btn btn-xs btn-outline-info" title="Loan History"><i class="fa fa-history"></i></a>
                            <?php if (can('loans','create')): ?>
                            <a href="<?= BASE_URL ?>/modules/loans/create.php?id=<?= $loan['id'] ?>"
                               class="btn btn-xs btn-outline-primary" title="Edit"><i class="fa fa-pen"></i></a>
                            <form method="POST" action="<?= BASE_URL ?>/modules/loans/delete.php" class="d-inline"
                                  onsubmit="return confirm('Delete this loan / advance record?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $loan['id'] ?>">
                                <button type="submit" class="btn btn-xs btn-outline-danger" title="Delete"><i class="fa fa-trash"></i></button>
                            </form>
                            <?php endif; ?>
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
