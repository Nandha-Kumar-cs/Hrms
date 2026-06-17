<?php
/**
 * Audit → Activity Log
 *
 * Admin-only audit viewer over activity_logs. Filters (user / module / action /
 * date range / search), server-side pagination, and CSV / PDF / Print export.
 *
 * Description rendering: plain text, OR JSON {summary, changes:[{field,from,to}]}
 * shown as old → new (mirrors the Laravel activity-log view). Entries are
 * written by includes/activity_log.php from across the app.
 *
 * Exports run BEFORE any HTML output (they emit their own headers and exit).
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('activity', 'view');

$db = db();

// ── Filters ──────────────────────────────────────────────────────────────────
$module = trim((string)($_GET['module'] ?? ''));
$action = trim((string)($_GET['action'] ?? ''));
$userF  = trim((string)($_GET['user'] ?? ''));
$search = trim((string)($_GET['search'] ?? ''));
if (mb_strlen($search) > 100) $search = mb_substr($search, 0, 100);
$from   = trim((string)($_GET['from'] ?? ''));
$to     = trim((string)($_GET['to'] ?? ''));
$validDate = fn ($d) => $d !== '' && DateTime::createFromFormat('Y-m-d', $d) && DateTime::createFromFormat('Y-m-d', $d)->format('Y-m-d') === $d;
if (!$validDate($from)) $from = '';
if (!$validDate($to))   $to = '';
$export = (string)($_GET['export'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;

// ── WHERE assembly (shared by list, count, export) ───────────────────────────
$where = [];
$params = [];
if ($module !== '') { $where[] = 'module = ?';    $params[] = $module; }
if ($action !== '') { $where[] = 'action = ?';    $params[] = $action; }
if ($userF !== '')  { $where[] = 'user_name = ?'; $params[] = $userF; }
if ($from)          { $where[] = 'created_at >= ?'; $params[] = $from . ' 00:00:00'; }
if ($to)            { $where[] = 'created_at <= ?'; $params[] = $to . ' 23:59:59'; }
if ($search !== '') { $where[] = '(user_name LIKE ? OR description LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// ── Description renderers ────────────────────────────────────────────────────
/** Flat plain-text version (CSV / PDF). */
function activity_desc_plain(?string $d): string {
    $j = json_decode((string)$d, true);
    if (is_array($j) && isset($j['summary'])) {
        $parts = [$j['summary']];
        foreach ($j['changes'] ?? [] as $c) {
            $parts[] = ($c['field'] ?? '') . ': ' . ($c['from'] ?? '') . ' -> ' . ($c['to'] ?? '');
        }
        return implode(' | ', $parts);
    }
    return (string)$d;
}

$moduleColors = [
    'Employee'=>'primary','Auth'=>'secondary','Increment'=>'success','Promotion'=>'info',
    'Bonus'=>'warning','Benefit'=>'info','Holiday'=>'primary','OD'=>'info',
    'Department'=>'secondary','Designation'=>'secondary','Asset'=>'dark',
];
$actionColors = [
    'created'=>'success','updated'=>'primary','deleted'=>'danger','approved'=>'success',
    'rejected'=>'danger','login'=>'info','logout'=>'secondary',
];

