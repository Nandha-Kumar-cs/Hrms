<?php
/**
 * Employee ID Card generator — preview / print / download.
 * ─────────────────────────────────────────────────────────────────────────────
 * Renders a CR80 portrait ID-card sticker (54 × 85.6 mm, the ISO/IEC 7810 ID-1
 * standard) carrying the employee's photo, name, department, designation and a
 * unique QR code.
 *
 * The QR encodes ONLY the secure-access URL (a random token) — never salary,
 * attendance or any personal data. See includes/id_card.php for the model.
 *
 * POST handling and all redirects run BEFORE header.php so redirect() can never
 * hit "headers already sent".
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();
require_permission('idcard', 'view');
require_once __DIR__ . '/../../includes/id_card.php';

/* Card accent colours, sampled from the supplied template artwork. The soft
   tone is the pale band that sits under the crimson in every wave. */
const ID_CARD_ACCENT      = '#CE1230';
const ID_CARD_ACCENT_SOFT = '#F4A9B6';

/* ── Wave geometry ────────────────────────────────────────────────────────────
 * Traced directly from the template PNGs rather than hand-drawn: each image was
 * scanned column by column for the white→pink and pink→crimson boundaries, and
 * those sample points converted to Catmull-Rom cubics, so the curve passes
 * through the measured points exactly. Coordinates are in the card's own
 * 540 × 856 viewBox (1 unit = 0.1 mm).
 *
 * The SOFT path is drawn first and the crimson over it; where the crimson edge
 * rises above the pale one — the right-hand side of the bottom wave — the pale
 * band is covered, which is exactly how the template behaves. */
const ID_CARD_WAVE_BOTTOM_SOFT =
    'M0,766 C8,772 30,792 45,801 C60,810 75,815 90,819 C105,823 120,824 135,824 '
  . 'C150,824 164,822 179,819 C194,816 209,810 224,804 C239,798 255,789 270,782 '
  . 'C285,775 300,768 315,762 C330,756 345,750 360,746 C375,742 390,738 405,736 '
  . 'C420,734 434,733 449,732 C464,731 479,730 494,730 C509,730 532,732 539,732 '
  . 'L540,856 L0,856 Z';

const ID_CARD_WAVE_BOTTOM =
    'M0,768 C8,774 30,796 45,806 C60,816 75,823 90,829 C105,835 120,838 135,840 '
  . 'C150,842 164,841 179,839 C194,837 209,833 224,827 C239,821 255,810 270,802 '
  . 'C285,794 300,785 315,778 C330,771 345,764 360,758 C375,752 390,748 405,744 '
  . 'C420,740 434,737 449,735 C464,733 479,732 494,731 C509,730 532,728 539,727 '
  . 'L540,856 L0,856 Z';

const ID_CARD_WAVE_TOP_SOFT =
    'M0,0 L540,0 L539,91 C532,85 509,66 494,57 C479,48 464,43 449,39 '
  . 'C434,35 420,33 405,33 C390,33 375,34 360,38 C345,42 330,48 315,54 '
  . 'C300,60 285,69 270,76 C255,83 239,90 224,96 C209,102 194,108 179,112 '
  . 'C164,116 150,120 135,122 C120,124 105,124 90,125 C75,126 60,128 45,128 '
  . 'C30,128 8,127 0,127 Z';

const ID_CARD_WAVE_TOP =
    'M0,0 L540,0 L539,77 C532,73 509,61 494,53 C479,45 464,35 449,29 '
  . 'C434,23 420,20 405,18 C390,16 375,17 360,19 C345,21 330,26 315,32 '
  . 'C300,38 285,47 270,55 C255,63 239,72 224,80 C209,88 194,94 179,100 '
  . 'C164,106 150,109 135,113 C120,117 105,121 90,123 C75,125 60,127 45,127 '
  . 'C30,127 8,125 0,125 Z';

$db    = db();
$empId = (int) ($_GET['id'] ?? 0);

// Self-scoped users (role flag) may only ever reach their OWN card.
if (is_self_scoped()) $empId = current_employee_id();
require_own_employee($empId);

if ($empId <= 0) {
    flash('error', 'No employee selected.');
    redirect(BASE_URL . '/modules/employee/index.php');
}

// The module needs its migration — fail with a clear instruction, not a stack trace.
try {
    $db->query('SELECT 1 FROM employee_qr_tokens LIMIT 1');
} catch (Throwable $e) {
    $page_title = 'Employee ID Card';
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-error" style="margin-top:18px">'
       . '<strong>ID Card module not installed.</strong><br>Run '
       . '<code>install/add_employee_id_card.sql</code> against the HRMS database, then reload this page.'
       . '</div>';
    include __DIR__ . '/../../includes/footer.php';
    exit;
}

