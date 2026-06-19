<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('documents', 'delete');
verify_csrf($_POST['csrf_token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . '/modules/documents/index.php');

$db = db();
$id = (int)($_POST['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/documents/index.php');

$doc = $db->prepare('SELECT * FROM employee_documents WHERE id = ?');
$doc->execute([$id]);
$row = $doc->fetch();

if (!$row) {
    flash('error', 'Document not found.');
    redirect(BASE_URL . '/modules/documents/index.php');
}

// Remove the stored file (best-effort) then the DB row.
$full = dirname(__DIR__, 2) . '/' . $row['file_path'];
if (is_file($full)) @unlink($full);

$db->prepare('DELETE FROM employee_documents WHERE id = ?')->execute([$id]);
flash('success', 'Document deleted.');

// Return to wherever the user came from when it's an employee profile, else the list.
$ref = $_SERVER['HTTP_REFERER'] ?? '';
if ($ref && strpos($ref, 'employee/view.php') !== false) {
    redirect(BASE_URL . '/modules/employee/view.php?id=' . (int)$row['employee_id'] . '#documents');
}
redirect(BASE_URL . '/modules/documents/index.php');
