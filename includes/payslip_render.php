<?php
/**
 * MagDyn HRMS — Payslip renderer (single source of truth)
 * ────────────────────────────────────────────────────────
 * The payslip layout lives here so the three places that show it stay
 * byte-identical:
 *
 *   • modules/payroll/slip_pdf.php  — HR downloads the PDF
 *   • employee/secure-access/       — the employee's own view after a QR scan
 *   • employee/secure-access/?dl=1  — the employee's own PDF download
 *
 * Before this file the portal had its own simplified layout, so an employee's
 * copy did not look like the one HR issues. Any change to the design belongs
 * here and shows up everywhere at once.
 *
 * The markup is deliberately table-based with inline-ish CSS: it has to survive
 * mPDF and TCPDF, neither of which supports modern layout.
 */

/**
 * Resolve the company header (name + address) for a slip row.
 * Falls back to the COMPANY_* constants when the employee has no entity.
 *
 * @return array{0:string,1:string} [companyName, companyAddress]
 */
function payslip_company(array $s): array
{
    $name = $s['entity_name'] ?: COMPANY_NAME;
    $addr = COMPANY_ADDRESS;
    if ($s['entity_name']) {
        $cityLine = trim(implode(' ', array_filter([
            $s['entity_city'] ?? '', $s['entity_state'] ?? '', $s['entity_pincode'] ?? '',
        ])));
        $addr = trim(implode(', ', array_filter([$s['entity_address'] ?? '', $cityLine])));
    }
    return [$name, $addr];
}

/**
 * Company logo as a base64 data URI, or null.
 * Prefers the employee's own entity logo, else any configured entity logo —
 * the payslip has always behaved this way, so it is kept as-is here.
 */
function payslip_logo_data(?string $entityLogo): ?string
{
    $logoFile = $entityLogo ?: null;
    if (!$logoFile) {
        try {
            $logoFile = db()->query(
                "SELECT logo FROM entities WHERE logo IS NOT NULL AND logo <> '' LIMIT 1"
            )->fetchColumn() ?: null;
        } catch (Throwable $e) { /* entities table absent */ }
    }
    if (!$logoFile) return null;

    $path = BASE_PATH . '/storage/entities/' . basename($logoFile);
    if (!is_file($path)) return null;

    // Sniff the ACTUAL image type — uploaded files are often mis-named (e.g. a
    // JPEG saved as ".png"); trusting the extension emits a wrong MIME and
    // breaks decoding in browsers and TCPDF.
    $info = @getimagesize($path);
    $mime = $info['mime'] ?? null;
    if (!$mime) {
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
                 'gif' => 'image/gif', 'svg' => 'image/svg+xml'][$ext] ?? 'image/png';
    }
    return 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path));
}

/**
 * Split a slip's allowances/deductions into the two printed columns.
 *
 * Earnings: base components + each benefit + one combined Bonus line.
 * Deductions: one line each, with "(N days …)" detail stripped and multiple
 * loans (#id) merged into a single "Loan Deduction".
 *
 * @return array{earnings: array<string,float>, deductions: array<string,float>}
 */
function payslip_lines(array $s): array
{
    $isIndividual = ($s['slip_type'] ?? 'batch') === 'individual';
    $allowances   = !empty($s['allowances'])      ? (json_decode($s['allowances'], true) ?: []) : [];
    $deductions   = !empty($s['deductions_json']) ? (json_decode($s['deductions_json'], true) ?: []) : [];

    if (!$isIndividual || empty($allowances)) {
        $allowances = array_filter([
            'Basic Salary'      => (float) $s['basic'],
            'HRA'               => (float) $s['hra'],
            'Conveyance'        => (float) $s['conveyance'],
            'Medical Allowance' => (float) $s['medical'],
            'Special Allowance' => (float) $s['special_allow'],
            'Other Allowance'   => (float) $s['other_allow'],
        ]);
    }
    if (!$isIndividual || empty($deductions)) {
        $deductions = array_filter([
            'Provident Fund (Employee)' => (float) $s['pf_employee'],
            'ESI (Employee)'            => (float) $s['esi_employee'],
            'TDS'                       => (float) $s['tds'],
            'Other Deductions'          => (float) $s['other_deductions'],
        ]);
    }

    $earnList = []; $bonusTotal = 0.0;
    foreach ($allowances as $name => $amt) {
        if ($amt <= 0) continue;
        if (str_starts_with($name, '[BENEFIT]'))    $earnList[trim(substr($name, 9))] = $amt;
        elseif (str_starts_with($name, '[BONUS]'))   $bonusTotal += (float) $amt;
        else                                         $earnList[$name] = $amt;
    }
    if ($bonusTotal > 0) $earnList['Bonus'] = $bonusTotal;

    $dedList = [];
    foreach ($deductions as $name => $amt) {
        if ($amt <= 0) continue;
        $clean = preg_replace('/\s*\(\s*\d+\s*day.*?\)/i', '', $name);
        $clean = preg_replace('/\s*#\d+/', '', $clean);
        $dedList[$clean] = ($dedList[$clean] ?? 0) + (float) $amt;
    }

    return ['earnings' => $earnList, 'deductions' => $dedList];
}

