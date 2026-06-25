<?php
// IMPORTANT: resolve the slip and run all auth/redirects BEFORE including
// header.php — otherwise a redirect() here triggers "headers already sent".
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('payroll');

$db = db();

// Accept ?id=<slip>, or ?employee_id=<emp> (the employee-list "View Salary Slip"
// link passes employee_id) — resolve that to the employee's latest slip.
$id = (int)($_GET['id'] ?? 0);
if (!$id && !empty($_GET['employee_id'])) {
    $latest = $db->prepare(
        'SELECT id FROM salary_slips WHERE employee_id = ? ORDER BY payroll_month DESC, id DESC LIMIT 1'
    );
    $latest->execute([(int)$_GET['employee_id']]);
    $id = (int)$latest->fetchColumn();
}

$slip = $db->prepare(
    'SELECT ss.*, e.name, e.employee_id AS emp_code, e.designation_id,
            e.department_id, e.created_at, e.entity_id,
            e.bank_account, e.bank_name, e.bank_ifsc, e.pan_number, e.uan_number,
            d.name AS dept_name, des.name AS desig_name,
            ent.name AS entity_name, ent.address AS entity_address,
            ent.city AS entity_city, ent.state AS entity_state,
            ent.pincode AS entity_pincode, ent.logo AS entity_logo
     FROM salary_slips ss
     JOIN employees e ON e.id = ss.employee_id
     LEFT JOIN departments d   ON d.id = e.department_id
     LEFT JOIN designations des ON des.id = e.designation_id
     LEFT JOIN entities ent    ON ent.id = e.entity_id
     WHERE ss.id = ?'
);
$slip->execute([$id]);
$s = $slip->fetch();
if (!$s) { flash('error', 'Salary slip not found.'); redirect(BASE_URL . '/modules/payroll/index.php'); }

// Auth check: employees can view their own slips only
$user = current_user();
if ($user['role_name'] === 'Employee') {
    $myEmp = $db->prepare('SELECT id FROM employees WHERE email = ? LIMIT 1');
    $myEmp->execute([$user['email']]);
    $myId = $myEmp->fetchColumn();
    if ($s['employee_id'] != $myId) {
        http_response_code(403); include BASE_PATH . '/includes/403.php'; exit;
    }
}

// All redirects/auth done — now it is safe to emit output.
$page_title = 'Salary Slip';
require_once __DIR__ . '/../../includes/header.php';

// ─── Determine slip type and build display data ──────────────────────────────
$isIndividual = ($s['slip_type'] ?? 'batch') === 'individual';
$monthLabel   = date('F Y', strtotime($s['payroll_month'] . '-01'));

$allowances = [];
$deductions = [];
$attSummary = [];

if ($isIndividual && $s['allowances']) {
    $allowances = json_decode($s['allowances'], true) ?? [];
}
if ($isIndividual && $s['deductions_json']) {
    $deductions = json_decode($s['deductions_json'], true) ?? [];
}
if ($s['attendance_summary']) {
    $attSummary = json_decode($s['attendance_summary'], true) ?? [];
}

// Fallback to fixed columns for batch slips or if JSON is empty
if (!$allowances) {
    $allowances = array_filter([
        'Basic Salary'      => (float)$s['basic'],
        'HRA'               => (float)$s['hra'],
        'Conveyance'        => (float)$s['conveyance'],
        'Medical Allowance' => (float)$s['medical'],
        'Special Allowance' => (float)$s['special_allow'],
        'Other Allowance'   => (float)$s['other_allow'],
    ]);
}
if (!$deductions) {
    $deductions = array_filter([
        'Provident Fund (Employee)' => (float)$s['pf_employee'],
        'ESI (Employee)'            => (float)$s['esi_employee'],
        'TDS'                       => (float)$s['tds'],
        'Other Deductions'          => (float)$s['other_deductions'],
    ]);
}

