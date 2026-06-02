<?php
$page_title = 'Employee Profile';
require_once __DIR__ . '/../../includes/header.php';
require_permission('employee');

$id = (int)($_GET['id'] ?? 0);
$db = db();

$emp = $db->prepare(
    'SELECT e.*, d.name AS dept_name, des.name AS desig_name, m.name AS manager_name
     FROM employees e
     LEFT JOIN departments d   ON d.id  = e.department_id
     LEFT JOIN designations des ON des.id = e.designation_id
     LEFT JOIN employees m     ON m.id  = e.manager_id
     WHERE e.id = ?'
);
$emp->execute([$id]);
$e = $emp->fetch();
if (!$e) { redirect(BASE_URL . '/modules/employee/index.php'); }

// Current salary structure
$sal = $db->prepare('SELECT * FROM salary_structures WHERE employee_id=? AND is_current=1 ORDER BY effective_from DESC LIMIT 1');
$sal->execute([$id]);
$salary = $sal->fetch();

// All salary structure revisions
$salHist = $db->prepare('SELECT * FROM salary_structures WHERE employee_id=? ORDER BY effective_from DESC');
$salHist->execute([$id]);
$salHistRows = $salHist->fetchAll();

// Attendance (last 30)
$att = $db->prepare('SELECT * FROM attendance WHERE employee_id=? ORDER BY att_date DESC LIMIT 30');
$att->execute([$id]);
$att_rows = $att->fetchAll();

// Active assets
$assets = $db->prepare(
    'SELECT aa.*, a.name AS asset_name, a.asset_code, c.name AS cat_name
     FROM asset_assignments aa
     JOIN assets a ON a.id = aa.asset_id
     JOIN asset_categories c ON c.id = a.category_id
     WHERE aa.employee_id = ? AND aa.is_returned = 0'
);
$assets->execute([$id]);
$asset_rows = $assets->fetchAll();

// Letters
$letters = $db->prepare('SELECT * FROM letters WHERE employee_id=? ORDER BY issued_date DESC');
$letters->execute([$id]);
$letter_rows = $letters->fetchAll();

// Salary slips
$slips = $db->prepare('SELECT * FROM salary_slips WHERE employee_id=? ORDER BY payroll_month DESC');
$slips->execute([$id]);
$slip_rows = $slips->fetchAll();
?>

<style>
.btn-xs { padding:.2rem .5rem; font-size:.75rem; }
.tab-content { padding-top:1rem; }
.profile-avatar {
    width:80px; height:80px; border-radius:50%;
    background:var(--primary,#3b82f6); color:#fff;
    font-size:2rem; display:flex; align-items:center;
    justify-content:center; font-weight:700; flex-shrink:0;
}
.profile-photo { width:80px; height:80px; border-radius:50%; object-fit:cover; border:3px solid #e2e8f0; flex-shrink:0; }

/* ── Issue 2: Kill ALL black hover/focus boxes ────────────────────
   Root causes:
   1. Global a:hover { text-decoration: underline } in magdyn-base.css
   2. Bootstrap --bs-nav-tabs-link-hover-border-color drawing a border
   3. Browser native focus ring staying after tab click
   ─────────────────────────────────────────────────────────────── */

/* Override global a:hover underline inside tabs */
#profileTabs a,
#profileTabs a:hover,
#profileTabs a:focus,
#profileTabs a:visited,
#profileTabs a:active {
    text-decoration: none !important;
}

/* Wipe every outline / shadow / border on tab links for every state */
#profileTabs .nav-link,
#profileTabs .nav-link:hover,
#profileTabs .nav-link:focus,
#profileTabs .nav-link:focus-within,
#profileTabs .nav-link:focus-visible,
#profileTabs .nav-link:active,
#profileTabs .nav-link:visited {
    outline: none !important;
    outline-offset: 0 !important;
    box-shadow: none !important;
    -webkit-tap-highlight-color: transparent !important;
    border-color: transparent !important;
    text-decoration: none !important;
}

/* BS5 CSS variable override */
#profileTabs {
    --bs-nav-tabs-link-hover-border-color: transparent transparent transparent;
    --bs-nav-tabs-border-color: #dee2e6;
}

/* Normal state */
#profileTabs .nav-link {
    color: #374151;
    border: 1px solid transparent;
    border-bottom: none;
    border-radius: .375rem .375rem 0 0;
    transition: color .15s, background .15s;
}

