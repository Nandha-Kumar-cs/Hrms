<?php
/**
 * Holidays — month-wise calendar of all non-working days for the year:
 * Sundays, 1st & 3rd Saturdays (auto rows) and declared public holidays.
 *
 * Ported from the Employee_Management holidays.index screen and adapted to the
 * HRMS plain-PHP / Bootstrap stack. Writes happen in save.php / delete.php /
 * toggle_working.php / import.php (PRG); comp-off grants live on comp_off.php.
 */
require_once __DIR__ . '/../../includes/comp_off.php';
require_login();
require_permission('holidays', 'view');

$page_title = 'Holidays';

$db   = db();
$year = (int)($_GET['year'] ?? date('Y'));

$canCreate = can('holidays', 'create');
$canEdit   = can('holidays', 'edit');
$canDelete = can('holidays', 'delete');

// ── Data ──────────────────────────────────────────────────────────────────────
$stmt = $db->prepare(
    'SELECT h.*, t.name AS type_name, t.color AS type_color
     FROM holidays h LEFT JOIN holiday_types t ON t.id = h.holiday_type_id
     WHERE YEAR(h.h_date) = ? ORDER BY h.h_date'
);
$stmt->execute([$year]);
$holidays = $stmt->fetchAll();
$dbByDate = [];
foreach ($holidays as $h) $dbByDate[$h['h_date']] = $h;

$types    = $db->query('SELECT id, name, color FROM holiday_types ORDER BY name')->fetchAll();
$entities = $db->query('SELECT id, name FROM entities ORDER BY name')->fetchAll();

$weeklySat    = WorkCalendar::firstAndThirdSaturdays($year);   // [date => label]
$summaryMap   = comp_off_year_summary($year);
$availedDates = comp_off_availed_dates($year);
$totalActive  = (int)$db->query("SELECT COUNT(*) FROM employees WHERE status = 'Active'")->fetchColumn();

// ── Walk the year → list of non-working days, grouped by month ────────────────
$allDays = [];
$start = new DateTime("$year-01-01");
$end   = new DateTime("$year-12-31");
for ($d = clone $start; $d <= $end; $d->modify('+1 day')) {
    $ds    = $d->format('Y-m-d');
    $dbRow = $dbByDate[$ds] ?? null;
    $isSun = ((int)$d->format('N') === 7);
    $isSat = isset($weeklySat[$ds]);

    if ($isSun) {
        $row = ['date' => $ds, 'name' => 'Sunday', 'kind' => 'sunday',
                'type_label' => 'Weekly Off', 'type_color' => 'secondary'];
    } elseif ($isSat) {
        $row = ['date' => $ds, 'name' => $weeklySat[$ds], 'kind' => 'weekly_sat',
                'type_label' => 'Weekly Off', 'type_color' => 'secondary'];
    } elseif ($dbRow) {
        $row = ['date' => $ds, 'name' => $dbRow['name'], 'kind' => 'holiday',
                'type_label' => $dbRow['type_name'] ?: 'Holiday',
                'type_color' => $dbRow['type_color'] ?: 'danger'];
    } else {
        continue;
    }
    $row['model']          = $dbRow;
    $row['is_working_day'] = $dbRow ? (int)$dbRow['is_working_day'] : 0;
    $row['locked']         = isset($availedDates[$ds]);
    $allDays[(int)$d->format('n')][] = $row;
}

$publicCount = count($holidays);
$satCount    = count($weeklySat);
$sunCount    = 0;
foreach ($allDays as $days) foreach ($days as $r) if ($r['kind'] === 'sunday') $sunCount++;
$totalNonWorking = $publicCount + count($weeklySat) + $sunCount;

// Quick-add national holidays (fixed-date Indian holidays).
$nationalDefaults = [
    ['md' => '01-26', 'name' => 'Republic Day'],
    ['md' => '08-15', 'name' => 'Independence Day'],
    ['md' => '10-02', 'name' => 'Gandhi Jayanti'],
    ['md' => '12-25', 'name' => 'Christmas'],
    ['md' => '01-01', 'name' => 'New Year'],
];
$nationalTypeId = null;
foreach ($types as $t) if ($t['name'] === 'National') { $nationalTypeId = $t['id']; break; }

