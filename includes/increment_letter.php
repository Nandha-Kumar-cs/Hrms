<?php
/**
 * Salary Increment letter rendering — shared by the PDF download
 * (modules/letters/download.php) and the on-screen view (modules/letters/view.php)
 * so both look identical (the reference info-box design). All CSS is scoped under
 * .inc-doc so the fragment is safe to embed inside another page.
 */

/** Parse the increment figures (prev/new CTC, %, effective date) from the letter. */
function increment_letter_data(array $letter): array
{
    $content = (string)($letter['content'] ?? '');
    $rawnum  = fn($v) => (float)preg_replace('/[^0-9.]/', '', (string)$v);
    $pick    = function (string $label) use ($content): string {
        return preg_match('/' . preg_quote($label, '/') . '\s*:?\s*(.+)/i', $content, $m) ? trim($m[1]) : '';
    };

    $prev = $rawnum($pick('Previous Monthly Gross'));
    $new  = $rawnum($pick('Revised Monthly Gross'));
    $pct  = $prev > 0 ? round(($new - $prev) / $prev * 100, 2) : 0;

    $effRaw  = preg_match('/with effect from\s+(.+?)[\.\n]/i', $content, $m) ? trim($m[1]) : '';
    $effTs   = $effRaw ? strtotime($effRaw) : strtotime((string)($letter['issued_date'] ?? 'now'));
    $effDate = $effTs ? date('d F Y', $effTs) : date('d M Y');

    return ['prev' => $prev, 'new' => $new, 'pct' => $pct, 'eff_date' => $effDate];
}

/**
 * Build the increment-letter HTML fragment (a <style> block + <div class="inc-doc">…</div>).
 * $co   = ['name','addr','email','phone','logo']   (logo = data-URI or URL)
 * $opts = ['screen' => bool]  — adds on-screen "paper sheet" styling.
 */
