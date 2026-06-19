<?php
$page_title = 'Salary Slip';
require_once __DIR__ . '/../../includes/header.php';
require_permission('payroll');

$id  = (int)($_GET['id'] ?? 0);
$db  = db();
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
if (!$s) { flash('error','Salary slip not found'); redirect(BASE_URL . '/modules/payroll/index.php'); }

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

// ─── Salary revision lookup ───────────────────────────────────────────────────
$increment = null;
$slipMonth = (int)date('n', strtotime($s['payroll_month'] . '-01'));
$slipYear  = (int)date('Y', strtotime($s['payroll_month'] . '-01'));
$firstOfMonth = $s['payroll_month'] . '-01';
$lastOfMonth  = date('Y-m-t', strtotime($firstOfMonth));

$incSt = $db->prepare(
    'SELECT ss.effective_from, ss.gross AS new_salary,
            (SELECT gross FROM salary_structures
              WHERE employee_id = ss.employee_id AND effective_from < ss.effective_from
              ORDER BY effective_from DESC LIMIT 1) AS prev_salary
     FROM salary_structures ss
     WHERE ss.employee_id = ? AND ss.effective_from BETWEEN ? AND ?
     ORDER BY ss.effective_from DESC LIMIT 1'
);
$incSt->execute([$s['employee_id'], $firstOfMonth, $lastOfMonth]);
$increment = $incSt->fetch() ?: null;

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
        <form method="POST" action="<?= BASE_URL ?>/modules/payroll/generate_slip.php" style="display:inline"
              onsubmit="return confirm('Regenerate this salary slip?\n\nThe current slip will be overwritten with a fresh calculation.')">
            <?= csrf_field() ?>
            <input type="hidden" name="employee_id" value="<?= $s['employee_id'] ?>">
            <?php
                $pm = explode('-', $s['payroll_month']);
                echo '<input type="hidden" name="month" value="' . (int)($pm[1] ?? 1) . '">';
                echo '<input type="hidden" name="year"  value="' . (int)($pm[0] ?? date('Y')) . '">';
            ?>
            <input type="hidden" name="force_regenerate" value="1">
            <button type="submit" class="btn" style="border-color:#e6a817;color:#e6a817" title="Regenerate">
                <i class="fa fa-refresh"></i> Regenerate
            </button>
        </form>
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

    <!-- ── Slip Header ─────────────────────────────────────────────────────── -->
    <div style="display:flex;justify-content:space-between;align-items:flex-start;padding:20px 24px;border-bottom:2px solid var(--primary)">
        <div>
            <?php if ($logoUrl): ?>
            <img src="<?= $logoUrl ?>" alt="Logo" style="max-height:52px;margin-bottom:6px;display:block">
            <?php endif; ?>
            <div style="font-size:18px;font-weight:800;color:var(--primary)"><?= h($companyName) ?></div>
            <div style="font-size:11px;color:var(--muted);margin-top:2px"><?= h($companyAddress) ?></div>
        </div>
        <div style="text-align:right">
            <div style="font-size:13px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:var(--muted)">SALARY SLIP</div>
            <div style="font-size:16px;font-weight:700;color:var(--primary)"><?= $monthLabel ?></div>
        </div>
    </div>

    <!-- ── Employee Info ───────────────────────────────────────────────────── -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0;margin:18px 24px;border:1px solid var(--border);border-radius:var(--radius)">
        <?php
        $infoRows = [
            ['Employee Name',  $s['name'],           'Employee ID',    $s['emp_code']],
            ['Department',     $s['dept_name'] ?? '—','Designation',   $s['desig_name'] ?? '—'],
            ['Pay Period',     $monthLabel,            'Working Days',  $s['working_days']],
            ['Present Days',   $s['present_days'],    'LOP / Absent',  $s['lop_days'] > 0 ? `<span style="color:var(--danger)">`.$s['lop_days'].`</span>` : '0'],
        ];
        if ($s['pan_number']) {
            $infoRows[] = ['PAN Number', $s['pan_number'], 'UAN Number', $s['uan_number'] ?? '—'];
        }
        foreach ($infoRows as $i => [$lbl1, $val1, $lbl2, $val2]):
            $border = $i < count($infoRows)-1 ? 'border-bottom:1px solid var(--border)' : '';
        ?>
        <div style="padding:8px 12px;font-size:13px;<?= $border ?>">
            <span style="color:var(--muted)"><?= $lbl1 ?>: </span>
            <strong><?= is_string($val1) ? h($val1) : $val1 ?></strong>
        </div>
        <div style="padding:8px 12px;font-size:13px;border-left:1px solid var(--border);<?= $border ?>">
            <span style="color:var(--muted)"><?= $lbl2 ?>: </span>
            <strong><?= is_string($val2) ? h($val2) : $val2 ?></strong>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ── Salary Revision Alert ───────────────────────────────────────────── -->
    <?php if ($increment && $increment['prev_salary'] && $increment['new_salary'] != $increment['prev_salary']): ?>
    <div style="margin:0 24px 16px;background:#fffde7;border:1px solid #ffc107;border-radius:var(--radius);padding:12px 14px;display:flex;gap:10px;align-items:flex-start">
        <i class="fa fa-arrow-trend-up" style="color:#e6a817;margin-top:2px"></i>
        <div style="font-size:13px">
            <strong>Salary Revision Applied</strong> w.e.f. <?= date_fmt($increment['effective_from']) ?><br>
            <span style="text-decoration:line-through;color:var(--muted)">
                <?= money((float)$increment['prev_salary']) ?>
            </span>
            &nbsp;&rarr;&nbsp;
            <span style="color:var(--success);font-weight:700"><?= money((float)$increment['new_salary']) ?></span>
            <?php
                if ($increment['prev_salary'] > 0) {
                    $pct = (($increment['new_salary'] - $increment['prev_salary']) / $increment['prev_salary']) * 100;
                    echo ' &nbsp;<span style="background:var(--success);color:#fff;padding:2px 6px;border-radius:3px;font-size:11px">+' . number_format($pct, 1) . '%</span>';
                }
            ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Earnings & Deductions ───────────────────────────────────────────── -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0 24px;padding:0 24px;margin-bottom:16px">

        <!-- Earnings -->
        <div>
            <?php
            $earningRows   = [];
            $benefitRows   = [];
            $bonusRows     = [];
            foreach ($allowances as $label => $amount) {
                if ($amount <= 0) continue;
                if (str_starts_with($label, '[BENEFIT]')) {
                    $benefitRows[ltrim(substr($label, 9))] = $amount;
                } elseif (str_starts_with($label, '[BONUS]')) {
                    $bonusRows[ltrim(substr($label, 7))] = $amount;
                } else {
                    $earningRows[$label] = $amount;
                }
            }
            $earnTotal   = array_sum($earningRows);
            $benefitTotal = array_sum($benefitRows);
            $bonusTotal  = array_sum($bonusRows);
            ?>
            <!-- Main earnings -->
            <div class="slip-col head" style="color:var(--success)">Earnings</div>
            <?php foreach ($earningRows as $label => $amount): ?>
            <div class="slip-col" style="display:flex;justify-content:space-between">
                <span><?= h($label) ?></span>
                <span><?= money($amount) ?></span>
            </div>
            <?php endforeach; ?>
            <div class="slip-col slip-total-row" style="display:flex;justify-content:space-between;color:var(--success)">
                <strong>Salary Sub-total</strong>
                <strong><?= money($earnTotal) ?></strong>
            </div>

            <?php if ($benefitRows): ?>
            <div style="margin-top:10px"></div>
            <div class="slip-col head" style="color:var(--info)">Benefit Funds</div>
            <?php foreach ($benefitRows as $label => $amount): ?>
            <div class="slip-col" style="display:flex;justify-content:space-between">
                <span><?= h($label) ?> <span class="badge" style="background:var(--info);font-size:9px">BENEFIT</span></span>
                <span><?= money($amount) ?></span>
            </div>
            <?php endforeach; ?>
            <div class="slip-col slip-total-row" style="display:flex;justify-content:space-between;color:var(--info)">
                <strong>Benefits Sub-total</strong>
                <strong><?= money($benefitTotal) ?></strong>
            </div>
            <?php endif; ?>

            <?php if ($bonusRows): ?>
            <div style="margin-top:10px"></div>
            <div class="slip-col head" style="color:#e6a817">Bonuses &amp; Incentives</div>
            <?php foreach ($bonusRows as $label => $amount): ?>
            <div class="slip-col" style="display:flex;justify-content:space-between">
                <span><?= h($label) ?> <span class="badge" style="background:#e6a817;font-size:9px">BONUS</span></span>
                <span><?= money($amount) ?></span>
            </div>
            <?php endforeach; ?>
            <div class="slip-col slip-total-row" style="display:flex;justify-content:space-between;color:#e6a817">
                <strong>Bonus Sub-total</strong>
                <strong><?= money($bonusTotal) ?></strong>
            </div>
            <?php endif; ?>

            <div style="margin-top:12px;background:#d4edda;border:1px solid #a8d5b3;border-radius:var(--radius);padding:10px 12px;display:flex;justify-content:space-between;font-weight:700;font-size:14px">
                <span><i class="fa fa-coins" style="color:var(--success)"></i> Total Gross</span>
                <span style="color:var(--success)"><?= money($s['gross_earnings']) ?></span>
            </div>
        </div>

        <!-- Deductions -->
        <div>
            <div class="slip-col head" style="color:var(--danger)">Deductions</div>
            <?php if ($deductions): ?>
            <?php foreach ($deductions as $label => $amount): if ($amount <= 0) continue; ?>
            <?php
                $badge = '';
                if (preg_match('/^(PF|Provident|ESI)/i', $label)) $badge = '<span class="badge" style="background:#555;font-size:9px;margin-left:4px">STAT</span>';
                elseif (preg_match('/absent/i', $label))           $badge = '<span class="badge" style="background:var(--danger);font-size:9px;margin-left:4px">ABS</span>';
                elseif (preg_match('/late/i', $label))             $badge = '<span class="badge" style="background:#e6a817;font-size:9px;margin-left:4px">LATE</span>';
            ?>
            <div class="slip-col" style="display:flex;justify-content:space-between">
                <span><?= h($label) ?><?= $badge ?></span>
                <span><?= money($amount) ?></span>
            </div>
            <?php endforeach; ?>
            <?php else: ?>
            <div class="slip-col" style="color:var(--muted);font-style:italic">No deductions this month</div>
            <?php endif; ?>
            <div class="slip-col slip-total-row" style="display:flex;justify-content:space-between;color:var(--danger)">
                <strong>Total Deductions</strong>
                <strong><?= money($s['total_deductions']) ?></strong>
            </div>
        </div>
    </div>

    <!-- ── Net Pay Banner ─────────────────────────────────────────────────── -->
    <div style="margin:0 24px 20px;background:var(--primary);color:#fff;border-radius:var(--radius);padding:16px 20px;display:flex;justify-content:space-between;align-items:center">
        <div>
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.08em;opacity:.8">NET SALARY (Take Home)</div>
            <div style="font-size:26px;font-weight:800"><?= money($s['net_pay']) ?></div>
        </div>
        <div style="text-align:right;font-size:12px;opacity:.85">
            <div>PF Employer: <?= money($s['pf_employer']) ?></div>
            <div>ESI Employer: <?= money($s['esi_employer']) ?></div>
        </div>
    </div>

    <!-- ── Attendance Summary ─────────────────────────────────────────────── -->
    <?php if ($attSummary): ?>
    <div style="margin:0 24px 20px">
        <div style="font-weight:700;font-size:13px;margin-bottom:10px;display:flex;align-items:center;gap:6px">
            <i class="fa fa-calendar-alt" style="color:var(--primary)"></i>
            Attendance Summary — <?= $monthLabel ?>
        </div>
        <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:10px;margin-bottom:12px">
            <?php
            $attCards = [
                ['Working Days', $attSummary['total_working_days'] ?? '—', ''],
                ['Present',      $attSummary['present_days']       ?? '—', 'var(--success)'],
                ['Half Day',     $attSummary['half_days']          ?? 0,   '#e6a817'],
                ['Paid Leave',   $attSummary['paid_leave_days']    ?? ($attSummary['leave_days'] ?? 0), 'var(--info)'],
                ['Absent',       number_format((float)($attSummary['absent_days'] ?? 0), 1), (($attSummary['absent_days'] ?? 0) > 0 ? 'var(--danger)' : 'var(--muted)')],
                ['Late Days',    $attSummary['late_days']          ?? 0, (($attSummary['late_days'] ?? 0) > 0 ? '#e6a817' : 'var(--muted)')],
            ];
            foreach ($attCards as [$lbl, $val, $color]):
            ?>
            <div style="text-align:center;background:var(--bg-subtle);border-radius:var(--radius);padding:8px 4px;border:1px solid var(--border)">
                <div style="font-size:18px;font-weight:700;<?= $color ? "color:{$color}" : '' ?>"><?= $val ?></div>
                <div style="font-size:10px;color:var(--muted)"><?= $lbl ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if (($attSummary['ot_hours'] ?? 0) > 0 || ($attSummary['late_minutes'] ?? 0) > 0): ?>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <?php if (($attSummary['ot_hours'] ?? 0) > 0): ?>
            <div style="background:var(--bg-subtle);border-radius:var(--radius);padding:10px 12px;font-size:12px;border:1px solid var(--border)">
                <strong>Overtime Details</strong>
                <div style="margin-top:6px;color:var(--muted)">OT Hours: <strong style="color:var(--success)"><?= number_format((float)$attSummary['ot_hours'], 2) ?> hrs</strong></div>
                <div style="color:var(--muted)">Rate: 2× hourly (₹<?= number_format((float)($attSummary['ot_per_hour_rate'] ?? $attSummary['per_hour_rate'] ?? 0), 2) ?>/hr × 2)</div>
                <div style="color:var(--muted)">OT Pay Added: <strong style="color:var(--success)"><?= money($attSummary['ot_amount'] ?? 0) ?></strong></div>
            </div>
            <?php endif; ?>
            <?php if (($attSummary['late_minutes'] ?? 0) > 0):
                $lateMins   = (int)$attSummary['late_minutes'];
                $lateGrace  = (int)($attSummary['late_grace_minutes']    ?? 90);
                $lateDed    = (int)($attSummary['deductable_late_mins']  ?? 0);
                $lateRemain = max(0, $lateGrace - $lateMins);
                $exceeded   = $lateMins > $lateGrace;
                $fmtMin     = function (int $m): string {
                    return ($m >= 60 ? intdiv($m, 60) . 'h ' : '') . ($m % 60) . 'm';
                };
            ?>
            <div style="background:var(--bg-subtle);border-radius:var(--radius);padding:10px 12px;font-size:12px;border:1px solid var(--border)">
                <strong><i class="fa fa-clock me-1"></i>Late Arrival Breakdown</strong>
                <div style="margin-top:6px;color:var(--muted)">
                    Total Late: <strong><?= $fmtMin($lateMins) ?></strong>
                    <span style="color:var(--muted);font-size:11px">(<?= $attSummary['late_days'] ?? 0 ?> day<?= ($attSummary['late_days'] ?? 0) === 1 ? '' : 's' ?>)</span>
                </div>
                <div style="color:var(--muted)">Monthly Grace: <strong><?= $fmtMin($lateGrace) ?></strong></div>
                <?php if ($exceeded): ?>
                    <div style="color:var(--danger);font-weight:600">Exceeded! Deducting <?= $fmtMin($lateDed) ?> (2×)</div>
                    <?php if (($attSummary['late_deduction'] ?? 0) > 0): ?>
                    <div style="color:var(--danger)"><?= money($attSummary['late_deduction']) ?> deducted</div>
                    <?php endif; ?>
                <?php else: ?>
                    <div style="color:var(--success)">Remaining grace: <strong><?= $fmtMin($lateRemain) ?></strong></div>
                    <div style="color:var(--success)"><i class="fa fa-check-circle me-1"></i>No deduction</div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ── Bottom: per-day / per-hour rates and calculation breakdown (matches Laravel) -->
        <?php
            $perDay   = (float)($attSummary['per_day_salary'] ?? 0);
            $perHour  = (float)($attSummary['per_hour_rate']  ?? 0);
            $calDays  = (int)($attSummary['calendar_days']    ?? 30);
            $workDays = $attSummary['total_working_days']     ?? '—';
            $absDays  = (float)($attSummary['absent_days']    ?? 0);
            $basicVal = (float)($attSummary['basic_salary']   ?? 0);
            $ctcVal   = (float)($attSummary['ctc_per_month']  ?? 0);
            $absDed   = (float)($attSummary['absent_deduction'] ?? ($absDays * $perDay));
        ?>
        <div style="margin-top:14px;padding-top:12px;border-top:1px dashed var(--border);font-size:12px;color:var(--muted);display:flex;flex-wrap:wrap;gap:16px;line-height:1.7">
            <span>Working Days: <strong style="color:var(--text)"><?= $workDays ?></strong>
                <span style="font-size:10px">(actual working)</span>
            </span>
            <span>Calendar Days: <strong style="color:var(--text)"><?= $calDays ?></strong>
                <span style="font-size:10px">(month total)</span>
            </span>
            <span>Per Day Rate: <strong style="color:var(--text)">₹<?= number_format($perDay, 2) ?></strong>
                <span style="font-size:10px">(₹<?= number_format($ctcVal, 0) ?> &divide; <?= $calDays ?> days)</span>
            </span>
            <span>Per Hour Rate: <strong style="color:var(--text)">₹<?= number_format($perHour, 2) ?></strong>
                <span style="font-size:10px">(per day &divide; 8 hrs)</span>
            </span>
            <?php if ($absDays > 0): ?>
            <span style="color:var(--danger)">Absent Deduction: <strong>₹<?= number_format($absDed, 2) ?></strong>
                <span style="font-size:10px">(<?= number_format($absDays, 1) ?> days &times; ₹<?= number_format($perDay, 2) ?>)</span>
            </span>
            <?php endif; ?>
            <span>Basic Salary: <strong style="color:var(--text)">₹<?= number_format($basicVal, 2) ?></strong></span>
            <span>CTC/Month: <strong style="color:var(--text)">₹<?= number_format($ctcVal, 2) ?></strong></span>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Footer ──────────────────────────────────────────────────────────── -->
    <div style="padding:12px 24px;border-top:1px solid var(--border);text-align:center;color:var(--muted);font-size:11px">
        Computer-generated salary slip. No signature required. For queries contact <?= h(COMPANY_EMAIL) ?>.
    </div>
</div>

<script>
window.BASE_URL = '<?= BASE_URL ?>';
window.PAGE_SHORTCUTS = {
    'p': () => window.print()
};
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