$importErrors = $_SESSION['holiday_import_errors'] ?? [];
unset($_SESSION['holiday_import_errors']);

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Holidays — <?= $year ?></h1>
        <p class="page-subtitle"><?= $publicCount ?> public holiday(s) · <?= $satCount ?> Sat offs · <?= $sunCount ?> Sundays · <strong><?= $totalNonWorking ?></strong> non-working days</p>
    </div>
    <div class="page-actions d-flex gap-2 align-items-center">
        <form method="GET" class="d-inline-flex">
            <select name="year" onchange="this.form.submit()" class="form-select form-select-sm" style="width:auto">
                <?php for ($y = (int)date('Y') + 1; $y >= (int)date('Y') - 3; $y--): ?>
                <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </form>
        <a href="<?= BASE_URL ?>/modules/attendance/comp_off.php?year=<?= $year ?>" class="btn btn-sm btn-outline-primary">
            <i class="fa fa-calendar-plus me-1"></i>Comp Offs
        </a>
        <?php if ($canCreate): ?>
        <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#holidayImportModal">
            <i class="fa fa-file-csv me-1"></i>Import CSV
        </button>
        <?php endif; ?>
    </div>
</div>

<?php render_flash(); ?>

<?php if ($importErrors): ?>
<div class="alert alert-warning alert-dismissible fade show">
    <strong>Some rows were skipped:</strong>
    <ul class="mb-0 mt-1 small"><?php foreach ($importErrors as $ie): ?><li><?= h($ie) ?></li><?php endforeach; ?></ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-4">
    <!-- ── Left: month-wise non-working days ─────────────────────────────────── -->
    <div class="col-lg-8">
        <div class="card page-card">
            <div class="card-header bg-white py-3">
                <h5 class="mb-0 fw-semibold"><i class="fa fa-calendar-day me-2 text-danger"></i>Non-Working Days</h5>
            </div>
            <div class="card-body">
                <?php if (!$allDays): ?>
                    <p class="text-muted text-center py-5"><i class="fa fa-calendar-xmark fa-2x d-block mb-2 opacity-25"></i>No data for <?= $year ?>.</p>
                <?php endif; ?>
                <?php for ($m = 1; $m <= 12; $m++): if (empty($allDays[$m])) continue; $days = $allDays[$m]; ?>
                <div class="mb-4">
                    <div class="d-flex align-items-center gap-2 mb-1">
                        <span class="badge bg-dark px-3 py-2"><i class="fa fa-calendar me-1"></i><?= date('F Y', mktime(0, 0, 0, $m, 1, $year)) ?></span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm align-middle mb-0" style="font-size:.86rem">
                            <thead class="table-secondary"><tr>
                                <th style="width:90px">Date</th>
                                <th style="width:90px">Day</th>
                                <th>Name</th>
                                <th style="width:110px">Type</th>
                                <th class="text-center" style="width:120px">Working Day</th>
                                <th class="text-center" style="width:170px">Actions</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($days as $day):
                                $dObj    = new DateTime($day['date']);
                                $isAuto  = in_array($day['kind'], ['sunday', 'weekly_sat'], true);
                                $model   = $day['model'];
                                $working = $day['is_working_day'];
                                $rowCls  = $working ? 'table-success' : ($isAuto ? 'table-light' : '');
                            ?>
                            <tr class="<?= $rowCls ?>">
                                <td class="fw-semibold"><?= $dObj->format('d M') ?></td>
                                <td class="<?= $isAuto ? 'text-muted' : '' ?>"><?= $dObj->format('l') ?></td>
                                <td><?= h($day['name']) ?><?php if ($working): ?> <span class="badge bg-success ms-1" style="font-size:.65rem"><i class="fa fa-briefcase me-1"></i>Working</span><?php endif; ?></td>
                                <td>
                                    <?php if ($day['kind'] === 'holiday'): ?>
                                        <span class="badge bg-<?= h($day['type_color']) ?>"><?= h($day['type_label']) ?></span>
                                    <?php elseif ($day['kind'] === 'weekly_sat'): ?>
                                        <span class="badge bg-secondary" style="font-size:.7rem">Weekly Off</span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-dark border" style="font-size:.7rem">Sunday</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Working-day toggle -->
                                <td class="text-center">
                                    <?php if ($day['locked']): ?>
                                        <span class="btn btn-sm btn-success disabled" style="font-size:.72rem;opacity:.75" title="Locked — comp offs availed"><i class="fa fa-lock me-1"></i>Working</span>
                                    <?php elseif (!$canEdit): ?>
                                        <span class="pill <?= $working ? 'pill-success' : 'pill-neutral' ?>"><?= $working ? 'Working' : 'Off' ?></span>
                                    <?php elseif ($working && $model): ?>
                                        <form method="POST" action="toggle_working.php" class="d-inline" onsubmit="return confirm('Restore as an off day?')">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= $model['id'] ?>">
                                            <input type="hidden" name="year" value="<?= $year ?>">
                                            <button class="btn btn-sm btn-success" style="font-size:.72rem"><i class="fa fa-toggle-on me-1"></i>Working</button>
                                        </form>
                                    <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-outline-secondary btn-mark-working" style="font-size:.72rem"
                                                data-id="<?= $model['id'] ?? '' ?>" data-date="<?= $day['date'] ?>"
                                                data-name="<?= h($day['name']) ?>" data-label="<?= h($day['name']) ?> (<?= $dObj->format('d M Y') ?>)">
                                            <i class="fa fa-toggle-off me-1"></i><?= $isAuto ? 'Off' : 'Holiday' ?>
                                        </button>
                                    <?php endif; ?>
                                </td>

                                <!-- Actions -->
                                <td class="text-center">
                                    <?php if ($day['locked']): ?>
                                        <span class="badge py-2 px-2" style="background:#6f42c1;font-size:.7rem"><i class="fa fa-calendar-check me-1"></i>CO Availed</span>
                                    <?php else: ?>
                                        <div class="d-flex gap-1 justify-content-center flex-wrap">
                                            <?php if ($working && $model): ?>
                                                <a href="circular.php?id=<?= $model['id'] ?>" target="_blank" class="btn btn-sm btn-outline-dark" style="font-size:.72rem" title="Circular"><i class="fa fa-file-pdf me-1"></i>Circular</a>
                                                <?php if ($canCreate): ?>
                                                <form method="POST" action="<?= BASE_URL ?>/modules/attendance/comp_off.php" onsubmit="return confirm('Grant comp off to ALL active employees?')">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="action" value="grant">
                                                    <input type="hidden" name="holiday_date" value="<?= $day['date'] ?>">
                                                    <input type="hidden" name="holiday_name" value="<?= h($day['name']) ?>">
                                                    <input type="hidden" name="back" value="holidays">
                                                    <input type="hidden" name="year" value="<?= $year ?>">
                                                    <button class="btn btn-sm btn-outline-primary" style="font-size:.72rem" title="Grant comp off to all"><i class="fa fa-users me-1"></i>Comp Off All</button>
                                                </form>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php if ($day['kind'] === 'holiday' && $model && $canDelete): ?>
                                                <form method="POST" action="delete.php" class="d-inline" onsubmit="return confirm('Remove &quot;<?= h($day['name']) ?>&quot;?')">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="id" value="<?= $model['id'] ?>">
                                                    <input type="hidden" name="year" value="<?= $year ?>">
                                                    <button class="btn btn-sm btn-outline-danger" style="font-size:.72rem" title="Remove"><i class="fa fa-trash"></i></button>
                                                </form>
                                            <?php elseif (!$working): ?>
                                                <span class="text-muted" style="font-size:.75rem">—</span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endfor; ?>
            </div>
        </div>
    </div>

    <!-- ── Right: add holiday + quick add ────────────────────────────────────── -->
    <div class="col-lg-4">
        <?php if ($canCreate): ?>
        <div class="card page-card">
            <div class="card-header bg-white py-3"><h6 class="mb-0 fw-semibold"><i class="fa fa-plus-circle me-2 text-success"></i>Add Holiday</h6></div>
            <div class="card-body">
                <form method="POST" action="save.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="year" value="<?= $year ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Date <span class="text-danger">*</span></label>
                        <input type="date" name="h_date" class="form-control" required value="<?= $year ?>-<?= date('m-d') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Holiday Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" maxlength="120" required placeholder="e.g. Diwali">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Type</label>
                        <select name="holiday_type_id" class="form-select">
                            <option value="">— None —</option>
                            <?php foreach ($types as $t): ?><option value="<?= $t['id'] ?>"><?= h($t['name']) ?></option><?php endforeach; ?>
                        </select>
                        <small class="text-muted">Manage under <a href="<?= BASE_URL ?>/modules/settings/index.php?tab=holiday-types">Settings → Holiday Types</a>.</small>
                    </div>
                    <button type="submit" class="btn btn-success w-100"><i class="fa fa-plus me-1"></i>Add Holiday</button>
                </form>
            </div>
        </div>

        <div class="card page-card mt-3">
            <div class="card-header bg-white py-3"><h6 class="mb-0 fw-semibold"><i class="fa fa-bolt me-2 text-warning"></i>Quick Add — National (<?= $year ?>)</h6></div>
            <div class="card-body p-2">
                <?php foreach ($nationalDefaults as $nd): $nDate = $year . '-' . $nd['md']; $exists = isset($dbByDate[$nDate]); ?>
                <div class="d-flex align-items-center gap-2 p-2 border-bottom">
                    <div class="flex-grow-1 small"><div class="fw-semibold"><?= h($nd['name']) ?></div><div class="text-muted"><?= date_fmt($nDate) ?></div></div>
                    <?php if ($exists): ?>
                        <span class="badge bg-success"><i class="fa fa-check"></i></span>
                    <?php else: ?>
                        <form method="POST" action="save.php">
                            <?= csrf_field() ?>
                            <input type="hidden" name="h_date" value="<?= $nDate ?>">
                            <input type="hidden" name="name" value="<?= h($nd['name']) ?>">
                            <input type="hidden" name="source" value="national">
                            <?php if ($nationalTypeId): ?><input type="hidden" name="holiday_type_id" value="<?= $nationalTypeId ?>"><?php endif; ?>
                            <input type="hidden" name="year" value="<?= $year ?>">
                            <button class="btn btn-sm btn-outline-success" title="Add"><i class="fa fa-plus"></i></button>
                        </form>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($canEdit): ?>