function increment_letter_html(array $letter, array $co, array $data, array $opts = []): string
{
    $coName  = (string)($co['name'] ?? '');
    $coAddr  = (string)($co['addr'] ?? '');
    $coEmail = (string)($co['email'] ?? '');
    $coPhone = (string)($co['phone'] ?? '');
    $coLogo  = $co['logo'] ?? null;
    $screen  = !empty($opts['screen']);

    $prev    = (float)($data['prev'] ?? 0);
    $new     = (float)($data['new'] ?? 0);
    $effDate = (string)($data['eff_date'] ?? '');
    $pctStr  = rtrim(rtrim(number_format((float)($data['pct'] ?? 0), 2), '0'), '.');
    $nf      = fn($v) => number_format((float)$v, 2);

    // Signatory — the name after "Yours sincerely," in the content, else HR Department.
    $signName = 'HR Department';
    if (preg_match('/Yours sincerely,\s*\n+\s*(.+)/i', (string)($letter['content'] ?? ''), $m)) {
        $cand = trim($m[1]);
        if ($cand !== '' && strcasecmp($cand, $coName) !== 0) $signName = $cand;
    }

    $dateStr    = function_exists('date_fmt')
        ? date_fmt($letter['issued_date'] ?? null)
        : date('d M Y', strtotime((string)($letter['issued_date'] ?? 'now')));
    $contact    = trim($coEmail . ($coEmail && $coPhone ? ' | ' : '') . $coPhone);
    $footerLine = trim($coName . ($coAddr ? ' &bull; ' . $coAddr : '')) . ' &bull; Confidential';

    ob_start();
    ?>
<style>
    .inc-doc * { margin: 0; padding: 0; box-sizing: border-box; }
    .inc-doc { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10pt; color: #222; }
    .inc-doc .header { display: table; width: 100%; border-bottom: 2px solid #3b82f6; padding-bottom: 10px; margin-bottom: 12px; }
    .inc-doc .header-left  { display: table-cell; vertical-align: middle; }
    .inc-doc .header-right { display: table-cell; vertical-align: middle; text-align: right; white-space: nowrap; padding-left: 10px; }
    .inc-doc .company-name { font-size: 16pt; font-weight: bold; color: #3b82f6; }
    .inc-doc .company-sub  { font-size: 8pt; color: #666; margin-top: 2px; }
    .inc-doc .ref          { font-size: 9pt; color: #666; }
    .inc-doc h1 { text-align: center; font-size: 13pt; text-transform: uppercase; letter-spacing: 2px;
         color: #1e293b; margin: 10px 0; border-top: 1px solid #ddd; border-bottom: 1px solid #ddd; padding: 5px 0; }
    .inc-doc p { font-size: 9.5pt; line-height: 1.55; color: #444; margin-bottom: 8px; }
    .inc-doc .info-box { background: #f8f9fc; border: 1px solid #e2e8f0; border-radius: 4px; padding: 10px 14px; margin: 12px 0; }
    .inc-doc .info-row { display: table; width: 100%; margin-bottom: 5px; }
    .inc-doc .info-label { display: table-cell; width: 170px; color: #64748b; font-size: 8.5pt; font-weight: bold; vertical-align: middle; }
    .inc-doc .info-value { display: table-cell; font-weight: bold; font-size: 9.5pt; vertical-align: middle; }
    .inc-doc .old-sal { color: #999; text-decoration: line-through; }
    .inc-doc .new-sal { color: #16a34a; font-size: 11pt; }
    .inc-doc .pct-badge { background: #16a34a; color: #fff; padding: 1px 8px; border-radius: 3px; font-size: 9pt; }
    .inc-doc .signature-row { display: table; width: 100%; margin-top: 28px; }
    .inc-doc .sig-left  { display: table-cell; width: 50%; }
    .inc-doc .sig-right { display: table-cell; width: 50%; text-align: right; }
    .inc-doc .sig-box   { display: inline-block; width: 200px; border-top: 1px solid #333; padding-top: 5px; font-size: 8.5pt; }
    .inc-doc .sig-sub   { color: #888; font-size: 8pt; }
    .inc-doc .footer { margin-top: 18px; border-top: 1px solid #e2e8f0; padding-top: 7px; font-size: 7.5pt; color: #999; text-align: center; }
<?php if ($screen): ?>
    .inc-doc { background: #fff; border: 1px solid #e2e8f0; box-shadow: 0 1px 8px rgba(0,0,0,.10); max-width: 820px; margin: 0 auto; padding: 40px 48px; }
<?php endif; ?>
</style>
<div class="inc-doc">

    <div class="header">
        <div class="header-left">
            <?php if ($coLogo): ?>
                <img src="<?= h($coLogo) ?>" alt="Logo" style="height:44px;display:block;margin-bottom:4px">
                <div class="company-sub"><?= h($coAddr) ?></div>
            <?php else: ?>
                <div class="company-name"><?= h($coName) ?></div>
                <div class="company-sub"><?= h($coAddr) ?></div>
            <?php endif; ?>
            <?php if ($contact): ?><div class="company-sub"><?= h($contact) ?></div><?php endif; ?>
        </div>
        <div class="header-right">
            <div class="ref">Date: <?= h($dateStr) ?></div>
            <div class="ref">Ref: <?= h($letter['reference'] ?? '') ?></div>
        </div>
    </div>

    <h1>Salary Increment Letter</h1>

    <p>Dear <strong><?= h($letter['emp_name'] ?? '') ?></strong>,</p>
    <p>We are pleased to inform you that the management has decided to revise your salary, effective <strong><?= h($effDate) ?></strong>. This reflects your outstanding contribution to the organization.</p>

    <div class="info-box">
        <div class="info-row"><div class="info-label">Employee Code:</div><div class="info-value"><?= h($letter['emp_code'] ?? '') ?></div></div>
        <div class="info-row"><div class="info-label">Name:</div><div class="info-value"><?= h($letter['emp_name'] ?? '') ?></div></div>
        <div class="info-row"><div class="info-label">Designation:</div><div class="info-value"><?= h($letter['designation'] ?: 'N/A') ?></div></div>
        <div class="info-row"><div class="info-label">Department:</div><div class="info-value"><?= h($letter['dept_name'] ?: 'N/A') ?></div></div>
        <div class="info-row"><div class="info-label">Previous CTC:</div><div class="info-value"><span class="old-sal">&#8377; <?= h($nf($prev)) ?></span></div></div>
        <div class="info-row"><div class="info-label">Revised CTC:</div><div class="info-value"><span class="new-sal">&#8377; <?= h($nf($new)) ?></span></div></div>
        <div class="info-row"><div class="info-label">Increment:</div><div class="info-value"><span class="pct-badge"><?= h($pctStr) ?>%</span></div></div>
        <div class="info-row"><div class="info-label">Effective From:</div><div class="info-value"><?= h($effDate) ?></div></div>
    </div>

    <p>We appreciate your dedication and expect continued excellence in your work. Congratulations on this well-deserved recognition.</p>

    <div class="signature-row">
        <div class="sig-left">
            <div class="sig-box">
                <div><?= h($letter['emp_name'] ?? '') ?></div>
                <div class="sig-sub">Employee Acknowledgment</div>
            </div>
        </div>
        <div class="sig-right">
            <div class="sig-box" style="text-align:left">
                <div><?= h($signName) ?></div>
                <div class="sig-sub">Authorized Signatory</div>
            </div>
        </div>
    </div>

    <div class="footer"><?= $footerLine ?></div>
</div>
    <?php
    return ob_get_clean();
}
