<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('documents', 'create');
verify_csrf($_POST['csrf_token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . '/modules/employee/index.php');

$db     = db();
$emp_id = (int)($_POST['emp_id'] ?? 0);

// Honour where the upload form was opened from (Documents list vs. employee
// profile) so Upload/errors return the user to the same place.
$from      = ($_POST['from'] ?? '') === 'docs' ? 'docs' : '';
$createUrl = BASE_URL . '/modules/documents/create.php?emp_id=' . $emp_id . ($from ? '&from=docs' : '');
$returnUrl = $from === 'docs'
    ? BASE_URL . '/modules/documents/index.php?emp=' . $emp_id
    : BASE_URL . '/modules/employee/view.php?id=' . $emp_id . '#documents';

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

$errors = [];
$document_type = trim($_POST['document_type'] ?? '');
$document_name = trim($_POST['document_name'] ?? '');
$description   = trim($_POST['description'] ?? '') ?: null;
$file          = $_FILES['document_file'] ?? null;

if (!$document_type) $errors[] = 'Document Type is required.';
if (!$document_name) $errors[] = 'Document Name is required.';
if (!$emp_id)        $errors[] = 'Invalid employee.';

if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
    $errors[] = 'Please select a valid file to upload.';
}

$max_size = 5 * 1024 * 1024; // 5 MB

/* ── Size only; the TYPE is decided by upload_file() ──────────────────────────
 * This used to read the extension straight off $file['name'] — a value the
 * client supplies and can say anything — check it against a list, and then reuse
 * that same untrusted extension as the one it saved under. Nothing ever looked
 * at what the file actually contained. upload_file() sniffs the real MIME type
 * and derives the extension from THAT, so a file can only be stored as what it
 * genuinely is (security audit L-2). */
if (!$errors && $file['size'] > $max_size) $errors[] = 'File size exceeds 5 MB limit.';

if ($errors) {
    $_SESSION['errors']   = $errors;
    $_SESSION['form_old'] = $_POST;
    redirect($createUrl);
}

// Store file — MIME-verified, extension derived from content, is_uploaded_file()
// checked, directory created with the same permissions as before.
$hrmsRoot  = dirname(__DIR__, 2); // modules/documents/../../ = hrms root
$relDir    = 'uploads/employee_docs/' . $emp_id;
$fileName  = upload_file($file, $hrmsRoot . '/' . $relDir,
                         date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_',
                         upload_document_ext_map(), $max_size);

if ($fileName === null) {
    $_SESSION['errors'] = ['That file could not be accepted. Allowed documents are PDF, JPG, PNG '
                         . 'and Word (.doc/.docx) — and the file must genuinely be one of those, '
                         . 'not merely named like one.'];
    $_SESSION['form_old'] = $_POST;
    redirect($createUrl);
}
$filePath = $relDir . '/' . $fileName;

$db->prepare('INSERT INTO employee_documents (employee_id,document_type,document_name,file_path,file_size,description) VALUES (?,?,?,?,?,?)')
   ->execute([$emp_id, $document_type, $document_name, $filePath, (int)$file['size'], $description]);

flash('success', 'Document uploaded successfully.');
redirect($returnUrl);
