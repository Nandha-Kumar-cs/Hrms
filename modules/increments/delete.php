<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('increments', 'create');
verify_csrf($_POST['csrf_token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . '/modules/increments/index.php');

$db = db();
$id = (int)($_POST['id'] ?? 0);

if ($id) {
    $st = $db->prepare('SELECT ei.*, e.name AS emp_name FROM employee_increments ei JOIN employees e ON e.id = ei.employee_id WHERE ei.id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if ($row) {
        $db->prepare('DELETE FROM employee_increments WHERE id = ?')->execute([$id]);
        // Re-sync current salary to the now-latest remaining increment.
        sync_current_salary_from_increments($db, (int)$row['employee_id']);
        if (function_exists('activity_log')) activity_log('deleted', 'Increment', 'Deleted increment for ' . $row['emp_name']);
        flash('success', 'Increment deleted.');
    } else {
        flash('error', 'Record not found.');
    }
}
redirect(BASE_URL . '/modules/increments/index.php');
