<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('employee');
verify_csrf($_POST['csrf_token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . '/modules/employee/index.php');

$db     = db();
$emp_id = (int)($_POST['emp_id'] ?? 0);

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

$allowed_ext  = ['pdf','jpg','jpeg','png','doc','docx'];
$max_size     = 5 * 1024 * 1024; // 5 MB

if (!$errors) {
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_ext)) $errors[] = 'File type not allowed. Use: ' . implode(', ', $allowed_ext);
    if ($file['size'] > $max_size)     $errors[] = 'File size exceeds 5 MB limit.';
}

if ($errors) {
    $_SESSION['errors']   = $errors;
    $_SESSION['form_old'] = $_POST;
    redirect(BASE_URL . '/modules/documents/create.php?emp_id=' . $emp_id);
}

// Store file
$hrmsRoot = dirname(__DIR__, 2); // modules/documents/../../ = hrms root
$uploadDir = $hrmsRoot . '/uploads/employee_docs/' . $emp_id . '/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

$ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$fileName = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$filePath = 'uploads/employee_docs/' . $emp_id . '/' . $fileName;

if (!move_uploaded_file($file['tmp_name'], $hrmsRoot . '/' . $filePath)) {
    flash('error', 'File upload failed. Please try again.');
    redirect(BASE_URL . '/modules/documents/create.php?emp_id=' . $emp_id);
}

$db->prepare('INSERT INTO employee_documents (employee_id,document_type,document_name,file_path,file_size,description) VALUES (?,?,?,?,?,?)')
   ->execute([$emp_id, $document_type, $document_name, $filePath, (int)$file['size'], $description]);

flash('success', 'Document uploaded successfully.');
redirect(BASE_URL . '/modules/employee/view.php?id=' . $emp_id . '#documents');
