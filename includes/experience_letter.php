<?php
/**
 * Experience / Relieving letter rendering — shared by the PDF download
 * (modules/letters/download.php) and the on-screen view (modules/letters/view.php)
 * so both look identical (title left, logo right, thick rule, body, signatory,
 * entity-address footer — the same reference design as the confirmation letter).
 * All CSS is scoped under .exp-doc so the fragment is safe to embed in a page.
 */

/**
 * Parse the tenure details (joining date, last working day, conduct) out of the
 * letter content. The labels below are written verbatim by
 * modules/letters/create.php, so parsing is reliable; employee columns from the
 * JOIN are used as the fallback.
 */
function experience_letter_data(array $letter): array
{
    $content = (string)($letter['content'] ?? '');
    $pick    = function (string $label) use ($content): string {
        // [ \t] not \s: \s matches newlines, so a label with an empty value would
        // swallow the NEXT line (e.g. a blank "Signed By:" picking up the
        // "Authorized Signatory" line below it).
        return preg_match('/' . preg_quote($label, '/') . '[ \t]*:[ \t]*([^\r\n]+)/i', $content, $m)
            ? trim($m[1]) : '';
    };

    // Joining date: the letter body wins, else the employee's profile join_date.
    $joinRaw = $pick('Date of Joining');
    $joinTs  = $joinRaw ? strtotime($joinRaw) : strtotime((string)($letter['join_date'] ?? ''));
    $lwdRaw  = $pick('Last Working Day');
    $lwdTs   = $lwdRaw ? strtotime($lwdRaw) : strtotime((string)($letter['issued_date'] ?? 'now'));

    // Tenure in whole years + months, e.g. "2 years 4 months".
    $tenure = '';
    if ($joinTs && $lwdTs && $lwdTs >= $joinTs) {
        $d  = date_diff(date_create(date('Y-m-d', $joinTs)), date_create(date('Y-m-d', $lwdTs)));
        $yr = (int)$d->y; $mo = (int)$d->m;
        $parts = [];
        if ($yr) $parts[] = $yr . ' year'  . ($yr > 1 ? 's' : '');
        if ($mo) $parts[] = $mo . ' month' . ($mo > 1 ? 's' : '');
        $tenure = $parts ? implode(' ', $parts) : 'less than a month';
    }

    // Gender salutation, matching the offer / confirmation letters.
    $g = (string)($letter['gender'] ?? '');

    return [
        'join_date'   => $joinTs ? date('d F Y', $joinTs) : ($joinRaw ?: ''),
        'last_day'    => $lwdTs  ? date('d F Y', $lwdTs)  : ($lwdRaw  ?: ''),
        'tenure'      => $tenure,
        'designation' => $pick('Designation') ?: (string)($letter['designation'] ?? ''),
        'department'  => $pick('Department')  ?: (string)($letter['dept_name']   ?? ''),
        'conduct'     => $pick('Conduct') ?: 'satisfactory',
        'salutation'  => $g === 'Male' ? 'Mr. ' : ($g === 'Female' ? 'Ms. ' : ''),
        'pronoun'     => $g === 'Male' ? 'his'  : ($g === 'Female' ? 'her'  : 'their'),
        'object'      => $g === 'Male' ? 'him'  : ($g === 'Female' ? 'her'  : 'them'),
        'subject'     => $g === 'Male' ? 'He'   : ($g === 'Female' ? 'She'  : 'They'),
        'verb_was'    => $g === 'Male' || $g === 'Female' ? 'was' : 'were',
        'signed_by'   => $pick('Signed By'),
    ];
}

/**
 * Build the experience-letter HTML fragment (a <style> block + <div class="exp-doc">…</div>).
 * $co   = ['name','addr','logo','signature','signatory_title']  (logo = data-URI or URL)
 * $opts = ['screen' => bool, 'inline_footer' => bool]
 */