<!-- ── Mark as Working Day modal ────────────────────────────────────────────── -->
<div class="modal fade" id="workingDayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="workingDayForm" method="POST" action="toggle_working.php">
            <?= csrf_field() ?>
            <input type="hidden" name="id" id="wdId">
            <input type="hidden" name="date" id="wdDate">
            <input type="hidden" name="name" id="wdName">
            <input type="hidden" name="year" value="<?= $year ?>">
            <div class="modal-header bg-warning bg-opacity-10">
                <h5 class="modal-title fw-semibold"><i class="fa fa-calendar-check me-2 text-warning"></i>Mark as Working Day</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3"><i class="fa fa-info-circle me-1 text-primary"></i><strong id="wdLabel"></strong> will be marked as a working day. Comp off is auto-granted to all active employees and a circular becomes available.</p>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Company / Entity <span class="text-danger">*</span></label>
                    <select name="entity_id" class="form-select" required>
                        <option value="">Select Entity…</option>
                        <?php foreach ($entities as $ent): ?><option value="<?= $ent['id'] ?>" <?= count($entities) === 1 ? 'selected' : '' ?>><?= h($ent['name']) ?></option><?php endforeach; ?>
                    </select>
                    <div class="form-text">This entity name appears on the circular.</div>
                </div>
                <div class="mb-2">
                    <label class="form-label fw-semibold">Reason for Working Day <span class="text-danger">*</span></label>
                    <textarea name="working_day_reason" rows="3" class="form-control" maxlength="500" required placeholder="e.g. year-end work, client delivery…"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-warning"><i class="fa fa-calendar-check me-1"></i>Mark as Working Day</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($canCreate): ?>
