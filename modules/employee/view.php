<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('employee');

$id = (int)($_GET['id'] ?? 0);
$db = db();

// Employee self-service: a scoped user can only ever view their OWN profile,
// regardless of the requested id.
if (is_self_scoped()) $id = current_employee_id();

// Resolve employee BEFORE any output so a bad/missing id can redirect cleanly
// (the Documents sidebar link, for example, has no id).
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

$page_title = 'Employee Profile';
require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/loan_history.php';   // loan_figures() — interest-aware totals

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

// Auto-create employee extension tables
$db->exec('CREATE TABLE IF NOT EXISTS employee_family_members (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    relationship VARCHAR(50) NOT NULL,
    dob DATE NULL,
    occupation VARCHAR(100) NULL,
    contact_number VARCHAR(30) NULL,
    dependency_status ENUM(\'dependent\',\'independent\') DEFAULT \'dependent\',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

// Family members
$famQ = $db->prepare('SELECT * FROM employee_family_members WHERE employee_id=? ORDER BY id');
$famQ->execute([$id]);
$family_rows = $famQ->fetchAll();

// Documents (table is created lazily on first upload — ensure it exists)
$db->exec('CREATE TABLE IF NOT EXISTS employee_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    document_type VARCHAR(50) NOT NULL,
    document_name VARCHAR(200) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_size INT DEFAULT 0,
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
$docQ = $db->prepare('SELECT * FROM employee_documents WHERE employee_id=? ORDER BY created_at DESC');
$docQ->execute([$id]);
$doc_rows = $docQ->fetchAll();

// Benefit funds assigned to this employee
$benQ = $db->prepare('SELECT * FROM employee_benefits WHERE employee_id=? ORDER BY effective_month DESC, id DESC');
$benQ->execute([$id]);
$benefit_rows = $benQ->fetchAll();

// Payroll-group history (loans / increments / promotions / bonuses). Each list
// page lazily creates its table, but a profile may be opened before any record
// exists, so guard every read so a missing table never breaks the page.
$safeRows = function (string $sql) use ($db, $id): array {
    try { $s = $db->prepare($sql); $s->execute([$id]); return $s->fetchAll(); }
    catch (Throwable $e) { return []; }
};
$loan_rows      = $safeRows('SELECT * FROM employee_loans WHERE employee_id=? ORDER BY date_given DESC, id DESC');
$increment_rows = $safeRows('SELECT * FROM employee_increments WHERE employee_id=? ORDER BY effective_date DESC, id DESC');
$promotion_rows = $safeRows(
    'SELECT ep.*, pd.name AS prev_desig, nd.name AS new_desig, d.name AS dept_name
     FROM employee_promotions ep
     LEFT JOIN designations pd ON pd.id = ep.previous_designation_id
     LEFT JOIN designations nd ON nd.id = ep.new_designation_id
     LEFT JOIN departments  d  ON d.id  = ep.department_id
     WHERE ep.employee_id=? ORDER BY ep.effective_date DESC, ep.id DESC'
);
$bonus_rows     = $safeRows('SELECT * FROM employee_bonuses WHERE employee_id=? ORDER BY payroll_year DESC, payroll_month DESC, id DESC');

// Human-readable file size for the Documents tab.
$doc_size = function (int $bytes): string {
    if ($bytes <= 0) return '—';
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 2) . ' MB';
};
?>

<style>
.btn-xs { padding:.2rem .5rem; font-size:.75rem; }
.tab-content { padding-top:1rem; }
/* Guarantee modals sit above the sticky topbar and sidebar */
.modal         { z-index: 1060 !important; }
.modal-backdrop{ z-index: 1055 !important; }
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

/* ── THE dark box: global `.nav-item:hover/.active` (meant for the sidebar,
   but NOT scoped to .sidebar) fills the <li class="nav-item"> wrapper with
   --sidebar-bg-hover (#1e293b). Neutralise it for the profile tabs. ── */
#profileTabs .nav-item,
#profileTabs .nav-item:hover,
#profileTabs .nav-item:focus,
#profileTabs .nav-item.active {
    background: transparent !important;
    color: inherit !important;
}

/* Normal state */
#profileTabs .nav-link {
    color: #374151;
    border: 1px solid transparent;
    border-bottom: none;
    border-radius: .375rem .375rem 0 0;
    transition: color .15s, background .15s;
}

