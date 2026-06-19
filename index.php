<?php
$page_title = 'Dashboard';

// Bootstrap + FA already loaded globally by header.php
// Only add page-specific extras here
$extra_head = '
<style>
  .stat-icon {
    width: 56px; height: 56px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.4rem; flex-shrink: 0;
  }
  .stat-card  { border: none; box-shadow: 0 1px 4px rgba(0,0,0,.08); border-radius: 8px; }
  .page-card  { border: none; box-shadow: 0 1px 4px rgba(0,0,0,.08); border-radius: 8px; }
</style>';

require_once __DIR__ . '/includes/header.php';
require_permission('dashboard');

// ── Data queries ──────────────────────────────────────────────────────────────
$db = db();

// ── Employee self-service dashboard — shows only the logged-in employee's data ──
if (is_self_scoped()) {
    $eid = current_employee_id();
    $u   = current_user();
    $ms  = date('Y-m-01'); $me = date('Y-m-t'); $yr = (int)date('Y');
    $slipSt = $db->prepare('SELECT net_pay, payroll_month FROM salary_slips WHERE employee_id=? ORDER BY payroll_month DESC, id DESC LIMIT 1');
    $slipSt->execute([$eid]); $mySlip = $slipSt->fetch();
    $cnt = function (string $sql, array $p) use ($db) { $s = $db->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); };
    $present = $cnt("SELECT COUNT(*) FROM attendance WHERE employee_id=? AND att_date BETWEEN ? AND ? AND status IN ('On Time','Late','OD','Comp Off')", [$eid, $ms, $me]);
    $pendLv  = $cnt("SELECT COUNT(*) FROM leave_requests WHERE employee_id=? AND status='pending'", [$eid]);
    $apprLv  = $cnt("SELECT COUNT(*) FROM leave_requests WHERE employee_id=? AND status='approved' AND YEAR(start_date)=?", [$eid, $yr]);
    ?>
    <nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb"><li class="breadcrumb-item active">My Dashboard</li></ol></nav>
    <div class="mb-4"><h4 class="fw-semibold mb-1">Welcome, <?= h($u['name'] ?? '') ?></h4>
        <p class="text-muted small mb-0">Here is your personal overview. You can only see your own records.</p></div>
    <div class="row g-4 mb-4">
        <div class="col-xl-3 col-md-6"><div class="card stat-card h-100"><div class="card-body">
            <div class="text-muted small fw-semibold text-uppercase mb-1">Latest Net Pay</div>
            <div class="h4 mb-0 fw-bold text-success"><?= $mySlip ? money((float)$mySlip['net_pay']) : '—' ?></div>
            <div class="small text-muted"><?= $mySlip ? date('F Y', strtotime($mySlip['payroll_month'] . '-01')) : 'No slip yet' ?></div>
        </div></div></div>
        <div class="col-xl-3 col-md-6"><div class="card stat-card h-100"><div class="card-body">
            <div class="text-muted small fw-semibold text-uppercase mb-1">Present Days (This Month)</div>
            <div class="h4 mb-0 fw-bold text-primary"><?= $present ?></div></div></div></div>
        <div class="col-xl-3 col-md-6"><div class="card stat-card h-100"><div class="card-body">
            <div class="text-muted small fw-semibold text-uppercase mb-1">Pending Leaves</div>
            <div class="h4 mb-0 fw-bold text-warning"><?= $pendLv ?></div></div></div></div>
        <div class="col-xl-3 col-md-6"><div class="card stat-card h-100"><div class="card-body">
            <div class="text-muted small fw-semibold text-uppercase mb-1">Approved Leaves (<?= $yr ?>)</div>
            <div class="h4 mb-0 fw-bold text-info"><?= $apprLv ?></div></div></div></div>
    </div>
    <div class="card page-card"><div class="card-body">
        <h6 class="fw-semibold mb-3">Quick Links</h6>
        <div class="d-flex flex-wrap gap-2">
            <?php if (can('payroll', 'view')): ?><a href="<?= BASE_URL ?>/modules/payroll/index.php" class="btn btn-outline-primary btn-sm"><i class="fa fa-money-bill-wave me-1"></i>My Salary Slips</a><?php endif; ?>
            <?php if (can('leaves', 'view')): ?><a href="<?= BASE_URL ?>/modules/attendance/leaves.php" class="btn btn-outline-primary btn-sm"><i class="fa fa-calendar-xmark me-1"></i>Leave Requests</a><?php endif; ?>
            <?php if (can('attendance', 'view')): ?><a href="<?= BASE_URL ?>/modules/attendance/comp_off_credits.php" class="btn btn-outline-primary btn-sm"><i class="fa fa-clock me-1"></i>Comp Off Balance</a><?php endif; ?>
        </div>
    </div></div>
    <?php
    include __DIR__ . '/includes/footer.php';
    return;
}

$totalEmployees    = (int) $db->query('SELECT COUNT(*) FROM employees')->fetchColumn();
$totalDepartments  = (int) $db->query('SELECT COUNT(*) FROM departments')->fetchColumn();
$totalSalarySlips  = (int) $db->query('SELECT COUNT(*) FROM salary_slips')->fetchColumn();
$totalOfferLetters = (int) $db->query("SELECT COUNT(*) FROM letters WHERE type = 'Offer'")->fetchColumn();

// Department-wise active employee counts (for bar chart)
$deptRows = $db->query(
    'SELECT d.name AS dept_name, COUNT(e.id) AS emp_count
     FROM departments d
     LEFT JOIN employees e ON e.department_id = d.id AND e.status = "Active"
     GROUP BY d.id, d.name
     ORDER BY emp_count DESC'
)->fetchAll();

$deptLabels = array_column($deptRows, 'dept_name');
$deptCounts = array_map('intval', array_column($deptRows, 'emp_count'));