$stmt = $db->prepare(
    'SELECT e.*, d.name AS dept_name, des.name AS desig_name,
            ent.name AS entity_name, ent.name_font AS entity_name_font, ent.logo AS entity_logo,
            ent.address AS entity_address, ent.city AS entity_city,
            ent.state AS entity_state, ent.pincode AS entity_pincode
       FROM employees e
       LEFT JOIN departments d    ON d.id = e.department_id
       LEFT JOIN designations des ON des.id = e.designation_id
       LEFT JOIN entities ent     ON ent.id = e.entity_id
      WHERE e.id = ? LIMIT 1'
);
$stmt->execute([$empId]);
$emp = $stmt->fetch();

if (!$emp) {
    flash('error', 'Employee not found.');
    redirect(BASE_URL . '/modules/employee/index.php');
}

/* ── Reset just the portal password (the printed card keeps working) ──────── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'reset_password') {
    verify_csrf($_POST['csrf_token'] ?? '');
    if (!can('idcard', 'generate')) {
        flash('error', 'You do not have permission to reset the portal password.');
    } else {
        $tk = $db->prepare('SELECT id FROM employee_qr_tokens WHERE employee_id = ? LIMIT 1');
        $tk->execute([$empId]);
        $tkId = (int) $tk->fetchColumn();
        if (!$tkId) {
            flash('error', 'No ID card has been issued for this employee yet.');
        } else {
            id_card_set_password($tkId, $empId, 'reset');
            activity_log('updated', 'Employee ID Card',
                'Reset the secure-access portal password for ' . $emp['name'] . ' (' . $emp['employee_id'] . ')');
            flash('success', 'A new portal password was generated. It is shown below once — copy it now.');
        }
    }
    redirect(BASE_URL . '/modules/employee/id_card.php?id=' . $empId);
}

/* ── Regenerate the token (invalidates every previously printed sticker) ───── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'regenerate') {
    verify_csrf($_POST['csrf_token'] ?? '');
    if (!can('idcard', 'revoke')) {
        flash('error', 'You do not have permission to regenerate QR tokens.');
    } else {
        id_card_token_for($empId, true);
        activity_log('updated', 'Employee ID Card',
            'Regenerated QR token for ' . $emp['name'] . ' (' . $emp['employee_id'] . ') — previously printed cards no longer work');
        flash('success', 'A new QR token was issued. Previously printed ID cards for this employee will no longer work.');
    }
    redirect(BASE_URL . '/modules/employee/id_card.php?id=' . $empId);
}

/* ── Token, QR, photo ─────────────────────────────────────────────────────── */
// Issuing the FIRST token for an employee is the "generate" permission; merely
// viewing/reprinting an already-issued card only needs "view".
$existing = $db->prepare('SELECT id FROM employee_qr_tokens WHERE employee_id = ? LIMIT 1');
$existing->execute([$empId]);
$hasToken = (bool) $existing->fetchColumn();

if (!$hasToken && !can('idcard', 'generate')) {
    $page_title = 'Employee ID Card';
    require_once __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-warn" style="margin-top:18px">No ID card has been issued for '
       . h($emp['name']) . ' yet, and you do not have permission to generate one. '
       . 'Please ask HR to issue the card.</div>';
    include __DIR__ . '/../../includes/footer.php';
    exit;
}

$token = id_card_token_for($empId);
if (!$token) {
    flash('error', 'Could not issue a QR token for this employee.');
    redirect(BASE_URL . '/modules/employee/index.php');
}

if (!$hasToken) {
    activity_log('created', 'Employee ID Card',
        'Issued ID card / QR token for ' . $emp['name'] . ' (' . $emp['employee_id'] . ')');
}

$portalUrl = id_card_portal_url($token['token']);
// margin 2 (not the spec's 4): the .idc-qr-plate padding plus the white card
// panel already give more than 4 modules of quiet zone, so trimming it here buys
// ~12% larger printed modules — the difference between an easy and a fiddly scan.
$qrUri     = id_card_qr_data_uri($token['token'], 6, 2);
$photoUri  = id_card_photo_data_uri($emp['photo'] ?? null);
// One-time reveal: the plaintext exists only at the moment it is issued or
// reset. It is never derivable and never recoverable from the stored hash, so
// this is the only chance to hand it to the employee (security audit M-4).
$revealed = id_card_take_revealed_password($empId);

