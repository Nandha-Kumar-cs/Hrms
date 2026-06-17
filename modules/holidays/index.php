<?php
/**
 * Holidays — list, filter, add / edit, delete and working-day toggle.
 *
 * The actual writes happen in save.php / delete.php / toggle_working.php
 * (POST → redirect → GET). This page is read-only output, so it follows the
 * letters/index.php convention of including the header first.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('holidays', 'view');

$page_title = 'Holidays';
require_once __DIR__ . '/../../includes/header.php';

$db = db();

// ── Filters ──────────────────────────────────────────────────────────────────
$year       = (int)($_GET['year'] ?? date('Y'));
$typeFilter = (int)($_GET['type'] ?? 0);
$editId     = (int)($_GET['edit'] ?? 0);

// Year list for the selector (current year ±3, plus any year already in the data).
$dataYears = $db->query('SELECT DISTINCT YEAR(h_date) y FROM holidays ORDER BY y')->fetchAll(PDO::FETCH_COLUMN);
$thisYear  = (int)date('Y');
$years     = array_unique(array_merge(range($thisYear - 3, $thisYear + 3), array_map('intval', $dataYears)));
sort($years);

// Holiday types (master) for the dropdowns / badges.
$types   = $db->query('SELECT id, name, color FROM holiday_types ORDER BY name')->fetchAll();
$typeById = [];
foreach ($types as $t) $typeById[$t['id']] = $t;

// ── Load holidays for the selected year ──────────────────────────────────────
$sql = 'SELECT h.*, t.name AS type_name, t.color AS type_color
        FROM holidays h
        LEFT JOIN holiday_types t ON t.id = h.holiday_type_id
        WHERE YEAR(h.h_date) = ?';
$params = [$year];
if ($typeFilter) { $sql .= ' AND h.holiday_type_id = ?'; $params[] = $typeFilter; }
$sql .= ' ORDER BY h.h_date';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$holidays = $stmt->fetchAll();

// Per-type counts for the filter tabs (whole year, ignoring the type filter).
$countStmt = $db->prepare('SELECT holiday_type_id, COUNT(*) c FROM holidays WHERE YEAR(h_date)=? GROUP BY holiday_type_id');
$countStmt->execute([$year]);
$typeCounts = $countStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$yearTotal  = array_sum($typeCounts);
$workingCnt = (int)$db->query('SELECT COUNT(*) FROM holidays WHERE YEAR(h_date)=' . $year . ' AND is_working_day=1')->fetchColumn();

// ── Record being edited (prefills the form) ──────────────────────────────────
$edit = null;
if ($editId) {
    $e = $db->prepare('SELECT * FROM holidays WHERE id=?');
    $e->execute([$editId]);
    $edit = $e->fetch() ?: null;
}
$old = $_SESSION['holiday_old'] ?? [];
unset($_SESSION['holiday_old']);
$formVal = function (string $k, $default = '') use ($edit, $old) {
    if (array_key_exists($k, $old))   return $old[$k];
    if ($edit && array_key_exists($k, $edit)) return $edit[$k];
    return $default;
};

// ── National-holiday quick-add list (fixed-date Indian holidays) ─────────────
$nationalDefaults = [
    ['md' => '01-26', 'name' => 'Republic Day'],
    ['md' => '08-15', 'name' => 'Independence Day'],
    ['md' => '10-02', 'name' => 'Gandhi Jayanti'],
    ['md' => '12-25', 'name' => 'Christmas'],
    ['md' => '01-01', 'name' => 'New Year'],
];
$existingDates = [];
foreach ($holidays as $h) $existingDates[$h['h_date']] = true;

$canCreate = can('holidays', 'create');
$canEdit   = can('holidays', 'edit');
$canDelete = can('holidays', 'delete');
?>

<div class="page-head">
    <div>
        <h1>Holidays</h1>
        <p class="muted"><?= $yearTotal ?> holiday<?= $yearTotal === 1 ? '' : 's' ?> in <?= $year ?><?= $workingCnt ? ' · ' . $workingCnt . ' working day' . ($workingCnt === 1 ? '' : 's') : '' ?></p>
    </div>
    <div class="head-actions">
        <form method="GET" class="d-inline-flex" style="gap:8px">
            <select name="year" onchange="this.form.submit()" class="form-select form-select-sm" style="width:auto">
                <?php foreach ($years as $y): ?>
                <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($typeFilter): ?><input type="hidden" name="type" value="<?= $typeFilter ?>"><?php endif; ?>
        </form>
    </div>
</div>

<!-- Type filter tabs -->
<div class="tabs" style="margin-bottom:18px;flex-wrap:wrap">
    <a class="tab <?= !$typeFilter ? 'active' : '' ?>" href="index.php?year=<?= $year ?>">
        All <span class="pill pill-neutral" style="margin-left:4px"><?= $yearTotal ?></span>
    </a>
    <?php foreach ($types as $t): ?>
    <a class="tab <?= $typeFilter === (int)$t['id'] ? 'active' : '' ?>" href="index.php?year=<?= $year ?>&type=<?= $t['id'] ?>">
        <?= h($t['name']) ?> <span class="pill pill-neutral" style="margin-left:4px"><?= (int)($typeCounts[$t['id']] ?? 0) ?></span>
    </a>
    <?php endforeach; ?>
</div>

<div class="holiday-layout">
    <!-- ── List ─────────────────────────────────────────────────────────── -->
    <div class="card">
        <div class="card-head">
            <h3>Holiday List · <?= $year ?></h3>
        </div>
        <div class="card-body">
        <table class="data-table" id="tbl-holidays">
            <thead><tr>
                <th>Date</th>
                <th>Day</th>
                <th>Holiday</th>
                <th>Type</th>
                <th>Working Day</th>
                <?php if ($canEdit || $canDelete): ?><th class="r">Actions</th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($holidays as $hday):
                $color = $hday['type_color'] ?: 'secondary';
            ?>
            <tr<?= $hday['is_working_day'] ? ' style="background:#ecfdf5"' : '' ?>>
                <td class="nowrap"><strong><?= date_fmt($hday['h_date']) ?></strong></td>
                <td class="nowrap"><?= date_fmt($hday['h_date'], 'l') ?></td>
                <td><?= h($hday['name']) ?></td>
                <td>
                    <?php if ($hday['type_name']): ?>
                        <span class="badge bg-<?= h($color) ?>"><?= h($hday['type_name']) ?></span>
                    <?php else: ?>
                        <span class="badge bg-secondary"><?= h($hday['type'] ?? '—') ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($canEdit): ?>
                    <form method="POST" action="toggle_working.php" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $hday['id'] ?>">
                        <input type="hidden" name="year" value="<?= $year ?>">
                        <button type="submit" class="btn btn-sm <?= $hday['is_working_day'] ? 'btn-success' : 'btn-ghost' ?>"
                                title="Toggle working day">
                            <?= $hday['is_working_day'] ? '✓ Working' : 'Holiday' ?>
                        </button>
                    </form>
                    <?php else: ?>
                        <span class="pill <?= $hday['is_working_day'] ? 'pill-success' : 'pill-neutral' ?>">
                            <?= $hday['is_working_day'] ? 'Working' : 'Holiday' ?>
                        </span>
                    <?php endif; ?>
                </td>
                <?php if ($canEdit || $canDelete): ?>
                <td class="r nowrap">
                    <?php if ($canEdit): ?>
                    <a href="index.php?year=<?= $year ?>&edit=<?= $hday['id'] ?>#holiday-form" class="btn btn-sm">Edit</a>
                    <?php endif; ?>
                    <?php if ($canDelete): ?>
                    <form method="POST" action="delete.php" class="d-inline"
                          onsubmit="return confirm('Delete holiday &quot;<?= h($hday['name']) ?>&quot;?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $hday['id'] ?>">
                        <input type="hidden" name="year" value="<?= $year ?>">
                        <button type="submit" class="btn btn-sm btn-danger">Del</button>
                    </form>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php if (!$holidays): ?>
            <p class="muted" style="padding:12px 4px">No holidays found for <?= $year ?><?= $typeFilter ? ' in this type' : '' ?>.</p>
        <?php endif; ?>
        </div>
    </div>

    <!-- ── Side panel: add / edit + quick add ───────────────────────────── -->
    <div>
        <?php if ($canCreate || ($edit && $canEdit)): ?>
        <div class="card form-card" id="holiday-form">
            <div class="section-title"><?= $edit ? 'Edit Holiday' : 'Add Holiday' ?></div>
            <form method="POST" action="save.php">
                <?= csrf_field() ?>
                <?php if ($edit): ?><input type="hidden" name="id" value="<?= $edit['id'] ?>"><?php endif; ?>
                <input type="hidden" name="year" value="<?= $year ?>">
                <div class="field">
                    <label>Date *</label>
                    <input type="date" name="h_date" required value="<?= h($formVal('h_date', $year . '-' . date('m-d'))) ?>">
                </div>
                <div class="field">
                    <label>Holiday Name *</label>
                    <input type="text" name="name" maxlength="120" required
                           placeholder="e.g. Diwali" value="<?= h($formVal('name')) ?>">
                </div>
                <div class="field">
                    <label>Type</label>
                    <select name="holiday_type_id">
                        <option value="">— Select Type —</option>
                        <?php foreach ($types as $t): ?>
                        <option value="<?= $t['id'] ?>" <?= (int)$formVal('holiday_type_id') === (int)$t['id'] ? 'selected' : '' ?>>
                            <?= h($t['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="muted">Manage types under
                        <a href="<?= BASE_URL ?>/modules/settings/index.php?tab=holiday-types">Settings → Holiday Types</a>.
                    </small>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><?= $edit ? 'Update' : 'Add Holiday' ?></button>
                    <?php if ($edit): ?>
                    <a href="index.php?year=<?= $year ?>" class="btn btn-ghost">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($canCreate): ?>
        <div class="card form-card">
            <div class="section-title">Quick Add — National Holidays (<?= $year ?>)</div>
            <p class="muted small" style="margin-top:-6px">One-click add for fixed-date holidays.</p>
            <?php foreach ($nationalDefaults as $nd):
                $d = $year . '-' . $nd['md'];
                $exists = isset($existingDates[$d]);
            ?>
            <div class="d-flex align-items-center justify-content-between" style="padding:6px 0;border-bottom:1px solid var(--border)">
                <div>
                    <strong><?= h($nd['name']) ?></strong>
                    <div class="small muted"><?= date_fmt($d) ?></div>
                </div>
                <?php if ($exists): ?>
                    <span class="pill pill-success">Added ✓</span>
                <?php else: ?>
                    <form method="POST" action="save.php" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="h_date" value="<?= $d ?>">
                        <input type="hidden" name="name" value="<?= h($nd['name']) ?>">
                        <input type="hidden" name="source" value="national">
                        <input type="hidden" name="year" value="<?= $year ?>">
                        <button type="submit" class="btn btn-sm btn-primary">+ Add</button>
                    </form>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
.holiday-layout { display:grid; grid-template-columns:minmax(0,2.2fr) minmax(0,1fr); gap:18px; align-items:start; }
@media (max-width: 900px){ .holiday-layout { grid-template-columns:1fr; } }
.holiday-layout .form-card { margin-bottom:18px; }
</style>

<?php $page_scripts = <<<'JS'
<script>
$(function () {
    if ($.fn.DataTable) {
        $('#tbl-holidays').DataTable({
            pageLength: 25,
            order: [[0, 'asc']],
            language: { search: '', searchPlaceholder: 'Search holidays...', emptyTable: 'No holidays found.' },
            columnDefs: [{ orderable: false, targets: '_all' }, { orderable: true, targets: [0] }]
        });
    }
});
</script>
JS; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