function experience_letter_html(array $letter, array $co, array $data, array $opts = []): string
{
    $coName = (string)($co['name'] ?? '');
    $coAddr = (string)($co['addr'] ?? '');
    $coLogo = $co['logo'] ?? null;
    $coSign = $co['signature'] ?? null;
    $coTitle = trim((string)($co['signatory_title'] ?? '')) ?: 'Authorized Signatory';
    $screen = !empty($opts['screen']);
    $inline = !empty($opts['inline_footer']);

    $empName = trim((string)($data['salutation'] ?? '') . (string)($letter['emp_name'] ?? ''));
    $join    = (string)($data['join_date'] ?? '');
    $lwd     = (string)($data['last_day'] ?? '');
    $tenure  = (string)($data['tenure'] ?? '');
    $desig   = (string)($data['designation'] ?? '');
    $dept    = (string)($data['department'] ?? '');
    $conduct = strtolower((string)($data['conduct'] ?? 'satisfactory'));
    $pron    = (string)($data['pronoun'] ?? 'their');
    $obj     = (string)($data['object'] ?? 'them');
    $possCap = ucfirst($pron);
    $subj    = (string)($data['subject'] ?? 'They');
    $wasWere = (string)($data['verb_was'] ?? 'was');
    $signBy  = (string)($data['signed_by'] ?? '');
    // A signatory name identical to the title would render the same line twice.
    if (strcasecmp(trim($signBy), trim($coTitle)) === 0) $signBy = '';

    $dateStr = function_exists('date_fmt')
        ? date_fmt($letter['issued_date'] ?? null)
        : date('d M Y', strtotime((string)($letter['issued_date'] ?? 'now')));

    $footerLine = trim($coName . ($coAddr ? ' ' . $coAddr : ''));
    $logoCell = $coLogo
        ? '<img src="' . h($coLogo) . '" align="right" alt="Logo" style="height:76px">'
        : '<div style="font-size:13pt;font-weight:bold;color:#333;text-align:right">' . h($coName) . '</div>';
    $footerHtml = $inline ? '<div class="ofooter">' . h($footerLine) . '</div>' : '';

    ob_start();
    ?>
<style>
    .exp-doc * { margin: 0; padding: 0; box-sizing: border-box; }
    .exp-doc { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10.5pt; color: #222; }
    .exp-doc table.oh { width: 100%; margin-bottom: 4px; }
    .exp-doc table.oh td.oh-left  { vertical-align: middle; }
    .exp-doc table.oh td.oh-right { vertical-align: middle; text-align: right; width: 210px; }
    .exp-doc hr.thick { border: none; border-top: 2px solid #222; margin: 8px 0 16px 0; }
    .exp-doc .meta { font-size: 9.5pt; color: #555; margin-bottom: 18px; }
    .exp-doc p { font-size: 10.5pt; line-height: 1.7; margin-bottom: 12px; color: #222; }
    .exp-doc .tbl { width: 100%; border-collapse: collapse; margin: 6px 0 16px 0; }
    .exp-doc .tbl td { padding: 5px 0; font-size: 10.5pt; vertical-align: top; }
    .exp-doc .tbl td.k { width: 190px; color: #555; }
    .exp-doc .tbl td.v { font-weight: bold; }
    .exp-doc .sig-name { font-size: 10.5pt; margin-top: 4px; }
    .exp-doc .sig-img { height: 46px; display: block; margin-bottom: 2px; }
    .exp-doc .ofooter { border-top: 1px solid #aaa; margin-top: 24px; padding-top: 6px; text-align: center; font-size: 9pt; color: #555; }
<?php if ($screen): ?>
    .exp-doc { background: #fff; border: 1px solid #e2e8f0; box-shadow: 0 1px 8px rgba(0,0,0,.10); max-width: 820px; margin: 0 auto; padding: 44px 52px; }
<?php endif; ?>
</style>
<div class="exp-doc">
    <table class="oh"><tr>
        <td class="oh-left"><div style="font-size:18pt;font-weight:bold;color:#111;font-family:'DejaVu Sans',Arial,sans-serif">Experience Letter</div></td>
        <td class="oh-right"><?= $logoCell ?></td>
    </tr></table>
    <hr class="thick">

    <?php // Date only — the internal reference number is deliberately not shown
          // on the experience letter, which the employee hands to third parties. ?>
    <div class="meta">
        Date: <?= h($dateStr) ?>
    </div>

    <p><strong>TO WHOMSOEVER IT MAY CONCERN</strong></p>

    <p>This is to certify that <strong><?= h($empName) ?></strong><?php if (!empty($letter['emp_code'])): ?>
        (Employee ID: <strong><?= h($letter['emp_code']) ?></strong>)<?php endif; ?>
        <?= h($wasWere) ?> employed with <strong><?= h($coName) ?></strong><?php if ($join): ?>
        from <strong><?= h($join) ?></strong><?php endif; ?><?php if ($lwd): ?>
        to <strong><?= h($lwd) ?></strong><?php endif; ?><?php if ($tenure): ?>,
        a total tenure of <strong><?= h($tenure) ?></strong><?php endif; ?>.</p>

    <table class="tbl">
        <?php if ($desig): ?><tr><td class="k">Designation</td><td class="v"><?= h($desig) ?></td></tr><?php endif; ?>
        <?php if ($dept): ?><tr><td class="k">Department</td><td class="v"><?= h($dept) ?></td></tr><?php endif; ?>
        <?php if ($join): ?><tr><td class="k">Date of Joining</td><td class="v"><?= h($join) ?></td></tr><?php endif; ?>
        <?php if ($lwd): ?><tr><td class="k">Last Working Day</td><td class="v"><?= h($lwd) ?></td></tr><?php endif; ?>
    </table>

    <p>During <?= h($pron) ?> tenure with us, <?= h(strtolower($subj)) ?> handled the responsibilities assigned
       to <?= h($pron) ?> role diligently. <?= h($possCap) ?> conduct and performance during this period were found
       to be <strong><?= h($conduct) ?></strong>.</p>

    <p><?= h($subj) ?> <?= h($wasWere) ?> relieved from the services of the company with effect from the close of
       business hours on <strong><?= h($lwd) ?></strong>, and <?= h(strtolower($subj)) ?> <?= h($wasWere) ?> not
       required to fulfil any further obligations towards the organisation.</p>

    <p>We thank <?= h($obj) ?> for the services rendered and wish <?= h($obj) ?> success in all future endeavours.</p>

    <p>For <strong><?= h($coName) ?></strong></p>

    <div style="margin-top:26px">
        <?php if ($coSign): ?><img src="<?= h($coSign) ?>" class="sig-img" alt="Signature"><?php else: ?>
        <div style="height:38px"></div><?php endif; ?>
        <?php if ($signBy): ?><div class="sig-name"><strong><?= h($signBy) ?></strong></div><?php endif; ?>
        <div class="sig-name"><strong><?= h($coTitle) ?></strong></div>
    </div>

    <?= $footerHtml ?>
</div>
    <?php
    return ob_get_clean();
}
