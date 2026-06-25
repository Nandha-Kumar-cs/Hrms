<?php
/**
 * Offer letter rendering — shared by the PDF download (modules/letters/download.php)
 * and the on-screen view (modules/letters/view.php) so both look identical.
 *
 * The salary breakup is computed at render time from salary_components × the
 * offered salary (so it is never ₹0). All CSS is scoped under .offer-doc so the
 * fragment is safe to embed inside another page.
 */

/**
 * Compute the offer letter's structured fields from the stored letter row.
 * Returns: salary, join_fmt, join_day, salutation, allowances, deductions, gross_pay.
 */
function offer_letter_data(PDO $db, array $letter): array
{
    $content  = (string)($letter['content'] ?? '');
    $rawnum   = fn($v) => (float)preg_replace('/[^0-9.]/', '', (string)$v);
    // Line-bounded read — an empty value must not bleed into the next line.
    $pickLine = function (string $label) use ($content): string {
        return preg_match('/' . preg_quote($label, '/') . '[^\S\n]*:?[^\S\n]*([^\n]*)/i', $content, $m)
            ? trim($m[1]) : '';
    };

    // Offered salary: prefer the figure in the letter, else the employee's pay.
    $salary = $rawnum($pickLine('Total Cost to Company'));
    if ($salary <= 0) $salary = $rawnum($pickLine('Compensation'));
    if ($salary <= 0) $salary = (float)($letter['fixed_salary'] ?? 0) + (float)($letter['variable_salary'] ?? 0);

    // Joining date (from the letter body, else the employee record).
    $joinRaw   = $pickLine('Date Of Joining');
    $joinClean = $joinRaw ? trim(preg_replace('/\(.*$/', '', $joinRaw)) : '';
    $joinTs    = $joinClean ? strtotime($joinClean) : false;
    if (!$joinTs && !empty($letter['join_date'])) $joinTs = strtotime((string)$letter['join_date']);

    // Salary breakup from configured components (percentage of salary, or fixed).
    $allowances = []; $deductions = [];
    try {
        foreach ($db->query('SELECT name, type, calculation_type, value FROM salary_components ORDER BY id') as $c) {
            $amt = $c['calculation_type'] === 'percentage' ? ($c['value'] / 100) * $salary : (float)$c['value'];
            if ($c['type'] === 'allowance') $allowances[$c['name']] = round($amt, 2);
            else                            $deductions[$c['name']] = round($amt, 2);
        }
    } catch (Throwable $e) { /* no components table — breakup stays empty */ }

    return [
        'salary'     => $salary,
        'join_fmt'   => $joinTs ? date('d-m-y', $joinTs) : '',
        'join_day'   => $joinTs ? date('l', $joinTs) : '',
        'salutation' => ($letter['gender'] ?? '') === 'Male' ? 'Mr.'
                      : (($letter['gender'] ?? '') === 'Female' ? 'Mrs.' : ''),
        'allowances' => $allowances,
        'deductions' => $deductions,
        'gross_pay'  => array_sum($allowances),
    ];
}

/**
 * Build the 2-page offer-letter HTML fragment (a <style> block + <div class="offer-doc">…</div>).
 *
 * $co   = ['name' => …, 'addr' => …, 'logo' => data-URI|null]
 * $opts = ['screen' => bool, 'inline_footer' => bool]
 *   • screen        — add on-screen "paper sheet" styling for each page.
 *   • inline_footer — render the entity address at the foot of each page (the PDF
 *                     instead uses a repeating mPDF page footer, so it omits this).
 */
