<?php
$page_title = 'Promotion History';
require_once __DIR__ . '/../../includes/header.php';
require_permission('promotions', 'view');

$db = db();

$db->exec('CREATE TABLE IF NOT EXISTS employee_promotions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    effective_date DATE NOT NULL,
    previous_designation_id INT NULL,
    new_designation_id INT NOT NULL,
    department_id INT NULL,
    remarks TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$filterEmp = (int)($_GET['employee_id'] ?? 0);
 if (is_self_scoped()) $filterEmp = current_employee_id();   // self-scope: own records only

$sql    = 'SELECT ep.*,
                  e.name AS emp_name, e.employee_id AS emp_code,
                  pd.name AS prev_desig, nd.name AS new_desig,
                  d.name AS dept_name
           FROM employee_promotions ep
           JOIN employees e ON e.id = ep.employee_id
           LEFT JOIN designations pd ON pd.id = ep.previous_designation_id
           LEFT JOIN designations nd ON nd.id = ep.new_designation_id
           LEFT JOIN departments  d  ON d.id  = ep.department_id
           WHERE 1=1';
$params = [];
if ($filterEmp) { $sql .= ' AND ep.employee_id = ?'; $params[] = $filterEmp; }
$sql .= ' ORDER BY ep.effective_date DESC, ep.created_at DESC';

$stmt = $db->prepare($sql);
$stmt->execute($params);
$promotions = $stmt->fetchAll();

$employees = scope_employees_for_dropdown($db);
?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
        <h5 class="mb-0 fw-semibold">Promotion History</h5>
        <?php if (can('letters','create')): ?>
        <!-- Single creation workflow: promotions are created via the promotion letter. -->
        <a href="<?= BASE_URL ?>/modules/letters/create.php?type=Promotion" class="btn btn-sm btn-primary">
            <i class="fa fa-plus me-1"></i>Add Promotion
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
                        <th>Effective Date</th>
                        <th>Previous Designation</th>
                        <th>New Designation</th>
                        <th>Department</th>
                        <th>Remarks</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($promotions)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">No promotion records found.</td></tr>
                    <?php else: ?>
                    <?php foreach ($promotions as $i => $p): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td>
                            <div class="fw-semibold"><?= h($p['emp_name']) ?></div>
                            <small class="text-muted"><?= h($p['emp_code']) ?></small>
                        </td>
                        <td><?= date('d M Y', strtotime($p['effective_date'])) ?></td>
                        <td class="text-muted"><?= h($p['prev_desig'] ?? '—') ?></td>
                        <td><strong><?= h($p['new_desig'] ?? '—') ?></strong></td>
                        <td><?= h($p['dept_name'] ?? '—') ?></td>
                        <td class="small text-muted"><?= h($p['remarks'] ?? '—') ?></td>
                        <td class="text-center text-nowrap">
                            <?php
                            $proDetail = [
                                'Employee'             => $p['emp_name'] . ' (' . $p['emp_code'] . ')',
                                'Effective Date'       => date('d M Y', strtotime($p['effective_date'])),
                                'Promotion Date'       => !empty($p['promotion_date']) ? date('d M Y', strtotime($p['promotion_date'])) : '—',
                                'Previous Designation' => $p['prev_desig'] ?? '—',
                                'New Designation'      => $p['new_desig'] ?? '—',
                                'Department'           => $p['dept_name'] ?? '—',
                                'Salary Revision'      => ($p['salary_revision'] !== null && $p['salary_revision'] !== '') ? money($p['salary_revision']) : '—',
                                'Status'               => $p['status'] ?? '—',
                                'Letter Reference'     => $p['letter_reference'] ?? '—',
                                'Remarks'              => $p['remarks'] ?? '—',
                            ];
                            ?>
                            <button type="button" class="btn btn-xs btn-outline-info" title="View Details"
                                    data-bs-toggle="modal" data-bs-target="#recModal"
                                    data-rec-title="Promotion — <?= h($p['emp_name']) ?>"
                                    data-rec="<?= h(json_encode($proDetail)) ?>"><i class="fa fa-eye"></i></button>
                            <?php if (can('promotions','create')): ?>
                            <a href="<?= BASE_URL ?>/modules/promotions/create.php?id=<?= $p['id'] ?>"
                               class="btn btn-xs btn-outline-primary" title="Edit"><i class="fa fa-pen"></i></a>
                            <form method="POST" action="<?= BASE_URL ?>/modules/promotions/delete.php" class="d-inline"
                                  onsubmit="return confirm('Delete this promotion record?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $p['id'] ?>">
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

<?php include __DIR__ . '/../../includes/record_modal.php'; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
