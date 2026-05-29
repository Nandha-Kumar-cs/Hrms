<?php
/**
 * Assets POST handler — add / edit / delete
 *
 * How CSRF protection works:
 *   1. csrf_field() in your form renders:
 *        <input type="hidden" name="csrf_token" value="...token...">
 *   2. verify_csrf() here checks that token against $_SESSION['csrf_token'].
 *      On mismatch it flashes an error and redirects back — no DB work happens.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('assets', 'assign');

// ── CSRF check — must be the very first thing after auth ─────────────────────
verify_csrf($_POST['csrf_token'] ?? '');

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL . '/modules/assets/index.php');
}

$action = sanitize($_POST['action'] ?? '');
$db     = db();

// ============================================================================
// DELETE
// ============================================================================
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) {
        flash('error', 'Invalid asset ID.');
        redirect(BASE_URL . '/modules/assets/index.php');
    }

    // Prevent deleting an asset that is currently assigned
    $isAssigned = $db->prepare(
        "SELECT 1 FROM asset_assignments WHERE asset_id = ? AND is_returned = 0 LIMIT 1"
    );
    $isAssigned->execute([$id]);
    if ($isAssigned->fetchColumn()) {
        flash('error', 'Cannot delete an asset that is currently assigned. Process the return first.');
        redirect(BASE_URL . '/modules/assets/index.php');
    }

    $db->prepare('DELETE FROM asset_assignments WHERE asset_id = ?')->execute([$id]);
    $db->prepare('DELETE FROM assets WHERE id = ?')->execute([$id]);

    flash('success', 'Asset deleted successfully.');
    redirect(BASE_URL . '/modules/assets/index.php');
}

// ============================================================================
// Shared input collection & validation (add + edit)
// ============================================================================
$name         = sanitize($_POST['name'] ?? '');
$categoryId   = (int)($_POST['category_id'] ?? 0);
$assetCode    = sanitize($_POST['asset_code'] ?? '');
$serialNo     = sanitize($_POST['serial_no'] ?? '');
$brand        = sanitize($_POST['brand'] ?? '');
$model        = sanitize($_POST['model'] ?? '');
$purchaseDate = $_POST['purchase_date'] ?? null;
$purchaseCost = $_POST['purchase_cost'] !== '' ? (float)$_POST['purchase_cost'] : null;
$condition    = sanitize($_POST['condition'] ?? 'Good');
$status       = sanitize($_POST['status'] ?? 'Available');
$notes        = sanitize($_POST['notes'] ?? '');

// Map submitted status values to the exact ENUM expected by the DB
$validStatuses = ['Available', 'Assigned', 'Under Repair', 'Retired'];
if (!in_array($status, $validStatuses, true)) {
    $status = 'Available';
}

$validConditions = ['New', 'Good', 'Fair', 'Poor'];
if (!in_array($condition, $validConditions, true)) {
    $condition = 'Good';
}

// Sanitise date fields — store NULL if empty
$purchaseDate = $purchaseDate ?: null;

// Validation
$errors = [];
if ($name === '')      $errors[] = 'Asset name is required.';
if ($assetCode === '') $errors[] = 'Asset code is required.';
if ($categoryId < 1)  $errors[] = 'Please select a category.';

if ($errors) {
    $_SESSION['errors']   = $errors;
    $_SESSION['form_old'] = $_POST;
    $back = ($action === 'edit')
        ? BASE_URL . '/modules/assets/edit_asset.php?id=' . (int)($_POST['id'] ?? 0)
        : BASE_URL . '/modules/assets/add_asset.php';
    redirect($back);
}

// ============================================================================
// ADD
// ============================================================================
if ($action === 'add') {
    // Duplicate asset_code check
    $exists = $db->prepare('SELECT 1 FROM assets WHERE asset_code = ? LIMIT 1');
    $exists->execute([$assetCode]);
    if ($exists->fetchColumn()) {
        $_SESSION['errors']   = ["Asset code '$assetCode' is already in use. Please choose another."];
        $_SESSION['form_old'] = $_POST;
        redirect(BASE_URL . '/modules/assets/add_asset.php');
    }

    $stmt = $db->prepare(
        "INSERT INTO assets
            (asset_code, name, category_id, brand, model, serial_no,
             purchase_date, purchase_cost, `condition`, status, notes, created_at)
         VALUES
            (:code, :name, :cat, :brand, :model, :serial,
             :pd, :pc, :cond, :status, :notes, NOW())"
    );
    $stmt->execute([
        ':code'   => $assetCode,
        ':name'   => $name,
        ':cat'    => $categoryId ?: null,
        ':brand'  => $brand  ?: null,
        ':model'  => $model  ?: null,
        ':serial' => $serialNo ?: null,
        ':pd'     => $purchaseDate,
        ':pc'     => $purchaseCost,
        ':cond'   => $condition,
        ':status' => 'Available',       // new assets always start as Available
        ':notes'  => $notes ?: null,
    ]);

    flash('success', "Asset '$name' added successfully.");
    redirect(BASE_URL . '/modules/assets/index.php');
}

// ============================================================================
// EDIT
// ============================================================================
if ($action === 'edit') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) {
        flash('error', 'Invalid asset ID.');
        redirect(BASE_URL . '/modules/assets/index.php');
    }

    // Make sure asset exists
    $check = $db->prepare('SELECT id FROM assets WHERE id = ? LIMIT 1');
    $check->execute([$id]);
    if (!$check->fetchColumn()) {
        flash('error', 'Asset not found.');
        redirect(BASE_URL . '/modules/assets/index.php');
    }

    // Duplicate code check (exclude self)
    $dup = $db->prepare('SELECT 1 FROM assets WHERE asset_code = ? AND id != ? LIMIT 1');
    $dup->execute([$assetCode, $id]);
    if ($dup->fetchColumn()) {
        $_SESSION['errors']   = ["Asset code '$assetCode' is already used by another asset."];
        $_SESSION['form_old'] = $_POST;
        redirect(BASE_URL . '/modules/assets/edit_asset.php?id=' . $id);
    }

    $stmt = $db->prepare(
        "UPDATE assets SET
            asset_code    = :code,
            name          = :name,
            category_id   = :cat,
            brand         = :brand,
            model         = :model,
            serial_no     = :serial,
            purchase_date = :pd,
            purchase_cost = :pc,
            `condition`   = :cond,
            status        = :status,
            notes         = :notes
         WHERE id = :id"
    );
    $stmt->execute([
        ':code'   => $assetCode,
        ':name'   => $name,
        ':cat'    => $categoryId ?: null,
        ':brand'  => $brand  ?: null,
        ':model'  => $model  ?: null,
        ':serial' => $serialNo ?: null,
        ':pd'     => $purchaseDate,
        ':pc'     => $purchaseCost,
        ':cond'   => $condition,
        ':status' => $status,
        ':notes'  => $notes ?: null,
        ':id'     => $id,
    ]);

    flash('success', "Asset '$name' updated successfully.");
    redirect(BASE_URL . '/modules/assets/index.php');
}

// Unknown action
flash('error', 'Unknown action.');
redirect(BASE_URL . '/modules/assets/index.php');