// ─── Company header from the employee's selected entity ──────────────────────
// Falls back to the global COMPANY_* constants when no entity is assigned.
$companyName = $s['entity_name'] ?: COMPANY_NAME;
$companyAddress = COMPANY_ADDRESS;
if ($s['entity_name']) {
    $cityLine = trim(implode(' ', array_filter([$s['entity_city'], $s['entity_state'], $s['entity_pincode']])));
    $companyAddress = trim(implode(', ', array_filter([$s['entity_address'], $cityLine])));
}

// Logo: prefer the employee's entity logo, else any entity logo, else none.
$logoUrl  = null;
$logoFile = $s['entity_logo'] ?? null;
if (!$logoFile) {
    try {
        $logoSt = $db->prepare("SELECT logo FROM entities WHERE logo IS NOT NULL AND logo != '' LIMIT 1");
        $logoSt->execute();
        $logoFile = $logoSt->fetchColumn() ?: null;
    } catch (Exception $_e) {}
}
if ($logoFile && file_exists(BASE_PATH . '/storage/entities/' . $logoFile)) {
    $logoUrl = BASE_URL . '/storage/entities/' . h($logoFile);
}

$extra_head = '<style>
@media print {
    .topbar, #sidebar, .sidebar-overlay, .page-head .head-actions,
    .no-print, .nav-link, footer { display:none !important; }
    .main-wrapper { margin:0 !important; }
    #mainContent  { padding:0 !important; }
    #salarySlip   { box-shadow:none !important; border:none !important; max-width:100% !important; }
}
.slip-col { padding:7px 12px; border-bottom:1px solid var(--border); font-size:13px; }
.slip-col.head { font-weight:700; background:var(--bg-subtle); border-bottom:2px solid var(--border-strong); }
.slip-total-row { background:var(--bg-subtle); font-weight:700; }
</style>';

/** Whole-rupee amount → words using the Indian numbering system (Lakh/Crore). */
if (!function_exists('_inr_words')) {
    function _inr_words(float $num): string {
        $num = (int) round($num);
        if ($num <= 0) return 'Zero';
        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
                 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
        $two  = function (int $n) use ($ones, $tens): string {
            return $n < 20 ? $ones[$n] : trim($tens[intdiv($n, 10)] . ' ' . $ones[$n % 10]);
        };
        $three = function (int $n) use ($ones, $two): string {
            $h = intdiv($n, 100); $r = $n % 100;
            return trim(($h ? $ones[$h] . ' Hundred ' : '') . ($r ? $two($r) : ''));
        };
        $parts = [];
        $crore = intdiv($num, 10000000); $num %= 10000000;
        $lakh  = intdiv($num, 100000);   $num %= 100000;
        $thou  = intdiv($num, 1000);     $num %= 1000;
        if ($crore) $parts[] = $three($crore) . ' Crore';
        if ($lakh)  $parts[] = $three($lakh)  . ' Lakh';
        if ($thou)  $parts[] = $three($thou)  . ' Thousand';
        if ($num)   $parts[] = $three($num);
        return trim(implode(' ', $parts));
    }
}
?>

<div class="page-head">
    <div>
        <h1>Salary Slip</h1>
        <p class="muted"><?= h($s['name']) ?> &middot; <?= $monthLabel ?></p>
    </div>
    <div class="head-actions no-print">
        <button onclick="window.print()" class="btn btn-primary" title="Print">
            <i class="fa fa-print"></i> Print
        </button>
        <a href="<?= BASE_URL ?>/modules/payroll/slip_pdf.php?id=<?= $s['id'] ?>"
           class="btn" style="border-color:var(--danger);color:var(--danger)" target="_blank" title="Download PDF">
            <i class="fa fa-file-pdf"></i> PDF
        </a>
        <?php if (can('payroll','process')): ?>
        <?php
            // Regenerate opens the Generate Slip page (employee/month/year pre-filled)
            // so the admin can review/enter Paid Leaves and OT Hours before regenerating,
            // instead of silently recomputing straight from attendance.
            $pm = explode('-', $s['payroll_month']);
            $regUrl = BASE_URL . '/modules/payroll/generate_slip.php?emp=' . (int)$s['employee_id']
                    . '&month=' . (int)($pm[1] ?? 1) . '&year=' . (int)($pm[0] ?? date('Y'));
        ?>
        <a href="<?= $regUrl ?>" class="btn" style="border-color:#e6a817;color:#e6a817" title="Regenerate (set paid leaves / OT)">
            <i class="fa fa-refresh"></i> Regenerate
        </a>
        <form method="POST" action="<?= BASE_URL ?>/modules/payroll/delete_slip.php" style="display:inline"
              onsubmit="return confirm('Delete this salary slip permanently?')">
            <?= csrf_field() ?>
            <input type="hidden" name="slip_id" value="<?= $s['id'] ?>">
            <button type="submit" class="btn btn-outline-danger" title="Delete">
                <i class="fa fa-trash"></i>
            </button>
        </form>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/modules/payroll/index.php" class="btn btn-ghost no-print">← Back</a>
    </div>
