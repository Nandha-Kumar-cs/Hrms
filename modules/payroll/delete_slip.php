<?php
/**
 * Delete a salary slip (POST only)
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('payroll', 'process');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    flash('error', 'Invalid request.');
    redirect(BASE_URL . '/modules/payroll/index.php');
}

$slipId = (int)($_POST['slip_id'] ?? 0);
if (!$slipId) {
    flash('error', 'Invalid slip ID.');
    redirect(BASE_URL . '/modules/payroll/index.php');
}

$db   = db();
$slip = $db->prepare('SELECT * FROM salary_slips WHERE id = ? LIMIT 1');
$slip->execute([$slipId]);
$s = $slip->fetch();

if (!$s) {
    flash('error', 'Salary slip not found.');
    redirect(BASE_URL . '/modules/payroll/index.php');
}

$db->prepare('DELETE FROM salary_slips WHERE id = ?')->execute([$slipId]);

flash('success', 'Salary slip deleted.');
$returnMonth = $s['payroll_month'] ?? date('Y-m');
redirect(BASE_URL . '/modules/payroll/index.php?month=' . $returnMonth);