/* Hover: colour only, no background box */
#profileTabs .nav-link:hover {
    color: var(--primary, #3b82f6) !important;
    background: transparent !important;
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
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#family">Family</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#benefits">Benefits</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#bonuses">Bonuses</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#increments">Increments</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#promotions">Promotions</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#loans">Loans &amp; Advances</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#documents">Documents</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#bank"><i class="fa fa-university me-1"></i>Bank Details</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#assets">Assets</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#salary">Salary History</a></li>
    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#letters">Letters</a></li>
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
                    <a href="../payroll/salary_components.php?employee_id=<?= $id ?>" class="btn btn-xs btn-outline-secondary"><i class="fa fa-list me-1"></i>Salary Component</a>
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

    <!-- ── FAMILY MEMBERS ──────────────────────────────────────────── -->
    <div class="tab-pane fade" id="family">
        <div class="d-flex justify-content-between mb-3">
            <h6 class="fw-semibold mb-0"><i class="fa fa-people-roof me-1 text-primary"></i>Family Details</h6>
            <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#familyModal"><i class="fa fa-plus me-1"></i>Add Member</button>
        </div>
        <?php if (empty($family_rows)): ?>
        <p class="text-muted text-center py-4">No family members recorded.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover table-bordered align-middle">
                <thead class="table-dark">
                    <tr><th>#</th><th>Name</th><th>Relationship</th><th>DOB</th><th>Occupation</th><th>Contact</th><th>Dependency</th><th></th></tr>
                </thead>
                <tbody>
                    <?php foreach ($family_rows as $fi => $fm): ?>
                    <tr>
                        <td><?= $fi + 1 ?></td>
                        <td class="fw-semibold"><?= h($fm['name']) ?></td>
                        <td><span class="badge bg-light text-dark"><?= h($fm['relationship']) ?></span></td>
                        <td><?= $fm['dob'] ? date('d M Y', strtotime($fm['dob'])) : '—' ?></td>
                        <td><?= h($fm['occupation'] ?? '—') ?></td>
                        <td><?= h($fm['contact_number'] ?? '—') ?></td>
                        <td><span class="badge bg-<?= $fm['dependency_status'] === 'dependent' ? 'info' : 'secondary' ?>"><?= ucfirst($fm['dependency_status']) ?></span></td>
                        <td>
                            <form method="POST" action="<?= BASE_URL ?>/modules/employee/family_save.php" class="d-inline" onsubmit="return confirm('Remove this family member?')">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="fid" value="<?= $fm['id'] ?>">
                                <input type="hidden" name="emp_id" value="<?= $id ?>">
                                <button class="btn btn-xs btn-outline-danger" title="Delete"><i class="fa fa-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── BENEFITS ────────────────────────────────────────────────── -->
    <div class="tab-pane fade" id="benefits">
        <div class="d-flex justify-content-between mb-3">
            <h6 class="fw-semibold mb-0"><i class="fa fa-gift me-1 text-primary"></i>Benefit Funds</h6>
            <a href="<?= BASE_URL ?>/modules/benefits/create.php?emp_id=<?= $id ?>" class="btn btn-sm btn-primary"><i class="fa fa-plus me-1"></i>Assign Benefit</a>
        </div>
        <?php if ($benefit_rows): ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Fund Type</th>
                        <th class="text-end">Amount</th>
                        <th>Effective Month</th>
                        <th>Status</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($benefit_rows as $b): ?>
                    <tr>
                        <td><span class="badge bg-secondary"><?= h($b['fund_type']) ?></span></td>
                        <td class="text-end"><?= money((float)$b['amount']) ?></td>
                        <td><?= date('M Y', strtotime($b['effective_month'])) ?></td>
                        <td><span class="badge bg-<?= ($b['status'] ?? 'active') === 'active' ? 'success' : 'secondary' ?>"><?= h(ucfirst($b['status'] ?? 'active')) ?></span></td>
                        <td><?= h($b['description'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="text-muted text-center py-4">No benefits assigned.</p>
        <?php endif; ?>
    </div>

    <!-- ── BONUSES ──────────────────────────────────────────────────── -->
    <div class="tab-pane fade" id="bonuses">
        <div class="d-flex justify-content-between mb-3">
            <h6 class="fw-semibold mb-0"><i class="fa fa-trophy me-1 text-warning"></i>Bonuses &amp; Incentives</h6>
            <a href="<?= BASE_URL ?>/modules/bonuses/create.php?emp_id=<?= $id ?>" class="btn btn-sm btn-primary"><i class="fa fa-plus me-1"></i>Add Bonus</a>
        </div>
        <?php if ($bonus_rows):
            $bTypeColors = ['monthly_bonus'=>'primary','performance'=>'success','festival'=>'warning','overtime'=>'info','one_time'=>'secondary'];
            $bTypeLabels = ['monthly_bonus'=>'Monthly Bonus','performance'=>'Performance Incentive','festival'=>'Festival Bonus','overtime'=>'Overtime Incentive','one_time'=>'One-time Reward'];
            $bStatColors = ['pending'=>'warning','approved'=>'success','rejected'=>'danger'];
            $bMonths     = ['','Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr><th>Type</th><th class="text-end">Amount</th><th>Payroll Month</th><th>Status</th><th>Reason</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($bonus_rows as $b): ?>
                    <tr>
                        <td><span class="badge bg-<?= $bTypeColors[$b['type']] ?? 'secondary' ?>"><?= h($bTypeLabels[$b['type']] ?? ucwords(str_replace('_',' ',$b['type']))) ?></span></td>
                        <td class="text-end fw-semibold text-success"><?= money((float)$b['amount']) ?></td>
                        <td><?= h(($bMonths[(int)$b['payroll_month']] ?? '') . ' ' . $b['payroll_year']) ?></td>
                        <td><span class="badge bg-<?= $bStatColors[$b['status']] ?? 'secondary' ?> <?= $b['status']==='pending'?'text-dark':'' ?>"><?= h(ucfirst($b['status'])) ?></span></td>
                        <td><?= h($b['reason'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="text-muted text-center py-4">No bonus / incentive records.</p>
        <?php endif; ?>
    </div>

    <!-- ── INCREMENTS ───────────────────────────────────────────────── -->
    <div class="tab-pane fade" id="increments">
        <div class="d-flex justify-content-between mb-3">
            <h6 class="fw-semibold mb-0">Increment History</h6>
            <a href="<?= BASE_URL ?>/modules/increments/create.php?emp_id=<?= $id ?>" class="btn btn-sm btn-primary"><i class="fa fa-plus me-1"></i>Add Increment</a>
        </div>
        <?php if ($increment_rows): ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr><th>Effective Date</th><th class="text-end">Previous Salary</th><th class="text-end">New Salary</th><th class="text-end">Increment</th><th class="text-center">%</th><th>Remarks</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($increment_rows as $inc):
                        $diff = (float)$inc['new_salary'] - (float)$inc['previous_salary'];
                        $pct  = (float)$inc['previous_salary'] > 0 ? round($diff / (float)$inc['previous_salary'] * 100, 2) : 0;
                    ?>
                    <tr>
                        <td><?= date('d M Y', strtotime($inc['effective_date'])) ?></td>
                        <td class="text-end"><?= money((float)$inc['previous_salary']) ?></td>
                        <td class="text-end fw-semibold"><?= money((float)$inc['new_salary']) ?></td>
                        <td class="text-end text-success fw-semibold">+<?= money($diff) ?></td>
                        <td class="text-center"><span class="badge bg-success"><?= $pct ?>%</span></td>
                        <td><?= h($inc['remarks'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="text-muted text-center py-4">No increment records found.</p>
        <?php endif; ?>
    </div>

    <!-- ── PROMOTIONS ───────────────────────────────────────────────── -->
    <div class="tab-pane fade" id="promotions">
        <div class="d-flex justify-content-between mb-3">
            <h6 class="fw-semibold mb-0">Promotion History</h6>
            <a href="<?= BASE_URL ?>/modules/promotions/create.php?emp_id=<?= $id ?>" class="btn btn-sm btn-primary"><i class="fa fa-plus me-1"></i>Add Promotion</a>
        </div>
        <?php if ($promotion_rows): ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr><th>Effective Date</th><th>Previous Designation</th><th>New Designation</th><th>Department</th><th>Remarks</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($promotion_rows as $p): ?>
                    <tr>
                        <td><?= date('d M Y', strtotime($p['effective_date'])) ?></td>
                        <td class="text-muted"><?= h($p['prev_desig'] ?? '—') ?></td>
                        <td><strong><?= h($p['new_desig'] ?? '—') ?></strong></td>
                        <td><?= h($p['dept_name'] ?? '—') ?></td>
                        <td><?= h($p['remarks'] ?? '') ?: '<span class="text-muted">—</span>' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="text-muted text-center py-4">No promotion records found.</p>
        <?php endif; ?>
    </div>

    <!-- ── LOANS ────────────────────────────────────────────────────── -->
    <div class="tab-pane fade" id="loans">
        <div class="d-flex justify-content-between mb-3">
            <h6 class="fw-semibold mb-0">Loan &amp; Advance History</h6>
            <a href="<?= BASE_URL ?>/modules/loans/create.php?emp_id=<?= $id ?>" class="btn btn-sm btn-primary"><i class="fa fa-plus me-1"></i>Add Loan / Advance</a>
        </div>
        <?php if ($loan_rows): ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr><th>Type</th><th class="text-end">Principal</th><th class="text-center">Interest %</th><th>Date Given</th><th class="text-end">Monthly EMI</th><th class="text-end">Interest</th><th class="text-end">Total Due</th><th class="text-end">Returned</th><th class="text-end">Pending</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($loan_rows as $l):
                        // Interest-aware figures (matches the Loans module): pending is
                        // computed against total due = principal + interest, not principal alone.
                        $fig      = loan_figures($db, $l);
                        $returned = $fig['returned'];
                        $pending  = $fig['pending'];
                        $lStatus  = $fig['status'];
                    ?>
                    <tr>
                        <td><span class="badge bg-<?= $l['type']==='loan'?'primary':'info' ?>"><?= h(ucfirst($l['type'])) ?></span></td>
                        <td class="text-end"><?= money((float)$l['amount']) ?></td>
                        <td class="text-center"><?= (float)$l['interest_rate'] > 0 ? h($l['interest_rate']).'%' : '—' ?></td>
                        <td><?= date('d M Y', strtotime($l['date_given'])) ?></td>
                        <td class="text-end"><?= money((float)$l['monthly_deduction']) ?></td>
                        <td class="text-end"><?= $fig['interest'] > 0 ? '<span class="text-warning">'.money($fig['interest']).'</span>' : '<span class="text-muted">—</span>' ?></td>
                        <td class="text-end fw-semibold"><?= money($fig['total_due']) ?></td>
                        <td class="text-end text-success"><?= money($returned) ?></td>
                        <td class="text-end <?= $pending > 0 ? 'text-danger fw-semibold' : 'text-success' ?>"><?= money($pending) ?></td>
                        <td><span class="badge bg-<?= $lStatus==='active'?'success':($lStatus==='completed'?'primary':'secondary') ?>"><?= h(ucfirst($lStatus)) ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <p class="text-muted text-center py-4">No loan records found.</p>
        <?php endif; ?>
    </div>

    <!-- ── DOCUMENTS ────────────────────────────────────────────────── -->
    <div class="tab-pane fade" id="documents">
        <div class="d-flex justify-content-between mb-3">
            <h6 class="fw-semibold mb-0"><i class="fa fa-folder-open me-1 text-primary"></i>Documents</h6>
            <a href="<?= BASE_URL ?>/modules/documents/create.php?emp_id=<?= $id ?>" class="btn btn-sm btn-primary"><i class="fa fa-upload me-1"></i>Upload Document</a>
        </div>
        <?php if (!$doc_rows): ?>
            <p class="text-muted text-center py-4">No documents uploaded yet.</p>
        <?php else: ?>
        <?php
        $docIcons = ['pdf'=>'fa-file-pdf text-danger','doc'=>'fa-file-word text-primary','docx'=>'fa-file-word text-primary',
                     'jpg'=>'fa-file-image text-success','jpeg'=>'fa-file-image text-success','png'=>'fa-file-image text-success'];
        ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover table-bordered align-middle">
                <thead class="table-dark">
                    <tr><th>#</th><th>Type</th><th>Document</th><th>Size</th><th>Uploaded</th><th class="text-center" style="width:150px">Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($doc_rows as $di => $d):
                    $ext = strtolower(pathinfo($d['file_path'], PATHINFO_EXTENSION));
                    $icon = $docIcons[$ext] ?? 'fa-file text-secondary';
                ?>
                <tr>
                    <td><?= $di + 1 ?></td>
                    <td><span class="badge bg-light text-dark border"><?= h($d['document_type']) ?></span></td>
                    <td>
                        <i class="fa <?= $icon ?> me-1"></i><?= h($d['document_name']) ?>
                        <?php if (!empty($d['description'])): ?><div class="small text-muted"><?= h($d['description']) ?></div><?php endif; ?>
                    </td>
                    <td><?= $doc_size((int)$d['file_size']) ?></td>
                    <td><?= date_fmt($d['created_at']) ?></td>
                    <td class="text-center text-nowrap">
                        <a href="<?= BASE_URL ?>/<?= h($d['file_path']) ?>" target="_blank" class="btn btn-xs btn-outline-primary" title="View"><i class="fa fa-eye"></i></a>
                        <a href="<?= BASE_URL ?>/<?= h($d['file_path']) ?>" download class="btn btn-xs btn-outline-secondary ms-1" title="Download"><i class="fa fa-download"></i></a>
                        <?php if (can('employee','edit')): ?>
                        <form method="POST" action="<?= BASE_URL ?>/modules/documents/delete.php" class="d-inline" onsubmit="return confirm('Delete this document?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $d['id'] ?>">
                            <button type="submit" class="btn btn-xs btn-outline-danger ms-1" title="Delete"><i class="fa fa-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
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
            <a href="<?= BASE_URL ?>/modules/payroll/generate_slip.php?emp=<?= $id ?>" class="btn btn-sm btn-primary">
                <i class="fa fa-plus me-1"></i>Generate Slip
            </a>
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

<!-- ── Add Family Member Modal ────────────────────────────────────── -->
<div class="modal fade" id="familyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-semibold">Add Family Member</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <!-- Row 1: Name | Relationship -->
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                        <input type="text" id="fm_name" class="form-control" maxlength="150">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Relationship <span class="text-danger">*</span></label>
                        <select id="fm_relationship" class="form-select">
                            <option value="">Select</option>
                            <option>Father</option><option>Mother</option><option>Spouse</option>
                            <option>Son</option><option>Daughter</option><option>Brother</option>
                            <option>Sister</option><option>Guardian</option><option>Other</option>
                        </select>
                    </div>
                    <!-- Row 2: DOB | Occupation | Contact -->
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Date of Birth</label>
                        <input type="date" id="fm_dob" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Occupation</label>
                        <input type="text" id="fm_occupation" class="form-control" maxlength="100">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Contact Number</label>
                        <input type="text" id="fm_contact" class="form-control" maxlength="30">
                    </div>
                    <!-- Row 3: Dependency Status (full width) -->
                    <div class="col-12">
                        <label class="form-label fw-semibold">Dependency Status <span class="text-danger">*</span></label>
                        <select id="fm_dependency" class="form-select" style="max-width:320px">
                            <option value="dependent">Dependent</option>
                            <option value="independent">Independent</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="btnSaveFamily" class="btn btn-primary"><i class="fa fa-save me-1"></i>Save</button>
            </div>
        </div>
    </div>
</div>

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
<?php
// $page_scripts is output by footer.php AFTER Bootstrap is loaded — safe to use bootstrap.Modal here
ob_start();
?>
<script>
(function () {
    var famModalEl = document.getElementById('familyModal');
    if (!famModalEl) return;

    // Move modal to <body> so it escapes overflow/stacking-context of #mainContent
    document.body.appendChild(famModalEl);

    // Reset fields every time the modal is about to open
    famModalEl.addEventListener('show.bs.modal', function () {
        document.getElementById('fm_name').value        = '';
        document.getElementById('fm_relationship').value = '';
        document.getElementById('fm_dob').value         = '';
        document.getElementById('fm_occupation').value  = '';
        document.getElementById('fm_contact').value     = '';
        document.getElementById('fm_dependency').value  = 'dependent';
    });

    document.getElementById('btnSaveFamily').addEventListener('click', function () {
        var name = document.getElementById('fm_name').value.trim();
        var rel  = document.getElementById('fm_relationship').value;
        if (!name || !rel) { alert('Name and Relationship are required.'); return; }

        var btn = this;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';

        fetch('<?= BASE_URL ?>/modules/employee/family_save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action:            'add',
                emp_id:            '<?= $id ?>',
                name:              name,
                relationship:      rel,
                dob:               document.getElementById('fm_dob').value,
                occupation:        document.getElementById('fm_occupation').value,
                contact_number:    document.getElementById('fm_contact').value,
                dependency_status: document.getElementById('fm_dependency').value,
                csrf_token:        '<?= csrf_token() ?>'
            })
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.success) {
                // Set hash so the tab init JS opens #family after reload
                history.replaceState(null, null, '#family');
                location.reload();
            } else {
                alert(d.error || 'Save failed.');
                btn.disabled = false;
                btn.innerHTML = '<i class="fa fa-save me-1"></i>Save';
            }
        })
        .catch(function () {
            alert('Network error. Please try again.');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-save me-1"></i>Save';
        });
    });
}());
</script>
<?php
$page_scripts = ob_get_clean();
include __DIR__ . '/../../includes/footer.php';
?>
