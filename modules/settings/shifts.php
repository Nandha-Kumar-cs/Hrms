<?php
/**
 * Shift Settings — per-shift timing (office hours, grace, OT, breaks, lunch).
 *
 * Each employee is assigned a shift (on their profile). Attendance and payroll
 * read that shift's timing instead of the old global settings. "General" mirrors
 * the legacy defaults; "straight" shifts (e.g. Morning/Evening) can turn OT off
 * and carry no lunch/breaks.
 *
 * Part of the Shift System — see docs/shift_system_plan.md.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('settings', 'view');

$db     = db();
$errors = [];
$timeRe = '/^([01]\d|2[0-3]):[0-5]\d$/';
$self   = BASE_URL . '/modules/settings/shifts.php';

/** Read + validate a required HH:MM field. */
$reqTime = function (string $key, string $label) use (&$errors, $timeRe): string {
    $v = trim($_POST[$key] ?? '');
    if (!preg_match($timeRe, $v)) $errors[] = "$label must be a valid time (HH:MM).";
    return $v;
};
/** Read an optional HH:MM field → '' or valid time. */
$optTime = function (string $key, string $label) use (&$errors, $timeRe): string {
    $v = trim($_POST[$key] ?? '');
    if ($v !== '' && !preg_match($timeRe, $v)) $errors[] = "$label must be a valid time (HH:MM).";
    return $v;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { flash('error', 'Invalid or expired request.'); redirect($self); }
    $action = $_POST['action'] ?? '';

    // ── Add / update a shift ─────────────────────────────────────────────────
    if ($action === 'save_shift') {
        $id     = (int)($_POST['id'] ?? 0);
        $name   = trim($_POST['name'] ?? '');
        $start  = $reqTime('start_time', 'Start time');
        $end    = $reqTime('end_time', 'End time');
        $dGrace = max(0, (int)($_POST['daily_grace_mins'] ?? 0));
        $mGrace = max(0, (int)($_POST['monthly_grace_mins'] ?? 0));
        $otOn   = isset($_POST['ot_enabled']) ? 1 : 0;
        $otTrig = $optTime('ot_trigger_time', 'OT trigger');
        $otBase = $optTime('ot_baseline_time', 'OT baseline');
        $halfCut= $optTime('half_day_cutoff', 'Half-day cutoff');
        $lunchS = $optTime('lunch_start', 'Lunch start');
        $lunchE = $optTime('lunch_end', 'Lunch end');
        $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';

        if ($name === '') $errors[] = 'Shift name is required.';
        if (!$errors && time_to_mins($end) <= time_to_mins($start)) $errors[] = 'End time must be after start time.';
        if (!$errors && $otOn && ($otTrig === '' || $otBase === '')) $errors[] = 'OT trigger and baseline are required when OT is enabled.';
        if (!$errors && $lunchS !== '' && $lunchE !== '' && time_to_mins($lunchE) <= time_to_mins($lunchS)) $errors[] = 'Lunch end must be after lunch start.';
        // Uniqueness on name
        if (!$errors) {
            $dup = $db->prepare('SELECT 1 FROM shifts WHERE name = ? AND id <> ? LIMIT 1');
            $dup->execute([$name, $id]);
            if ($dup->fetchColumn()) $errors[] = 'Another shift already uses that name.';
        }
        // Deactivating hides a shift from the assignment dropdowns but would NOT
        // move the employees already on it — they would keep being judged by an
        // inactive shift no one can see. Require them to be moved first.
        if (!$errors && $id > 0 && $status === 'inactive') {
            $cnt = $db->prepare('SELECT COUNT(*) FROM employees WHERE shift_id = ?');
            $cnt->execute([$id]);
            $n = (int)$cnt->fetchColumn();
            if ($n > 0) {
                $errors[] = "Cannot deactivate '{$name}' — {$n} employee(s) are still assigned to it. Move them to another shift first.";
            }
        }
        // The General shift is the system-wide fallback; it must stay active.
        if (!$errors && $id > 0 && $status === 'inactive' && $name === 'General') {
            $errors[] = 'The General shift is the fallback and cannot be deactivated.';
        }

        if (!$errors) {
            $params = [
                $name, $status, $start, $end, $dGrace, $mGrace, $otOn,
                $otTrig ?: null, $otBase ?: null, $halfCut ?: null,
                $lunchS ?: null, $lunchE ?: null,
            ];
            if ($id > 0) {
                $sql = 'UPDATE shifts SET name=?, status=?, start_time=?, end_time=?, daily_grace_mins=?, monthly_grace_mins=?, ot_enabled=?, ot_trigger_time=?, ot_baseline_time=?, half_day_cutoff=?, lunch_start=?, lunch_end=? WHERE id=?';
                $params[] = $id;
                $db->prepare($sql)->execute($params);
                flash('success', "Shift '{$name}' updated.");
            } else {
                $sql = 'INSERT INTO shifts (name, status, start_time, end_time, daily_grace_mins, monthly_grace_mins, ot_enabled, ot_trigger_time, ot_baseline_time, half_day_cutoff, lunch_start, lunch_end) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)';
                $db->prepare($sql)->execute($params);
                flash('success', "Shift '{$name}' created.");
            }
            redirect($self);
        }
    }

    // ── Delete a shift ───────────────────────────────────────────────────────
    if ($action === 'delete_shift') {
        $id = (int)($_POST['id'] ?? 0);
        $row = $db->prepare('SELECT name FROM shifts WHERE id=?'); $row->execute([$id]);
        $sname = $row->fetchColumn();
        if (!$sname) { flash('error', 'Shift not found.'); redirect($self); }
        if ($sname === 'General') { flash('error', 'The General shift cannot be deleted (it is the fallback).'); redirect($self); }
        $cnt = $db->prepare('SELECT COUNT(*) FROM employees WHERE shift_id=?'); $cnt->execute([$id]);
        if ((int)$cnt->fetchColumn() > 0) { flash('error', "Cannot delete '{$sname}' — employees are still assigned to it. Move them first."); redirect($self); }
        $db->prepare('DELETE FROM shift_breaks WHERE shift_id=?')->execute([$id]);
        $db->prepare('UPDATE lunch_batches SET shift_id=NULL WHERE shift_id=?')->execute([$id]);
        // Clear the stamp on historical attendance too — a dangling shift_id would
        // resolve differently in the payroll SQL (globals) than in PHP (employee's
        // current shift). NULL makes every consumer fall back the same way.
        $db->prepare('UPDATE attendance SET shift_id=NULL WHERE shift_id=?')->execute([$id]);
        $db->prepare('DELETE FROM shifts WHERE id=?')->execute([$id]);
        flash('success', "Shift '{$sname}' deleted.");
        redirect($self);
    }

    // ── Add a break to a shift ───────────────────────────────────────────────
    if ($action === 'add_break') {
        $sid  = (int)($_POST['shift_id'] ?? 0);
        $kind = ($_POST['kind'] ?? 'tea') === 'break' ? 'break' : 'tea';
        $name = trim($_POST['break_name'] ?? '');
        $s    = $reqTime('bstart', 'Break start');
        $e    = $reqTime('bend', 'Break end');
        if ($name === '') $errors[] = 'Break name is required.';
        if (!$errors && time_to_mins($e) <= time_to_mins($s)) $errors[] = 'Break end must be after its start.';
        if (!$errors) {
            $db->prepare('INSERT INTO shift_breaks (shift_id, kind, name, start_time, end_time) VALUES (?,?,?,?,?)')
               ->execute([$sid, $kind, $name, $s, $e]);
            flash('success', 'Break added.');
            redirect($self . '?edit=' . $sid);
        }
    }

    // ── Delete a break ───────────────────────────────────────────────────────
    if ($action === 'delete_break') {
        $bid = (int)($_POST['id'] ?? 0);
        $sid = (int)($_POST['shift_id'] ?? 0);
        if ($bid) { $db->prepare('DELETE FROM shift_breaks WHERE id=?')->execute([$bid]); flash('success', 'Break removed.'); }
        redirect($self . '?edit=' . $sid);
    }
}