/* Hover: subtle tint, no border box */
#profileTabs .nav-link:hover {
    color: var(--primary, #3b82f6) !important;
    background: #f1f5f9 !important;
    border-color: transparent !important;
}

/* Active tab */
#profileTabs .nav-link.active,
#profileTabs .nav-link.active:hover,
#profileTabs .nav-link.active:focus {
    color: var(--primary, #3b82f6) !important;
    background: #fff !important;
    border-color: #dee2e6 #dee2e6 #fff !important;
    font-weight: 600;
}

/* Kill any hover underline or box-shadow inside the card body too */
.card-body h6,
.card-body .table th,
.card-body .table td { outline: none !important; box-shadow: none !important; }
</style>

<?= render_flash() ?>

<!-- ── Profile Header ──────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-body py-3">
        <div class="d-flex align-items-center gap-3">
            <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left"></i></a>

            <?php if ($e['photo']): ?>
            <img src="<?= BASE_URL ?>/uploads/photos/<?= h($e['photo']) ?>" alt="<?= h($e['name']) ?>" class="profile-photo">
            <?php else: ?>
            <div class="profile-avatar"><?= strtoupper(substr($e['name'],0,1)) ?></div>
            <?php endif; ?>

            <div>
                <h5 class="mb-0 fw-bold"><?= h($e['name']) ?></h5>
                <small class="text-muted">
                    <?= h($e['employee_id']) ?> &bull;
                    <?= h($e['desig_name'] ?? 'N/A') ?> &bull;
                    <?= h($e['dept_name'] ?? 'N/A') ?>
                </small><br>
                <?php
                $statusColor = ['Active'=>'success','On Leave'=>'warning','Resigned'=>'danger','Terminated'=>'secondary'][$e['status']] ?? 'secondary';
                ?>
                <span class="badge bg-<?= $statusColor ?>"><?= h($e['status']) ?></span>
                <?php if ($e['employment_type']): ?>
                <span class="badge bg-info ms-1"><?= h($e['employment_type']) ?></span>
                <?php endif; ?>
            </div>

            <div class="ms-auto d-flex gap-2">
                <?php if (can('employee','edit')): ?>
                <a href="edit.php?id=<?= $id ?>" class="btn btn-sm btn-primary">
                    <i class="fa fa-edit me-1"></i>Edit
                </a>
                <?php endif; ?>
                <?php if (can('letters','create')): ?>
                <a href="../letters/create.php?emp_id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="fa fa-envelope me-1"></i>Issue Letter
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ── Tabs ───────────────────────────────────────────────────────── -->
<ul class="nav nav-tabs mb-0 px-1" id="profileTabs">
    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#overview">Overview</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#attendance">Attendance</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#salary">Salary History</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#assets">Assets</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#letters">Letters</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#bank"><i class="fa fa-university me-1"></i>Bank Details</a></li>
</ul>