/* ── Company identity printed on the card ─────────────────────────────────────
 * Name, logo and address all come from the employee's OWN entity, so a
 * multi-entity company issues each employee a card carrying the entity they
 * actually belong to. An employee with no entity gets neutral placeholders,
 * never another entity's details — see $hasEntity below. */
$entityName     = trim((string) ($emp['entity_name'] ?? ''));
$entityFont     = (string) ($emp['entity_name_font'] ?? '');
$entityLogoFile = (string) ($emp['entity_logo'] ?? '');

// Company address printed on the reverse — the employee's own entity only.
$entityAddrParts = [
    $emp['entity_address'] ?? '', $emp['entity_city'] ?? '',
    trim(implode(' ', array_filter([$emp['entity_state'] ?? '', $emp['entity_pincode'] ?? '']))),
];

/* No entity assigned → print neutral placeholders. The card must never borrow
 * another entity's name, logo or address: a printed card that names the wrong
 * company is worse than one that visibly needs filling in. */
$companyAddr = trim(implode(', ', array_filter(array_map('trim', $entityAddrParts))));

/* Employee address, formatted as the template does it — "street, city – pincode".
 * State is deliberately left off: the reverse allows the address two lines, and
 * on a 54mm card the state pushes the pincode off the end. Blanks are skipped,
 * so a partially-filled address still reads correctly. */
$empAddr = trim(implode(', ', array_filter(array_map('trim', [
    (string) ($emp['address'] ?? ''),
    (string) ($emp['city'] ?? ''),
]))));
if (!empty($emp['pincode'])) {
    $empAddr = ($empAddr === '') ? (string) $emp['pincode'] : $empAddr . ' – ' . $emp['pincode'];
}

/* Fields the reverse prints that this employee has no data for. Surfaced on the
 * page (not the card) so HR can fill them before printing a batch — the card
 * itself just shows a dash. */
$missingBack = [];
if (empty($emp['blood_group'])) $missingBack[] = 'Blood group';
if (empty($emp['dob']))         $missingBack[] = 'Date of birth';
if (empty($emp['phone']))       $missingBack[] = 'Phone';
if ($empAddr === '')            $missingBack[] = 'Address';

$hasEntity  = ($entityName !== '');
$brandName  = $hasEntity ? $entityName : 'Your company name';
$brandStyle = $hasEntity ? entity_name_style($entityFont) : '';
if ($companyAddr === '' ) $companyAddr = $hasEntity ? COMPANY_ADDRESS : 'Your company address';

/* Logo above the company name — the entity's own upload, nothing else.
 *   entity with a logo  → that logo
 *   entity without one  → a monogram of ITS OWN name ($logoIsMark)
 *   no entity at all    → a "Your company logo" placeholder ($hasEntity false)
 * No branch ever reaches another entity's mark. */
$logoUri    = $hasEntity ? id_card_logo_data_uri($entityLogoFile ?: null) : null;
$logoIsMark = ($hasEntity && $logoUri === null);
if ($logoIsMark) $logoUri = id_card_monogram_data_uri($brandName, ID_CARD_ACCENT);

// Optional tagline under the company name (Settings → Branding). Hidden if unset.
$tagline = trim((string) setting_get('brand_tagline', ''));

$page_title = 'ID Card — ' . $emp['name'];
require_once __DIR__ . '/../../includes/header.php';
?>

<style>
/* ── Screen chrome (never printed) ───────────────────────────────────────── */
.idc-wrap { display:flex; gap:24px; flex-wrap:wrap; align-items:flex-start; margin-top:18px; }
.idc-stage {
    background:#e9ecef;
    border:1px solid var(--border);
    border-radius:var(--radius);
    padding:22px;
    display:flex; flex-direction:column; align-items:center; gap:14px;
}
.idc-side { flex:1; min-width:300px; }
.idc-faces { display:flex; gap:20px; flex-wrap:wrap; justify-content:center; }
.idc-face-label {
    text-align:center; font-size:11px; font-weight:700; text-transform:uppercase;
    letter-spacing:.08em; color:var(--text-muted); margin-bottom:8px;
}
.idc-meta-row { display:flex; justify-content:space-between; gap:12px; padding:7px 0; border-bottom:1px dashed var(--border); font-size:13px; }
.idc-meta-row:last-child { border-bottom:0; }
.idc-meta-row span:first-child { color:var(--text-muted); }
.idc-url {
    font-family:'Courier New',monospace; font-size:11px; word-break:break-all;
    background:var(--bg-secondary); border:1px solid var(--border);
    border-radius:var(--radius); padding:8px 10px;
}

