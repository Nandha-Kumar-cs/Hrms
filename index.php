<?php
$page_title    = 'Dashboard';
// $page_subtitle = 'Overview of ' . APP_NAME;
require_once __DIR__ . '/includes/header.php';
require_permission('dashboard');

// Stats
$db = db();
$total_emp   = $db->query('SELECT COUNT(*) FROM employees WHERE status="Active"')->fetchColumn();
$today       = date('Y-m-d');
$present     = $db->prepare('SELECT COUNT(*) FROM attendance WHERE att_date=? AND status IN ("On Time","Late")');
$present->execute([$today]);
$present_today = $present->fetchColumn();
$late_today    = $db->prepare('SELECT COUNT(*) FROM attendance WHERE att_date=? AND status="Late"');
$late_today->execute([$today]);
$late_cnt = $late_today->fetchColumn();
$pending_letters = $db->query('SELECT COUNT(*) FROM letters WHERE status="Draft"')->fetchColumn();
$pending_payroll = $db->query('SELECT COUNT(*) FROM payroll_runs WHERE status="Draft"')->fetchColumn();
$assets_assigned = $db->query('SELECT COUNT(*) FROM asset_assignments WHERE is_returned=0')->fetchColumn();
$pending_training = $db->query('SELECT COUNT(*) FROM training_enrollments WHERE status IN ("Enrolled","In Progress")')->fetchColumn();

// Recent attendance
$recent_att = $db->prepare(
    'SELECT a.*, e.name, e.employee_id AS emp_code FROM attendance a
     JOIN employees e ON e.id=a.employee_id
     WHERE a.att_date = ? ORDER BY a.created_at DESC LIMIT 10'
);
$recent_att->execute([$today]);
$att_rows = $recent_att->fetchAll();

// Upcoming birthdays
$bdays = $db->query(
    'SELECT name, employee_id, dob FROM employees
     WHERE status="Active" AND MONTH(dob)=MONTH(CURDATE()) AND DAY(dob) >= DAY(CURDATE())
     ORDER BY DAY(dob) LIMIT 5'
)->fetchAll();
?>

<!-- PAGE HEAD -->
<div class="page-head">
    <div>
        <h1>Dashboard</h1>
        <p class="muted"><?= date('l, d F Y') ?> — Welcome back, <?= h($user['name']) ?></p>
    </div>
    <div class="head-actions">
        <?php if (can('attendance','mark')): ?>
        <a href="modules/attendance/mark.php" class="btn btn-primary" accesskey="m" data-shortcut data-key="M">
            <u>M</u>ark Attendance
        </a>
        <?php endif; ?>
        <?php if (can('employee','create')): ?>
        <a href="modules/employee/create.php" class="btn" accesskey="n" data-shortcut data-key="N">
            + <u>N</u>ew Employee
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- STAT CARDS -->
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-label">Active Employees</div>
        <div class="stat-value"><?= $total_emp ?></div>
        <div class="stat-sub">Total headcount</div>
    </div>
    <div class="stat-card stat-success">
        <div class="stat-label">Present Today</div>
        <div class="stat-value"><?= $present_today ?></div>
        <div class="stat-sub">of <?= $total_emp ?> employees</div>
    </div>
    <div class="stat-card stat-warn">
        <div class="stat-label">Late Today</div>
        <div class="stat-value"><?= $late_cnt ?></div>
        <div class="stat-sub">Late arrivals</div>
    </div>
    <div class="stat-card stat-info">
        <div class="stat-label">Assets Assigned</div>
        <div class="stat-value"><?= $assets_assigned ?></div>
        <div class="stat-sub">Currently with employees</div>
    </div>
    <div class="stat-card stat-danger">
        <div class="stat-label">Pending Letters</div>
        <div class="stat-value"><?= $pending_letters ?></div>
        <div class="stat-sub">Draft letters to issue</div>
    </div>
    <div class="stat-card">
        <div class="stat-label">Training Active</div>
        <div class="stat-value"><?= $pending_training ?></div>
        <div class="stat-sub">Enrollments in progress</div>
    </div>
</div>

<div class="grid-2">
    <!-- Today's Attendance -->
    <div class="card">
        <div class="card-head">
            <h3>Today's Attendance</h3>
            <a href="modules/attendance/index.php" class="link">View all →</a>
        </div>
        <?php if ($att_rows): ?>
        <table class="data-table">
            <thead><tr>
                <th>Employee</th>
                <th>Status</th>
                <th>In</th>
                <th>Out</th>
            </tr></thead>
            <tbody>
            <?php foreach ($att_rows as $r): ?>
            <tr>
                <td>
                    <div style="display:flex;align-items:center;gap:8px">
                        <div class="emp-avatar"><?= strtoupper(substr($r['name'],0,1)) ?></div>
                        <div>
                            <div style="font-weight:500"><?= h($r['name']) ?></div>
                            <div class="small muted"><?= h($r['emp_code']) ?></div>
                        </div>
                    </div>
                </td>
                <td>
                    <?php
                    $pillMap = ['On Time'=>'pill-on-time','Late'=>'pill-late','Absent'=>'pill-absent',
                                'OD'=>'pill-od','Comp Off'=>'pill-comp-off','Half Day'=>'pill-half'];
                    $pc = $pillMap[$r['status']] ?? 'pill-neutral';
                    ?>
                    <span class="pill <?= $pc ?>"><?= h($r['status']) ?></span>
                </td>
                <td><?= $r['in_time'] ? date('h:i A', strtotime($r['in_time'])) : '—' ?></td>
                <td><?= $r['out_time'] ? date('h:i A', strtotime($r['out_time'])) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="data-table"><div style="padding:30px;text-align:center;color:var(--text-muted)">No attendance marked yet today.</div></div>
        <?php endif; ?>
    </div>

    <!-- Right column -->
    <div>
        <!-- Birthdays -->
        <div class="card" style="margin-bottom:18px">
            <div class="card-head"><h3>🎂 Upcoming Birthdays</h3></div>
            <div style="padding:14px 18px">
            <?php if ($bdays): ?>
                <?php foreach ($bdays as $b): ?>
                <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border);font-size:13px">
                    <span><?= h($b['name']) ?></span>
                    <span class="muted"><?= date('d M', strtotime($b['dob'])) ?></span>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p class="muted small">No birthdays this month.</p>
            <?php endif; ?>
            </div>
        </div>

        <!-- Quick Links -->
        <div class="card">
            <div class="card-head"><h3>Quick Actions</h3></div>
            <div style="padding:14px 18px;display:grid;grid-template-columns:1fr 1fr;gap:8px">
                <?php if (can('payroll','process')): ?>
                <a href="modules/payroll/process.php" class="btn btn-sm" style="text-align:center">Process Payroll</a>
                <?php endif; ?>
                <?php if (can('letters','create')): ?>
                <a href="modules/letters/create.php" class="btn btn-sm" style="text-align:center">Issue Letter</a>
                <?php endif; ?>
                <?php if (can('assets','assign')): ?>
                <a href="modules/assets/assign.php" class="btn btn-sm" style="text-align:center">Assign Asset</a>
                <?php endif; ?>
                <?php if (can('training','manage')): ?>
                <a href="modules/training/create.php" class="btn btn-sm" style="text-align:center">New Training</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
window.BASE_URL = '<?= BASE_URL ?>';
window.PAGE_SHORTCUTS = {
    'm': () => window.location.href = '<?= BASE_URL ?>/modules/attendance/mark.php',
    'n': () => window.location.href = '<?= BASE_URL ?>/modules/employee/create.php'
};
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
