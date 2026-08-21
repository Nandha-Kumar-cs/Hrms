<?php
/**
 * Internship Certificate rendering — shared by the PDF download
 * (modules/letters/download.php) and the on-screen view (modules/letters/view.php)
 * so both look identical. Unlike the other letters this one is a framed
 * certificate (centred title inside a double border) rather than a business
 * letter. All CSS is scoped under .intern-doc so the fragment is safe to embed.
 */

/**
 * Parse the internship details (period, mentor, institution) out of the
 * letter content. The labels below are written verbatim by
 * modules/letters/create.php, so parsing is reliable.
 */
function internship_certificate_data(array $letter): array
{
    $content = (string)($letter['content'] ?? '');
    $pick    = function (string $label) use ($content): string {
        // [ \t] not \s: \s matches newlines, so a label with an empty value would
        // swallow the NEXT line (e.g. a blank "Signed By:" picking up the
        // "Authorized Signatory" line below it).
        return preg_match('/' . preg_quote($label, '/') . '[ \t]*:[ \t]*([^\r\n]+)/i', $content, $m)
            ? trim($m[1]) : '';
    };

    $startRaw = $pick('Start Date');
    $endRaw   = $pick('End Date');
    $startTs  = $startRaw ? strtotime($startRaw) : strtotime((string)($letter['join_date'] ?? ''));
    $endTs    = $endRaw   ? strtotime($endRaw)   : strtotime((string)($letter['issued_date'] ?? 'now'));

    // Duration in whole months (rounded up when a part-month remains), which is
    // how internships are normally described — e.g. "3 months".
    $duration = '';
    if ($startTs && $endTs && $endTs >= $startTs) {
        $d  = date_diff(date_create(date('Y-m-d', $startTs)), date_create(date('Y-m-d', $endTs)));
        $mo = (int)$d->y * 12 + (int)$d->m;
        if ((int)$d->d >= 15) $mo++;
        $duration = $mo >= 1
            ? $mo . ' month' . ($mo > 1 ? 's' : '')
            : max(1, (int)ceil(((int)$d->days ?: 1) / 7)) . ' week' . (ceil(((int)$d->days ?: 1) / 7) > 1 ? 's' : '');
    }

    $g = (string)($letter['gender'] ?? '');

    return [
        'start'      => $startTs ? date('d F Y', $startTs) : ($startRaw ?: ''),
        'end'        => $endTs   ? date('d F Y', $endTs)   : ($endRaw   ?: ''),
        'duration'   => $duration,
        'mentor'     => $pick('Mentor'),
        'college'    => $pick('Institution'),
        'conduct'    => $pick('Conduct') ?: 'satisfactory',
        'salutation' => $g === 'Male' ? 'Mr. ' : ($g === 'Female' ? 'Ms. ' : ''),
        'pronoun'    => $g === 'Male' ? 'his'  : ($g === 'Female' ? 'her'  : 'their'),
        'object'     => $g === 'Male' ? 'him'  : ($g === 'Female' ? 'her'  : 'them'),
        'subject'    => $g === 'Male' ? 'He'   : ($g === 'Female' ? 'She'  : 'They'),
        'verb_has'   => $g === 'Male' || $g === 'Female' ? 'has' : 'have',
        'signed_by'  => $pick('Signed By'),
    ];
}

/**
 * Build the internship-certificate HTML fragment
 * (a <style> block + <div class="intern-doc">…</div>).
 * $co   = ['name','addr','logo','signature','signatory_title']  (logo = data-URI or URL)
 * $opts = ['screen' => bool, 'inline_footer' => bool]
 */
