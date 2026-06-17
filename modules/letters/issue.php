<?php
require_once '../../includes/bootstrap.php';
require_login();
require_permission('letters', 'edit');

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/letters/index.php');

$letter = db()->query("SELECT * FROM letters WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
if (!$letter || $letter['status'] !== 'Draft') {
    flash('danger','Letter not found or already issued.');
    redirect(BASE_URL . '/modules/letters/index.php');
}

db()->prepare("UPDATE letters SET status='Issued', issued_by=:uid WHERE id=:id")
     ->execute([':uid'=>current_user()['id'],':id'=>$id]);

flash('success','Letter issued successfully.');
redirect(BASE_URL . "/modules/letters/view.php?id=$id");