<div class="card border-top-0" style="border-radius:0 0 .5rem .5rem">
<div class="card-body">
<div class="tab-content">

    <!-- ── OVERVIEW ──────────────────────────────────────────────── -->
    <div class="tab-pane fade show active" id="overview">
        <div class="row g-3">

            <!-- Personal Info -->
            <div class="col-md-6">
                <h6 class="text-primary fw-semibold border-bottom pb-2">Personal Information</h6>
                <table class="table table-sm table-borderless">
                    <tr><th class="text-muted" style="width:160px">Email</th><td><?= h($e['email']) ?></td></tr>
                    <tr><th class="text-muted">Phone</th><td><?= h($e['phone'] ?? '—') ?></td></tr>
                    <tr><th class="text-muted">Date of Birth</th><td><?= $e['dob'] ? date_fmt($e['dob']) . ' (' . age($e['dob']) . ' yrs)' : '—' ?></td></tr>
                    <tr><th class="text-muted">Gender</th><td><?= h(ucfirst($e['gender'] ?? '—')) ?></td></tr>
                    <tr><th class="text-muted">Address</th><td><?= h(implode(', ', array_filter([$e['address'] ?? '', $e['city'] ?? '', $e['state'] ?? '', $e['pincode'] ?? ''])) ?: '—') ?></td></tr>
                </table>
            </div>

            <!-- Employment Details -->
            <div class="col-md-6">
                <h6 class="text-primary fw-semibold border-bottom pb-2">Employment Details</h6>
                <table class="table table-sm table-borderless">
                    <tr><th class="text-muted" style="width:160px">Employee Code</th><td><?= h($e['employee_id']) ?></td></tr>
                    <tr><th class="text-muted">Joining Date</th><td><?= $e['join_date'] ? date_fmt($e['join_date']) . ' (' . tenure($e['join_date']) . ')' : '—' ?></td></tr>
                    <tr><th class="text-muted">Department</th><td><?= h($e['dept_name'] ?? '—') ?></td></tr>
                    <tr><th class="text-muted">Designation</th><td><?= h($e['desig_name'] ?? '—') ?></td></tr>
                    <tr><th class="text-muted">Reporting Manager</th><td><?= h($e['manager_name'] ?? '—') ?></td></tr>
                </table>
            </div>

            <!-- Salary — mirrors slip.php allowances logic exactly -->
            <div class="col-md-6">
                <h6 class="text-primary fw-semibold border-bottom pb-2">Salary Breakdown</h6>
                <?php
                $latestSlip = $slip_rows[0] ?? null;

                // ── Parse allowances from latest slip (same logic as slip.php) ──
                $viewAllowances = [];
                if ($latestSlip) {
                    $isIndividual = ($latestSlip['slip_type'] ?? 'batch') === 'individual';
                    if ($isIndividual && !empty($latestSlip['allowances'])) {
                        $viewAllowances = json_decode($latestSlip['allowances'], true) ?? [];
                    }
                    // Fallback to fixed columns (batch slips / empty JSON)
                    if (!$viewAllowances) {
                        $viewAllowances = array_filter([
                            'Basic Salary'      => (float)$latestSlip['basic'],
                            'HRA'               => (float)$latestSlip['hra'],
                            'Conveyance'        => (float)$latestSlip['conveyance'],
                            'Medical Allowance' => (float)$latestSlip['medical'],
                            'Special Allowance' => (float)$latestSlip['special_allow'],
                            'Other Allowance'   => (float)$latestSlip['other_allow'],
                        ]);
                    }

                    // ── Parse deductions from latest slip ──
                    $viewDeductions = [];
                    if ($isIndividual && !empty($latestSlip['deductions_json'])) {
                        $viewDeductions = json_decode($latestSlip['deductions_json'], true) ?? [];
                    }
                    if (!$viewDeductions) {
                        $viewDeductions = array_filter([
                            'Provident Fund (Employee)' => (float)$latestSlip['pf_employee'],
                            'ESI (Employee)'            => (float)$latestSlip['esi_employee'],
                            'TDS'                       => (float)$latestSlip['tds'],
                            'Other Deductions'          => (float)$latestSlip['other_deductions'],
                        ]);
                    }

                    // Separate earning rows (skip [BENEFIT] / [BONUS] prefixes for this summary)
                    $earningRows = [];
                    foreach ($viewAllowances as $lbl => $amt) {
                        if ($amt <= 0) continue;
                        if (!str_starts_with($lbl, '[BENEFIT]') && !str_starts_with($lbl, '[BONUS]')) {
                            $earningRows[$lbl] = $amt;
                        }
                    }
                    $slipMonth = date('F Y', strtotime($latestSlip['payroll_month'] . '-01'));
                }
                ?>

                <?php if ($latestSlip && ($earningRows || $viewDeductions)): ?>
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <small class="text-muted">Based on slip: <strong><?= $slipMonth ?></strong></small>
                    <?php if (can('payroll','process')): ?>
                    <a href="../payroll/salary_structure.php?employee_id=<?= $id ?>" class="btn btn-xs btn-outline-secondary"><i class="fa fa-cog me-1"></i>Structure</a>
                    <?php endif; ?>
                </div>

                <table class="table table-sm table-borderless mb-1">
                    <thead><tr>
                        <th class="text-success small text-uppercase" style="width:55%">Earnings</th>
                        <th class="text-danger small text-uppercase">Deductions</th>
                    </tr></thead>
                    <tbody>
                    <?php
                    $eKeys = array_keys($earningRows);
                    $dKeys = array_keys($viewDeductions);
                    $maxRows = max(count($eKeys), count($dKeys));
                    for ($ri = 0; $ri < $maxRows; $ri++):
                        $eLabel = $eKeys[$ri] ?? null;
                        $dLabel = $dKeys[$ri] ?? null;
                    ?>
                    <tr>
                        <td class="py-1 pe-2" style="font-size:12px">
                            <?php if ($eLabel): ?>
                            <span class="text-muted"><?= h($eLabel) ?></span>
                            <span class="float-end fw-semibold"><?= money($earningRows[$eLabel]) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="py-1" style="font-size:12px">
                            <?php if ($dLabel): ?>
                            <span class="text-muted"><?= h($dLabel) ?></span>
                            <span class="float-end text-danger fw-semibold"><?= money($viewDeductions[$dLabel]) ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endfor; ?>
                    </tbody>
                    <tfoot>
                    <tr class="border-top">
                        <td class="py-1 pe-2" style="font-size:12px">
                            <strong class="text-success">Total Gross</strong>
                            <strong class="float-end text-success"><?= money($latestSlip['gross_earnings']) ?></strong>
                        </td>
                        <td class="py-1" style="font-size:12px">
                            <strong class="text-danger">Total Deductions</strong>
                            <strong class="float-end text-danger"><?= money($latestSlip['total_deductions']) ?></strong>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2" class="pt-1 pb-0">
                            <div style="background:var(--primary,#3b82f6);color:#fff;border-radius:.375rem;padding:7px 12px;display:flex;justify-content:space-between;align-items:center">
                                <strong style="font-size:13px">Net Pay</strong>
                                <strong style="font-size:15px"><?= money($latestSlip['net_pay']) ?></strong>
                            </div>
                        </td>
                    </tr>
                    </tfoot>
                </table>

                <?php elseif ($salary): ?>
                <!-- No slip yet — fall back to salary structure columns -->
                <?php
                $salComponents = array_filter([
                    'Basic Salary'      => (float)($salary['basic']         ?? 0),
                    'HRA'               => (float)($salary['hra']           ?? 0),
                    'Conveyance'        => (float)($salary['conveyance']    ?? 0),
                    'Medical Allowance' => (float)($salary['medical']       ?? 0),
                    'Special Allowance' => (float)($salary['special_allow'] ?? 0),
                    'Other Allowance'   => (float)($salary['other_allow']   ?? 0),
                ]);
                $displayGross = array_sum($salComponents) ?: (float)($salary['gross'] ?? 0);
                ?>
                <table class="table table-sm table-borderless mb-0">
                    <?php foreach ($salComponents as $lbl => $amt): ?>
                    <tr><th class="text-muted fw-normal" style="width:165px"><?= h($lbl) ?></th><td><?= money($amt) ?></td></tr>
                    <?php endforeach; ?>
                    <tr class="border-top">
                        <th class="fw-semibold">Gross CTC</th>
                        <td><strong class="text-primary"><?= money($displayGross) ?></strong>
                            <small class="text-muted d-block">Effective: <?= date_fmt($salary['effective_from']) ?></small>
                        </td>
                    </tr>
                </table>
                <?php if (can('payroll','process')): ?>
                <a href="../payroll/salary_structure.php?employee_id=<?= $id ?>" class="btn btn-xs btn-outline-secondary mt-2"><i class="fa fa-cog me-1"></i>Edit Structure</a>
                <?php endif; ?>

                <?php else: ?>
                <p class="text-muted small">No salary data available.</p>
                <?php if (can('payroll','process')): ?>
                <a href="../payroll/salary_structure.php?employee_id=<?= $id ?>" class="btn btn-sm btn-primary mt-1">Set Salary</a>
                <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Quick Stats + Statutory -->
            <div class="col-md-6">
                <h6 class="text-primary fw-semibold border-bottom pb-2">Quick Stats</h6>
                <div class="row g-2 text-center">
                    <div class="col-4"><div class="bg-light rounded p-2"><div class="fw-bold text-primary"><?= count($slip_rows) ?></div><small class="text-muted">Salary Slips</small></div></div>
                    <div class="col-4"><div class="bg-light rounded p-2"><div class="fw-bold text-success"><?= count($letter_rows) ?></div><small class="text-muted">Letters</small></div></div>
                    <div class="col-4"><div class="bg-light rounded p-2"><div class="fw-bold text-warning"><?= count($asset_rows) ?></div><small class="text-muted">Assets</small></div></div>
                    <div class="col-4"><div class="bg-light rounded p-2"><div class="fw-bold text-info"><?= count($att_rows) ?></div><small class="text-muted">Att. Records</small></div></div>
                    <div class="col-4"><div class="bg-light rounded p-2"><div class="fw-bold text-secondary"><?= count($salHistRows) ?></div><small class="text-muted">Sal. Revisions</small></div></div>
                    <div class="col-4"><div class="bg-light rounded p-2"><div class="fw-bold text-dark"><?= $e['pan_number'] ? '✓' : '—' ?></div><small class="text-muted">PAN</small></div></div>
                </div>

                <h6 class="text-primary fw-semibold border-bottom pb-2 mt-3">Statutory</h6>
                <table class="table table-sm table-borderless mb-0">
                    <tr><th class="text-muted" style="width:100px">PAN</th><td class="font-monospace"><?= h($e['pan_number'] ?: '—') ?></td></tr>
                    <tr><th class="text-muted">Aadhaar</th><td class="font-monospace"><?= $e['aadhaar_number'] ? '****' . substr($e['aadhaar_number'],-4) : '—' ?></td></tr>
                    <tr><th class="text-muted">UAN</th><td class="font-monospace"><?= h($e['uan_number'] ?: '—') ?></td></tr>
                </table>
            </div>

        </div>
    </div>

    <!-- ── ATTENDANCE ──────────────────────────────────────────────── -->
    <div class="tab-pane fade" id="attendance">
        <div class="d-flex justify-content-between mb-3">
            <h6 class="fw-semibold mb-0"><i class="fa fa-calendar-check me-1 text-primary"></i>Recent Attendance</h6>
            <a href="../attendance/index.php?emp_id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">
                <i class="fa fa-external-link me-1"></i>View Full Report
            </a>
        </div>
        <?php if (!$att_rows): ?>
            <p class="text-muted text-center py-4">No attendance records found.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover table-bordered align-middle">
                <thead class="table-dark">
                    <tr><th>Date</th><th>Status</th><th>Check In</th><th>Check Out</th><th>OT Hrs</th><th>Remarks</th></tr>
                </thead>
                <tbody>
                <?php foreach ($att_rows as $r):
                    $bc = ['On Time'=>'success','Late'=>'warning','Absent'=>'danger','OD'=>'info','Comp Off'=>'secondary','Half Day'=>'warning','Holiday'=>'primary'][$r['status']] ?? 'secondary';
                ?>
                <tr>
                    <td><?= date('d M Y', strtotime($r['att_date'])) ?></td>
                    <td><span class="badge bg-<?= $bc ?>"><?= h($r['status']) ?></span></td>
                    <td><?= $r['in_time']  ? date('h:i A', strtotime($r['in_time']))  : '—' ?></td>
                    <td><?= $r['out_time'] ? date('h:i A', strtotime($r['out_time'])) : '—' ?></td>
                    <td><?= ($r['ot_hours'] ?? 0) > 0 ? '<span class="text-success fw-semibold">' . number_format((float)$r['ot_hours'],2) . '</span>' : '—' ?></td>
                    <td class="small text-muted"><?= h($r['remarks'] ?? '') ?: '—' ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── SALARY HISTORY ──────────────────────────────────────────── -->
    <div class="tab-pane fade" id="salary">
        <div class="d-flex justify-content-between mb-3">
            <h6 class="fw-semibold mb-0"><i class="fa fa-money-bill-wave me-1 text-primary"></i>Salary Slip History</h6>
            <?php if (can('payroll','process')): ?>
            <div class="d-flex gap-2">
                <a href="../payroll/salary_structure.php?employee_id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="fa fa-cog me-1"></i>Salary Structure
                </a>
                <a href="../payroll/process.php?employee_id=<?= $id ?>" class="btn btn-sm btn-primary">
                    <i class="fa fa-plus me-1"></i>Generate Slip
                </a>
            </div>
            <?php endif; ?>
        </div>
        <?php if (!$slip_rows): ?>
            <p class="text-muted text-center py-4">No salary slips generated.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover table-bordered align-middle">
                <thead class="table-dark">
                    <tr><th>Month / Year</th><th>Gross</th><th>Deductions</th><th>Net Salary</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($slip_rows as $slip): ?>
                <tr>
                    <td><?= date('F Y', strtotime($slip['payroll_month'] . '-01')) ?></td>
                    <td>₹<?= number_format((float)$slip['gross_earnings'], 2) ?></td>
                    <td class="text-danger">₹<?= number_format((float)$slip['total_deductions'], 2) ?></td>
                    <td><strong class="text-primary">₹<?= number_format((float)$slip['net_pay'], 2) ?></strong></td>
                    <td class="text-nowrap">
                        <a href="../payroll/slip.php?id=<?= $slip['id'] ?>" class="btn btn-xs btn-outline-primary" title="View"><i class="fa fa-eye"></i></a>
                        <a href="../payroll/slip_pdf.php?id=<?= $slip['id'] ?>" class="btn btn-xs btn-outline-danger ms-1" target="_blank" title="PDF"><i class="fa fa-file-pdf"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php if ($salHistRows): ?>
        <div class="mt-4">
            <h6 class="fw-semibold mb-3 text-primary border-bottom pb-2">Salary Structure Revisions</h6>
            <div class="table-responsive">
                <table class="table table-sm table-hover table-bordered align-middle">
                    <thead class="table-dark">
                        <tr><th>Effective From</th><th>Basic</th><th>HRA</th><th>Conveyance</th><th>Gross CTC</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($salHistRows as $sh): ?>
                    <tr>
                        <td><?= date_fmt($sh['effective_from']) ?></td>
                        <td>₹<?= number_format((float)$sh['basic'], 2) ?></td>
                        <td>₹<?= number_format((float)$sh['hra'], 2) ?></td>
                        <td>₹<?= number_format((float)$sh['conveyance'], 2) ?></td>
                        <td><strong>₹<?= number_format((float)$sh['gross'], 2) ?></strong></td>
                        <td><span class="badge bg-<?= $sh['is_current'] ? 'success' : 'secondary' ?>"><?= $sh['is_current'] ? 'Current' : 'Superseded' ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── ASSETS ──────────────────────────────────────────────────── -->
    <div class="tab-pane fade" id="assets">
        <div class="d-flex justify-content-between mb-3">
            <h6 class="fw-semibold mb-0"><i class="fa fa-laptop me-1 text-primary"></i>Assigned Assets</h6>
            <?php if (can('assets','assign')): ?>
            <a href="../assets/assign.php?emp_id=<?= $id ?>" class="btn btn-sm btn-primary">
                <i class="fa fa-plus me-1"></i>Assign Asset
            </a>
            <?php endif; ?>
        </div>
        <?php if (!$asset_rows): ?>
            <p class="text-muted text-center py-4">No assets assigned.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover table-bordered align-middle">
                <thead class="table-dark">
                    <tr><th>Asset</th><th>Code</th><th>Category</th><th>Assigned Date</th></tr>
                </thead>
                <tbody>
                <?php foreach ($asset_rows as $a): ?>
                <tr>
                    <td class="fw-semibold"><?= h($a['asset_name']) ?></td>
                    <td><code><?= h($a['asset_code']) ?></code></td>
                    <td><?= h($a['cat_name']) ?></td>
                    <td><?= date_fmt($a['assigned_date']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── LETTERS ──────────────────────────────────────────────────── -->
    <div class="tab-pane fade" id="letters">
        <div class="d-flex justify-content-between mb-3">
            <h6 class="fw-semibold mb-0"><i class="fa fa-envelope me-1 text-primary"></i>Letters Issued</h6>
            <?php if (can('letters','create')): ?>
            <a href="../letters/create.php?emp_id=<?= $id ?>" class="btn btn-sm btn-primary">
                <i class="fa fa-plus me-1"></i>Issue Letter
            </a>
            <?php endif; ?>
        </div>
        <?php if (!$letter_rows): ?>
            <p class="text-muted text-center py-4">No letters issued yet.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover table-bordered align-middle">
                <thead class="table-dark">
                    <tr><th>Type</th><th>Issued Date</th><th>Status</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($letter_rows as $l):
                    $lc = ['Issued'=>'success','Draft'=>'secondary','Revoked'=>'danger'][$l['status']] ?? 'secondary';
                ?>
                <tr>
                    <td><span class="badge bg-light text-dark border"><?= h($l['type']) ?></span></td>
                    <td><?= date_fmt($l['issued_date']) ?></td>
                    <td><span class="badge bg-<?= $lc ?>"><?= h($l['status']) ?></span></td>
                    <td>
                        <a href="../letters/view.php?id=<?= $l['id'] ?>" class="btn btn-xs btn-outline-primary" title="View">
                            <i class="fa fa-eye"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── BANK DETAILS ──────────────────────────────────────────────── -->
    <div class="tab-pane fade" id="bank">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h6 class="fw-semibold mb-0"><i class="fa fa-university me-1 text-primary"></i>Bank Details</h6>
            <?php if (can('employee','edit')): ?>
            <a href="edit.php?id=<?= $id ?>" class="btn btn-sm btn-primary">
                <i class="fa fa-<?= $e['bank_account'] ? 'edit' : 'plus' ?> me-1"></i>
                <?= $e['bank_account'] ? 'Edit' : 'Add' ?> Bank Details
            </a>
            <?php endif; ?>
        </div>

        <?php if (!$e['bank_account'] && !$e['bank_name']): ?>
        <p class="text-muted text-center py-4">
            <i class="fa fa-university fa-2x d-block mb-2 text-muted"></i>
            No bank details recorded yet.
        </p>
        <?php else: ?>
        <div class="row g-3">
            <div class="col-md-6">
                <div class="card border-0 bg-light h-100">
                    <div class="card-body">
                        <h6 class="text-primary fw-semibold border-bottom pb-2 mb-3">Account Information</h6>
                        <table class="table table-sm table-borderless mb-0">
                            <tr><th class="text-muted" style="width:180px">Bank Name</th><td class="fw-semibold"><?= h($e['bank_name'] ?: '—') ?></td></tr>
                            <tr>
                                <th class="text-muted">Account Number</th>
                                <td>
                                    <?php $acc = $e['bank_account'] ?? ''; ?>
                                    <span class="fw-semibold font-monospace" id="accNumDisplay">
                                        <?= $acc ? str_repeat('•', max(0, strlen($acc)-4)) . substr($acc,-4) : '—' ?>
                                    </span>
                                    <?php if ($acc): ?>
                                    <button class="btn btn-xs btn-outline-secondary ms-2" id="toggleAccNum">
                                        <i class="fa fa-eye" id="accNumIcon"></i>
                                    </button>
                                    <span class="d-none" id="accNumFull"><?= h($acc) ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card border-0 bg-light h-100">
                    <div class="card-body">
                        <h6 class="text-primary fw-semibold border-bottom pb-2 mb-3">Branch Information</h6>
                        <table class="table table-sm table-borderless mb-0">
                            <tr><th class="text-muted" style="width:180px">IFSC Code</th><td class="fw-semibold font-monospace"><?= h($e['bank_ifsc'] ?: '—') ?></td></tr>
                            <tr><th class="text-muted">PAN Number</th><td class="font-monospace"><?= h($e['pan_number'] ?: '—') ?></td></tr>
                            <tr><th class="text-muted">UAN Number</th><td class="font-monospace"><?= h($e['uan_number'] ?: '—') ?></td></tr>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /tab-content -->
</div><!-- /card-body -->
</div><!-- /card -->

<script>
window.BASE_URL = '<?= BASE_URL ?>';
(function () {
    // Tab persistence via URL hash (matches Laravel behaviour)
    var hashTab = window.location.hash ? window.location.hash.substring(1) : null;
    if (hashTab) {
        var target = document.querySelector('#profileTabs a[href="#' + hashTab + '"]');
        if (target) {
            document.querySelectorAll('#profileTabs .nav-link.active').forEach(function (el) { el.classList.remove('active'); });
            document.querySelectorAll('.tab-pane.show.active').forEach(function (el) { el.classList.remove('show','active'); });
            target.classList.add('active');
            var pane = document.getElementById(hashTab);
            if (pane) pane.classList.add('show','active');
        }
    }
    document.querySelectorAll('#profileTabs a[data-bs-toggle="tab"]').forEach(function (el) {
        el.addEventListener('shown.bs.tab', function (e) {
            history.replaceState(null, null, e.target.getAttribute('href'));
        });
        // Blur immediately after click so the browser drops its focus ring
        el.addEventListener('mouseup', function () {
            var self = this;
            setTimeout(function () { self.blur(); }, 0);
        });
    });

    // Bank account number toggle
    var toggleBtn = document.getElementById('toggleAccNum');
    if (toggleBtn) {
        var accVisible = false;
        var masked = document.getElementById('accNumDisplay').textContent.trim();
        var full   = (document.getElementById('accNumFull') || {}).textContent || '';
        toggleBtn.addEventListener('click', function () {
            accVisible = !accVisible;
            document.getElementById('accNumDisplay').textContent = accVisible ? full.trim() : masked;
            var icon = document.getElementById('accNumIcon');
            icon.classList.toggle('fa-eye',      !accVisible);
            icon.classList.toggle('fa-eye-slash', accVisible);
        });
    }
}());
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