// ── Load data ────────────────────────────────────────────────────────────────
$shifts = $db->query(
    'SELECT s.*,
            (SELECT COUNT(*) FROM employees e WHERE e.shift_id = s.id) AS emp_count,
            (SELECT COUNT(*) FROM shift_breaks b WHERE b.shift_id = s.id) AS break_count
       FROM shifts s ORDER BY s.id'
)->fetchAll();

$editId = (int)($_GET['edit'] ?? 0);
$edit   = null;
$editBreaks = [];
if ($editId) {
    foreach ($shifts as $s) if ((int)$s['id'] === $editId) $edit = $s;
    if ($edit) {
        $bs = $db->prepare('SELECT * FROM shift_breaks WHERE shift_id=? ORDER BY start_time');
        $bs->execute([$editId]);
        $editBreaks = $bs->fetchAll();
    }
}

$fmt12 = fn(?string $t) => $t ? date('h:i A', strtotime($t)) : '—';
$hhmm  = fn(?string $t) => $t ? substr($t, 0, 5) : '';

$page_title = 'Shift Settings';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex flex-column gap-3" style="max-width:960px">

    <?= render_flash() ?>
    <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <div class="alert alert-info small mb-0 py-2">
        <i class="fa fa-info-circle me-1"></i>
        Each employee is assigned a shift on their profile. Attendance &amp; payroll use that shift's
        timing. <strong>General</strong> mirrors the legacy settings; a straight shift can turn OT off and
        carry no lunch/breaks.
    </div>

    <!-- ── Shift list ─────────────────────────────────────────────────────── -->
    <div class="card page-card">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-semibold"><i class="fa fa-clock me-2 text-primary"></i>Shifts</h6>
            <a href="<?= $self ?>?edit=new" class="btn btn-sm btn-primary"><i class="fa fa-plus me-1"></i>Add Shift</a>
        </div>
        <div class="card-body">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr><th>Shift</th><th>Hours</th><th class="text-center">OT</th><th class="text-center">Grace</th><th class="text-center">Breaks</th><th class="text-center">Lunch</th><th class="text-center">Emp.</th><th class="text-end">Action</th></tr>
                </thead>
                <tbody>
                <?php foreach ($shifts as $s): ?>
                    <tr>
                        <td class="fw-semibold"><?= h($s['name']) ?><?php if ($s['status']==='inactive'): ?> <span class="badge bg-secondary">inactive</span><?php endif; ?></td>
                        <td><?= $fmt12($s['start_time']) ?> – <?= $fmt12($s['end_time']) ?></td>
                        <td class="text-center"><?= $s['ot_enabled'] ? '<span class="badge bg-success">On</span>' : '<span class="badge bg-secondary">Off</span>' ?></td>
                        <td class="text-center"><?= (int)$s['daily_grace_mins'] ?>/<?= (int)$s['monthly_grace_mins'] ?>m</td>
                        <td class="text-center"><?= (int)$s['break_count'] ?></td>
                        <td class="text-center"><?= ($s['lunch_start'] && $s['lunch_end']) ? h($fmt12($s['lunch_start'])) : '—' ?></td>
                        <td class="text-center"><span class="badge bg-secondary"><?= (int)$s['emp_count'] ?></span></td>
                        <td class="text-end text-nowrap">
                            <a href="<?= $self ?>?edit=<?= (int)$s['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit"><i class="fa fa-pen-to-square"></i></a>
                            <?php if ($s['name'] !== 'General'): ?>
                            <form method="POST" action="<?= $self ?>" style="display:inline" onsubmit="return confirm('Delete this shift? (Only allowed when no employees are assigned.)')">
                                <?= csrf_field() ?><input type="hidden" name="action" value="delete_shift"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa fa-trash"></i></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($editId): $isNew = ($edit === null); ?>
    <!-- ── Add / edit shift form ──────────────────────────────────────────── -->
    <div class="card page-card" id="shiftForm">
        <div class="card-header bg-white py-3"><h6 class="mb-0 fw-semibold"><i class="fa fa-<?= $isNew ? 'plus' : 'pen-to-square' ?> me-2 text-primary"></i><?= $isNew ? 'Add Shift' : 'Edit Shift: ' . h($edit['name']) ?></h6></div>
        <div class="card-body">
            <form method="POST" action="<?= $self ?>" class="row g-3">
                <?= csrf_field() ?><input type="hidden" name="action" value="save_shift">
                <input type="hidden" name="id" value="<?= $isNew ? 0 : (int)$edit['id'] ?>">

                <div class="col-md-6">
                    <label class="form-label">Shift Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" value="<?= h($edit['name'] ?? '') ?>" <?= (!$isNew && $edit['name']==='General') ? 'readonly' : '' ?> required placeholder="e.g. Night (10-6)">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Start <span class="text-danger">*</span></label>
                    <input type="time" name="start_time" class="form-control" value="<?= h($hhmm($edit['start_time'] ?? '09:00')) ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">End <span class="text-danger">*</span></label>
                    <input type="time" name="end_time" class="form-control" value="<?= h($hhmm($edit['end_time'] ?? '18:00')) ?>" required>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Daily Grace (min)</label>
                    <input type="number" name="daily_grace_mins" class="form-control" min="0" value="<?= (int)($edit['daily_grace_mins'] ?? 15) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Monthly Grace (min)</label>
                    <input type="number" name="monthly_grace_mins" class="form-control" min="0" value="<?= (int)($edit['monthly_grace_mins'] ?? 90) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Half-day cutoff</label>
                    <input type="time" name="half_day_cutoff" class="form-control" value="<?= h($hhmm($edit['half_day_cutoff'] ?? '')) ?>">
                    <div class="form-text">Check-in after this = Half Day.</div>
                </div>
                <div class="col-md-3 d-flex align-items-center">
                    <div class="form-check form-switch mt-4">
                        <input class="form-check-input" type="checkbox" name="ot_enabled" value="1" id="otEnabled" <?= (!$isNew && !$edit['ot_enabled']) ? '' : 'checked' ?>>
                        <label class="form-check-label fw-semibold" for="otEnabled">OT Enabled</label>
                    </div>
                </div>

                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="active"   <?= (!$isNew && $edit['status'] === 'inactive') ? '' : 'selected' ?>>Active</option>
                        <option value="inactive" <?= (!$isNew && $edit['status'] === 'inactive') ? 'selected' : '' ?>>Inactive</option>
                    </select>
                    <div class="form-text">Inactive hides it from assignment. Move employees off it first.</div>
                </div>

                <div class="col-md-3">
                    <label class="form-label">OT Trigger</label>
                    <input type="time" name="ot_trigger_time" class="form-control" value="<?= h($hhmm($edit['ot_trigger_time'] ?? '')) ?>">
                    <div class="form-text">Checkout must reach this for OT.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">OT Baseline</label>
                    <input type="time" name="ot_baseline_time" class="form-control" value="<?= h($hhmm($edit['ot_baseline_time'] ?? '')) ?>">
                    <div class="form-text">OT counted from here.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Default Lunch Start</label>
                    <input type="time" name="lunch_start" class="form-control" value="<?= h($hhmm($edit['lunch_start'] ?? '')) ?>">
                    <div class="form-text">Blank = no lunch (straight shift).</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Default Lunch End</label>
                    <input type="time" name="lunch_end" class="form-control" value="<?= h($hhmm($edit['lunch_end'] ?? '')) ?>">
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i><?= $isNew ? 'Create Shift' : 'Save Shift' ?></button>
                    <a href="<?= $self ?>" class="btn btn-light">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <?php if (!$isNew): ?>
    <!-- ── Breaks for this shift ──────────────────────────────────────────── -->
    <div class="card page-card">
        <div class="card-header bg-white py-3"><h6 class="mb-0 fw-semibold"><i class="fa fa-mug-hot me-2 text-primary"></i>Breaks for <?= h($edit['name']) ?></h6></div>
        <div class="card-body">
            <table class="table table-sm align-middle">
                <thead class="table-light"><tr><th>Name</th><th>Kind</th><th>Start</th><th>End</th><th class="text-end">Action</th></tr></thead>
                <tbody>
                <?php if (!$editBreaks): ?>
                    <tr><td colspan="5" class="text-center text-muted py-3">No breaks — this shift works straight through.</td></tr>
                <?php else: foreach ($editBreaks as $b): ?>
                    <tr>
                        <td><?= h($b['name']) ?></td>
                        <td><span class="badge bg-light text-dark border"><?= h(ucfirst($b['kind'])) ?></span></td>
                        <td><?= $fmt12($b['start_time']) ?></td>
                        <td><?= $fmt12($b['end_time']) ?></td>
                        <td class="text-end">
                            <form method="POST" action="<?= $self ?>" style="display:inline" onsubmit="return confirm('Remove this break?')">
                                <?= csrf_field() ?><input type="hidden" name="action" value="delete_break"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><input type="hidden" name="shift_id" value="<?= (int)$edit['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" title="Remove"><i class="fa fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
            <hr>
            <form method="POST" action="<?= $self ?>" class="row g-2 align-items-end">
                <?= csrf_field() ?><input type="hidden" name="action" value="add_break"><input type="hidden" name="shift_id" value="<?= (int)$edit['id'] ?>">
                <div class="col-md-4"><label class="form-label small mb-1">Break Name</label><input type="text" name="break_name" class="form-control form-control-sm" placeholder="e.g. Tea Break 1" required></div>
                <div class="col-md-2"><label class="form-label small mb-1">Kind</label><select name="kind" class="form-select form-select-sm"><option value="tea">Tea</option><option value="break">Break</option></select></div>
                <div class="col-md-2"><label class="form-label small mb-1">Start</label><input type="time" name="bstart" class="form-control form-control-sm" value="11:00" required></div>
                <div class="col-md-2"><label class="form-label small mb-1">End</label><input type="time" name="bend" class="form-control form-control-sm" value="11:15" required></div>
                <div class="col-md-2"><button class="btn btn-success btn-sm w-100"><i class="fa fa-plus me-1"></i>Add</button></div>
            </form>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
