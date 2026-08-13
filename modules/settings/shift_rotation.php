<?php
/**
 * Shift Rotation — effective-dated shift assignments.
 *
 * Lets an employee be on different shifts over time ("2 weeks Morning, then
 * 2 weeks General, repeating"). Each row says: this employee is on this shift
 * between these dates. Attendance marking and the biometric imports resolve the
 * shift AS OF the attendance date, so back-dated data is still judged correctly.
 *
 * Employees with NO rows here keep their standing shift (employees.shift_id) —
 * nothing changes for non-rotating staff.
 *
 * Part of the Shift System — see docs/shift_system_plan.md.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('settings', 'view');

$db     = db();
$errors = [];
$self   = BASE_URL . '/modules/settings/shift_rotation.php';
$dateRe = '/^\d{4}-\d{2}-\d{2}$/';

// Guard: the migration must have been run.
$hasTable = true;
try { $db->query('SELECT 1 FROM employee_shift_schedule LIMIT 1'); }
catch (Throwable $e) { $hasTable = false; }

if ($hasTable && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { flash('error', 'Invalid or expired request.'); redirect($self); }
    $action = $_POST['action'] ?? '';

    // ── Generate a repeating rotation for one or more employees ──────────────
    if ($action === 'generate') {
        $empIds  = array_filter(array_map('intval', (array)($_POST['emp_ids'] ?? [])));
        $shiftA  = (int)($_POST['shift_a'] ?? 0);
        $shiftB  = (int)($_POST['shift_b'] ?? 0);
        $weeksA  = max(1, (int)($_POST['weeks_a'] ?? 2));
        $weeksB  = max(1, (int)($_POST['weeks_b'] ?? 2));
        $start   = trim($_POST['start_date'] ?? '');
        $until   = trim($_POST['until_date'] ?? '');
        $replace = !empty($_POST['replace_existing']);

        if (!$empIds)                    $errors[] = 'Select at least one employee.';
        if (!$shiftA || !$shiftB)        $errors[] = 'Choose both shifts for the cycle.';
        if ($shiftA === $shiftB)         $errors[] = 'The two shifts in a cycle must be different.';
        if (!preg_match($dateRe, $start))$errors[] = 'Start date must be a valid date.';
        if (!preg_match($dateRe, $until))$errors[] = 'Generate-until date must be a valid date.';
        if (!$errors && strtotime($until) <= strtotime($start)) $errors[] = 'Generate-until must be after the start date.';
        // Keep the row count sane (a 2-week cycle over 2 years is ~52 rows/employee).
        if (!$errors && (strtotime($until) - strtotime($start)) > 3 * 365 * 86400) {
            $errors[] = 'Please generate at most 3 years ahead.';
        }

        if (!$errors) {
            $ins = $db->prepare(
                'INSERT INTO employee_shift_schedule (employee_id, shift_id, start_date, end_date, note)
                 VALUES (?,?,?,?,?)'
            );
            $del = $db->prepare('DELETE FROM employee_shift_schedule WHERE employee_id = ? AND start_date >= ?');
            $made = 0;
            $db->beginTransaction();
            try {
                foreach ($empIds as $eid) {
                    if ($replace) $del->execute([$eid, $start]);
                    $cursor = new DateTime($start);
                    $limit  = new DateTime($until);
                    $cycle  = 0;
                    while ($cursor < $limit) {
                        $useShift = ($cycle % 2 === 0) ? $shiftA : $shiftB;
                        $weeks    = ($cycle % 2 === 0) ? $weeksA : $weeksB;
                        $blockEnd = (clone $cursor)->modify('+' . $weeks . ' weeks')->modify('-1 day');
                        if ($blockEnd > $limit) $blockEnd = clone $limit;
                        $ins->execute([
                            $eid, $useShift,
                            $cursor->format('Y-m-d'),
                            $blockEnd->format('Y-m-d'),
                            'Rotation block ' . ($cycle + 1),
                        ]);
                        $made++;
                        $cursor = (clone $blockEnd)->modify('+1 day');
                        $cycle++;
                    }
                }
                $db->commit();
                flash('success', "Rotation generated — {$made} block(s) across " . count($empIds) . ' employee(s).');
            } catch (Throwable $e) {
                $db->rollBack();
                flash('error', 'Could not generate the rotation: ' . $e->getMessage());
            }
            redirect($self);
        }
    }

    // ── Add a single manual block (exceptions, swaps, cover) ─────────────────
    if ($action === 'add_block') {
        $eid   = (int)($_POST['employee_id'] ?? 0);
        $sid   = (int)($_POST['shift_id'] ?? 0);
        $start = trim($_POST['start_date'] ?? '');
        $end   = trim($_POST['end_date'] ?? '');
        $note  = sanitize($_POST['note'] ?? '');
        if (!$eid)                        $errors[] = 'Choose an employee.';
        if (!$sid)                        $errors[] = 'Choose a shift.';
        if (!preg_match($dateRe, $start)) $errors[] = 'Start date must be a valid date.';
        if ($end !== '' && !preg_match($dateRe, $end)) $errors[] = 'End date must be a valid date.';
        if (!$errors && $end !== '' && strtotime($end) < strtotime($start)) $errors[] = 'End date cannot be before the start date.';
        if (!$errors) {
            $db->prepare('INSERT INTO employee_shift_schedule (employee_id, shift_id, start_date, end_date, note) VALUES (?,?,?,?,?)')
               ->execute([$eid, $sid, $start, $end ?: null, $note ?: null]);
            flash('success', 'Shift block added.');
            redirect($self . '?emp=' . $eid);
        }
    }

    if ($action === 'delete_block') {
        $bid = (int)($_POST['id'] ?? 0);
        $eid = (int)($_POST['employee_id'] ?? 0);
        if ($bid) { $db->prepare('DELETE FROM employee_shift_schedule WHERE id = ?')->execute([$bid]); flash('success', 'Block removed.'); }
        redirect($self . ($eid ? '?emp=' . $eid : ''));
    }

    if ($action === 'clear_employee') {
        $eid = (int)($_POST['employee_id'] ?? 0);
        if ($eid) {
            $db->prepare('DELETE FROM employee_shift_schedule WHERE employee_id = ?')->execute([$eid]);
            flash('success', 'All rotation blocks cleared — the employee reverts to their standing shift.');
        }
        redirect($self);
    }
}

// ── Data ─────────────────────────────────────────────────────────────────────
$employees = $db->query(
    "SELECT e.id, e.name, e.employee_id AS code, s.name AS shift_name
       FROM employees e LEFT JOIN shifts s ON s.id = e.shift_id
      WHERE e.status='Active' ORDER BY e.name"
)->fetchAll();

$shiftOpts = [];
try { $shiftOpts = $db->query("SELECT id, name FROM shifts WHERE status='active' ORDER BY id")->fetchAll(); }
catch (Throwable $e) { /* shifts absent */ }

