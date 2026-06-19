<?php
/**
 * Comp Off Management — holiday-driven comp-off grants.
 *
 * Ported from the Employee_Management comp-offs.index screen (replacing the old
 * request-based worked/comp-date page). Comp offs are auto-granted to all
 * employees when a holiday is marked a Working Day on the Holidays page; here
 * they can be (re-)granted, marked availed on a date (which writes a "Comp Off"
 * attendance record), or removed (which reverts those attendance records).
 */
require_once __DIR__ . '/../../includes/comp_off.php';
require_login();
require_permission('compoff', 'view');
block_cross_employee();   // company-wide comp-off management; employees use Comp Off Credits

$canEdit = can('compoff', 'edit') || can('attendance', 'edit') || can('holidays', 'edit');
$canEdit = can('attendance', 'edit') || can('holidays', 'edit');

// ── POST actions (grant / avail / remove / destroy) ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf($_POST['csrf_token'] ?? '');
    if (!$canEdit) { http_response_code(403); exit('Forbidden'); }

    $action = $_POST['action'] ?? '';
    $date   = sanitize($_POST['holiday_date'] ?? '');
    $year   = (int)($_POST['year'] ?? date('Y'));
    $self   = BASE_URL . '/modules/attendance/comp_off.php?year=' . $year;
    $back   = (($_POST['back'] ?? '') === 'holidays')
        ? BASE_URL . '/modules/holidays/index.php?year=' . $year
        : $self;

    if ($action === 'grant') {
        $name = sanitize($_POST['holiday_name'] ?? 'Holiday');
        $n = comp_off_grant_all($date, $name);
        flash('success', "Comp off granted to {$n} employee(s) for {$name}.");
        redirect($back);
    }

    if ($action === 'avail') {
        $availed = sanitize($_POST['availed_date'] ?? '');
        $a = DateTime::createFromFormat('Y-m-d', $availed);
        if (!$a || $a->format('Y-m-d') !== $availed) {
            flash('error', 'A valid availed date is required.');
            redirect($self);
        }
        $n = comp_off_avail_all($date, $availed, (int)$user['id']);
        flash('success', "{$n} comp off(s) marked as availed on " . date('d M Y', strtotime($availed)) . ' and attendance updated.');
        redirect($self);
    }

    if ($action === 'remove') {
        $n = comp_off_remove_all($date);
        flash('success', "{$n} comp off record(s) removed. Related attendance entries reverted.");
        redirect($self);
    }

    if ($action === 'destroy') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) db()->prepare('DELETE FROM comp_offs WHERE id = ?')->execute([$id]);
        flash('success', 'Comp off record removed.');
        redirect($self);
    }

    redirect($self);
}

// ── View ──────────────────────────────────────────────────────────────────────
$year = (int)($_GET['year'] ?? date('Y'));

$wh = db()->prepare("SELECT * FROM holidays WHERE YEAR(h_date) = ? AND is_working_day = 1 ORDER BY h_date");
$wh->execute([$year]);
$workingHolidays = $wh->fetchAll();

$summaryMap  = comp_off_year_summary($year);
$totalActive = (int)db()->query("SELECT COUNT(*) FROM employees WHERE status = 'Active'")->fetchColumn();

$page_title = 'Comp Off Management';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Comp Off Management</h1>
        <p class="page-subtitle">Comp offs for company working days — <?= $year ?></p>
    </div>
    <div class="page-actions d-flex gap-2 align-items-center">
        <form method="GET" class="d-inline-flex">
            <select name="year" onchange="this.form.submit()" class="form-select form-select-sm" style="width:auto">
                <?php for ($y = (int)date('Y') + 1; $y >= (int)date('Y') - 2; $y--): ?>
                <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </form>
        <a href="<?= BASE_URL ?>/modules/holidays/index.php?year=<?= $year ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Holidays</a>
        <a href="<?= BASE_URL ?>/modules/attendance/comp_off_credits.php?year=<?= $year ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-coins me-1"></i>Credits &amp; Balance</a>
    </div>
</div>

<?php render_flash(); ?>