function offer_letter_html(array $letter, array $co, array $data, array $opts = []): string
{
    $coName = (string)($co['name'] ?? '');
    $coAddr = (string)($co['addr'] ?? '');
    $coLogo = $co['logo'] ?? null;
    $footerLine = trim($coName . ($coAddr ? ' ' . $coAddr : ''));

    $salutation  = $data['salutation'] ?? '';
    $joinFmt     = $data['join_fmt'] ?? '';
    $joinDay     = $data['join_day'] ?? '';
    $offerSalary = (float)($data['salary'] ?? 0);
    $allowances  = $data['allowances'] ?? [];
    $deductions  = $data['deductions'] ?? [];
    $grossPay    = (float)($data['gross_pay'] ?? 0);
    $signatory   = 'Authorized Signatory';
    $sigImg      = $co['signature'] ?? null;          // signature image src (URL or data URI)
    $sigTitle    = trim((string)($co['signatory_title'] ?? ''));
    $issuedDate  = (string)($letter['issued_date'] ?? date('Y-m-d'));

    $screen = !empty($opts['screen']);
    $inline = !empty($opts['inline_footer']);

    // Explicit height (not max-height) — mPDF ignores max-* on images and would
    // otherwise render the logo at its native pixel size. height-only keeps aspect.
    $logoCell = $coLogo
        ? '<img src="' . h($coLogo) . '" align="right" alt="Logo" style="height:56px">'
        : '<div class="logo-ph">' . h($coName) . '</div>';
    // Inline title style — mPDF doesn't reliably apply the scoped .letter-title rule.
    $titleHtml = '<div class="letter-title" style="font-size:28pt;font-weight:bold;color:#111;font-family:\'DejaVu Sans\',Arial,sans-serif">Offer Letter</div>';
    $footerHtml = $inline ? '<div class="ofooter">' . h($footerLine) . '</div>' : '';

    ob_start();
    ?>
<style>
    .offer-doc * { margin: 0; padding: 0; box-sizing: border-box; }
    .offer-doc { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10pt; color: #222; }
    .offer-doc .page-break { page-break-after: always; }
    .offer-doc table.oh { width: 100%; margin-bottom: 4px; }
    .offer-doc table.oh td.oh-left  { vertical-align: middle; }
    .offer-doc table.oh td.oh-right { vertical-align: middle; text-align: right; width: 140px; }
    .offer-doc .letter-title { font-size: 20pt; font-weight: bold; color: #111; }
    .offer-doc .logo-img { max-height: 40px; max-width: 95px; }
    .offer-doc .logo-ph  { font-size: 13pt; font-weight: bold; color: #333; text-align: right; }
    .offer-doc hr.thick { border: none; border-top: 2px solid #222; margin: 6px 0 12px 0; }
    .offer-doc p { font-size: 10pt; line-height: 1.35; margin-bottom: 4px; color: #222; }
    .offer-doc .odate { margin-bottom: 6px; }
    .offer-doc .salutation { margin-bottom: 6px; }
    .offer-doc table.terms { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    .offer-doc table.terms td { font-size: 9.5pt; padding: 2px 6px; vertical-align: top; line-height: 1.3; }
    .offer-doc table.terms td.num   { width: 22px; white-space: nowrap; }
    .offer-doc table.terms td.label { width: 130px; white-space: nowrap; }
    .offer-doc table.terms td.colon { width: 10px; }
    .offer-doc .sig-name { font-weight: bold; margin-top: 4px; }
    .offer-doc .acceptance { margin-top: 6px; }
    .offer-doc .acc-row { display: table; width: 100%; margin-top: 8px; }
    .offer-doc .acc-left  { display: table-cell; vertical-align: bottom; font-weight: bold; font-size: 11pt; }
    .offer-doc .acc-right { display: table-cell; vertical-align: bottom; text-align: right; }
    .offer-doc table.salary { width: 64%; margin: 0 auto 20px auto; border-collapse: collapse; font-size: 10.5pt; }
    .offer-doc table.salary th { background: #fff; text-align: center; font-weight: bold; padding: 7px 10px; border: 1px solid #555; }
    .offer-doc table.salary td { border: 1px solid #aaa; padding: 5px 8px; }
    .offer-doc table.salary td.sl  { width: 30px; text-align: center; color: #666; }
    .offer-doc table.salary td.amt { text-align: right; }
    .offer-doc table.salary tr.total td { font-weight: bold; }
    .offer-doc .notes { margin-top: 10px; font-size: 10pt; }
    .offer-doc ol.notes-list { margin-left: 20px; }
    .offer-doc ol.notes-list li { font-size: 10pt; margin-bottom: 5px; line-height: 1.4; }
    .offer-doc .ofooter { border-top: 1px solid #aaa; margin-top: 24px; padding-top: 6px; text-align: center; font-size: 9pt; color: #555; }
<?php if ($screen): ?>
    .offer-doc .offer-page { background: #fff; border: 1px solid #e2e8f0; box-shadow: 0 1px 8px rgba(0,0,0,.10); max-width: 820px; margin: 0 auto 22px; padding: 44px 52px; }
<?php endif; ?>
</style>
<div class="offer-doc">

    <!-- ===== PAGE 1 : OFFER LETTER ===== -->
    <div class="offer-page page-break">
        <table class="oh"><tr>
            <td class="oh-left"><?= $titleHtml ?></td>
            <td class="oh-right"><?= $logoCell ?></td>
        </tr></table>
        <hr class="thick">

        <div class="odate"><?= h(date('d-F-Y', strtotime($issuedDate))) ?></div>
        <div class="salutation">Dear <?= h(trim($salutation . ' ' . ($letter['emp_name'] ?? ''))) ?></div>

        <p>With reference to your application and the interviews you had with <strong><?= h($coName) ?></strong> , we are pleased to offer you employment in our company on the following terms and conditions.</p>

        <table class="terms">
            <tr><td class="num">1.</td><td class="label">Designation</td><td class="colon">:</td><td><?= h($letter['designation'] ?: 'N/A') ?></td></tr>
            <tr><td class="num">2.</td><td class="label">Department</td><td class="colon">:</td><td><?= h($letter['dept_name'] ?: 'N/A') ?></td></tr>
            <tr><td class="num">3.</td><td class="label">Date Of Joining</td><td class="colon">:</td><td><?= h($joinFmt) ?><?= $joinDay ? ' ( ' . h($joinDay) . ' )' : '' ?></td></tr>
            <tr><td class="num">4.</td><td class="label">Compensation</td><td class="colon">:</td><td>Rs <?= number_format($offerSalary, 0) ?> per month + retirals</td></tr>
            <tr><td class="num">5.</td><td class="label">Probation</td><td class="colon">:</td><td>First six months from the date of joining will be treated as probation period. During this period, no increments will apply</td></tr>
            <tr><td class="num">6.</td><td class="label">Confirmation</td><td class="colon">:</td><td>After completion of six months, we will evaluate your performance and decide whether to retain your services. Unless the employment is confirmed in writing at the end of the probation period, it should be considered terminated.</td></tr>
            <tr><td class="num">7.</td><td class="label">House Of work</td><td class="colon">:</td><td>9.00am to 6.15pm (with weekly off as per company policy)</td></tr>
            <tr><td class="num">8.</td><td class="label">Notice Of<br>termination</td><td class="colon">:</td><td>During the probation period, your service can be terminated by either side by giving two day's written notice. Upon confirmation, one month's written notice is required from either side. If you are already on an assignment and if your presence in the assignment is necessary as assessed by the management, the management reserves the right to require you to work till the assignment is complete.</td></tr>
            <tr><td class="num">9.</td><td class="label">Leave Policy</td><td class="colon">:</td><td>As per the rules of the company, you can avail 6 days casual &amp; 6 days sick leave per year.</td></tr>
        </table>

        <p>Please sign and return the copy of this letter in token of your acceptance, if the terms and conditions specified above and enclosed are acceptable to you.</p>
        <p>We welcome you to <?= h($coName) ?> and look forward to your contribution to the success and growth of the Company<br>For <?= h($coName) ?></p>

        <div style="margin-top:8px">
            <?php if ($sigImg): ?><img src="<?= h($sigImg) ?>" alt="Signature" style="height:50px;display:block;margin-bottom:3px"><?php endif; ?>
            <div class="sig-name"><?= h($signatory) ?></div>
            <?php if ($sigTitle !== ''): ?><div class="sig-name" style="font-weight:normal"><?= h($sigTitle) ?></div><?php endif; ?>
        </div>

        <div class="acceptance">
            <p>I agree to the above terms and conditions and will be joining on:</p>
            <div class="acc-row">
                <div class="acc-left">[ <?= h($letter['emp_name'] ?? '') ?>]</div>
                <div class="acc-right">confirmed Date Of Joining<br><?= h($joinFmt) ?></div>
            </div>
        </div>

        <?= $footerHtml ?>
    </div>

    <!-- ===== PAGE 2 : SALARY BREAKUP ===== -->
    <div class="offer-page">
        <table class="oh"><tr>
            <td class="oh-left"><?= $titleHtml ?></td>
            <td class="oh-right"><?= $logoCell ?></td>
        </tr></table>
        <hr class="thick"><br>

        <table class="salary">
            <thead><tr><th colspan="3">SALARY BREAKUP</th></tr></thead>
            <tbody>
                <?php $i = 1; foreach ($allowances as $name => $amt): ?>
                <tr><td class="sl"><?= $i++ ?></td><td><?= h($name) ?></td><td class="amt"><?= number_format($amt, 0) ?></td></tr>
                <?php endforeach; foreach ($deductions as $name => $amt): ?>
                <tr><td class="sl"><?= $i++ ?></td><td><?= h($name) ?></td><td class="amt"><?= number_format($amt, 0) ?></td></tr>
                <?php endforeach; ?>
                <tr class="total"><td class="sl"></td><td>Gross Pay</td><td class="amt"><?= number_format($grossPay, 0) ?></td></tr>
                <tr><td class="sl"><?= $i++ ?></td><td>Benefits</td><td class="amt"></td></tr>
                <tr><td class="sl"></td><td style="color:#666"><?php foreach ($deductions as $name => $v) echo h($name) . ' '; ?></td><td class="amt"></td></tr>
                <tr class="total"><td class="sl"><?= $i++ ?></td><td>Total Cost to Company</td><td class="amt"><?= number_format($offerSalary, 2) ?></td></tr>
            </tbody>
        </table>

        <div class="notes">
            <p><strong>Note :</strong></p>
            <ol class="notes-list">
                <li>All payments are subject to Tax deduction at source (TDS). You are responsible for declaring your tax exemptions &amp; tax liabilities</li>
                <li>Take home pay will be Gross Pay - Applicable Statutory deductions(PF, ESI, Professional Tax etc.)</li>
                <li>All reimbursements are at actuals and need to be supported with bills/vouchers whenever available</li>
            </ol>
        </div>

        <?= $footerHtml ?>
    </div>
</div>
    <?php
    return ob_get_clean();
}
