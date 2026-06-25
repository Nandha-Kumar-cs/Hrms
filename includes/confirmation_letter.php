<?php
/**
 * Confirmation letter rendering — shared by the PDF download
 * (modules/letters/download.php) and the on-screen view (modules/letters/view.php)
 * so both look identical (the reference design: title left, logo right, thick
 * rule, body, signatory, entity-address footer). CSS scoped under .conf-doc.
 */

/** Confirmation date (the w.e.f date) + gender salutation, from the letter. */
function confirmation_letter_data(array $letter): array
{
    $content = (string)($letter['content'] ?? '');

    // Confirmation date: prefer the "w.e.f dd-mm-yy" in the body, else the leading
    // "Month dd, yyyy" line, else the letter's issued date.
    $ts = false;
    if (preg_match('/w\.e\.f\.?\s+(\d{1,2})-(\d{1,2})-(\d{2,4})/i', $content, $m)) {
        $y = (int)$m[3]; if ($y < 100) $y += 2000;
        $ts = mktime(0, 0, 0, (int)$m[2], (int)$m[1], $y);
    }
    if (!$ts && preg_match('/with effect from\s+([^\.\n]+)/i', $content, $m)) $ts = strtotime(trim($m[1]));
    if (!$ts && preg_match('/^\s*([A-Z][a-z]+ \d{1,2}, \d{4})\s*$/m', $content, $m)) $ts = strtotime($m[1]);
    if (!$ts) $ts = strtotime((string)($letter['issued_date'] ?? 'now'));

    return [
        'fdy'        => $ts ? date('F d, Y', $ts) : '',
        'dmy'        => $ts ? date('d-m-y', $ts) : '',
        'salutation' => ($letter['gender'] ?? '') === 'Male' ? 'Mr. '
                      : (($letter['gender'] ?? '') === 'Female' ? 'Mrs. ' : ''),
    ];
}

/**
 * Build the confirmation-letter HTML fragment (a <style> block + <div class="conf-doc">…</div>).
 * $co   = ['name','addr','logo']   (logo = data-URI or URL)
 * $opts = ['screen' => bool, 'inline_footer' => bool]
 */
function confirmation_letter_html(array $letter, array $co, array $data, array $opts = []): string
{
    $coName = (string)($co['name'] ?? '');
    $coAddr = (string)($co['addr'] ?? '');
    $coLogo = $co['logo'] ?? null;
    $screen = !empty($opts['screen']);
    $inline = !empty($opts['inline_footer']);

    $fdy = (string)($data['fdy'] ?? '');
    $dmy = (string)($data['dmy'] ?? '');
    $sal = (string)($data['salutation'] ?? '');

    $footerLine = trim($coName . ($coAddr ? ' ' . $coAddr : ''));
    $logoCell = $coLogo
        ? '<img src="' . h($coLogo) . '" align="right" alt="Logo" style="height:48px">'
        : '<div style="font-size:13pt;font-weight:bold;color:#333;text-align:right">' . h($coName) . '</div>';
    $footerHtml = $inline ? '<div class="ofooter">' . h($footerLine) . '</div>' : '';

    ob_start();
    ?>
<style>
    .conf-doc * { margin: 0; padding: 0; box-sizing: border-box; }
    .conf-doc { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10.5pt; color: #222; }
    .conf-doc table.oh { width: 100%; margin-bottom: 4px; }
    .conf-doc table.oh td.oh-left  { vertical-align: middle; }
    .conf-doc table.oh td.oh-right { vertical-align: middle; text-align: right; width: 150px; }
    .conf-doc hr.thick { border: none; border-top: 2px solid #222; margin: 8px 0 18px 0; }
    .conf-doc .spacer { height: 55px; }
    .conf-doc p { font-size: 10.5pt; line-height: 1.6; margin-bottom: 12px; color: #222; }
    .conf-doc .sig-name { font-size: 10.5pt; margin-top: 4px; }
    .conf-doc .ofooter { border-top: 1px solid #aaa; margin-top: 24px; padding-top: 6px; text-align: center; font-size: 9pt; color: #555; }
<?php if ($screen): ?>
    .conf-doc { background: #fff; border: 1px solid #e2e8f0; box-shadow: 0 1px 8px rgba(0,0,0,.10); max-width: 820px; margin: 0 auto; padding: 44px 52px; }
<?php endif; ?>
</style>
<div class="conf-doc">
    <table class="oh"><tr>
        <td class="oh-left"><div style="font-size:26pt;font-weight:bold;color:#111;font-family:'DejaVu Sans',Arial,sans-serif">Confirmation Letter</div></td>
        <td class="oh-right"><?= $logoCell ?></td>
    </tr></table>
    <hr class="thick">

    <div class="spacer"></div>

    <p><?= h($fdy) ?></p>
    <p>Dear <strong><?= h(trim($sal . ($letter['emp_name'] ?? ''))) ?></strong>,</p>

    <p>Based on your performance, the management is pleased to inform you that you have been confirmed on the rolls of <strong><?= h($coName) ?></strong> w.e.f <strong><?= h($dmy) ?></strong>. The salary remains the same as given in the offer letter at the time of joining.</p>

    <p>For <?= h($coName) ?></p>

    <div style="margin-top:30px"><div class="sig-name"><strong>Authorized Signatory</strong></div></div>

    <?= $footerHtml ?>
</div>
    <?php
    return ob_get_clean();
}