$empFilter = (int)($_GET['emp'] ?? 0);
$blocks = [];
if ($hasTable) {
    $sql = "SELECT b.*, e.name AS emp_name, e.employee_id AS code, s.name AS shift_name
              FROM employee_shift_schedule b
              JOIN employees e ON e.id = b.employee_id
              LEFT JOIN shifts s ON s.id = b.shift_id";
    $prm = [];
    if ($empFilter) { $sql .= ' WHERE b.employee_id = ?'; $prm[] = $empFilter; }
    $sql .= ' ORDER BY e.name, b.start_date';
    $st = $db->prepare($sql); $st->execute($prm); $blocks = $st->fetchAll();
}
$today = date('Y-m-d');
$fmtD  = fn(?string $d) => $d ? date('d M Y', strtotime($d)) : '—';

$page_title = 'Shift Rotation';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex flex-column gap-3" style="max-width:1050px">

    <?= render_flash() ?>
    <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <?php if (!$hasTable): ?>
    <div class="alert alert-warning">
        <strong>Migration not run.</strong> Apply
        <code>install/add_shift_rotation.sql</code> to enable rotational shifts.
    </div>
    <?php else: ?>

    <div class="alert alert-info small mb-0 py-2">
        <i class="fa fa-info-circle me-1"></i>
        A rotation says <em>which shift an employee is on between which dates</em>. Attendance
        marking and biometric imports use the shift in force <strong>on the day worked</strong>,
        so back-dated data stays correct. Employees with no blocks keep their standing shift.
    </div>

    <!-- ── Generate a repeating rotation ──────────────────────────────────── -->
    <div class="card page-card">
        <div class="card-header bg-white py-3"><h6 class="mb-0 fw-semibold"><i class="fa fa-rotate me-2 text-primary"></i>Generate a Repeating Rotation</h6></div>
        <div class="card-body">
            <form method="POST" action="<?= $self ?>" class="row g-3">
                <?= csrf_field() ?><input type="hidden" name="action" value="generate">

                <div class="col-md-4">
                    <label class="form-label">Employees <span class="text-danger">*</span></label>
                    <select name="emp_ids[]" class="form-select" multiple size="8" required>
                        <?php foreach ($employees as $e): ?>
                        <option value="<?= (int)$e['id'] ?>"><?= h($e['name']) ?> (<?= h($e['code']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Ctrl/Shift-click to pick several — they all get the same cycle.</div>
                </div>

                <div class="col-md-8">
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">First block <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <select name="shift_a" class="form-select" required>
                                    <?php foreach ($shiftOpts as $s): ?>
                                    <option value="<?= (int)$s['id'] ?>"><?= h($s['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="input-group-text">for</span>
                                <input type="number" name="weeks_a" class="form-control" min="1" max="26" value="2" style="max-width:90px">
                                <span class="input-group-text">week(s)</span>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Then <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <select name="shift_b" class="form-select" required>
                                    <?php foreach ($shiftOpts as $s): ?>
                                    <option value="<?= (int)$s['id'] ?>" <?= $s['name'] === 'General' ? 'selected' : '' ?>><?= h($s['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="input-group-text">for</span>
                                <input type="number" name="weeks_b" class="form-control" min="1" max="26" value="2" style="max-width:90px">
                                <span class="input-group-text">week(s)</span>
                            </div>
                            <div class="form-text">…then back to the first block, repeating.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Start date <span class="text-danger">*</span></label>
                            <input type="date" name="start_date" class="form-control" value="<?= h($today) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Generate until <span class="text-danger">*</span></label>
                            <input type="date" name="until_date" class="form-control" value="<?= h(date('Y-m-d', strtotime('+1 year'))) ?>" required>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="replace_existing" value="1" id="repl" checked>
                                <label class="form-check-label" for="repl">Replace existing blocks from the start date</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary"><i class="fa fa-wand-magic-sparkles me-1"></i>Generate Rotation</button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Manual single block ────────────────────────────────────────────── -->
    <div class="card page-card">
        <div class="card-header bg-white py-3"><h6 class="mb-0 fw-semibold"><i class="fa fa-plus me-2 text-primary"></i>Add a Single Block <span class="text-muted fw-normal small">(swap, cover, one-off)</span></h6></div>
        <div class="card-body">
            <form method="POST" action="<?= $self ?>" class="row g-2 align-items-end">
                <?= csrf_field() ?><input type="hidden" name="action" value="add_block">
                <div class="col-md-3"><label class="form-label small mb-1">Employee</label>
                    <select name="employee_id" class="form-select form-select-sm" required>
                        <option value="">Select…</option>
                        <?php foreach ($employees as $e): ?>
                        <option value="<?= (int)$e['id'] ?>" <?= $empFilter === (int)$e['id'] ? 'selected' : '' ?>><?= h($e['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2"><label class="form-label small mb-1">Shift</label>
                    <select name="shift_id" class="form-select form-select-sm" required>
                        <?php foreach ($shiftOpts as $s): ?><option value="<?= (int)$s['id'] ?>"><?= h($s['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2"><label class="form-label small mb-1">From</label><input type="date" name="start_date" class="form-control form-control-sm" value="<?= h($today) ?>" required></div>
                <div class="col-md-2"><label class="form-label small mb-1">To <span class="text-muted">(blank = open)</span></label><input type="date" name="end_date" class="form-control form-control-sm"></div>
                <div class="col-md-2"><label class="form-label small mb-1">Note</label><input type="text" name="note" class="form-control form-control-sm" maxlength="160" placeholder="optional"></div>
                <div class="col-md-1"><button class="btn btn-success btn-sm w-100"><i class="fa fa-plus"></i></button></div>
            </form>
        </div>
    </div>

    <!-- ── Existing blocks ────────────────────────────────────────────────── -->
    <div class="card page-card">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-semibold"><i class="fa fa-calendar-days me-2 text-primary"></i>Scheduled Blocks</h6>
            <form method="GET" class="d-flex gap-2">
                <select name="emp" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All employees</option>
                    <?php foreach ($employees as $e): ?>
                    <option value="<?= (int)$e['id'] ?>" <?= $empFilter === (int)$e['id'] ? 'selected' : '' ?>><?= h($e['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <div class="card-body">
            <?php if (!$blocks): ?>
                <p class="text-muted mb-0 py-2">No rotation blocks yet — every employee is on their standing shift.</p>
            <?php else: ?>
            <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>Employee</th><th>Shift</th><th>From</th><th>To</th><th>Note</th><th class="text-end">Action</th></tr></thead>
                <tbody>
                <?php foreach ($blocks as $b):
                    $isNow = $b['start_date'] <= $today && ($b['end_date'] === null || $b['end_date'] >= $today);
                    $isPast = $b['end_date'] !== null && $b['end_date'] < $today;
                ?>
                    <tr class="<?= $isNow ? 'table-success' : ($isPast ? 'text-muted' : '') ?>">
                        <td><?= h($b['emp_name']) ?> <small class="text-muted"><?= h($b['code']) ?></small></td>
                        <td><span class="badge bg-light text-dark border"><?= h($b['shift_name'] ?? '—') ?></span>
                            <?php if ($isNow): ?><span class="badge bg-success ms-1" style="font-size:.62rem">CURRENT</span><?php endif; ?></td>
                        <td><?= $fmtD($b['start_date']) ?></td>
                        <td><?= $fmtD($b['end_date']) ?></td>
                        <td class="small text-muted"><?= h($b['note'] ?? '') ?></td>
                        <td class="text-end">
                            <form method="POST" action="<?= $self ?>" style="display:inline" onsubmit="return confirm('Remove this block?')">
                                <?= csrf_field() ?><input type="hidden" name="action" value="delete_block">
                                <input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                                <input type="hidden" name="employee_id" value="<?= (int)$b['employee_id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" title="Remove"><i class="fa fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php if ($empFilter): ?>
            <hr>
            <form method="POST" action="<?= $self ?>" onsubmit="return confirm('Clear ALL rotation blocks for this employee? They revert to their standing shift.')">
                <?= csrf_field() ?><input type="hidden" name="action" value="clear_employee"><input type="hidden" name="employee_id" value="<?= $empFilter ?>">
                <button class="btn btn-sm btn-outline-danger"><i class="fa fa-trash me-1"></i>Clear all blocks for this employee</button>
            </form>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
