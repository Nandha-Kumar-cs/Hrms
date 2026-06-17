<?php
/**
 * Bulk-import holidays from a CSV file. POST → redirect → GET (PRG).
 *
 * Mirrors the reference (Employee_Management) HolidayImport but stays
 * dependency-free — HRMS ships no spreadsheet library, so we read CSV with
 * fgetcsv() rather than parsing .xlsx/.xls.
 *
 * Expected columns (header row optional — skipped if the first cell isn't numeric):
 *   A = Month (1-12)   B = Day (1-31)   C = Holiday name (optional → "Holiday")
 *
 * The chosen Year and Holiday Type apply to every imported row. Rows are
 * upserted on the UNIQUE h_date key, so re-importing a year is idempotent.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('holidays', 'create');
verify_csrf($_POST['csrf_token'] ?? '');

$year = (int)($_POST['year'] ?? date('Y'));
$back = BASE_URL . '/modules/holidays/index.php?year=' . $year;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect($back);
}

$db = db();

// ── Validate the request ──────────────────────────────────────────────────────
if ($year < 2000 || $year > 2099) {
    flash('error', 'Please choose a year between 2000 and 2099.');
    redirect($back);
}

$file = $_FILES['file'] ?? null;
if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
    flash('error', 'Please choose a CSV file to import.');
    redirect($back);
}
if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
    flash('error', 'The file is too large (max 5 MB).');
    redirect($back);
}
$ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
if (!in_array($ext, ['csv', 'txt'], true)) {
    flash('error', 'Only .csv files are supported. Export your Excel sheet as CSV first.');
    redirect($back);
}

// Resolve the holiday type (and its legacy ENUM equivalent, for back-compat).
$typeId     = (int)($_POST['holiday_type_id'] ?? 0) ?: null;
$legacyType = 'National'; // default for the legacy `type` column
if ($typeId) {
    $t = $db->prepare('SELECT name FROM holiday_types WHERE id = ?');
    $t->execute([$typeId]);
    $typeName = $t->fetchColumn();
    if ($typeName === false) {
        flash('error', 'The selected holiday type no longer exists.');
        redirect($back);
    }
    if (in_array($typeName, ['National', 'Optional', 'Company'], true)) {
        $legacyType = $typeName;
    }
}

// ── Parse the CSV ─────────────────────────────────────────────────────────────
$handle = fopen($file['tmp_name'], 'r');
if ($handle === false) {
    flash('error', 'Could not read the uploaded file.');
    redirect($back);
}

$imported = 0;
$skipped  = 0;
$errors   = [];

// Idempotent upsert keyed on the UNIQUE h_date.
$upsert = $db->prepare(
    'INSERT INTO holidays (name, h_date, type, holiday_type_id, source)
     VALUES (:name, :h_date, :type, :type_id, "import")
     ON DUPLICATE KEY UPDATE name = VALUES(name), holiday_type_id = VALUES(holiday_type_id), source = "import"'
);

$rowNum = 0;
while (($row = fgetcsv($handle)) !== false) {
    $rowNum++;

    // Skip a leading header row (first cell not numeric) and blank lines.
    if ($row === [null] || (count($row) === 1 && trim((string)$row[0]) === '')) {
        continue;
    }
    if ($rowNum === 1 && !is_numeric(trim((string)($row[0] ?? '')))) {
        continue;
    }

    $month = (int)trim((string)($row[0] ?? 0));
    $day   = (int)trim((string)($row[1] ?? 0));
    $name  = trim((string)($row[2] ?? ''));

    if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
        $errors[] = "Row {$rowNum}: invalid month ({$month}) or day ({$day}) — skipped.";
        $skipped++;
        continue;
    }

    // Validate the calendar date (catches 31 Feb etc.).
    if (!checkdate($month, $day, $year)) {
        $errors[] = "Row {$rowNum}: {$year}-{$month}-{$day} is not a real date — skipped.";
        $skipped++;
        continue;
    }
    $date = sprintf('%04d-%02d-%02d', $year, $month, $day);

    $upsert->execute([
        ':name'    => $name !== '' ? mb_substr($name, 0, 120) : 'Holiday',
        ':h_date'  => $date,
        ':type'    => $legacyType,
        ':type_id' => $typeId,
    ]);
    $imported++;
}
fclose($handle);

// ── Report back ───────────────────────────────────────────────────────────────
if ($imported === 0 && $skipped === 0) {
    flash('error', 'No rows found in the file. Expected columns: Month, Day, Name.');
    redirect($back);
}

$msg = "{$imported} holiday(s) imported for {$year}.";
if ($skipped) {
    $msg .= " {$skipped} row(s) skipped.";
}
flash($skipped && !$imported ? 'error' : 'success', $msg);

// Surface the first few row-level problems without flooding the banner.
if ($errors) {
    $_SESSION['holiday_import_errors'] = array_slice($errors, 0, 10);
}

redirect($back);
