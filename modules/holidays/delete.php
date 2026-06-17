<?php
/**
 * Delete a holiday. POST → redirect → GET (PRG).
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('holidays', 'delete');
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

$row = $db->prepare('SELECT id FROM holidays WHERE id = ?');
$row->execute([$id]);
if (!$row->fetchColumn()) {
    flash('error', 'Holiday not found.');
    redirect($back);
}

$db->prepare('DELETE FROM holidays WHERE id = ?')->execute([$id]);
flash('success', 'Holiday deleted.');
redirect($back);
