<?php
/**
 * Leave Status — yearly grid of each employee's APPROVED leave days per month
 * (Jan–Dec) plus an Annual paid-quota balance. Ported from the reference
 * Employee_Management LeaveStatusController.
 *
 *   • Paid vs unpaid is taken from leave_types.is_paid.
 *   • Only working days within each leave range are counted (Sundays, 1st/3rd
 *     Saturdays and holidays excluded — via WorkCalendar::isWorkingDay).
 *   • Annual quota accrues 1 paid leave/month: for the current year that is the
 *     current month number, otherwise 12. Balance = quota − paid days used.
 */
$page_title = 'Leave Status';
require_once __DIR__ . '/../../includes/header.php';
require_permission('leave_history', 'view');
require_once __DIR__ . '/../../includes/comp_off.php';   // WorkCalendar

$db = db();
const LS_MONTHLY_QUOTA = 1;

// ── Inputs ───────────────────────────────────────────────────────────────────
$year        = (int)($_GET['year'] ?? date('Y'));
$deptId      = (int)($_GET['department'] ?? 0);
$search      = trim($_GET['search'] ?? '');
$empStatus   = ($_GET['emp_status'] ?? 'active') === 'all' ? 'all' : 'active';
$leaveTypeId = (int)($_GET['leave_type'] ?? 0);
$page        = max(1, (int)($_GET['page'] ?? 1));
$perPage     = 30;

$currentYear  = (int)date('Y');
$currentMonth = (int)date('n');
$isCurrentYr  = ($year === $currentYear);
$yearFuture   = ($year > $currentYear);

// Self-scoped users (e.g. an Employee) only ever see their own row.
$scopeEmp = is_self_scoped() ? (int)current_employee_id() : 0;