// Monthly joining counts — last 12 months (for line chart)
$joiningRows = $db->query(
    'SELECT DATE_FORMAT(join_date, "%b %Y") AS month_label,
            DATE_FORMAT(join_date, "%Y-%m")  AS month_key,
            COUNT(*) AS cnt
     FROM employees
     WHERE join_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
     GROUP BY month_key, month_label
     ORDER BY month_key ASC'
)->fetchAll();

$monthLabels   = array_column($joiningRows, 'month_label');
$joiningCounts = array_map('intval', array_column($joiningRows, 'cnt'));
?>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb">
        <li class="breadcrumb-item active">Dashboard</li>
    </ol>
</nav>

<!-- ── Stat Cards ──────────────────────────────────────────────────────────── -->
<div class="row g-4 mb-4">

    <div class="col-xl-3 col-md-6">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small fw-semibold text-uppercase mb-1">Total Employees</div>
                    <div class="h3 mb-0 fw-bold"><?= $totalEmployees ?></div>
                </div>
                <div class="stat-icon" style="background:rgba(78,115,223,.15);color:#4e73df">
                    <i class="fa fa-users"></i>
                </div>
            </div>
            <div class="card-footer bg-transparent border-0 pt-0">
                <a href="<?= BASE_URL ?>/modules/employee/index.php" class="small text-primary">
                    View all <i class="fa fa-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small fw-semibold text-uppercase mb-1">Total Departments</div>
                    <div class="h3 mb-0 fw-bold"><?= $totalDepartments ?></div>
                </div>
                <div class="stat-icon" style="background:rgba(28,200,138,.15);color:#1cc88a">
                    <i class="fa fa-building"></i>
                </div>
            </div>
            <div class="card-footer bg-transparent border-0 pt-0">
                <a href="<?= BASE_URL ?>/modules/settings/index.php?tab=departments" class="small text-success">
                    View all <i class="fa fa-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small fw-semibold text-uppercase mb-1">Total Salary Slips</div>
                    <div class="h3 mb-0 fw-bold"><?= $totalSalarySlips ?></div>
                </div>
                <div class="stat-icon" style="background:rgba(246,194,62,.15);color:#f6c23e">
                    <i class="fa fa-money-bill-wave"></i>
                </div>
            </div>
            <div class="card-footer bg-transparent border-0 pt-0">
                <a href="<?= BASE_URL ?>/modules/payroll/index.php" class="small text-warning">
                    View all <i class="fa fa-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>

    <div class="col-xl-3 col-md-6">
        <div class="card stat-card h-100">
            <div class="card-body d-flex align-items-center justify-content-between">
                <div>
                    <div class="text-muted small fw-semibold text-uppercase mb-1">Total Offer Letters</div>
                    <div class="h3 mb-0 fw-bold"><?= $totalOfferLetters ?></div>
                </div>
                <div class="stat-icon" style="background:rgba(231,74,59,.15);color:#e74a3b">
                    <i class="fa fa-envelope-open-text"></i>
                </div>
            </div>
            <div class="card-footer bg-transparent border-0 pt-0">
                <a href="<?= BASE_URL ?>/modules/letters/index.php?type=offer" class="small text-danger">
                    View all <i class="fa fa-arrow-right ms-1"></i>
                </a>
            </div>
        </div>
    </div>

</div>

<!-- ── Charts ─────────────────────────────────────────────────────────────── -->
<div class="row g-4">

    <div class="col-xl-7">
        <div class="card page-card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                <h6 class="mb-0 fw-semibold">
                    <i class="fa fa-chart-bar me-2 text-primary"></i>Department-wise Employee Count
                </h6>
            </div>
            <div class="card-body">
                <canvas id="deptChart" height="280"></canvas>
            </div>
        </div>
    </div>

    <div class="col-xl-5">
        <div class="card page-card">
            <div class="card-header bg-white d-flex justify-content-between align-items-center py-3">
                <h6 class="mb-0 fw-semibold">
                    <i class="fa fa-chart-line me-2 text-success"></i>Monthly Joining (Last 12 Months)
                </h6>
            </div>
            <div class="card-body">
                <canvas id="joiningChart" height="280"></canvas>
            </div>
        </div>
    </div>

</div>

<?php
// Scripts injected before </body> via footer.php's $page_scripts slot
$page_scripts = '
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
const deptLabels    = ' . json_encode($deptLabels,   JSON_UNESCAPED_UNICODE) . ';
const deptCounts    = ' . json_encode($deptCounts) . ';
const monthLabels   = ' . json_encode($monthLabels,  JSON_UNESCAPED_UNICODE) . ';
const joiningCounts = ' . json_encode($joiningCounts) . ';

new Chart(document.getElementById("deptChart"), {
    type: "bar",
    data: {
        labels: deptLabels,
        datasets: [{
            label: "Employees",
            data: deptCounts,
            backgroundColor: "rgba(78,115,223,.8)",
            borderColor: "#4e73df",
            borderWidth: 1,
            borderRadius: 6,
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, ticks: { stepSize: 1 } },
            x: { grid: { display: false } }
        }
    }
});

new Chart(document.getElementById("joiningChart"), {
    type: "line",
    data: {
        labels: monthLabels,
        datasets: [{
            label: "Joinings",
            data: joiningCounts,
            borderColor: "#1cc88a",
            backgroundColor: "rgba(28,200,138,.1)",
            borderWidth: 2,
            fill: true,
            tension: 0.4,
            pointBackgroundColor: "#1cc88a",
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, ticks: { stepSize: 1 } },
            x: { grid: { display: false } }
        }
    }
});
</script>';
?>

<?php include __DIR__ . '/includes/footer.php'; ?>
