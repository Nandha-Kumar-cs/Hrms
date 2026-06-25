<?php
/**
 * Break Settings — global tea breaks (×2), a default lunch window, and staggered
 * LUNCH BATCHES. Each employee can be assigned a lunch batch (on their profile);
 * payroll deducts that batch's lunch + the tea breaks that fall within the
 * employee's working window. Unassigned employees use the default lunch.
 *
 * Net worked hours = (check-out − check-in) − overlapping breaks. Full day = 8 net hrs.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('settings', 'view');

$db     = db();
$errors = [];
$timeRe = '/^([01]\d|2[0-3]):[0-5]\d$/';
$self   = BASE_URL . '/modules/settings/breaks.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { flash('error', 'Invalid or expired request.'); redirect($self); }
    $action = $_POST['action'] ?? '';

    if ($action === 'save_times') {
        $vals = [];
        foreach (['lunch_start' => '13:00', 'lunch_end' => '13:30', 'tea1_start' => '11:00', 'tea1_end' => '11:15', 'tea2_start' => '16:00', 'tea2_end' => '16:15'] as $k => $d) {
            $vals[$k] = trim($_POST[$k] ?? '');
            if (!preg_match($timeRe, $vals[$k])) $errors[] = ucfirst(str_replace('_', ' ', $k)) . ' must be a valid time (HH:MM).';
        }
        foreach ([['Default lunch', 'lunch_start', 'lunch_end'], ['Tea Break 1', 'tea1_start', 'tea1_end'], ['Tea Break 2', 'tea2_start', 'tea2_end']] as [$lbl, $s, $e]) {
            if (!$errors && time_to_mins($vals[$e]) <= time_to_mins($vals[$s])) $errors[] = "$lbl end must be after its start.";
        }
        if (!$errors) {
            foreach ($vals as $k => $v) setting_set($k, $v);
            flash('success', 'Break times saved.');
            redirect($self);
        }
    }

    if ($action === 'add_batch' || $action === 'update_batch') {
        $name = trim($_POST['name'] ?? '');
        $s    = trim($_POST['start_time'] ?? '');
        $e    = trim($_POST['end_time'] ?? '');
        if ($name === '')           $errors[] = 'Batch name is required.';
        if (!preg_match($timeRe, $s)) $errors[] = 'Batch start must be a valid time.';
        if (!preg_match($timeRe, $e)) $errors[] = 'Batch end must be a valid time.';
        if (!$errors && time_to_mins($e) <= time_to_mins($s)) $errors[] = 'Batch end must be after its start.';
        if (!$errors) {
            if ($action === 'add_batch') {
                $db->prepare('INSERT INTO lunch_batches (name, start_time, end_time) VALUES (?,?,?)')->execute([$name, $s, $e]);
                flash('success', 'Lunch batch added.');
            } else {
                $db->prepare('UPDATE lunch_batches SET name=?, start_time=?, end_time=? WHERE id=?')->execute([$name, $s, $e, (int)($_POST['id'] ?? 0)]);
                flash('success', 'Lunch batch updated.');
            }
            redirect($self);
        }
    }

    if ($action === 'delete_batch') {
        $bid = (int)($_POST['id'] ?? 0);
        if ($bid) {
            $db->prepare('UPDATE employees SET lunch_batch_id = NULL WHERE lunch_batch_id = ?')->execute([$bid]);
            $db->prepare('DELETE FROM lunch_batches WHERE id = ?')->execute([$bid]);
            flash('success', 'Lunch batch deleted; affected employees reset to default lunch.');
        }
        redirect($self);
    }
}

$cur = [];
foreach (['lunch_start' => '13:00', 'lunch_end' => '13:30', 'tea1_start' => '11:00', 'tea1_end' => '11:15', 'tea2_start' => '16:00', 'tea2_end' => '16:15'] as $k => $d) {
    $cur[$k] = setting_get($k, $d);
}
$batches = $db->query('SELECT b.*, (SELECT COUNT(*) FROM employees e WHERE e.lunch_batch_id = b.id) AS emp_count FROM lunch_batches b ORDER BY b.start_time')->fetchAll();
$fmt12   = fn(string $t) => date('h:i A', strtotime($t));
$dur     = fn(string $s, string $e) => max(0, time_to_mins($e) - time_to_mins($s));

$page_title = 'Break Settings';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex flex-column gap-3" style="max-width:760px">

    <?= render_flash() ?>
    <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

    <div class="alert alert-info small mb-0 py-2">
        <i class="fa fa-info-circle me-1"></i>
        Net worked hours = (check-out − check-in) − the breaks within that window. Full day = <strong>8 net hours</strong>.
        <strong>Lunch is per-batch</strong> (assign a batch on each employee's profile); tea breaks are global.
        An employee who leaves before a break is not charged for it.
    </div>

    <!-- ── Tea breaks + default lunch ─────────────────────────────────────── -->
    <div class="card page-card">
        <div class="card-header bg-white py-3"><h6 class="mb-0 fw-semibold"><i class="fa fa-mug-hot me-2 text-primary"></i>Tea Breaks &amp; Default Lunch</h6></div>
        <div class="card-body">
            <form method="POST" action="<?= $self ?>">
                <?= csrf_field() ?><input type="hidden" name="action" value="save_times">
                <?php
                $groups = [
                    ['Default Lunch (for employees with no batch)', 'lunch_start', 'lunch_end', 'fa-utensils'],
                    ['Tea Break 1', 'tea1_start', 'tea1_end', 'fa-mug-hot'],
                    ['Tea Break 2', 'tea2_start', 'tea2_end', 'fa-mug-hot'],
                ];
                foreach ($groups as [$lbl, $sKey, $eKey, $icon]): ?>
                <h6 class="fw-semibold text-muted mb-2 mt-1"><i class="fa <?= $icon ?> me-1"></i><?= $lbl ?></h6>
                <div class="row g-3 mb-3 align-items-end">
                    <div class="col-md-4"><label class="form-label">Start <span class="text-danger">*</span></label>
                        <input type="time" name="<?= $sKey ?>" class="form-control" value="<?= h($cur[$sKey]) ?>" required></div>
                    <div class="col-md-4"><label class="form-label">End <span class="text-danger">*</span></label>
                        <input type="time" name="<?= $eKey ?>" class="form-control" value="<?= h($cur[$eKey]) ?>" required></div>
                    <div class="col-md-4"><div class="form-text">Duration: <strong><?= $dur($cur[$sKey], $cur[$eKey]) ?> min</strong></div></div>
                </div>
                <?php endforeach; ?>
                <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Save Times</button>
            </form>
        </div>
    </div>

    <!-- ── Lunch batches ──────────────────────────────────────────────────── -->
    <div class="card page-card">
        <div class="card-header bg-white py-3"><h6 class="mb-0 fw-semibold"><i class="fa fa-layer-group me-2 text-primary"></i>Lunch Batches</h6></div>
        <div class="card-body">
            <table class="table table-sm align-middle">
                <thead class="table-light"><tr><th>Batch</th><th>Start</th><th>End</th><th class="text-center">Duration</th><th class="text-center">Employees</th><th class="text-end">Action</th></tr></thead>
                <tbody>
                <?php if (!$batches): ?>
                    <tr><td colspan="6" class="text-center text-muted py-3">No batches yet. Add one below.</td></tr>
                <?php else: foreach ($batches as $b): ?>
                    <tr>
                        <form method="POST" action="<?= $self ?>">
                        <?= csrf_field() ?><input type="hidden" name="action" value="update_batch"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                        <td><input type="text" name="name" class="form-control form-control-sm" value="<?= h($b['name']) ?>" required></td>
                        <td><input type="time" name="start_time" class="form-control form-control-sm" value="<?= h(substr($b['start_time'],0,5)) ?>" required></td>
                        <td><input type="time" name="end_time" class="form-control form-control-sm" value="<?= h(substr($b['end_time'],0,5)) ?>" required></td>
                        <td class="text-center"><?= $dur(substr($b['start_time'],0,5), substr($b['end_time'],0,5)) ?>m</td>
                        <td class="text-center"><span class="badge bg-secondary"><?= (int)$b['emp_count'] ?></span></td>
                        <td class="text-end text-nowrap">
                            <button class="btn btn-sm btn-outline-primary" title="Save"><i class="fa fa-save"></i></button>
                        </td>
                        </form>
                        <td class="text-end" style="border:0;padding-left:0">
                            <form method="POST" action="<?= $self ?>" onsubmit="return confirm('Delete this batch? Assigned employees revert to the default lunch.')" style="display:inline">
                                <?= csrf_field() ?><input type="hidden" name="action" value="delete_batch"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>

            <hr>
            <form method="POST" action="<?= $self ?>" class="row g-2 align-items-end">
                <?= csrf_field() ?><input type="hidden" name="action" value="add_batch">
                <div class="col-md-4"><label class="form-label small mb-1">New Batch Name</label><input type="text" name="name" class="form-control form-control-sm" placeholder="e.g. Batch C" required></div>
                <div class="col-md-3"><label class="form-label small mb-1">Start</label><input type="time" name="start_time" class="form-control form-control-sm" value="14:00" required></div>
                <div class="col-md-3"><label class="form-label small mb-1">End</label><input type="time" name="end_time" class="form-control form-control-sm" value="14:30" required></div>
                <div class="col-md-2"><button class="btn btn-success btn-sm w-100"><i class="fa fa-plus me-1"></i>Add</button></div>
            </form>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