/**
 * The payslip itself.
 *
 * $s must carry the salary_slips row plus: emp_name, emp_code, dept_name,
 * desig_name, pan_number, esic_number, uan_number, bank_account, bank_name,
 * bank_ifsc, entity_* columns.
 *
 * @param bool $forScreen true renders the same layout for the browser (adds a
 *                        wrapper that lets the wide table scroll on a phone
 *                        instead of overflowing the page).
 */
function payslip_html(array $s, bool $forScreen = false): string
{
    [$companyName, $companyAddress] = payslip_company($s);
    $logoData   = payslip_logo_data($s['entity_logo'] ?? null);
    $monthLabel = date('F Y', strtotime($s['payroll_month'] . '-01'));
    $attSummary = !empty($s['attendance_summary']) ? (json_decode($s['attendance_summary'], true) ?: []) : [];

    ['earnings' => $earnList, 'deductions' => $dedList] = payslip_lines($s);

    $eLabels = array_keys($earnList); $eItems = array_values($earnList);

    $totalEarnings   = (float) $s['gross_earnings'];
    $totalDeductions = (float) $s['total_deductions'];

    $rs = '&#8377;';
    $nf = fn ($a) => number_format((float) $a, 2);
    $hh = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    // LOP days = absences + sandwich days + half days, exactly as the LOP Amount
    // line was priced. Short hours carry their own "Others" line.
    $lopDays  = (float) ($attSummary['lop_days'] ?? $attSummary['absent_days']
                         ?? $attSummary['unpaid_days'] ?? 0);
    $lateMins = (int) ($attSummary['late_minutes'] ?? 0);
    $fmtDays  = fn (float $d) => rtrim(rtrim(number_format($d, 2), '0'), '.');
    // Sandwich days sit inside the LOP figure, so say how many there are —
    // otherwise "4 days" for two days taken off reads as an error.
    $sandwichDays = (int) ($attSummary['sandwich_days'] ?? 0);

    // Printed deduction rows: [label, already-formatted value]. "LOP Amount" is
    // followed by a display-only "LOP Days" line holding the day count instead
    // of a rupee figure; it is not part of Total Deductions.
    $dRows = [];
    foreach ($dedList as $dLabel => $dAmt) {
        $dRows[] = [$dLabel, $nf($dAmt)];
        if ($dLabel === 'LOP Amount') {
            $dRows[] = ['LOP Days', $fmtDays($lopDays)];
        }
    }
    $maxRows = max(count($eLabels), count($dRows), 1);

    ob_start();
    ?>
<style>
    .payslip { font-family: dejavusans, sans-serif; font-size: 9.5pt; color: #000; background: #fff; }
    .payslip .company-name { font-size: 17pt; font-weight: bold; color: #000; }
    .payslip .company-sub  { font-size: 8pt; color: #000; }
    .payslip .title-bar { font-size: 12pt; font-weight: bold; text-align: center; padding: 5px 0;
                 border-top: 1px solid #000; border-bottom: 1px solid #000; }
    .payslip table.info { width: 100%; border-collapse: collapse; margin: 12px 0; }
    .payslip table.info td { border: 1px solid #000; padding: 5px 8px; font-size: 9pt; width: 50%; }
    .payslip table.ed { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    .payslip table.ed th, .payslip table.ed td { border: 1px solid #000; padding: 5px 8px; font-size: 9pt; }
    .payslip table.ed th { text-align: left; }
    .payslip .net-box { border: 1.5px solid #000; padding: 9px 12px; margin-bottom: 6px; }
    .payslip .footer { font-size: 8pt; color: #000; text-align: center; border-top: 1px solid #000; padding-top: 6px; margin-top: 10px; }
<?php if ($forScreen): ?>
    /* On a phone the info/earnings tables are wider than the viewport; let the
       slip scroll sideways rather than squashing the printed layout. */
    .payslip-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; background: #fff;
                      border: 1px solid #e5e7eb; border-radius: 12px; padding: 14px; }
    .payslip-scroll .payslip { min-width: 520px; }

    /* Printing the on-screen slip.
       The scroll wrapper has to be dismantled first: an overflow container
       keeps its own scrollport when printed, which pushed the slip onto a
       second A4 sheet even though the content is only ~183mm tall. Undoing the
       overflow / padding / min-width, then asking for the slip to be kept
       whole, lands it on one page. */
    @media print {
        @page { size: A4 portrait; margin: 12mm; }
        html, body { width: auto !important; margin: 0 !important; padding: 0 !important;
                     background: #fff !important; }
        .payslip-scroll {
            overflow: visible !important;
            border: 0 !important; border-radius: 0 !important; padding: 0 !important;
            background: #fff !important; width: auto !important; max-width: none !important;
        }
        .payslip-scroll .payslip { min-width: 0 !important; width: 100% !important; }
        .payslip { page-break-inside: avoid; break-inside: avoid; }
        .payslip table { page-break-inside: auto; break-inside: auto; }
        .payslip tr, .payslip .net-box, .payslip .title-bar {
            page-break-inside: avoid; break-inside: avoid;
        }
        /* Keep the black rules and the logo visible if the browser drops
           background graphics. */
        .payslip, .payslip * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    }
<?php endif; ?>
</style>
<?= $forScreen ? '<div class="payslip-scroll">' : '' ?>
<div class="payslip">

<div style="text-align:center;margin-bottom:6px">
    <?php if ($logoData): ?><img src="<?= $logoData ?>" style="height:48px"><br><?php endif; ?>
    <span class="company-name" style="<?= entity_name_style($s['entity_name_font'] ?? '') ?>"><?= $hh($companyName) ?></span><br>
    <span class="company-sub"><?= $hh($companyAddress) ?></span>
</div>
<div class="title-bar">Payslip for the month of <?= $hh($monthLabel) ?></div>

<table class="info">
    <tr><td><strong>Employee Name:</strong> <?= $hh($s['emp_name']) ?></td>
        <td><strong>Employee ID:</strong> <?= $hh($s['emp_code']) ?></td></tr>
    <tr><td><strong>Department:</strong> <?= $hh($s['dept_name'] ?? '—') ?: '—' ?></td>
        <td><strong>Designation:</strong> <?= $hh($s['desig_name'] ?? '—') ?: '—' ?></td></tr>
    <tr><td><strong>Pay Period:</strong> <?= $hh($monthLabel) ?></td>
        <td><strong>Shift:</strong> <?= $hh($attSummary['shift_name'] ?? '—') ?: '—' ?></td></tr>
    <tr><td><strong>LOP:</strong> <?= $hh($fmtDays($lopDays)) ?> day<?= $lopDays == 1 ? '' : 's' ?><?= $sandwichDays > 0 ? ' (incl. ' . $sandwichDays . ' sandwich)' : '' ?></td>
        <td><strong>Late:</strong> <?= $lateMins > 0 ? $hh(fmt_ot_hours($lateMins / 60)) : '—' ?></td></tr>
    <tr><td><strong>PAN Number:</strong> <?= $hh($s['pan_number'] ?? '—') ?: '—' ?></td>
        <td><strong>ESI Number:</strong> <?= $hh($s['esic_number'] ?? '—') ?: '—' ?></td></tr>
    <tr><td><strong>Bank Account:</strong> <?= $hh($s['bank_account'] ?? '—') ?: '—' ?></td>
        <td><strong>Bank Name:</strong> <?= $hh($s['bank_name'] ?? '—') ?: '—' ?></td></tr>
    <?php if (!empty($s['uan_number'])): ?>
    <tr><td><strong>UAN Number:</strong> <?= $hh($s['uan_number']) ?></td>
        <td><strong>IFSC:</strong> <?= $hh($s['bank_ifsc'] ?? '—') ?: '—' ?></td></tr>
    <?php endif; ?>
</table>

<table class="ed">
    <tr>
        <th style="width:35%">Earnings</th><th style="width:15%;text-align:right">Amount (<?= $rs ?>)</th>
        <th style="width:35%">Deductions</th><th style="width:15%;text-align:right">Amount (<?= $rs ?>)</th>
    </tr>
    <?php for ($i = 0; $i < $maxRows; $i++): ?>
    <tr>
        <td><?= isset($eLabels[$i]) ? $hh($eLabels[$i]) : '' ?></td>
        <td style="text-align:right"><?= isset($eItems[$i]) ? $nf($eItems[$i]) : '' ?></td>
        <td><?= isset($dRows[$i]) ? $hh($dRows[$i][0]) : '' ?></td>
        <td style="text-align:right"><?= isset($dRows[$i]) ? $hh($dRows[$i][1]) : '' ?></td>
    </tr>
    <?php endfor; ?>
    <tr style="font-weight:bold">
        <td>Total Earnings</td><td style="text-align:right"><?= $nf($totalEarnings) ?></td>
        <td>Total Deductions</td><td style="text-align:right"><?= $nf($totalDeductions) ?></td>
    </tr>
</table>

<?php $netRounded = round((float) $s['net_pay']); ?>
<div class="net-box" style="margin-bottom:10px">
    <span style="font-weight:bold;font-size:11pt">Net Pay for the month (Total Earnings &minus; Total Deductions): <?= $rs ?> <?= number_format($netRounded, 2) ?></span><br>
    <span style="font-size:8.5pt">(Rupees <?= $hh(_inr_words($netRounded)) ?> Only)</span>
</div>

<div class="footer">
    This is a system generated payslip and does not require signature.<br>
    Print Date: <?= date('d M Y, h:i A') ?>
</div>

</div>
<?= $forScreen ? '</div>' : '' ?>
    <?php
    return (string) ob_get_clean();
}