/* ── The card itself — ISO/IEC 7810 ID-1 (CR80) portrait, 54 × 85.6 mm ──────
   The crimson sweeps are inline SVG, not CSS backgrounds: SVG fills are page
   CONTENT, so they survive printing even when a browser drops background
   graphics, and html2canvas rasterises them cleanly for the PNG export. */
.id-card {
    width:54mm; height:85.6mm;
    background:#fff;
    border-radius:3mm;
    overflow:hidden;
    position:relative;
    font-family:Arial, Helvetica, sans-serif;
    color:#1f2430;
    box-shadow:0 6px 22px rgba(15,23,42,.28);
}
/* One SVG covering the whole card. Its viewBox is 540 × 856 — i.e. 1 unit =
   0.1 mm — so every curve below is expressed in real card millimetres, and the
   text blocks can be positioned to clear the crimson exactly. */
.idc-svg { position:absolute; top:0; left:0; width:100%; height:100%; display:block; }

/* Everything sits above the artwork. Content is height-capped and clamped, so
   no employee's data can push the QR (front) or overflow the waves (back).
   Worst case the text clips; the scannable area is untouchable. */
.idc-layer { position:absolute; inset:0; }

/* ── Shared: centred logo + company name lockup ─────────────────────────── */
.idc-lockup { text-align:center; }
.idc-logo { height:8mm; max-width:30mm; object-fit:contain; display:block; margin:0 auto; }
.idc-brand {
    font-size:8pt; font-weight:700; line-height:1.15; color:#1f2430;
    margin-top:1.6mm; padding:0 3mm; word-break:break-word;
    display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
}
.idc-tagline {
    font-size:5pt; letter-spacing:.2em; text-transform:uppercase;
    color:#8b8f9a; margin-top:.8mm; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
/* Shown only when the employee has no entity assigned. Plain text, no frame.
   It reserves exactly the same height as a real logo so the layout does not
   shift once an entity is selected, but the text is bottom-aligned within that
   box — sitting where a real logo's lower edge would be, so the gap down to the
   company name matches the real thing instead of leaving the box's empty half
   showing. */
.idc-logo-ph {
    height:8mm; margin:0 auto;
    display:flex; align-items:flex-end; justify-content:center;
    font-size:7pt; line-height:1.1; color:#8b8f9a;
}
.idc-brand-ph { color:#8b8f9a; font-weight:600; }

/* ══ FRONT ══════════════════════════════════════════════════════════════
   Every block is absolutely placed, so none can push another. Vertical plan:
     4mm    logo (8mm)   ~13.5mm company name
     18.5mm photo (21mm) → 39.5mm (leaves room for a 2-line name below)
     name+designation anchored by their BOTTOM at 56.1mm, growing upward
     57mm QR (19mm)    → 76mm
     77.5mm wave                                                            */
.idc-front-top { position:absolute; top:4mm; left:0; right:0; }

/* Rounded square, not a circle — matches the template. */
.idc-photo-wrap { position:absolute; top:18.5mm; left:0; right:0; display:flex; justify-content:center; }
.idc-photo, .idc-photo-fallback {
    width:21mm; height:21mm; border-radius:3.2mm;
    border:0.7mm solid #9aa0aa; background:#e5e7eb;
}
.idc-photo { object-fit:cover; display:block; }
.idc-photo-fallback {
    display:flex; align-items:center; justify-content:center;
    color:#C8102E; font-size:18pt; font-weight:700; letter-spacing:.03em;
}

/* Bottom-anchored: a 2-line name grows up toward the photo, never down into
   the QR. */
.idc-ident { position:absolute; bottom:32.6mm; left:0; right:0; text-align:center; padding:0 3mm; }
.idc-name {
    font-size:11pt; font-weight:700; line-height:1.1; letter-spacing:.01em;
    text-transform:uppercase; word-break:break-word;
    display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
}
.idc-desig {
    font-size:7pt; letter-spacing:.14em; text-transform:uppercase; color:#3f4653;
    margin-top:1.4mm; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}

/* Pinned above the wave; nothing above it can shift it. bottom:12.6mm puts the
   QR's lower edge at 73mm — the traced wave's highest point across the QR's own
   x-range is 74.6mm, so the code keeps a clear white margin on all four sides. */
.idc-qr-plate {
    position:absolute; left:0; right:0; bottom:12.6mm;
    display:flex; align-items:center; justify-content:center;
}
.idc-qr-plate img { width:19mm; height:19mm; display:block; image-rendering:pixelated; }

/* ══ BACK ═══════════════════════════════════════════════════════════════ */
.idc-back-top { position:absolute; top:14mm; left:0; right:0; }
/* The company name is rendered IDENTICALLY on both faces — same size, same
   case, same entity font. The back previously forced uppercase at a smaller
   size to mirror the sample artwork, which made the two sides look like
   different typefaces on the same card. */

/* Detail rows: fixed label column so every colon lines up, as in the template. */
.idc-rows { position:absolute; top:34mm; left:0; right:0; padding:0 5mm; }
.idc-row { display:flex; align-items:baseline; font-size:7pt; line-height:1.45; margin-bottom:.7mm; }
/* The colon is rendered by the LABEL, pinned to the right edge of a fixed-width
   dt, so every row's colon sits at the same x. It must not live in the dd: on a
   wrapping value (the address) the value's first line is what moves, which left
   the colon visually detached from its row. */
.idc-row dt {
    flex:none; width:13mm; color:#1f2430;
    display:flex; justify-content:space-between;
}
.idc-row dt::after { content:':'; }
.idc-row dd { margin:0 0 0 1.8mm; flex:1; min-width:0; color:#1f2430; }
/* Address is the one row allowed to wrap; capped at 2 lines. Wrapped lines hang
   under the value column, never under the label. */
.idc-row-addr dd {
    display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
}

.idc-coaddr {
    position:absolute; left:0; right:0; bottom:14mm; padding:0 5mm;
    font-size:7pt; line-height:1.45; color:#1f2430;
}
.idc-coaddr-label { margin-bottom:.6mm; }
/* Two lines, as in the template. Clamped so a long company address grows no
   further upward into the detail rows above it. */
.idc-coaddr-value {
    display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden;
}

/* ── Print: front and back, one card per page, exact physical size ───────── */
@media print {
    @page { size:54mm 85.6mm; margin:0; }
    body * { visibility:hidden !important; }
    #idCardPrintArea, #idCardPrintArea * { visibility:visible !important; }
    #idCardPrintArea {
        position:absolute; top:0; left:0; margin:0; padding:0;
        display:block; background:#fff;
    }
    #idCardPrintArea .idc-face-label { display:none !important; }
    #idCardPrintArea .idc-faces { display:block !important; gap:0 !important; }
    .id-card {
        box-shadow:none !important; border-radius:0 !important;
        width:54mm !important; height:85.6mm !important;
        -webkit-print-color-adjust:exact; print-color-adjust:exact;
    }
    /* Back starts a new sheet so a duplex printer puts it on the reverse. */
    #idCardBack { break-before:page; page-break-before:always; }
}
</style>

<div class="page-head">
    <div>
        <h1>Employee ID Card</h1>
        <p class="muted"><?= h($emp['name']) ?> · <?= h($emp['employee_id']) ?></p>
    </div>
    <div>
        <a href="<?= BASE_URL ?>/modules/employee/index.php" class="btn btn-secondary btn-sm">
            <i class="fa fa-arrow-left me-1"></i>Back to Employees
        </a>
    </div>
</div>

<?= render_flash() ?>

<?php if (!$hasEntity): ?>
<div class="alert alert-warn" style="margin-top:14px">
    <strong><?= h($emp['name']) ?> is not assigned to an entity.</strong>
    The card prints placeholder company details rather than another entity's name,
    logo or address.
    <a href="<?= BASE_URL ?>/modules/employee/edit.php?id=<?= $empId ?>">Assign an entity</a>
    and the real details appear automatically.
</div>
<?php endif; ?>

<?php if ($logoIsMark): ?>
<div class="alert alert-info" style="margin-top:14px">
    <strong><?= h($brandName) ?></strong> has no logo uploaded, so the card prints a
    monogram of its name. The card only ever uses that entity's own logo — it will
    not borrow another entity's.
    <a href="<?= BASE_URL ?>/modules/settings/index.php?tab=entities">Upload a logo for this entity</a>
    and it will appear here automatically.
</div>
<?php endif; ?>

<?php if ($missingBack): ?>
<div class="alert alert-info" style="margin-top:14px">
    The back of the card prints a dash for
    <strong><?= h(implode(', ', $missingBack)) ?></strong> —
    not on record for <?= h($emp['name']) ?>.
    <a href="<?= BASE_URL ?>/modules/employee/edit.php?id=<?= $empId ?>">Fill these in</a>
    before printing if you want them shown.
</div>
<?php endif; ?>

<?php if ($revealed !== null): ?>
<div class="alert alert-success no-print" style="margin-top:14px">
    <strong>
        <?php if ($revealed['reason'] === 'legacy'): ?>
            This card's portal password has been replaced.
        <?php elseif ($revealed['reason'] === 'new'): ?>
            Portal password issued for <?= h($emp['name']) ?>.
        <?php else: ?>
            New portal password generated.
        <?php endif; ?>
    </strong>
    <div style="margin:10px 0">
        <code style="font-size:22px;font-weight:700;letter-spacing:2px;padding:8px 14px;
                     background:#fff;border:2px solid var(--success);border-radius:var(--radius)"><?= h($revealed['password']) ?></code>
    </div>
    <div style="font-size:13px">
        <?php if ($revealed['reason'] === 'legacy'): ?>
            The previous password was derived from this employee's name and date of birth — both of
            which are on the printed card — so anyone holding the card could work it out.
            It has been replaced with a random one.
        <?php endif; ?>
        <strong>Copy it now and give it to the employee.</strong>
        It is stored only as a one-way hash and cannot be shown again — if it is lost,
        use <em>Reset portal password</em> to issue another.
    </div>
</div>
<?php endif; ?>

<div class="idc-wrap">

    <!-- ── Card preview / print area ──────────────────────────────────────── -->
    <div class="idc-stage">
        <div id="idCardPrintArea">
          <div class="idc-faces">

            <!-- ══ FRONT ══════════════════════════════════════════════════ -->
            <div>
                <div class="idc-face-label">Front</div>
                <div class="id-card" id="idCard">

                    <!-- Wave along the bottom edge only. Geometry sampled from
                         the supplied template artwork (see ID_CARD_WAVE_*). -->
                    <svg class="idc-svg" viewBox="0 0 540 856" preserveAspectRatio="none">
                        <path d="<?= ID_CARD_WAVE_BOTTOM_SOFT ?>" fill="<?= ID_CARD_ACCENT_SOFT ?>"/>
                        <path d="<?= ID_CARD_WAVE_BOTTOM ?>"      fill="<?= ID_CARD_ACCENT ?>"/>
                    </svg>

                    <div class="idc-layer">
                        <div class="idc-lockup idc-front-top">
                            <?php if ($hasEntity): ?>
                                <img class="idc-logo" src="<?= $logoUri ?>" alt="">
                            <?php else: ?>
                                <div class="idc-logo-ph">Your company logo</div>
                            <?php endif; ?>
                            <div class="idc-brand<?= $hasEntity ? '' : ' idc-brand-ph' ?>"
                                 style="<?= h($brandStyle) ?>"><?= h($brandName) ?></div>
                            <?php if ($tagline !== ''): ?>
                                <div class="idc-tagline"><?= h($tagline) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="idc-photo-wrap">
                            <?php if ($photoUri): ?>
                                <img class="idc-photo" src="<?= $photoUri ?>" alt="">
                            <?php else: ?>
                                <div class="idc-photo-fallback"><?= h(id_card_initials($emp['name'])) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="idc-ident">
                            <div class="idc-name"><?= h($emp['name']) ?></div>
                            <div class="idc-desig"><?= h($emp['desig_name'] ?: 'Employee') ?></div>
                        </div>

                        <div class="idc-qr-plate">
                            <img src="<?= $qrUri ?>" alt="Employee QR code" width="200" height="200">
                        </div>
                    </div>
                </div>
            </div>

            <!-- ══ BACK ═══════════════════════════════════════════════════ -->
            <div>
                <div class="idc-face-label">Back</div>
                <div class="id-card idc-back" id="idCardBack">

                    <!-- Waves along the top and bottom edges, both sampled from
                         the supplied template artwork. -->
                    <svg class="idc-svg" viewBox="0 0 540 856" preserveAspectRatio="none">
                        <path d="<?= ID_CARD_WAVE_TOP_SOFT ?>"    fill="<?= ID_CARD_ACCENT_SOFT ?>"/>
                        <path d="<?= ID_CARD_WAVE_TOP ?>"         fill="<?= ID_CARD_ACCENT ?>"/>
                        <path d="<?= ID_CARD_WAVE_BOTTOM_SOFT ?>" fill="<?= ID_CARD_ACCENT_SOFT ?>"/>
                        <path d="<?= ID_CARD_WAVE_BOTTOM ?>"      fill="<?= ID_CARD_ACCENT ?>"/>
                    </svg>

                    <div class="idc-layer">
                        <div class="idc-lockup idc-back-top">
                            <?php if ($hasEntity): ?>
                                <img class="idc-logo" src="<?= $logoUri ?>" alt="">
                            <?php else: ?>
                                <div class="idc-logo-ph">Your company logo</div>
                            <?php endif; ?>
                            <div class="idc-brand<?= $hasEntity ? '' : ' idc-brand-ph' ?>"
                                 style="<?= h($brandStyle) ?>"><?= h($brandName) ?></div>
                        </div>

                        <dl class="idc-rows">
                            <div class="idc-row">
                                <dt>ID No</dt><dd><?= h($emp['employee_id']) ?></dd>
                            </div>
                            <div class="idc-row">
                                <dt>BG</dt><dd><?= h($emp['blood_group'] ?: '—') ?></dd>
                            </div>
                            <div class="idc-row">
                                <dt>DOB</dt>
                                <dd><?= $emp['dob'] ? h(date('d-m-Y', strtotime($emp['dob']))) : '—' ?></dd>
                            </div>
                            <div class="idc-row">
                                <dt>Phone</dt><dd><?= h($emp['phone'] ?: '—') ?></dd>
                            </div>
                            <div class="idc-row idc-row-addr">
                                <dt>Addr</dt><dd><?= h($empAddr ?: '—') ?></dd>
                            </div>
                        </dl>

                        <div class="idc-coaddr">
                            <div class="idc-coaddr-label">Company Addr :</div>
                            <div class="idc-coaddr-value"><?= h($companyAddr) ?></div>
                        </div>
                    </div>
                </div>
            </div>

          </div>
        </div>

        <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:center">
            <button class="btn btn-primary btn-sm" onclick="window.print()">
                <i class="fa fa-print me-1"></i>Print Both Sides
            </button>
            <button class="btn btn-success btn-sm" id="btnDownloadPdf">
                <i class="fa fa-file-pdf me-1"></i>Download PDF (Front + Back)
            </button>
        </div>
        <div class="text-muted" style="font-size:11px">Card size: 54 × 85.6 mm (CR80 / ID-1 standard)</div>
    </div>

    <!-- ── Details / actions ──────────────────────────────────────────────── -->
    <div class="idc-side">
        <div class="card">
            <div class="card-body" style="padding:16px">
                <h3 style="margin:0 0 12px;font-size:15px;font-weight:700">
                    <i class="fa fa-qrcode me-2 text-primary"></i>Secure Access
                </h3>

                <div style="font-size:12px;color:var(--text-muted);margin-bottom:6px">
                    The QR code opens this URL. It contains only a random token — no salary,
                    attendance or personal data is stored in the QR image.
                </div>
                <div class="idc-url"><?= h($portalUrl) ?></div>

                <div style="margin-top:14px">
                    <div class="idc-meta-row">
                        <span>Company name</span>
                        <strong>
                            <?= h($brandName) ?>
                            <?php if ($hasEntity): ?>
                                <span class="text-muted" style="font-weight:400">(entity)</span>
                            <?php else: ?>
                                <span class="text-muted" style="font-weight:400">(placeholder — no entity)</span>
                            <?php endif; ?>
                        </strong>
                    </div>
                    <div class="idc-meta-row">
                        <span>Token issued</span>
                        <strong><?= h(date_fmt($token['issued_at'] ?? $token['created_at'], 'd M Y, h:i A')) ?></strong>
                    </div>
                    <div class="idc-meta-row">
                        <span>Last scanned</span>
                        <strong><?= $token['last_used_at'] ? h(date_fmt($token['last_used_at'], 'd M Y, h:i A')) : '—' ?></strong>
                    </div>
                    <div class="idc-meta-row">
                        <span>Portal password</span>
                        <strong>
                            <?php if (!empty($token['password_hash'])): ?>
                                Set — random, not retrievable
                            <?php else: ?>
                                <span class="text-danger">Not set</span>
                            <?php endif; ?>
                        </strong>
                    </div>
                </div>

                <?php if (can('idcard', 'generate')): ?>
                <form method="POST" style="margin-top:12px"
                      onsubmit="return confirm('Generate a new portal password?\n\nThe employee\'s current password will stop working immediately. The printed card and QR code are NOT affected.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reset_password">
                    <button type="submit" class="btn btn-outline-primary btn-sm">
                        <i class="fa fa-key me-1"></i>Reset portal password
                    </button>
                </form>
                <?php endif; ?>

                <div style="background:var(--bg-secondary);border:1px solid var(--border);border-radius:var(--radius);padding:10px 12px;font-size:12px;margin-top:14px">
                    <i class="fa fa-circle-info me-1" style="color:var(--primary)"></i>
                    On scanning, the employee sees a password screen first. Only after the password is
                    verified are their salary slips and attendance shown — and only ever their own.
                    The password is stored as a one-way hash, never in plain text.
                </div>

                <?php if (can('idcard', 'revoke')): ?>
                <form method="POST" style="margin-top:14px;padding-top:12px;border-top:1px solid var(--border)"
                      onsubmit="return confirm('Issue a new QR token?\n\nEvery ID card already printed for this employee will stop working and must be reprinted.');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="regenerate">
                    <button type="submit" class="btn btn-outline-danger btn-sm">
                        <i class="fa fa-rotate me-1"></i>Regenerate QR Token
                    </button>
                    <div class="text-muted" style="font-size:11px;margin-top:6px">
                        Use this if a card is lost or stolen. Old stickers stop working immediately.
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script>
// One download: a 2-page PDF, front on page 1 and back on page 2. Each page is
// exactly 54 × 85.6 mm (CR80), so a card printer or a duplex print of the PDF
// lands the two faces back-to-back at true physical size with no scaling.
document.getElementById('btnDownloadPdf').addEventListener('click', function () {
    var btn = this;
    var jsPDFCtor = (window.jspdf && window.jspdf.jsPDF) || window.jsPDF;

    if (typeof html2canvas !== 'function' || !jsPDFCtor) {
        alert('The PDF exporter could not be loaded (no internet connection?).\nUse "Print Both Sides" and choose "Save as PDF" instead.');
        return;
    }

    var original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i>Building PDF…';

    var CARD_W = 54, CARD_H = 85.6;          // CR80, true printed size
    var PAGE_W = 210, PAGE_H = 297;          // A4 portrait
    var OX = (PAGE_W - CARD_W) / 2;          // centre the card on the sheet
    var OY = (PAGE_H - CARD_H) / 2;

    // scale 4 = 386 DPI at 54 mm wide — comfortably above the 300 DPI print
    // standard. (scale 8 was 771 DPI: invisible on paper, but it made the
    // export take ~12s and produce a 945 KB file.)
    var opts = { scale: 4, backgroundColor: '#ffffff', useCORS: true };

    // JPEG q0.95 rather than PNG: jsPDF's PNG path deflates every pixel in JS,
    // which was ~3.4s of the wait on its own, versus ~0.1s for JPEG. Verified
    // that the QR still decodes from the compressed image at print resolution.
    var IMG_FMT = 'JPEG', IMG_Q = 0.95;

    // Corner crop marks, drawn just OUTSIDE the card so cutting along them
    // leaves no line on the finished card. Needed because a white card edge is
    // invisible against a white A4 sheet.
    function cropMarks(pdf) {
        var L = 4, G = 2;                    // mark length / gap from the card
        pdf.setDrawColor(150).setLineWidth(0.15);
        [[OX, OY, -1, -1], [OX + CARD_W, OY, 1, -1],
         [OX, OY + CARD_H, -1, 1], [OX + CARD_W, OY + CARD_H, 1, 1]
        ].forEach(function (m) {
            var x = m[0], y = m[1], sx = m[2], sy = m[3];
            pdf.line(x + sx * G, y, x + sx * (G + L), y);   // horizontal arm
            pdf.line(x, y + sy * G, x, y + sy * (G + L));   // vertical arm
        });
    }

    Promise.all([
        html2canvas(document.getElementById('idCard'), opts),
        html2canvas(document.getElementById('idCardBack'), opts)
    ]).then(function (faces) {
        // A4 pages, one face each. Card-sized pages made both faces fit the
        // viewport at once, so PDF readers showed them stacked and page-2
        // navigation did nothing.
        var pdf = new jsPDFCtor({
            orientation: 'portrait',
            unit: 'mm',
            format: 'a4',
            compress: true
        });

        pdf.addImage(faces[0].toDataURL('image/jpeg', IMG_Q), IMG_FMT, OX, OY, CARD_W, CARD_H);
        cropMarks(pdf);

        pdf.addPage('a4', 'portrait');
        pdf.addImage(faces[1].toDataURL('image/jpeg', IMG_Q), IMG_FMT, OX, OY, CARD_W, CARD_H);
        cropMarks(pdf);

        pdf.save('id-card-<?= h(preg_replace('/[^A-Za-z0-9_-]/', '', $emp['employee_id'])) ?>.pdf');
    }).catch(function () {
        alert('Could not build the PDF. Use "Print Both Sides" and choose "Save as PDF" instead.');
    }).finally(function () {
        btn.disabled = false;
        btn.innerHTML = original;
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
