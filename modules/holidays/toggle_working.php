<?php
/**
 * Mark / unmark a day as a company Working Day. POST → redirect → GET (PRG).
 *
 * Ported from Employee_Management HolidayController::toggleWorkingDay /
 * toggleDateWorkingDay. Two modes:
 *   • id   given  → toggle an existing holiday row.
 *   • date given  → toggle an auto-generated weekly-off (Sat/Sun); the holiday
 *                   row is created on demand.
 *
 * Marking a day as working requires an Entity + Reason (used on the circular),
 * records it in comp_off_working_days and auto-grants a comp-off to every
 * active employee. Unmarking clears the working-day fields and the declaration.
 */
require_once __DIR__ . '/../../includes/comp_off.php';
require_login();
require_permission('holidays', 'edit');
verify_csrf($_POST['csrf_token'] ?? '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(BASE_URL . '/modules/holidays/index.php');
}

$db   = db();
$user = current_user();
$id   = (int)($_POST['id'] ?? 0);
$date = sanitize($_POST['date'] ?? '');
$name = sanitize($_POST['name'] ?? '');
$year = (int)($_POST['year'] ?? 0);

// Resolve the holiday row (create on demand for auto Sat/Sun rows).
if ($id) {
    $h = $db->prepare('SELECT * FROM holidays WHERE id = ?');
    $h->execute([$id]);
    $holiday = $h->fetch() ?: null;
} else {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$date || !$d || $d->format('Y-m-d') !== $date) {
        flash('error', 'A valid date is required.');
        redirect(BASE_URL . '/modules/holidays/index.php' . ($year ? '?year=' . $year : ''));
    }
    $h = $db->prepare('SELECT * FROM holidays WHERE h_date = ?');
    $h->execute([$date]);
    $holiday = $h->fetch() ?: null;
    if (!$holiday) {
        $db->prepare('INSERT INTO holidays (name, h_date, type, source, is_working_day) VALUES (?,?,?,?,0)')
           ->execute([$name ?: 'Working Day', $date, 'National', 'manual']);
        $h->execute([$date]);
        $holiday = $h->fetch();
    }
}

if (!$holiday) {
    flash('error', 'Holiday not found.');
    redirect(BASE_URL . '/modules/holidays/index.php' . ($year ? '?year=' . $year : ''));
}

$hDate = $holiday['h_date'];
$hName = $holiday['name'];
$year  = $year ?: (int)substr($hDate, 0, 4);
$back  = BASE_URL . '/modules/holidays/index.php?year=' . $year;

$isNowWorking = !((int)$holiday['is_working_day']);

if ($isNowWorking) {
    // Need entity + reason to declare a working day.
    $entityId = (int)($_POST['entity_id'] ?? 0);
    $reason   = trim($_POST['working_day_reason'] ?? '');
    $errors   = [];
    if (!$entityId) $errors[] = 'Please choose an entity.';
    else {
        $chk = $db->prepare('SELECT 1 FROM entities WHERE id = ?');
        $chk->execute([$entityId]);
        if (!$chk->fetchColumn()) $errors[] = 'Selected entity is invalid.';
    }
    if ($reason === '')          $errors[] = 'A reason for the working day is required.';
    if (mb_strlen($reason) > 500) $errors[] = 'Reason must be 500 characters or fewer.';
    if ($errors) {
        flash('error', implode(' ', $errors));
        redirect($back);
    }

    $db->prepare('UPDATE holidays SET is_working_day = 1, entity_id = ?, working_day_reason = ? WHERE id = ?')
       ->execute([$entityId, $reason, $holiday['id']]);

    [$dayType, $clsName] = WorkCalendar::classifyNonWorkingDay(new DateTime($hDate));
    comp_off_declare_working_day($hDate, $dayType, $hName ?: $clsName, $reason, (int)$user['id']);

    $granted = comp_off_grant_all($hDate, $hName ?: $clsName);
    flash('success', "'{$hName}' marked as a working day. Comp off granted to {$granted} employee(s). You can now download the circular.");
} else {
    $db->prepare('UPDATE holidays SET is_working_day = 0, entity_id = NULL, working_day_reason = NULL WHERE id = ?')
       ->execute([$holiday['id']]);
    comp_off_undeclare_working_day($hDate);
    flash('success', "'{$hName}' restored as an off day.");
}

redirect($back);