// ── Export (all matching rows, no pagination) ────────────────────────────────
if ($export === 'csv' || $export === 'pdf') {
    $rowsStmt = $db->prepare("SELECT * FROM activity_logs $whereSql ORDER BY created_at DESC, id DESC");
    $rowsStmt->execute($params);
    $all = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

    if ($export === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="activity_log.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Date & Time', 'User', 'Module', 'Action', 'Description', 'IP']);
        foreach ($all as $r) {
            fputcsv($out, [
                date_fmt($r['created_at'], 'd M Y H:i'), $r['user_name'], $r['module'],
                ucfirst($r['action']), activity_desc_plain($r['description']), $r['ip_address'] ?? '',
            ]);
        }
        fclose($out);
        exit;
    }
    // PDF
    require_once __DIR__ . '/../../lib/fpdf/fpdf.php';
    $t = function ($s) {
        $s = str_replace(['₹', '→', '—'], ['Rs.', '->', '-'], (string)$s);
        $r = @iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $s);
        return $r !== false ? $r : $s;
    };
    $pdf = new FPDF('L', 'mm', 'A4');           // landscape for the wide description column
    $pdf->SetTitle('Activity Log');
    $pdf->SetAutoPageBreak(true, 10);
    $pdf->AddPage();
    $pdf->SetFont('Arial', 'B', 14);
    $pdf->Cell(0, 8, $t('Activity Log'), 0, 1, 'C');
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->Cell(0, 5, $t(count($all) . ' entr' . (count($all) === 1 ? 'y' : 'ies')), 0, 1, 'C');
    $pdf->Ln(1);
    $w = [34, 34, 24, 22, 163]; // Date / User / Module / Action / Description = 277 (A4 landscape printable)
    $head = function () use ($pdf, $w, $t) {
        $pdf->SetFont('Arial', 'B', 8);
        $pdf->SetFillColor(33, 37, 41); $pdf->SetTextColor(255);
        $pdf->Cell($w[0], 6, $t('Date & Time'), 1, 0, 'L', true);
        $pdf->Cell($w[1], 6, $t('User'), 1, 0, 'L', true);
        $pdf->Cell($w[2], 6, $t('Module'), 1, 0, 'L', true);
        $pdf->Cell($w[3], 6, $t('Action'), 1, 0, 'L', true);
        $pdf->Cell($w[4], 6, $t('Description'), 1, 1, 'L', true);
        $pdf->SetTextColor(0); $pdf->SetFont('Arial', '', 8);
    };
    $head();
    foreach ($all as $r) {
        if ($pdf->GetY() > 195) { $pdf->AddPage(); $head(); }
        $desc = activity_desc_plain($r['description']);
        if (mb_strlen($desc) > 105) $desc = mb_substr($desc, 0, 102) . '...';
        $pdf->Cell($w[0], 6, $t(date_fmt($r['created_at'], 'd M Y H:i')), 1, 0, 'L');
        $pdf->Cell($w[1], 6, $t($r['user_name']), 1, 0, 'L');
        $pdf->Cell($w[2], 6, $t($r['module']), 1, 0, 'L');
        $pdf->Cell($w[3], 6, $t(ucfirst($r['action'])), 1, 0, 'L');
        $pdf->Cell($w[4], 6, $t($desc), 1, 1, 'L');
    }
    if (!$all) { $pdf->SetFont('Arial', 'I', 11); $pdf->Cell(0, 10, $t('No activity found.'), 0, 1, 'C'); }
    $pdf->Output('D', 'activity_log.pdf');
    exit;
}