function internship_certificate_html(array $letter, array $co, array $data, array $opts = []): string
{
    $coName  = (string)($co['name'] ?? '');
    $coAddr  = (string)($co['addr'] ?? '');
    $coLogo  = $co['logo'] ?? null;
    $coSign  = $co['signature'] ?? null;
    $coTitle = trim((string)($co['signatory_title'] ?? '')) ?: 'Authorized Signatory';
    $screen  = !empty($opts['screen']);
    $inline  = !empty($opts['inline_footer']);

    $empName  = trim((string)($data['salutation'] ?? '') . (string)($letter['emp_name'] ?? ''));
    $start    = (string)($data['start'] ?? '');
    $end      = (string)($data['end'] ?? '');
    $duration = (string)($data['duration'] ?? '');
    $mentor   = (string)($data['mentor'] ?? '');
    $college  = (string)($data['college'] ?? '');
    $conduct  = strtolower((string)($data['conduct'] ?? 'satisfactory'));
    $pron     = (string)($data['pronoun'] ?? 'their');
    $obj      = (string)($data['object'] ?? 'them');
    $possCap  = ucfirst($pron);
    $subj     = (string)($data['subject'] ?? 'They');
    $hasHave  = (string)($data['verb_has'] ?? 'has');
    $signBy   = (string)($data['signed_by'] ?? '');
    // A signatory name identical to the title would render the same line twice.
    if (strcasecmp(trim($signBy), trim($coTitle)) === 0) $signBy = '';

    $dateStr = function_exists('date_fmt')
        ? date_fmt($letter['issued_date'] ?? null)
        : date('d M Y', strtotime((string)($letter['issued_date'] ?? 'now')));

    $footerLine = trim($coName . ($coAddr ? ' ' . $coAddr : ''));
    $footerHtml = $inline ? '<div class="ofooter">' . h($footerLine) . '</div>' : '';

    ob_start();
    ?>
<style>
    .intern-doc * { margin: 0; padding: 0; box-sizing: border-box; }
    .intern-doc { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 10.5pt; color: #222; }
    .intern-doc .frame { border: 3px double #1e3a8a; padding: 16px 24px; }
    .intern-doc .cert-head { display: block; text-align: center; margin-bottom: 6px; padding: 0; border: none; }
    .intern-doc .cert-head img { height: 46px; display: block; margin: 0 auto 3px auto; }
    .intern-doc .cert-head .cert-co { font-size: 15pt; font-weight: bold; color: #1e3a8a; }
    .intern-doc h1 { text-align: center; font-size: 16pt; letter-spacing: 3px; text-transform: uppercase;
                     color: #1e3a8a; margin: 10px 0 4px 0; }
    .intern-doc .rule { width: 90px; border-top: 2px solid #1e3a8a; margin: 0 auto 12px auto; }
    .intern-doc p { font-size: 10.5pt; line-height: 1.6; margin-bottom: 9px; color: #222; text-align: justify; }
    .intern-doc p.ctr { text-align: center; }
    .intern-doc .nm { font-size: 15pt; font-weight: bold; color: #111; }
    .intern-doc .tbl { width: 100%; border-collapse: collapse; margin: 4px 0 10px 0; }
    .intern-doc .tbl td { padding: 3px 0; font-size: 10.5pt; vertical-align: top; }
    .intern-doc .tbl td.k { width: 175px; color: #555; }
    .intern-doc .tbl td.v { font-weight: bold; }
    .intern-doc .sigrow { width: 100%; margin-top: 18px; }
    .intern-doc .sigrow td { vertical-align: bottom; font-size: 9.5pt; }
    .intern-doc .sigrow td.rt { text-align: right; }
    .intern-doc .sig-img { height: 38px; display: block; margin: 0 0 2px auto; }
    .intern-doc .ofooter { border-top: 1px solid #aaa; margin-top: 18px; padding-top: 6px; text-align: center; font-size: 9pt; color: #555; }
<?php if ($screen): ?>
    .intern-doc { background: #fff; border: 1px solid #e2e8f0; box-shadow: 0 1px 8px rgba(0,0,0,.10); max-width: 820px; margin: 0 auto; padding: 34px 38px; }
<?php endif; ?>
</style>
<div class="intern-doc">
  <div class="frame">
    <?php // Logo + company name only — the address is not repeated in the
          // certificate header; it already appears in the page footer. ?>
    <div class="cert-head">
        <?php if ($coLogo): ?><img src="<?= h($coLogo) ?>" alt="<?= h($coName) ?>"><?php endif; ?>
        <div class="cert-co"><?= h($coName) ?></div>
    </div>

    <h1>Internship Certificate</h1>
    <div class="rule"></div>

    <p class="ctr">This is to certify that</p>
    <p class="ctr"><span class="nm"><?= h($empName) ?></span></p>

    <p><?= $college ? 'of <strong>' . h($college) . '</strong> ' : '' ?><?= h($hasHave) ?> successfully completed
       an internship with <strong><?= h($coName) ?></strong><?php if ($start): ?>
       from <strong><?= h($start) ?></strong><?php endif; ?><?php if ($end): ?>
       to <strong><?= h($end) ?></strong><?php endif; ?><?php if ($duration): ?>,
       a duration of <strong><?= h($duration) ?></strong><?php endif; ?>.</p>

    <table class="tbl">
        <?php if (!empty($letter['emp_code'])): ?><tr><td class="k">Intern ID</td><td class="v"><?= h($letter['emp_code']) ?></td></tr><?php endif; ?>
        <?php if ($start): ?><tr><td class="k">Start Date</td><td class="v"><?= h($start) ?></td></tr><?php endif; ?>
        <?php if ($end): ?><tr><td class="k">End Date</td><td class="v"><?= h($end) ?></td></tr><?php endif; ?>
        <?php if ($mentor): ?><tr><td class="k">Mentor / Guide</td><td class="v"><?= h($mentor) ?></td></tr><?php endif; ?>
    </table>

    <p>During the internship, <?= h(strtolower($subj)) ?> demonstrated a keen willingness to learn and carried out
       the assigned tasks sincerely. <?= h($possCap) ?> conduct and performance throughout the programme were found
       to be <strong><?= h($conduct) ?></strong>.</p>

    <p>We wish <?= h($obj) ?> continued success in all future endeavours.</p>

    <table class="sigrow"><tr>
        <td>
            <?php // Date only — the internal reference number is not printed on the
                  // certificate, which the intern hands to third parties. ?>
            <div>Date: <strong><?= h($dateStr) ?></strong></div>
        </td>
        <td class="rt">
            <?php if ($coSign): ?><img src="<?= h($coSign) ?>" class="sig-img" alt="Signature"><?php else: ?>
            <div style="height:36px"></div><?php endif; ?>
            <?php if ($signBy): ?><div><strong><?= h($signBy) ?></strong></div><?php endif; ?>
            <div><strong><?= h($coTitle) ?></strong></div>
            <div style="color:#666"><?= h($coName) ?></div>
        </td>
    </tr></table>
  </div>

  <?= $footerHtml ?>
</div>
    <?php
    return ob_get_clean();
}
