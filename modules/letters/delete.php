<?php
require_once '../../includes/bootstrap.php';
require_login();
verify_csrf($_POST['csrf_token'] ?? '');

$id = (int)($_POST['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/letters/index.php');

$letter = db()->query("SELECT * FROM letters WHERE id=$id")->fetch(PDO::FETCH_ASSOC);
if (!$letter) {
    flash('danger', 'Letter not found.');
    redirect(BASE_URL . '/modules/letters/index.php');
}

// Admins may delete any letter (including Issued/Acknowledged).
// Everyone else needs the delete permission AND the letter must still be a Draft.
if (is_admin()) {
    $allowed = true;
} elseif ($letter['status'] === 'Draft' && can('letters', 'delete')) {
    $allowed = true;
} else {
    $allowed = false;
}

if ($allowed) {
    db()->prepare('DELETE FROM letters WHERE id = ?')->execute([$id]);
    flash('success', 'Letter deleted.');
} elseif ($letter['status'] !== 'Draft') {
    flash('danger', 'Only an admin can delete an issued letter.');
} else {
    flash('danger', 'You do not have permission to delete this letter.');
}

redirect(BASE_URL . '/modules/letters/index.php');
