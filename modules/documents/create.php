<?php
$page_title = 'Upload Document';
require_once __DIR__ . '/../../includes/header.php';
require_permission('documents', 'create');

$db     = db();
$emp_id = (int)($_GET['emp_id'] ?? 0);
if (!$emp_id) redirect(BASE_URL . '/modules/employee/index.php');

$emp = $db->prepare('SELECT e.*, d.name AS dept_name, des.name AS desig_name FROM employees e LEFT JOIN departments d ON d.id=e.department_id LEFT JOIN designations des ON des.id=e.designation_id WHERE e.id=?');
$emp->execute([$emp_id]);
$e = $emp->fetch();
if (!$e) redirect(BASE_URL . '/modules/employee/index.php');

$old = $_SESSION['form_old'] ?? [];
unset($_SESSION['form_old']);

$docTypes = ['Aadhaar Card','PAN Card','Passport','Driving License','Offer Letter','Appointment Letter','Experience Letter','Education Certificate','Other'];
?>

<div class="row justify-content-center">
<div class="col-xl-7 col-lg-9">

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 d-flex align-items-center gap-2">
        <a href="<?= BASE_URL ?>/modules/employee/view.php?id=<?= $emp_id ?>#documents" class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-arrow-left"></i>
        </a>
        <div>
            <h5 class="mb-0 fw-semibold">Upload Document</h5>
            <small class="text-muted"><?= h($e['name']) ?> &bull; <?= h($e['desig_name'] ?? '') ?></small>
        </div>
    </div>
    <div class="card-body">

        <?php if (!empty($_SESSION['errors'])): ?>
        <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($_SESSION['errors'] as $err): ?><li><?= h($err) ?></li><?php endforeach; ?></ul></div>
        <?php unset($_SESSION['errors']); endif; ?>

        <form method="POST" action="<?= BASE_URL ?>/modules/documents/save.php" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="emp_id" value="<?= $emp_id ?>">

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Document Type <span class="text-danger">*</span></label>
                    <select name="document_type" class="form-select" required>
                        <option value="">Select type</option>
                        <?php foreach ($docTypes as $dt): ?>
                        <option value="<?= h($dt) ?>" <?= ($old['document_type'] ?? '') === $dt ? 'selected' : '' ?>><?= h($dt) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Document Name <span class="text-danger">*</span></label>
                    <input type="text" name="document_name" class="form-control" value="<?= h($old['document_name'] ?? '') ?>" placeholder="e.g. Aadhaar Front" required>
                </div>
                <div class="col-12">
                    <label class="form-label">File <span class="text-danger">*</span></label>
                    <input type="file" name="document_file" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" required>
                    <div class="form-text">Accepted: PDF, JPG, PNG, DOC, DOCX — max 5 MB</div>
                </div>
                <div class="col-12">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="2" placeholder="Optional description…"><?= h($old['description'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="fa fa-upload me-1"></i>Upload Document</button>
                <a href="<?= BASE_URL ?>/modules/employee/view.php?id=<?= $emp_id ?>#documents" class="btn btn-light">Cancel</a>
            </div>
        </form>

    </div>
</div>
</div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