</div>

<?= render_flash() ?>

<div class="card form-card" id="salarySlip" style="max-width:820px;margin:0 auto">

    <!-- ── Slip Header (centred — only the logo is in colour) ───────────────── -->
    <div style="text-align:center;padding:20px 24px 14px">
        <?php if ($logoUrl): ?>
        <img src="<?= $logoUrl ?>" alt="Logo" style="max-height:54px;margin-bottom:8px">
        <?php endif; ?>
        <div style="font-size:18px;font-weight:800;color:#000"><?= h($companyName) ?></div>
        <div style="font-size:11px;color:#000;margin-top:3px;line-height:1.5"><?= h($companyAddress) ?></div>
    </div>
    <div style="text-align:center;padding:8px 24px;border-top:1px solid #000;border-bottom:1px solid #000">
        <span style="font-size:14px;font-weight:700;color:#000">Payslip for the month of <?= $monthLabel ?></span>
    </div>

    <!-- ── Employee Info ───────────────────────────────────────────────────── -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0;margin:18px 24px;border:1px solid #000;border-radius:var(--radius)">
        <?php
        $infoRows = [
            ['Employee Name',  $s['name'],            'Employee ID',  $s['emp_code']],
            ['Department',     $s['dept_name'] ?? '—','Designation',  $s['desig_name'] ?? '—'],
            ['Pay Period',     $monthLabel,           'PAN Number',   $s['pan_number'] ?: '—'],
        ];
        if ($s['uan_number']) {
            $infoRows[] = ['UAN Number', $s['uan_number'], 'Bank Account', $s['bank_account'] ?: '—'];
        }
        foreach ($infoRows as $i => [$lbl1, $val1, $lbl2, $val2]):
            $border = $i < count($infoRows)-1 ? 'border-bottom:1px solid #000' : '';
        ?>
        <div style="padding:8px 12px;font-size:13px;color:#000;<?= $border ?>">
            <span><?= $lbl1 ?>: </span>
            <strong><?= is_string($val1) ? h($val1) : $val1 ?></strong>
        </div>
        <div style="padding:8px 12px;font-size:13px;color:#000;border-left:1px solid #000;<?= $border ?>">
            <span><?= $lbl2 ?>: </span>
            <strong><?= is_string($val2) ? h($val2) : $val2 ?></strong>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ── Earnings & Deductions (single combined table) ───────────────────── -->
    <?php
        // Earnings: base + benefits + bonuses flattened into one column.
        $earningRows = $benefitRows = $bonusRows = [];
        foreach ($allowances as $label => $amount) {
            if ($amount <= 0) continue;
            if (str_starts_with($label, '[BENEFIT]'))  $benefitRows[ltrim(substr($label, 9))] = $amount;
            elseif (str_starts_with($label, '[BONUS]')) $bonusRows[ltrim(substr($label, 7))]  = $amount;
            else                                        $earningRows[$label] = $amount;
        }
        $earnList = $earningRows;
        foreach ($benefitRows as $k => $v) $earnList[$k] = $v;
        // All bonuses collapse into a single "Bonus" line with the summed amount.
        $bonusTotal = array_sum($bonusRows);
        if ($bonusTotal > 0) $earnList['Bonus'] = $bonusTotal;

        // Deductions: each on its own line (Absent, Late, Short Hours shown separately).
        // Strip "(N days …)" detail; merge multiple loans (#id) into one "Loan Deduction".
        $dedList = [];
        foreach ($deductions as $label => $amount) {
            if ($amount <= 0) continue;
            $clean = preg_replace('/\s*\(\s*\d+\s*day.*?\)/i', '', $label);   // drop "(N days …)"
            $clean = preg_replace('/\s*#\d+/', '', $clean);                   // merge loans → one "Loan Deduction"
            $dedList[$clean] = ($dedList[$clean] ?? 0) + (float)$amount;
        }

        $eLabels = array_keys($earnList); $eItems = array_values($earnList);
        $dLabels = array_keys($dedList);  $dItems = array_values($dedList);
        $rowCount = max(count($eLabels), count($dLabels), 1);
        $tdC = 'border:1px solid #000;padding:6px 10px';
    ?>
    <table style="width:calc(100% - 48px);margin:18px 24px;border-collapse:collapse;font-size:13px;color:#000">
        <thead>
            <tr>
                <th style="<?= $tdC ?>;text-align:left;width:35%">Earnings</th>
                <th style="<?= $tdC ?>;text-align:right;width:15%">Amount</th>
                <th style="<?= $tdC ?>;text-align:left;width:35%">Deductions</th>
                <th style="<?= $tdC ?>;text-align:right;width:15%">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php for ($i = 0; $i < $rowCount; $i++): ?>
            <tr>
                <td style="<?= $tdC ?>"><?= isset($eLabels[$i]) ? h($eLabels[$i]) : '' ?></td>
                <td style="<?= $tdC ?>;text-align:right"><?= isset($eItems[$i]) ? money($eItems[$i]) : '' ?></td>
                <td style="<?= $tdC ?>"><?= isset($dLabels[$i]) ? h($dLabels[$i]) : '' ?></td>
                <td style="<?= $tdC ?>;text-align:right"><?= isset($dItems[$i]) ? money($dItems[$i]) : '' ?></td>
            </tr>
            <?php endfor; ?>
            <tr style="font-weight:700">
                <td style="<?= $tdC ?>">Total Earnings</td>
                <td style="<?= $tdC ?>;text-align:right"><?= money($s['gross_earnings']) ?></td>
                <td style="<?= $tdC ?>">Total Deductions</td>
                <td style="<?= $tdC ?>;text-align:right"><?= money($s['total_deductions']) ?></td>
            </tr>
        </tbody>
    </table>

    <!-- ── Net Pay ─────────────────────────────────────────────────────────── -->
    <div style="margin:0 24px 6px;border:2px solid #000;padding:12px 14px;color:#000">
        <div style="font-weight:700;font-size:14px">
            Net Pay for the month (Total Earnings &minus; Total Deductions): <?= money($s['net_pay']) ?>
        </div>
        <div style="font-size:12px;margin-top:4px">(Rupees <?= h(_inr_words((float)$s['net_pay'])) ?> Only)</div>
    </div>
    <div style="margin:0 24px 16px;font-size:11px;color:#000;display:flex;justify-content:flex-end;gap:18px">
        <span>PF Employer: <?= money($s['pf_employer']) ?></span>
        <span>ESI Employer: <?= money($s['esi_employer']) ?></span>
    </div>

    <!-- ── Footer ──────────────────────────────────────────────────────────── -->
    <div style="padding:12px 24px;border-top:1px solid #000;text-align:center;color:#000;font-size:11px">
        This is a system generated payslip and does not require signature.
        <div style="margin-top:4px">Print Date: <?= date('d M Y, h:i A') ?></div>
    </div>
</div>

<script>
window.BASE_URL = '<?= BASE_URL ?>';
window.PAGE_SHORTCUTS = {
    'p': () => window.print()
};
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
