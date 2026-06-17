<?php
/**
 * Toggle a holiday's is_working_day flag. POST → redirect → GET (PRG).
 *
 * A "working day" holiday is treated as a regular working day by the
 * attendance report / payroll (comp-off earner). See includes/PayrollCalculator.php
 * and modules/attendance/report.php for how the flag is consumed.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('holidays', 'edit');
verify_csrf($_POST['csrf_token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL . '/modules/holidays/index.php');
}

$db   = db();
$id   = (int)($_POST['id'] ?? 0);
$year = (int)($_POST['year'] ?? date('Y'));
$back = BASE_URL . '/modules/holidays/index.php?year=' . $year;

if (!$id) {
    redirect($back);
}

$row = $db->prepare('SELECT name, is_working_day FROM holidays WHERE id = ?');
$row->execute([$id]);
$hol = $row->fetch();
if (!$hol) {
    flash('error', 'Holiday not found.');
    redirect($back);
}

$new = $hol['is_working_day'] ? 0 : 1;
$db->prepare('UPDATE holidays SET is_working_day = ? WHERE id = ?')->execute([$new, $id]);
flash('success', '"' . $hol['name'] . '" marked as ' . ($new ? 'a working day.' : 'a holiday.'));
redirect($back);
