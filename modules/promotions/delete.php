<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('promotions', 'create');
verify_csrf($_POST['csrf_token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . '/modules/promotions/index.php');

$db = db();
$id = (int)($_POST['id'] ?? 0);

if ($id) {
    $st = $db->prepare('SELECT ep.*, e.name AS emp_name FROM employee_promotions ep JOIN employees e ON e.id = ep.employee_id WHERE ep.id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if ($row) {
        $db->prepare('DELETE FROM employee_promotions WHERE id = ?')->execute([$id]);
        if (function_exists('activity_log')) activity_log('deleted', 'Promotion', 'Deleted promotion for ' . $row['emp_name']);
        flash('success', 'Promotion deleted.');
    } else {
        flash('error', 'Record not found.');
    }
}
redirect(BASE_URL . '/modules/promotions/index.php');