// ── Employee query (filters + pagination) ────────────────────────────────────
$where  = 'WHERE 1=1';
$params = [];
if ($empStatus !== 'all') { $where .= ' AND e.status = ?';          $params[] = 'Active'; }
if ($deptId)              { $where .= ' AND e.department_id = ?';    $params[] = $deptId; }
if ($search)              { $where .= ' AND (e.name LIKE ? OR e.employee_id LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
if ($scopeEmp)            { $where .= ' AND e.id = ?';               $params[] = $scopeEmp; }

$cnt = $db->prepare("SELECT COUNT(*) FROM employees e $where");
$cnt->execute($params);
$total      = (int)$cnt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$empSt = $db->prepare(
    "SELECT e.id, e.name, e.employee_id AS emp_code, d.name AS dept_name
       FROM employees e
       LEFT JOIN departments d ON d.id = e.department_id
       $where
       ORDER BY e.name
       LIMIT $perPage OFFSET $offset"
);
$empSt->execute($params);
$employees = $empSt->fetchAll();

// ── Approved leaves for these employees in the year (with paid flag) ─────────
$leavesByEmp = [];
$empIds = array_column($employees, 'id');
if ($empIds) {
    $in      = implode(',', array_fill(0, count($empIds), '?'));
    $lwhere  = "lr.employee_id IN ($in) AND lr.status = 'approved' AND lr.end_date >= ? AND lr.start_date <= ?";
    $lparams = array_merge($empIds, ["$year-01-01", "$year-12-31"]);
    if ($leaveTypeId) { $lwhere .= ' AND lr.leave_type_id = ?'; $lparams[] = $leaveTypeId; }

    $lst = $db->prepare(
        "SELECT lr.employee_id, lr.start_date, lr.end_date, COALESCE(lt.is_paid, 1) AS is_paid
           FROM leave_requests lr
           LEFT JOIN leave_types lt ON lt.id = lr.leave_type_id
          WHERE $lwhere"
    );
    $lst->execute($lparams);
    foreach ($lst->fetchAll() as $r) {
        $leavesByEmp[(int)$r['employee_id']][] = $r;
    }
}

/** Count approved working days in a month, split into [paid, unpaid] (deduped). */
function ls_count_month(array $leaves, string $mStart, string $mEnd): array
{
    $paid = []; $unpaid = [];
    foreach ($leaves as $r) {
        $isPaid = (int)$r['is_paid'] === 1;
        $from = max($r['start_date'], $mStart);
        $to   = min($r['end_date'],   $mEnd);
        if ($from > $to) continue;
        $cur = new DateTime($from);
        $end = new DateTime($to);
        while ($cur <= $end) {
            $ds = $cur->format('Y-m-d');
            if (!isset($paid[$ds]) && !isset($unpaid[$ds]) && WorkCalendar::isWorkingDay($cur)) {
                if ($isPaid) $paid[$ds] = true; else $unpaid[$ds] = true;
            }
            $cur->modify('+1 day');
        }
    }
    return [count($paid), count($unpaid)];
}

// ── Build the grid ───────────────────────────────────────────────────────────
$grid = [];
$cntGreen = $cntNeutral = $cntRed = 0;
foreach ($employees as $emp) {
    $eid       = (int)$emp['id'];
    $empLeaves = $leavesByEmp[$eid] ?? [];
    $annPaid = 0; $annUnpaid = 0;

    for ($m = 1; $m <= 12; $m++) {
        $mStart = sprintf('%04d-%02d-01', $year, $m);
        $mEnd   = date('Y-m-t', strtotime($mStart));
        [$p, $u] = $empLeaves ? ls_count_month($empLeaves, $mStart, $mEnd) : [0, 0];
        $annPaid += $p; $annUnpaid += $u;
        $grid[$eid][$m] = ['p' => $p, 'u' => $u];
    }

    $monthsElapsed = $isCurrentYr ? $currentMonth : 12;
    $quota   = $monthsElapsed * LS_MONTHLY_QUOTA;
    $balance = $quota - $annPaid;
    $grid[$eid]['annual'] = ['p' => $annPaid, 'u' => $annUnpaid, 'quota' => $quota, 'balance' => $balance];

    if ($balance > 0)      $cntGreen++;
    elseif ($balance == 0) $cntNeutral++;
    else                   $cntRed++;
}

// ── Filter dropdown data ─────────────────────────────────────────────────────
$departments = $db->query('SELECT id, name FROM departments ORDER BY name')->fetchAll();
$leaveTypes  = $db->query("SELECT id, name FROM leave_types WHERE status='active' ORDER BY name")->fetchAll();

/** Build a query-string preserving the current filters (override given keys). */
$qs = function (array $over = []) use ($year, $deptId, $search, $empStatus, $leaveTypeId, $page) {
    $a = array_merge([
        'year' => $year, 'department' => $deptId ?: '', 'search' => $search,
        'emp_status' => $empStatus, 'leave_type' => $leaveTypeId ?: '', 'page' => $page,
    ], $over);
    return '?' . http_build_query(array_filter($a, fn($v) => $v !== '' && $v !== 0));
};
?>

<style>
.ls-chip { display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:20px;font-size:.78rem;font-weight:600; }
.ls-chip-green   { background:#dcfce7;color:#15803d;border:1px solid #bbf7d0; }
.ls-chip-neutral { background:#f1f5f9;color:#475569;border:1px solid #cbd5e1; }
.ls-chip-red     { background:#fee2e2;color:#b91c1c;border:1px solid #fecaca; }
.ls-table { font-size:.82rem; }
.ls-table th, .ls-table td { padding:6px 8px; white-space:nowrap; }
.ls-table thead th { background:#1e293b; color:#fff; text-align:center; vertical-align:middle; font-weight:600; }
.ls-table thead th.col-current { background:#2563eb !important; }
.ls-table thead th.col-annual  { background:#334155 !important; }
.ls-table td.col-current { border-left:3px solid #3b82f6; border-right:3px solid #3b82f6; }
.ls-table td.col-annual  { background:#f8fafc; font-weight:700; border-left:2px solid #94a3b8; }
.ls-paid   { color:#15803d; font-weight:700; }
.ls-unpaid { color:#b91c1c; font-weight:700; }
.ls-bal-pos { color:#15803d; }
.ls-bal-neg { color:#b91c1c; }
@media print { #sidebar, #topbar, .ls-noprint { display:none !important; } #mainContent { padding:0 !important; } }
</style>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <h5 class="mb-0 fw-semibold"><i class="fa fa-calendar-check me-2 text-primary"></i>Leave Status</h5>
        <form method="GET" class="d-flex align-items-end gap-2 flex-wrap ls-noprint">
            <div>
                <label class="form-label small fw-semibold mb-1">Year</label>
                <select name="year" class="form-select form-select-sm">
                    <?php for ($y = $currentYear + 1; $y >= $currentYear - 4; $y--): ?>
                    <option value="<?= $y ?>" <?= $year === $y ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div>
                <label class="form-label small fw-semibold mb-1">Department</label>
                <select name="department" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $deptId === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label small fw-semibold mb-1">Leave Type</label>
                <select name="leave_type" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach ($leaveTypes as $lt): ?>
                    <option value="<?= $lt['id'] ?>" <?= $leaveTypeId === (int)$lt['id'] ? 'selected' : '' ?>><?= h($lt['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="form-label small fw-semibold mb-1">Status</label>
                <select name="emp_status" class="form-select form-select-sm">
                    <option value="active" <?= $empStatus === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="all"    <?= $empStatus === 'all'    ? 'selected' : '' ?>>All</option>
                </select>
            </div>
            <div>
                <label class="form-label small fw-semibold mb-1">Search</label>
                <input type="text" name="search" value="<?= h($search) ?>" class="form-control form-control-sm" placeholder="Name / code" style="min-width:140px">
            </div>
            <div class="d-flex gap-1">
                <button class="btn btn-sm btn-primary"><i class="fa fa-filter"></i></button>
                <a href="?" class="btn btn-sm btn-outline-secondary">Reset</a>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()"><i class="fa fa-print"></i></button>
            </div>
        </form>
    </div>

    <div class="card-body">
        <!-- Summary chips -->
        <div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
            <span class="text-muted small me-1"><?= $year ?> &mdash; <?= $total ?> employees</span>
            <span class="ls-chip ls-chip-green"><i class="fa fa-circle-check"></i><?= $cntGreen ?> have annual balance</span>
            <span class="ls-chip ls-chip-neutral"><i class="fa fa-minus-circle"></i><?= $cntNeutral ?> fully used</span>
            <span class="ls-chip ls-chip-red"><i class="fa fa-circle-exclamation"></i><?= $cntRed ?> exceeded quota</span>
        </div>

        <!-- Legend -->
        <div class="alert alert-light border py-2 mb-3 small">
            <i class="fa fa-info-circle text-primary me-1"></i>
            Shows <span class="badge bg-success">Approved</span> leave days only &mdash; leave type-based.
            &nbsp;|&nbsp;
            <span class="fw-bold ls-paid">&#9632;</span> Green = Paid leave (no deduction) &nbsp;
            <span class="fw-bold ls-unpaid">&#9632;</span> Red = Unpaid leave (salary deducted).
            &nbsp;|&nbsp;
            <strong>&mdash;</strong> = No leave taken / future month.
            &nbsp;|&nbsp;
            Annual <strong>bal:</strong> = paid-quota balance (1 paid leave/month).
        </div>

        <div class="table-responsive">
            <table class="table table-bordered align-middle mb-0 ls-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th style="text-align:left">Employee</th>
                        <th style="text-align:left">Department</th>
                        <?php for ($m = 1; $m <= 12; $m++):
                            $isCurr = ($m === $currentMonth && $isCurrentYr); ?>
                        <th class="<?= $isCurr ? 'col-current' : '' ?>" style="min-width:54px">
                            <?= date('M', mktime(0, 0, 0, $m, 1)) ?>
                            <br><span style="font-size:.65rem;font-weight:400;opacity:.75"><?= $year ?></span>
                        </th>
                        <?php endfor; ?>
                        <th class="col-annual" style="min-width:72px">Annual<br><span style="font-size:.65rem;font-weight:400;opacity:.75">Balance</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$employees): ?>
                    <tr><td colspan="16" class="text-center text-muted py-4">No employees found.</td></tr>
                    <?php else: foreach ($employees as $i => $emp):
                        $eid = (int)$emp['id']; $g = $grid[$eid]; ?>
                    <tr>
                        <td class="text-center text-muted"><?= $offset + $i + 1 ?></td>
                        <td>
                            <div class="fw-semibold"><?= h($emp['name']) ?></div>
                            <small class="text-muted"><?= h($emp['emp_code']) ?></small>
                        </td>
                        <td class="small text-muted"><?= h($emp['dept_name'] ?? '—') ?></td>

                        <?php for ($m = 1; $m <= 12; $m++):
                            $isCurr   = ($m === $currentMonth && $isCurrentYr);
                            $isFuture = $yearFuture || ($isCurrentYr && $m > $currentMonth);
                            $p = $g[$m]['p']; $u = $g[$m]['u'];
                            $tip = $isFuture ? 'Not yet applicable'
                                 : (($p || $u)
                                    ? trim(($p ? "$p paid" : '') . ($p && $u ? ' + ' : '') . ($u ? "$u unpaid" : '')) . ' leave day(s)'
                                    : 'No approved leave'); ?>
                        <td class="text-center <?= $isCurr ? 'col-current' : '' ?>" title="<?= date('F', mktime(0,0,0,$m,1)) ?>: <?= h($tip) ?>">
                            <?php if ($isFuture || (!$p && !$u)): ?>
                                <span style="color:#cbd5e1">&mdash;</span>
                            <?php elseif ($p && !$u): ?>
                                <span class="ls-paid"><?= $p ?>d</span>
                            <?php elseif ($u && !$p): ?>
                                <span class="ls-unpaid"><?= $u ?>d</span>
                            <?php else: ?>
                                <span class="ls-paid"><?= $p ?>p</span>&nbsp;<span class="ls-unpaid"><?= $u ?>u</span>
                            <?php endif; ?>
                        </td>
                        <?php endfor; ?>

                        <?php $a = $g['annual']; $ap = $a['p']; $au = $a['u']; $ab = $a['balance']; ?>
                        <td class="text-center col-annual">
                            <?php if ($yearFuture): ?>
                                <span style="color:#cbd5e1">&mdash;</span>
                            <?php elseif (!$ap && !$au): ?>
                                <span class="text-muted">&mdash;</span>
                                <br><small style="font-size:.65rem;color:#94a3b8">bal: +<?= (int)$a['quota'] ?></small>
                            <?php else: ?>
                                <?php if ($ap && !$au): ?>
                                    <span class="ls-paid"><?= $ap ?>d</span>
                                <?php elseif ($au && !$ap): ?>
                                    <span class="ls-unpaid"><?= $au ?>d</span>
                                <?php else: ?>
                                    <span class="ls-paid"><?= $ap ?>p</span>&nbsp;<span class="ls-unpaid"><?= $au ?>u</span>
                                <?php endif; ?>
                                <br><small style="font-size:.65rem;font-weight:600" class="<?= $ab >= 0 ? 'ls-bal-pos' : 'ls-bal-neg' ?>">bal: <?= $ab >= 0 ? '+' . $ab : $ab ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="d-flex justify-content-between align-items-center mt-3 ls-noprint">
            <small class="text-muted">Page <?= $page ?> of <?= $totalPages ?> &middot; <?= $total ?> employees</small>
            <div class="btn-group btn-group-sm">
                <a class="btn btn-outline-secondary <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= $page <= 1 ? '#' : h($qs(['page' => $page - 1])) ?>">‹ Prev</a>
                <a class="btn btn-outline-secondary <?= $page >= $totalPages ? 'disabled' : '' ?>" href="<?= $page >= $totalPages ? '#' : h($qs(['page' => $page + 1])) ?>">Next ›</a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
