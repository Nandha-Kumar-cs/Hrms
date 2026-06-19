<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('loans', 'create');
verify_csrf($_POST['csrf_token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . '/modules/loans/index.php');

$db = db();
$id = (int)($_POST['id'] ?? 0);

if ($id) {
    $st = $db->prepare('SELECT el.*, e.name AS emp_name FROM employee_loans el JOIN employees e ON e.id = el.employee_id WHERE el.id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if ($row) {
        $db->prepare('DELETE FROM employee_loans WHERE id = ?')->execute([$id]);
        if (function_exists('activity_log')) activity_log('deleted', 'Loan', 'Deleted ' . $row['type'] . ' of ' . money((float)$row['amount']) . ' for ' . $row['emp_name']);
        flash('success', 'Loan / advance deleted.');
    } else {
        flash('error', 'Record not found.');
    }
}
redirect(BASE_URL . '/modules/loans/index.php');
