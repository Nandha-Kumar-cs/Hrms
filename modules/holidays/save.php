<?php
/**
 * Create or update a holiday. POST → redirect → GET (PRG).
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
verify_csrf($_POST['csrf_token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL . '/modules/holidays/index.php');
}

$db   = db();
$id   = (int)($_POST['id'] ?? 0);
$year = (int)($_POST['year'] ?? date('Y'));

// Permission: editing needs edit, creating needs create.
require_permission('holidays', $id ? 'edit' : 'create');

$date     = sanitize($_POST['h_date'] ?? '');
$name     = sanitize($_POST['name'] ?? '');
$typeId   = (int)($_POST['holiday_type_id'] ?? 0) ?: null;
$source   = in_array($_POST['source'] ?? '', ['manual', 'national', 'import'], true) ? $_POST['source'] : 'manual';
$backYear = $year ?: ($date ? (int)substr($date, 0, 4) : (int)date('Y'));
$back     = BASE_URL . '/modules/holidays/index.php?year=' . $backYear;

// ── Validation ───────────────────────────────────────────────────────────────
$errors = [];
$d = DateTime::createFromFormat('Y-m-d', $date);
if (!$date || !$d || $d->format('Y-m-d') !== $date) $errors[] = 'A valid date is required.';
if ($name === '')          $errors[] = 'Holiday name is required.';
if (mb_strlen($name) > 120) $errors[] = 'Holiday name must be 120 characters or fewer.';

// Holiday type must exist (and resolve to a legacy ENUM value for back-compat).
$legacyType = null; // stays NULL → DB keeps its default / existing value
if ($typeId) {
    $t = $db->prepare('SELECT name FROM holiday_types WHERE id = ?');
    $t->execute([$typeId]);
    $typeName = $t->fetchColumn();
    if ($typeName === false) { $errors[] = 'Selected holiday type is invalid.'; }
    elseif (in_array($typeName, ['National', 'Optional', 'Company'], true)) { $legacyType = $typeName; }
}

// Date must be unique across holidays (the table has a UNIQUE key on h_date).
if (!$errors) {
    $dup = $db->prepare('SELECT id FROM holidays WHERE h_date = ? AND id <> ?');
    $dup->execute([$date, $id]);
    if ($dup->fetchColumn()) $errors[] = 'A holiday already exists on ' . date_fmt($date) . '.';
}

if ($errors) {
    flash('error', implode(' ', $errors));
    $_SESSION['holiday_old'] = $_POST;
    redirect($back . ($id ? '&edit=' . $id : ''));
}

// ── Persist ──────────────────────────────────────────────────────────────────
if ($id) {
    // Only overwrite the legacy ENUM when the new type maps cleanly to it.
    if ($legacyType !== null) {
        $db->prepare('UPDATE holidays SET h_date=?, name=?, holiday_type_id=?, type=? WHERE id=?')
           ->execute([$date, $name, $typeId, $legacyType, $id]);
    } else {
        $db->prepare('UPDATE holidays SET h_date=?, name=?, holiday_type_id=? WHERE id=?')
           ->execute([$date, $name, $typeId, $id]);
    }
    flash('success', 'Holiday updated.');
} else {
    $db->prepare('INSERT INTO holidays (name, h_date, type, holiday_type_id, source) VALUES (?,?,?,?,?)')
       ->execute([$name, $date, $legacyType ?? 'National', $typeId, $source]);
    flash('success', 'Holiday added.');
}

redirect($back);