// ── Count + page ─────────────────────────────────────────────────────────────
$countStmt = $db->prepare("SELECT COUNT(*) FROM activity_logs $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
if ($page > $pages) $page = $pages;
$offset = ($page - 1) * $perPage;

$listStmt = $db->prepare("SELECT * FROM activity_logs $whereSql ORDER BY created_at DESC, id DESC LIMIT $perPage OFFSET $offset");
$listStmt->execute($params);
$logs = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// Filter dropdown sources.
$modules = $db->query('SELECT DISTINCT module FROM activity_logs ORDER BY module')->fetchAll(PDO::FETCH_COLUMN);
$actions = $db->query('SELECT DISTINCT action FROM activity_logs ORDER BY action')->fetchAll(PDO::FETCH_COLUMN);
$users   = $db->query('SELECT DISTINCT user_name FROM activity_logs ORDER BY user_name')->fetchAll(PDO::FETCH_COLUMN);

$exportQs = http_build_query([
    'module' => $module, 'action' => $action, 'user' => $userF,
    'search' => $search, 'from' => $from, 'to' => $to,
]);
$pageQs = function ($p) use ($module, $action, $userF, $search, $from, $to) {
    return '?' . http_build_query(array_filter([
        'module' => $module, 'action' => $action, 'user' => $userF,
        'search' => $search, 'from' => $from, 'to' => $to, 'page' => $p,
    ], fn ($v) => $v !== '' && $v !== null));
};
$hasFilters = ($module || $action || $userF || $search || $from || $to);

$page_title = 'Activity Log';
require_once __DIR__ . '/../../includes/header.php';
?>
<div class="page-head no-print">
    <div>
        <h1>Activity Log</h1>
        <p class="muted"><?= number_format($total) ?> entr<?= $total === 1 ? 'y' : 'ies' ?><?= $hasFilters ? ' (filtered)' : '' ?></p>
    </div>
    <div class="head-actions">
        <a href="?export=csv&<?= h($exportQs) ?>" class="btn btn-sm"><i class="fa fa-file-csv me-1"></i>Excel</a>
        <a href="?export=pdf&<?= h($exportQs) ?>" class="btn btn-sm"><i class="fa fa-file-pdf me-1"></i>PDF</a>
        <button type="button" class="btn btn-sm" onclick="window.print()"><i class="fa fa-print me-1"></i>Print</button>
    </div>
</div>

<?php render_flash(); ?>

<!-- Filters -->
<div class="card page-card mb-3 no-print">
    <div class="card-body py-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-sm-3">
                <label class="form-label fw-semibold mb-1">Search</label>
                <input type="text" name="search" class="form-control form-control-sm" placeholder="User or description…" value="<?= h($search) ?>">
            </div>
            <div class="col-sm-2">
                <label class="form-label fw-semibold mb-1">User</label>
                <select name="user" class="form-select form-select-sm">
                    <option value="">All Users</option>
                    <?php foreach ($users as $u): ?>
                    <option value="<?= h($u) ?>" <?= $userF === $u ? 'selected' : '' ?>><?= h($u) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-2">
                <label class="form-label fw-semibold mb-1">Module</label>
                <select name="module" class="form-select form-select-sm">
                    <option value="">All Modules</option>
                    <?php foreach ($modules as $m): ?>
                    <option value="<?= h($m) ?>" <?= $module === $m ? 'selected' : '' ?>><?= h($m) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-2">
                <label class="form-label fw-semibold mb-1">Action</label>
                <select name="action" class="form-select form-select-sm">
                    <option value="">All Actions</option>
                    <?php foreach ($actions as $a): ?>
                    <option value="<?= h($a) ?>" <?= $action === $a ? 'selected' : '' ?>><?= ucfirst(h($a)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-3 d-flex gap-2">
                <div><label class="form-label fw-semibold mb-1">From</label><input type="date" name="from" class="form-control form-control-sm" value="<?= h($from) ?>"></div>
                <div><label class="form-label fw-semibold mb-1">To</label><input type="date" name="to" class="form-control form-control-sm" value="<?= h($to) ?>"></div>
            </div>
            <div class="col-12 d-flex gap-2 mt-2">
                <button type="submit" class="btn btn-primary btn-sm px-3"><i class="fa fa-filter me-1"></i>Filter</button>
                <?php if ($hasFilters): ?><a href="?" class="btn btn-outline-secondary btn-sm px-3"><i class="fa fa-times me-1"></i>Reset</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="print-only" style="display:none"><h3 style="margin:0 0 10px">Activity Log</h3></div>

<div class="card page-card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:150px">Date &amp; Time</th>
                        <th style="width:140px">User</th>
                        <th style="width:110px">Module</th>
                        <th style="width:100px">Action</th>
                        <th>Description</th>
                        <th style="width:120px">IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log):
                        $mc = $moduleColors[$log['module']] ?? 'secondary';
                        $ac = $actionColors[$log['action']] ?? 'secondary';
                        $j  = json_decode((string)$log['description'], true);
                        $isJson = is_array($j) && isset($j['summary']);
                    ?>
                    <tr>
                        <td class="small nowrap"><?= date_fmt($log['created_at'], 'd M Y') ?><div class="text-muted"><?= date_fmt($log['created_at'], 'H:i') ?></div></td>
                        <td class="small fw-semibold"><?= h($log['user_name']) ?></td>
                        <td><span class="badge bg-<?= $mc ?>"><?= h($log['module']) ?></span></td>
                        <td><span class="badge bg-<?= $ac ?>"><?= ucfirst(h($log['action'])) ?></span></td>
                        <td class="small">
                            <?php if ($isJson): ?>
                                <div class="fw-semibold mb-1"><?= h($j['summary']) ?></div>
                                <?php foreach ($j['changes'] ?? [] as $c): ?>
                                <div class="d-flex align-items-center flex-wrap gap-1 mb-1">
                                    <span class="text-muted fw-semibold" style="min-width:110px"><?= h($c['field'] ?? '') ?>:</span>
                                    <span class="badge" style="background:#fee2e2;color:#991b1b;font-weight:500;text-decoration:line-through"><?= h($c['from'] ?? '') ?></span>
                                    <i class="fa fa-arrow-right-long text-muted" style="font-size:.65rem"></i>
                                    <span class="badge" style="background:#dcfce7;color:#166534;font-weight:500"><?= h($c['to'] ?? '') ?></span>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <?= h($log['description'] ?? '') ?>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= h($log['ip_address'] ?? '') ?: '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (!$logs): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No activity found<?= $hasFilters ? ' for the selected filters' : '' ?>.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($pages > 1): ?>
<div class="d-flex justify-content-between align-items-center mt-3 no-print">
    <small class="text-muted">Page <?= $page ?> of <?= $pages ?> · <?= number_format($total) ?> entries</small>
    <div class="btn-group">
        <a class="btn btn-sm btn-outline-secondary <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= $page > 1 ? h($pageQs($page - 1)) : '#' ?>">‹ Prev</a>
        <a class="btn btn-sm btn-outline-secondary <?= $page >= $pages ? 'disabled' : '' ?>" href="<?= $page < $pages ? h($pageQs($page + 1)) : '#' ?>">Next ›</a>
    </div>
</div>
<?php endif; ?>

<style>
@media print {
    #sidebar, #topbar, .no-print, .sidebar-overlay { display: none !important; }
    .main-wrapper, #mainContent { margin: 0 !important; padding: 0 !important; }
    .print-only { display: block !important; }
    .card { box-shadow: none !important; }
}
</style>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