<div class="card page-card">
    <div class="card-body">
        <div class="alert alert-info d-flex align-items-start gap-2 py-2 mb-4 small">
            <i class="fa fa-circle-info mt-1"></i>
            <div>Comp offs are auto-granted to all employees when you mark a holiday as a <strong>Working Day</strong> on the
                <a href="<?= BASE_URL ?>/modules/holidays/index.php?year=<?= $year ?>">Holidays</a> page.
                Once employees take their comp off, click <strong>“Set Availed Date”</strong> to record it for all — that also marks attendance as
                <span class="badge" style="background:#6f42c1">CO</span> on that date.</div>
        </div>

        <?php if (!$workingHolidays): ?>
            <div class="text-center py-5 text-muted">
                <i class="fa fa-calendar-xmark fa-3x d-block mb-3 opacity-25"></i>
                <p class="mb-1 fw-semibold">No working holidays in <?= $year ?></p>
                <p class="small">Go to <a href="<?= BASE_URL ?>/modules/holidays/index.php?year=<?= $year ?>">Holidays</a> and toggle a day as a working day.</p>
            </div>
        <?php else: foreach ($workingHolidays as $h):
            $ds      = $h['h_date'];
            $pending = $summaryMap[$ds]['pending'] ?? 0;
            $availed = $summaryMap[$ds]['availed'] ?? 0;
            $granted = $pending + $availed;
            $notGranted = $totalActive - $granted;
            $allAvailed = $availed >= $totalActive && $totalActive > 0;
            $dObj = new DateTime($ds);
        ?>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 px-3 py-3 mb-3 rounded border" style="background:#fffbeb;border-color:#fde68a">
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <div>
                    <i class="fa fa-briefcase me-1" style="color:#b45309"></i>
                    <strong style="color:#78350f"><?= h($h['name']) ?></strong>
                    <span class="text-muted ms-2" style="font-size:.85rem"><?= $dObj->format('d M Y') ?> — <?= $dObj->format('l') ?></span>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <?php if ($notGranted > 0): ?><span class="badge bg-secondary"><?= $notGranted ?> not granted</span><?php endif; ?>
                    <?php if ($pending > 0): ?><span class="badge bg-warning text-dark"><?= $pending ?> pending</span><?php endif; ?>
                    <?php if ($availed > 0): ?><span class="badge bg-success"><?= $availed ?> availed</span><?php endif; ?>
                    <?php if ($granted === 0): ?><span class="badge bg-light text-dark border">No comp offs granted yet</span><?php endif; ?>
                </div>
            </div>
            <?php if ($canEdit): ?>
            <div class="d-flex gap-2 align-items-center">
                <?php if ($notGranted > 0): ?>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="grant">
                    <input type="hidden" name="holiday_date" value="<?= $ds ?>">
                    <input type="hidden" name="holiday_name" value="<?= h($h['name']) ?>">
                    <input type="hidden" name="year" value="<?= $year ?>">
                    <button class="btn btn-sm btn-outline-success"><i class="fa fa-users me-1"></i>Grant to All</button>
                </form>
                <?php endif; ?>
                <?php if ($pending > 0 && !$allAvailed): ?>
                <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#availModal<?= $h['id'] ?>"><i class="fa fa-calendar-check me-1"></i>Set Availed Date</button>
                <?php endif; ?>
                <?php if ($allAvailed): ?>
                <span class="badge bg-success py-2 px-3" style="font-size:.8rem"><i class="fa fa-check-circle me-1"></i>All Availed</span>
                <?php endif; ?>
                <?php if ($granted > 0): ?>
                <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#removeModal<?= $h['id'] ?>"><i class="fa fa-trash me-1"></i>Remove</button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($canEdit && $pending > 0): ?>
        <div class="modal fade" id="availModal<?= $h['id'] ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-sm">
                <form class="modal-content" method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="avail">
                    <input type="hidden" name="holiday_date" value="<?= $ds ?>">
                    <input type="hidden" name="year" value="<?= $year ?>">
                    <div class="modal-header border-0 pb-0"><h6 class="modal-title fw-semibold"><i class="fa fa-calendar-check me-1 text-success"></i>Set Availed Date</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <p class="small text-muted mb-3">Date on which employees availed comp off for <strong><?= h($h['name']) ?></strong>. Updates all <strong><?= $pending ?> pending</strong> comp offs and marks attendance <span class="badge" style="background:#6f42c1;font-size:.7rem">CO</span>.</p>
                        <label class="form-label fw-semibold small">Availed On <span class="text-danger">*</span></label>
                        <input type="date" name="availed_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="modal-footer border-0 pt-0"><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-sm btn-success"><i class="fa fa-check me-1"></i>Confirm for All</button></div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($canEdit && $granted > 0): ?>
        <div class="modal fade" id="removeModal<?= $h['id'] ?>" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-sm">
                <form class="modal-content" method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="remove">
                    <input type="hidden" name="holiday_date" value="<?= $ds ?>">
                    <input type="hidden" name="year" value="<?= $year ?>">
                    <div class="modal-header border-0 pb-0"><h6 class="modal-title fw-semibold text-danger"><i class="fa fa-triangle-exclamation me-1"></i>Remove Comp Off</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                    <div class="modal-body">
                        <p class="small text-muted mb-2">Remove all <strong><?= $granted ?> comp off record(s)</strong> for <strong><?= h($h['name']) ?></strong>.</p>
                        <?php if ($availed > 0): ?><div class="alert alert-warning py-2 small mb-0"><i class="fa fa-circle-exclamation me-1"></i><strong><?= $availed ?> availed</strong> attendance record(s) auto-created will also be removed.</div><?php endif; ?>
                    </div>
                    <div class="modal-footer border-0 pt-0"><button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-sm btn-danger"><i class="fa fa-trash me-1"></i>Yes, Remove All</button></div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php endforeach; endif; ?>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