<!-- ── Import CSV modal ─────────────────────────────────────────────────────── -->
<div class="modal fade" id="holidayImportModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" method="POST" action="import.php" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="modal-header">
                <h5 class="modal-title"><i class="fa fa-file-csv me-2 text-success"></i>Import Holidays from CSV</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-light border small mb-3">
                    <div class="fw-semibold mb-1"><i class="fa fa-info-circle text-primary me-1"></i>CSV format</div>
                    <ul class="mb-1"><li>Column A — <strong>Month</strong> (1–12)</li><li>Column B — <strong>Day</strong> (1–31)</li><li>Column C — <strong>Holiday name</strong> (optional)</li></ul>
                    A header row is skipped automatically. The Year and Type below apply to every row.
                </div>
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Year *</label>
                        <select name="year" class="form-select">
                            <?php for ($y = (int)date('Y') + 1; $y >= (int)date('Y') - 3; $y--): ?><option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Apply Type</label>
                        <select name="holiday_type_id" class="form-select">
                            <option value="">— Default (National) —</option>
                            <?php foreach ($types as $t): ?><option value="<?= $t['id'] ?>"><?= h($t['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">CSV File *</label>
                        <input type="file" name="file" class="form-control" accept=".csv,.txt" required>
                        <div class="form-text">Max 5 MB. Export Excel sheets as CSV first.</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-success"><i class="fa fa-upload me-1"></i>Import Holidays</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php $page_scripts = <<<'JS'
<script>
document.querySelectorAll('.btn-mark-working').forEach(function (btn) {
    btn.addEventListener('click', function () {
        document.getElementById('wdId').value    = this.dataset.id || '';
        document.getElementById('wdDate').value   = this.dataset.date || '';
        document.getElementById('wdName').value   = this.dataset.name || '';
        document.getElementById('wdLabel').textContent = this.dataset.label || '';
        new bootstrap.Modal(document.getElementById('workingDayModal')).show();
    });
});
</script>
JS; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
