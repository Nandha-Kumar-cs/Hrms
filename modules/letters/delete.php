<?php
require_once '../../includes/bootstrap.php';
require_login();
require_permission('letters', 'edit');
verify_csrf($_POST['csrf_token'] ?? '');

$id = (int)($_POST['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/letters/index.php');

$letter = db()->query("SELECT * FROM letters WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
if ($letter && $letter['status'] === 'Draft') {
    db()->query("DELETE FROM letters WHERE id=$id");
    flash('success','Letter deleted.');
} else {
    flash('danger','Cannot delete an issued letter.');
}
redirect(BASE_URL . '/modules/letters/index.php');
