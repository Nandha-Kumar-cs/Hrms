<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('bonuses', 'create');
verify_csrf($_POST['csrf_token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . '/modules/bonuses/index.php');

$db = db();
$id = (int)($_POST['id'] ?? 0);

if ($id) {
    $st = $db->prepare('SELECT eb.*, e.name AS emp_name FROM employee_bonuses eb JOIN employees e ON e.id = eb.employee_id WHERE eb.id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if ($row) {
        $db->prepare('DELETE FROM employee_bonuses WHERE id = ?')->execute([$id]);
        if (function_exists('activity_log')) activity_log('deleted', 'Bonus', 'Deleted ' . ucfirst($row['type']) . ' for ' . $row['emp_name']);
        flash('success', 'Bonus / incentive deleted.');
    } else {
        flash('error', 'Record not found.');
    }
}
redirect(BASE_URL . '/modules/bonuses/index.php');
