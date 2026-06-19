<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('documents', 'view');

$page_title = 'Documents';
require_once __DIR__ . '/../../includes/header.php';

$db = db();

// Table is created lazily on first upload — ensure it exists so this page never errors.
$db->exec('CREATE TABLE IF NOT EXISTS employee_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    document_type VARCHAR(50) NOT NULL,
    document_name VARCHAR(200) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size INT DEFAULT 0,
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

// Optional employee filter
$emp_filter = (int)($_GET['emp'] ?? 0);
// Self-scoped users only ever see their own documents.
if (is_self_scoped()) $emp_filter = current_employee_id();

$sql = 'SELECT dc.*, e.name AS emp_name, e.employee_id AS emp_code
        FROM employee_documents dc
        JOIN employees e ON e.id = dc.employee_id';
$params = [];
if ($emp_filter) { $sql .= ' WHERE dc.employee_id = ?'; $params[] = $emp_filter; }
$sql .= ' ORDER BY dc.created_at DESC';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$docs = $stmt->fetchAll();

$employees = is_self_scoped()
    ? $db->query('SELECT id, name, employee_id FROM employees WHERE id = ' . (int)current_employee_id() . ' ORDER BY name')->fetchAll()
    : $db->query('SELECT id, name, employee_id FROM employees ORDER BY name')->fetchAll();

function doc_size(int $bytes): string {
    if ($bytes <= 0) return '—';
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 2) . ' MB';
}

$docIcons = [
    'pdf'  => 'fa-file-pdf text-danger',
    'doc'  => 'fa-file-word text-primary',
    'docx' => 'fa-file-word text-primary',
    'jpg'  => 'fa-file-image text-success',
    'jpeg' => 'fa-file-image text-success',
    'png'  => 'fa-file-image text-success',
];
?>
<div class="card page-card">
    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <h5 class="mb-0 fw-semibold"><i class="fa fa-folder-open me-2 text-primary"></i>Employee Documents</h5>
        <form method="GET" class="d-flex align-items-center gap-2">
            <select name="emp" class="form-select form-select-sm" style="min-width:240px" onchange="this.form.submit()">
                <option value="0">All Employees</option>
                <?php foreach ($employees as $emp): ?>
                <option value="<?= $emp['id'] ?>" <?= $emp_filter === (int)$emp['id'] ? 'selected' : '' ?>>
                    <?= h($emp['name']) ?> (<?= h($emp['employee_id']) ?>)
                </option>
                <?php endforeach; ?>
            </select>
            <?php if ($emp_filter): ?>
            <a href="<?= BASE_URL ?>/modules/documents/create.php?emp_id=<?= $emp_filter ?>" class="btn btn-primary btn-sm text-nowrap">
                <i class="fa fa-upload me-1"></i>Upload
            </a>
            <?php endif; ?>
        </form>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-bordered align-middle mb-0" id="docTable">
                <thead class="table-dark">
                    <tr>
                        <th style="width:60px">#</th>
                        <th>Employee</th>
                        <th>Type</th>
                        <th>Document</th>
                        <th style="width:100px">Size</th>
                        <th style="width:140px">Uploaded</th>
                        <th class="text-center" style="width:150px">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($docs as $i => $d):
                    $ext = strtolower(pathinfo($d['file_path'], PATHINFO_EXTENSION));
                    $icon = $docIcons[$ext] ?? 'fa-file text-secondary';
                ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td>
                            <a href="<?= BASE_URL ?>/modules/employee/view.php?id=<?= $d['employee_id'] ?>#documents" class="fw-semibold text-decoration-none">
                                <?= h($d['emp_name']) ?>
                            </a>
                            <div class="small text-muted"><?= h($d['emp_code']) ?></div>
                        </td>
                        <td><span class="badge bg-light text-dark border"><?= h($d['document_type']) ?></span></td>
                        <td>
                            <i class="fa <?= $icon ?> me-1"></i><?= h($d['document_name']) ?>
                            <?php if (!empty($d['description'])): ?>
                            <div class="small text-muted"><?= h($d['description']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= doc_size((int)$d['file_size']) ?></td>
                        <td><?= date_fmt($d['created_at']) ?></td>
                        <td class="text-center text-nowrap">
                            <a href="<?= BASE_URL ?>/<?= h($d['file_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary" title="View"><i class="fa fa-eye"></i></a>
                            <a href="<?= BASE_URL ?>/<?= h($d['file_path']) ?>" download class="btn btn-sm btn-outline-secondary" title="Download"><i class="fa fa-download"></i></a>
                            <?php if (can('employee','edit')): ?>
                            <form method="POST" action="<?= BASE_URL ?>/modules/documents/delete.php" class="d-inline" onsubmit="return confirm('Delete this document?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= $d['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa fa-trash"></i></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php $page_scripts = <<<'JS'
<script>
$(function () {
    if ($.fn.DataTable) {
        $('#docTable').DataTable({
            pageLength: 25,
            order: [[5, 'desc']],
            columnDefs: [{ orderable: false, targets: [0, 6] }],
            language: { emptyTable: 'No documents uploaded yet.' }
        });
    }
});
</script>
JS;
include __DIR__ . '/../../includes/footer.php';
?>
