<?php
/**
 * Employee Secure Access portal — the page an ID-card QR code opens.
 * ─────────────────────────────────────────────────────────────────────────────
 * Flow:  scan QR  →  password screen  →  salary slips + attendance
 *
 * Security notes (see also includes/id_card.php):
 *   • The URL token only selects WHOSE login screen to show. Nothing is queried
 *     or rendered about the employee until the password has been verified, so no
 *     salary or attendance data ever appears in the pre-auth page source.
 *   • After login the employee id lives in the SERVER session only. Every query
 *     below filters on that id, so editing the token/ids in the URL cannot reach
 *     another employee's data (IDOR).
 *   • Brute force is capped per token (lockout after repeated failures).
 *   • This page never calls require_login() — it is a standalone portal and
 *     grants no access whatsoever to the HRMS admin application.
 */

require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/id_card.php';

// Sensitive, personal, and must never be framed or cached by a shared device.
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');

$db = db();

/* ── The module must be installed ─────────────────────────────────────────── */
try {
    $db->query('SELECT 1 FROM employee_qr_tokens LIMIT 1');
} catch (Throwable $e) {
    http_response_code(503);
    portal_render_notice('Service unavailable', 'This employee portal has not been set up yet. Please contact HR.');
    exit;
}

/* ── Resolve the token: rewritten ?t=, or PATH_INFO as a fallback ─────────── */
$tokenStr = (string) ($_GET['t'] ?? '');
if ($tokenStr === '' && !empty($_SERVER['PATH_INFO'])) {
    $tokenStr = trim((string) $_SERVER['PATH_INFO'], '/');
}
$tokenStr = strtolower(trim($tokenStr));

$viaPretty = (bool) preg_match('#/secure-access/[a-f0-9]{64}#i', (string) ($_SERVER['REQUEST_URI'] ?? ''));

// Links stay HOST-RELATIVE on purpose. The QR code must carry the absolute
// BASE_URL, but once the employee is here they must keep talking to the host
// they actually reached — an absolute BASE_URL link would bounce a visitor on
// any other hostname to a different origin and silently lose their session
// cookie mid-login.
$basePath = rtrim((string) parse_url(BASE_URL, PHP_URL_PATH), '/');

/** Build a link back to this portal, preserving how the visitor arrived. */
$plink = function (array $params = []) use ($tokenStr, $viaPretty, $basePath): string {
    if ($viaPretty) {
        $url = $basePath . '/employee/secure-access/' . $tokenStr;
        return $params ? $url . '?' . http_build_query($params) : $url;
    }
    return $basePath . '/employee/secure-access/index.php?' . http_build_query(['t' => $tokenStr] + $params);
};

/* ── Logout ───────────────────────────────────────────────────────────────── */
if (($_GET['do'] ?? '') === 'logout') {
    $sess = $_SESSION['emp_portal'] ?? null;
    if (is_array($sess)) portal_log('logout', (int) ($sess['employee_id'] ?? 0), (int) ($sess['token_id'] ?? 0), true);
    portal_logout();
    portal_render_notice('Signed out', 'You have been signed out. Scan your ID card again to sign back in.',
        $tokenStr !== '' ? $plink() : null, 'Back to sign in');
    exit;
}

/* ── Load the token row (no employee data is rendered from it pre-auth) ───── */
$tokenRow = $tokenStr !== '' ? id_card_find_by_token($tokenStr) : null;

if (!$tokenRow) {
    http_response_code(404);
    portal_log('scan_invalid', null, null, false);
    // Deliberately generic — never reveals whether a token ever existed.
    portal_render_notice('Invalid link',
        'This QR code is not valid, or it has been revoked. Please contact HR for a new ID card.');
    exit;
}

$empId = (int) $tokenRow['employee_id'];

/* ── Session must belong to THIS token ────────────────────────────────────── */
// Scanning a different employee's card while signed in must not show the old
// session's data — drop it and ask for that card's password instead.
if (portal_employee_id() && !portal_session_matches_token($tokenStr)) {
    portal_logout();
}

$authedId = portal_employee_id();
$error    = '';

/* ── Password submission ──────────────────────────────────────────────────── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !$authedId) {
    $posted = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $posted)) {
        $error = 'Your session expired. Please try again.';
    } else {
        $lockedUntil = $tokenRow['locked_until'] ? strtotime((string) $tokenRow['locked_until']) : 0;

        if ($lockedUntil && $lockedUntil > time()) {
            $mins  = max(1, (int) ceil(($lockedUntil - time()) / 60));
            $error = 'Too many incorrect attempts. Please try again in ' . $mins . ' minute' . ($mins > 1 ? 's' : '') . '.';
            portal_log('locked', $empId, (int) $tokenRow['id'], false);

        } elseif (empty($tokenRow['password_hash'])) {
            // No DOB on record → no derivable password. Fails closed.
            $error = 'Access is not yet enabled for this card. Please contact HR.';
            portal_log('login_fail_nopass', $empId, (int) $tokenRow['id'], false);

        } elseif (id_card_verify_password($tokenRow, (string) ($_POST['password'] ?? ''))) {
            $db->prepare(
                'UPDATE employee_qr_tokens
                    SET failed_attempts = 0, locked_until = NULL, last_used_at = NOW()
                  WHERE id = ?'
            )->execute([(int) $tokenRow['id']]);

            portal_login($tokenRow);
            portal_log('login_ok', $empId, (int) $tokenRow['id'], true);

            // Redirect after POST so a refresh does not re-submit the password.
            header('Location: ' . $plink());
            exit;

        } else {
            $attempts = (int) $tokenRow['failed_attempts'] + 1;
            if ($attempts >= PORTAL_MAX_ATTEMPTS) {
                $db->prepare(
                    'UPDATE employee_qr_tokens
                        SET failed_attempts = ?, locked_until = DATE_ADD(NOW(), INTERVAL ? MINUTE)
                      WHERE id = ?'
                )->execute([$attempts, PORTAL_LOCKOUT_MINUTES, (int) $tokenRow['id']]);
                $error = 'Too many incorrect attempts. This card is locked for '
                       . PORTAL_LOCKOUT_MINUTES . ' minutes.';
            } else {
                $db->prepare('UPDATE employee_qr_tokens SET failed_attempts = ? WHERE id = ?')
                   ->execute([$attempts, (int) $tokenRow['id']]);
                $left  = PORTAL_MAX_ATTEMPTS - $attempts;
                $error = 'Incorrect password. ' . $left . ' attempt' . ($left > 1 ? 's' : '') . ' remaining.';
            }
            portal_log('login_fail', $empId, (int) $tokenRow['id'], false);
            // Small constant delay blunts scripted guessing.
            usleep(400000);
        }
    }
}

$authedId = portal_employee_id();

/* ═══════════════════════════════════════════════════════════════════════════
   NOT AUTHENTICATED → password screen only. No employee data is emitted here.
   ═══════════════════════════════════════════════════════════════════════════ */
if (!$authedId) {
    portal_log('scan', $empId, (int) $tokenRow['id'], false);
    portal_render_login($plink(), csrf_token(), $error);
    exit;
}

/* ═══════════════════════════════════════════════════════════════════════════
   AUTHENTICATED — every query below is bound to $authedId from the SESSION.
   ═══════════════════════════════════════════════════════════════════════════ */

$me = $db->prepare(
    'SELECT e.id, e.employee_id, e.name, e.email, e.phone, e.photo, e.join_date, e.status,
            e.pan_number, e.uan_number, e.esic_number,
            e.bank_account, e.bank_name, e.bank_ifsc,
            d.name AS dept_name, des.name AS desig_name,
            ent.name AS entity_name, ent.name_font AS entity_name_font, ent.logo AS entity_logo,
            ent.address AS entity_address, ent.city AS entity_city,
            ent.state AS entity_state, ent.pincode AS entity_pincode
       FROM employees e
       LEFT JOIN departments d    ON d.id = e.department_id
       LEFT JOIN designations des ON des.id = e.designation_id
       LEFT JOIN entities ent     ON ent.id = e.entity_id
      WHERE e.id = ? LIMIT 1'
);
$me->execute([$authedId]);
$emp = $me->fetch();

if (!$emp) {                       // employee deleted while signed in
    portal_logout();
    portal_render_notice('Unavailable', 'Your record is no longer available. Please contact HR.');
    exit;
}

$view     = (string) ($_GET['view'] ?? 'home');
$photoUri = id_card_photo_data_uri($emp['photo'] ?? null);

/* ── Salary slip detail ───────────────────────────────────────────────────── */
$slip = null;
if ($view === 'slip') {
    // Bound to BOTH the slip id and the session employee id — a guessed id
    // belonging to someone else simply returns no row.
    $st = $db->prepare(
        'SELECT * FROM salary_slips WHERE id = ? AND employee_id = ? LIMIT 1'
    );
    $st->execute([(int) ($_GET['id'] ?? 0), $authedId]);
    $slip = $st->fetch() ?: null;

    if (!$slip) {
        $view = 'slips';
    } else {
        portal_log('view_slip', $authedId, (int) ($_SESSION['emp_portal']['token_id'] ?? 0), true);

        // "Download PDF" — served here, before any output. The slip was already
        // bound to $authedId above, so the download inherits that scoping and a
        // guessed id still returns nothing.
        if (!empty($_GET['dl'])) {
            portal_log('download_slip', $authedId, (int) ($_SESSION['emp_portal']['token_id'] ?? 0), true);
            portal_send_slip_pdf($slip, $emp);      // exits on success
            // No PDF engine available — fall through and let the page render,
            // where it auto-opens the print dialog as a Save-as-PDF path.
            $slipPrintFallback = true;
        }
    }
}

/* ── Salary slip list ─────────────────────────────────────────────────────── */
$slips = [];
if ($view === 'slips' || $view === 'home') {
    $st = $db->prepare(
        'SELECT id, payroll_month, gross_earnings, total_deductions, net_pay, status, created_at
           FROM salary_slips
          WHERE employee_id = ?
          ORDER BY payroll_month DESC, id DESC'
    );
    $st->execute([$authedId]);
    $slips = $st->fetchAll();
}

/* ── Attendance ───────────────────────────────────────────────────────────── */
$attRows = [];
$attMonth = date('Y-m');
$attStats = ['present' => 0, 'absent' => 0, 'half' => 0, 'leave' => 0, 'weekoff' => 0, 'hours' => 0.0];

if ($view === 'attendance') {
    $req = (string) ($_GET['m'] ?? '');
    if (preg_match('/^\d{4}-\d{2}$/', $req)) $attMonth = $req;

    $start = $attMonth . '-01';
    $end   = date('Y-m-t', strtotime($start));

    $st = $db->prepare(
        'SELECT * FROM attendance
          WHERE employee_id = ? AND att_date BETWEEN ? AND ?
          ORDER BY att_date ASC'
    );
    $st->execute([$authedId, $start, $end]);
    $attRows = $st->fetchAll();

    /* Holidays in the month, so a configured holiday is not counted as an
       absence (and a working-holiday still is a working day). */
    $holidayMap = [];
    try {
        $hs = $db->prepare('SELECT h_date, name, is_working_day FROM holidays WHERE h_date BETWEEN ? AND ?');
        $hs->execute([$start, $end]);
        foreach ($hs->fetchAll() as $h) $holidayMap[$h['h_date']] = $h;
    } catch (Throwable $e) { /* holidays table absent */ }

    /* Days covered by an admin-approved PAID leave — shown as Paid Leave, not
       Absent, matching the monthly report. */
    $paidLeaveDates = [];
    try {
        $ls = $db->prepare(
            "SELECT lr.start_date, lr.end_date
               FROM leave_requests lr
               JOIN leave_types lt ON lt.id = lr.leave_type_id
              WHERE lr.employee_id = ? AND lr.status = 'approved' AND lt.is_paid = 1
                AND lr.start_date <= ? AND lr.end_date >= ?"
        );
        $ls->execute([$authedId, $end, $start]);
        foreach ($ls->fetchAll() as $l) {
            $cur = new DateTime(max($l['start_date'], $start));
            $to  = new DateTime(min($l['end_date'], $end));
            while ($cur <= $to) { $paidLeaveDates[$cur->format('Y-m-d')] = true; $cur->modify('+1 day'); }
        }
    } catch (Throwable $e) { /* leave tables absent */ }

    /* Classify each row. An 'Absent' row on a Sunday / 1st-3rd Saturday /
       holiday is NOT an absence — the importer stamps every unworked calendar
       day as Absent, so the raw status alone over-counts badly (a normal month
       shows ~6 phantom absences). The label the employee sees is corrected too,
       not just the counter. */
    foreach ($attRows as &$r) {
        $r['_hours'] = portal_worked_hours($r, $authedId);
        $attStats['hours'] += (float) ($r['_hours'] ?? 0);

        $date   = new DateTime($r['att_date']);
        $offWhy = non_working_day_reason($date, $holidayMap);
        $isPaid = isset($paidLeaveDates[$r['att_date']]);
        $r['_label'] = (string) $r['status'];
        $r['_note']  = '';

        if ($r['status'] === 'Absent' && $offWhy !== null) {
            $r['_label'] = 'Week Off';
            $r['_note']  = $offWhy;
            $attStats['weekoff']++;
        } elseif ($r['status'] === 'Absent' && $isPaid) {
            $r['_label'] = 'Paid Leave';
            $attStats['leave']++;
        } elseif ($r['status'] === 'Absent') {
            $attStats['absent']++;
        } elseif ($r['status'] === 'Half Day') {
            $attStats['half']++;
        } else {
            if ($offWhy !== null) $r['_note'] = $offWhy;   // worked on a week off
            $attStats['present']++;
        }
    }
    unset($r);

    portal_log('view_attendance', $authedId, (int) ($_SESSION['emp_portal']['token_id'] ?? 0), true);
}

/* ── Months available for the attendance picker (own records only) ────────── */
$attMonths = [];
if ($view === 'attendance') {
    $st = $db->prepare(
        "SELECT DISTINCT DATE_FORMAT(att_date, '%Y-%m') AS m
           FROM attendance WHERE employee_id = ? ORDER BY m DESC LIMIT 24"
    );
    $st->execute([$authedId]);
    $attMonths = $st->fetchAll(PDO::FETCH_COLUMN);
}
if (!in_array($attMonth, $attMonths, true)) array_unshift($attMonths, $attMonth);

/* ═══════════════════════════════════════════════════════════════════════════
   VIEW HELPERS — defined at the bottom so the logic above reads top-to-bottom.
   ═══════════════════════════════════════════════════════════════════════════ */

/** Minimal standalone page chrome (no HRMS header — this is a public portal). */
function portal_head(string $title): void
{
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="only light">
<title><?= h($title) ?> — <?= h(APP_NAME) ?></title>
<style>
    *,*::before,*::after { box-sizing:border-box; }
    body {
        margin:0; background:#eef1f6; color:#111827;
        font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
        font-size:15px; line-height:1.5; -webkit-text-size-adjust:100%;
    }
    .pw { max-width:640px; margin:0 auto; padding:16px 14px 40px; }
    .pcard { background:#fff; border:1px solid #dfe3ea; border-radius:14px; box-shadow:0 1px 3px rgba(15,23,42,.06); overflow:hidden; }
    .pcard + .pcard { margin-top:14px; }
    .pcard-body { padding:16px; }
    .pcard-head { padding:13px 16px; border-bottom:1px solid #e8ebf0; font-weight:700; font-size:14px; display:flex; align-items:center; justify-content:space-between; gap:10px; }

    .ptop { background:#0f172a; color:#fff; padding:16px 14px; }
    .ptop-inner { max-width:640px; margin:0 auto; display:flex; align-items:center; gap:12px; }
    .ptop-photo { width:52px; height:52px; border-radius:50%; object-fit:cover; border:2px solid rgba(255,255,255,.35); flex:none; background:#334155; }
    .ptop-fallback { width:52px; height:52px; border-radius:50%; border:2px solid rgba(255,255,255,.35); background:#334155; display:flex; align-items:center; justify-content:center; font-weight:700; flex:none; }
    .ptop-name { font-weight:700; font-size:16px; line-height:1.25; }
    .ptop-meta { font-size:12px; opacity:.8; margin-top:2px; }
    .ptop-out { margin-left:auto; color:#fff; text-decoration:none; font-size:12px; border:1px solid rgba(255,255,255,.4); border-radius:8px; padding:6px 10px; white-space:nowrap; }

    .ptabs { display:flex; gap:8px; max-width:640px; margin:14px auto 0; padding:0 14px; }
    .ptab { flex:1; text-align:center; padding:11px 8px; border-radius:10px; background:#fff; border:1px solid #dfe3ea;
            text-decoration:none; color:#374151; font-size:13px; font-weight:600; }
    .ptab.active { background:#0f172a; color:#fff; border-color:#0f172a; }

    .prow { display:flex; justify-content:space-between; gap:12px; padding:9px 0; border-bottom:1px solid #f0f2f5; font-size:14px; }
    .prow:last-child { border-bottom:0; }
    .prow span:first-child { color:#6b7280; }
    .prow span:last-child { font-weight:600; text-align:right; }

    table.ptable { width:100%; border-collapse:collapse; font-size:13px; }
    table.ptable th, table.ptable td { padding:9px 10px; border-bottom:1px solid #eef0f4; text-align:left; white-space:nowrap; }
    table.ptable th { background:#f7f8fa; font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:#6b7280; }
    table.ptable td.num, table.ptable th.num { text-align:right; }
    .pscroll { overflow-x:auto; -webkit-overflow-scrolling:touch; }

    .pill { display:inline-block; padding:2px 9px; border-radius:11px; font-size:11px; font-weight:700; }
    .pill-ok   { background:#d4edda; color:#1a7a40; }
    .pill-late { background:#cfe2ff; color:#0a58ca; }
    .pill-half { background:#fff3cd; color:#856404; }
    .pill-bad  { background:#f8d7da; color:#842029; }
    .pill-neu  { background:#e2e3e5; color:#41464b; }

    .pbtn { display:inline-block; background:#1d4ed8; color:#fff; border:0; border-radius:10px;
            padding:12px 16px; font-size:15px; font-weight:600; cursor:pointer; text-decoration:none; text-align:center; }
    .pbtn:disabled { opacity:.6; }
    .pbtn-block { display:block; width:100%; }
    .pbtn-ghost { background:#fff; color:#1d4ed8; border:1px solid #c7d2fe; }

    .pinput { width:100%; padding:13px 14px; font-size:16px; border:1px solid #cbd2dc; border-radius:10px; background:#fff; }
    .pinput:focus { outline:2px solid #93c5fd; outline-offset:1px; border-color:#60a5fa; }
    .plabel { display:block; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.06em; color:#6b7280; margin-bottom:6px; }

    .palert { border-radius:10px; padding:11px 13px; font-size:13px; margin-bottom:14px; }
    .palert-err  { background:#fdecee; border:1px solid #f5c2c7; color:#842029; }
    .palert-info { background:#eef4ff; border:1px solid #c7d9fb; color:#1e40af; }

    .pmuted { color:#6b7280; font-size:12px; }
    .pempty { text-align:center; color:#6b7280; padding:26px 12px; font-size:14px; }
    .pstats { display:grid; grid-template-columns:repeat(4,1fr); gap:8px; }
    .pstat { background:#f7f8fa; border:1px solid #eceff3; border-radius:10px; padding:10px 6px; text-align:center; }
    .pstat b { display:block; font-size:17px; }
    .pstat span { font-size:10px; text-transform:uppercase; letter-spacing:.05em; color:#6b7280; }

    @media print {
        .noprint { display:none !important; }
        body { background:#fff; margin:0; padding:0; }
        .pcard { border:0; box-shadow:none; }
        /* The page wrapper carries a max-width and top padding sized for the
           phone header; both waste vertical space on an A4 sheet and were part
           of what pushed the payslip onto a second page. */
        .pw { max-width:none !important; width:auto !important;
              margin:0 !important; padding:0 !important; }
    }
</style>
</head>
<body>
<?php
}

function portal_foot(): void
{
    ?>
<p class="pmuted noprint" style="text-align:center;margin:22px auto 0;max-width:640px;padding:0 14px">
    Confidential — for the named employee only. This session signs out automatically after
    <?= (int) (PORTAL_IDLE_TIMEOUT / 60) ?> minutes of inactivity.
</p>
</body>
</html>
<?php
}

/** Standalone message page (invalid link, signed out, not installed…). */
function portal_render_notice(string $title, string $message, ?string $link = null, string $linkLabel = 'Continue'): void
{
    portal_head($title);
    ?>
    <div class="pw" style="padding-top:48px">
        <div class="pcard">
            <div class="pcard-body" style="text-align:center;padding:30px 20px">
                <div style="font-size:34px;line-height:1">&#128274;</div>
                <h1 style="font-size:19px;margin:12px 0 8px"><?= h($title) ?></h1>
                <p class="pmuted" style="font-size:14px;margin:0"><?= h($message) ?></p>
                <?php if ($link): ?>
                    <a class="pbtn" style="margin-top:18px" href="<?= h($link) ?>"><?= h($linkLabel) ?></a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
    portal_foot();
}

/** The password gate. Emits no employee information at all. */
function portal_render_login(string $action, string $csrf, string $error): void
{
    portal_head('Employee Sign In');
    ?>
    <div class="pw" style="padding-top:40px">
        <div class="pcard">
            <div class="pcard-body" style="padding:24px 20px">
                <div style="text-align:center;margin-bottom:20px">
                    <div style="font-size:32px;line-height:1">&#128100;</div>
                    <h1 style="font-size:19px;margin:10px 0 4px">Employee Secure Access</h1>
                    <p class="pmuted" style="margin:0">Enter your password to view your salary slips and attendance.</p>
                </div>

                <?php if ($error !== ''): ?>
                    <div class="palert palert-err"><?= h($error) ?></div>
                <?php endif; ?>

                <form method="POST" action="<?= h($action) ?>" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <label class="plabel" for="pw">Password</label>
                    <input class="pinput" type="password" id="pw" name="password"
                           required autofocus autocapitalize="none" autocorrect="off" spellcheck="false"
                           inputmode="text" placeholder="Enter your password">
                    <p class="pmuted" style="margin:8px 0 16px">
                        <?php /* This used to spell out the derivation rule (name + DOB) — which,
                                 with the name printed on the card the QR is attached to, told a
                                 finder most of the secret (security audit M-4). The password is
                                 now random and issued by HR. */ ?>
                        Enter the password HR gave you with this card.
                        If you do not have it, ask HR to issue a new one.
                    </p>
                    <button class="pbtn pbtn-block" type="submit">Sign In</button>
                </form>

                <p class="pmuted" style="text-align:center;margin:16px 0 0">
                    Trouble signing in? Contact your HR team.
                </p>
            </div>
        </div>
    </div>
    <?php
    portal_foot();
}

/**
 * Merge the slip row with this employee's details into the shape payslip_html()
 * expects. The layout itself lives in includes/payslip_render.php, so the
 * employee's on-screen slip and PDF are the same document HR issues.
 */
function portal_slip_row(array $slip, array $emp): array
{
    return $slip + [
        'emp_name'         => $emp['name'],
        'emp_code'         => $emp['employee_id'],
        'dept_name'        => $emp['dept_name']        ?? null,
        'desig_name'       => $emp['desig_name']       ?? null,
        'pan_number'       => $emp['pan_number']       ?? null,
        'esic_number'      => $emp['esic_number']      ?? null,
        'uan_number'       => $emp['uan_number']       ?? null,
        'bank_account'     => $emp['bank_account']     ?? null,
        'bank_name'        => $emp['bank_name']        ?? null,
        'bank_ifsc'        => $emp['bank_ifsc']        ?? null,
        'entity_name'      => $emp['entity_name']      ?? null,
        'entity_name_font' => $emp['entity_name_font'] ?? null,
        'entity_logo'      => $emp['entity_logo']      ?? null,
        'entity_address'   => $emp['entity_address']   ?? null,
        'entity_city'      => $emp['entity_city']      ?? null,
        'entity_state'     => $emp['entity_state']     ?? null,
        'entity_pincode'   => $emp['entity_pincode']   ?? null,
    ];
}

/**
 * Render the employee's own slip as a downloadable PDF and exit.
 *
 * Engine order matches modules/payroll/slip_pdf.php (mPDF → TCPDF). Returns
 * normally — WITHOUT sending anything — when no engine is installed, so the
 * caller can fall back to the browser print dialog.
 */
function portal_send_slip_pdf(array $slip, array $emp): void
{
    $monthLabel = date('F Y', strtotime($slip['payroll_month'] . '-01'));
    $filename   = 'salary-slip-' . preg_replace('/[^A-Za-z0-9_-]/', '', (string) $emp['employee_id'])
                . '-' . str_replace('-', '', $slip['payroll_month']) . '.pdf';

    // Exactly the payslip HR downloads — same renderer, same markup.
    $html = payslip_html(portal_slip_row($slip, $emp));

    // 1) mPDF — best CSS fidelity and native ₹ support.
    foreach (['C:/xampp8.2/htdocs/xibo/vendor/autoload.php', BASE_PATH . '/vendor/autoload.php'] as $al) {
        if (!is_file($al)) continue;
        require_once $al;
        if (!class_exists('\\Mpdf\\Mpdf')) continue;
        try {
            $tmp = sys_get_temp_dir() . '/mpdf_hrms';
            if (!is_dir($tmp)) @mkdir($tmp, 0777, true);
            $mpdf = new \Mpdf\Mpdf(['tempDir' => $tmp, 'format' => 'A4', 'margin_top' => 12, 'margin_bottom' => 12]);
            $mpdf->SetTitle('Salary Slip - ' . $emp['name'] . ' - ' . $monthLabel);
            $mpdf->WriteHTML($html);
            $mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);
            exit;
        } catch (Throwable $e) { /* fall through to TCPDF */ }
    }

    // 2) TCPDF.
    foreach ([
        'C:/xampp8.2/phpMyAdmin/vendor/tecnickcom/tcpdf/tcpdf.php',
        'C:/xampp/phpMyAdmin/vendor/tecnickcom/tcpdf/tcpdf.php',
    ] as $cand) {
        if (!is_file($cand)) continue;
        require_once $cand;
        try {
            $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
            $pdf->SetCreator('MagDyn HRMS');
            $pdf->SetTitle('Salary Slip - ' . $emp['name'] . ' - ' . $monthLabel);
            $pdf->SetMargins(12, 12, 12);
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->AddPage();
            $pdf->SetFont('dejavusans', '', 9);
            $pdf->writeHTML($html, true, false, true, false, '');
            $pdf->Output($filename, 'D');
            exit;
        } catch (Throwable $e) { /* fall through */ }
    }
    // No engine — caller falls back to the print dialog.
}

/** Attendance status → badge class. */
function portal_status_pill(string $status): string
{
    switch ($status) {
        case 'On Time':    return 'pill-ok';
        case 'Late':       return 'pill-late';
        case 'Half Day':   return 'pill-half';
        case 'Absent':     return 'pill-bad';
        case 'Paid Leave': return 'pill-half';
        case 'Week Off':   return 'pill-neu';
        default:           return 'pill-neu';
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
   RENDER
   ═══════════════════════════════════════════════════════════════════════════ */

portal_head($emp['name']);
?>

<div class="ptop noprint">
    <div class="ptop-inner">
        <?php if ($photoUri): ?>
            <img class="ptop-photo" src="<?= $photoUri ?>" alt="">
        <?php else: ?>
            <div class="ptop-fallback"><?= h(id_card_initials($emp['name'])) ?></div>
        <?php endif; ?>
        <div style="min-width:0">
            <div class="ptop-name"><?= h($emp['name']) ?></div>
            <div class="ptop-meta"><?= h($emp['employee_id']) ?> &middot; <?= h($emp['desig_name'] ?: 'Employee') ?></div>
        </div>
        <a class="ptop-out" href="<?= h($plink(['do' => 'logout'])) ?>">Sign out</a>
    </div>
</div>

<div class="ptabs noprint">
    <a class="ptab <?= $view === 'home' ? 'active' : '' ?>"       href="<?= h($plink()) ?>">Profile</a>
    <a class="ptab <?= in_array($view, ['slips','slip'], true) ? 'active' : '' ?>" href="<?= h($plink(['view' => 'slips'])) ?>">Salary Slips</a>
    <a class="ptab <?= $view === 'attendance' ? 'active' : '' ?>" href="<?= h($plink(['view' => 'attendance'])) ?>">Attendance</a>
</div>

<div class="pw">

<?php if ($view === 'home'): ?>

    <div class="pcard">
        <div class="pcard-head">My Details</div>
        <div class="pcard-body" style="padding:6px 16px">
            <div class="prow"><span>Employee Code</span><span><?= h($emp['employee_id']) ?></span></div>
            <div class="prow"><span>Department</span><span><?= h($emp['dept_name'] ?: '—') ?></span></div>
            <div class="prow"><span>Designation</span><span><?= h($emp['desig_name'] ?: '—') ?></span></div>
            <div class="prow"><span>Date of Joining</span><span><?= h(date_fmt($emp['join_date'])) ?></span></div>
            <div class="prow"><span>Status</span><span><?= h($emp['status']) ?></span></div>
            <div class="prow"><span>Official Email</span><span style="word-break:break-all"><?= h($emp['email']) ?></span></div>
        </div>
    </div>

    <div class="pcard">
        <div class="pcard-head">Latest Salary Slip</div>
        <div class="pcard-body">
            <?php if ($slips): $latest = $slips[0]; ?>
                <div class="prow" style="border:0;padding-top:0">
                    <span><?= h(date('F Y', strtotime($latest['payroll_month'] . '-01'))) ?></span>
                    <span><?= h(money((float) $latest['net_pay'])) ?></span>
                </div>
                <a class="pbtn pbtn-block" style="margin-top:8px"
                   href="<?= h($plink(['view' => 'slip', 'id' => (int) $latest['id']])) ?>">View Salary Slip</a>
            <?php else: ?>
                <div class="pempty">No salary slips have been published yet.</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="pcard">
        <div class="pcard-head">Attendance</div>
        <div class="pcard-body">
            <a class="pbtn pbtn-block pbtn-ghost"
               href="<?= h($plink(['view' => 'attendance'])) ?>">View My Attendance Report</a>
        </div>
    </div>

<?php elseif ($view === 'slips'): ?>

    <div class="pcard">
        <div class="pcard-head">My Salary Slips</div>
        <?php if ($slips): ?>
        <div class="pscroll">
            <table class="ptable">
                <thead>
                <tr>
                    <th>Month</th>
                    <th class="num">Gross</th>
                    <th class="num">Deductions</th>
                    <th class="num">Net Pay</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($slips as $sl): ?>
                <tr>
                    <td><?= h(date('M Y', strtotime($sl['payroll_month'] . '-01'))) ?></td>
                    <td class="num"><?= h(money((float) $sl['gross_earnings'])) ?></td>
                    <td class="num"><?= h(money((float) $sl['total_deductions'])) ?></td>
                    <td class="num"><strong><?= h(money((float) $sl['net_pay'])) ?></strong></td>
                    <td><a href="<?= h($plink(['view' => 'slip', 'id' => (int) $sl['id']])) ?>">View</a></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <div class="pempty">No salary slips have been published for you yet.</div>
        <?php endif; ?>
    </div>

<?php elseif ($view === 'slip' && $slip):

    $monthLabel = date('F Y', strtotime($slip['payroll_month'] . '-01'));
?>

    <div class="noprint" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
        <strong>Salary Slip — <?= h($monthLabel) ?></strong>
        <a class="pmuted" href="<?= h($plink(['view' => 'slips'])) ?>">All slips</a>
    </div>

    <?php
    /* The exact payslip HR issues — same renderer as the PDF download and the
       HR-side slip. $forScreen only adds a horizontal-scroll wrapper so the
       printed-width tables stay readable on a phone. */
    echo payslip_html(portal_slip_row($slip, $emp), true);
    ?>

    <div class="noprint" style="display:flex;gap:10px;margin-top:14px">
        <a class="pbtn" style="flex:1;text-align:center;text-decoration:none"
           href="<?= h($plink(['view' => 'slip', 'id' => (int) $slip['id'], 'dl' => '1'])) ?>">
            Download PDF
        </a>
        <button class="pbtn pbtn-ghost" style="flex:1" type="button" onclick="window.print()">
            Print
        </button>
    </div>
    <?php if (!empty($slipPrintFallback)): ?>
    <p class="pmuted noprint" style="text-align:center;margin-top:8px">
        PDF download is unavailable on this server — use Print and choose
        &ldquo;Save as PDF&rdquo;.
    </p>
    <?php endif; ?>

<?php elseif ($view === 'attendance'): ?>

    <div class="pcard">
        <div class="pcard-head">
            <span>Attendance — <?= h(date('F Y', strtotime($attMonth . '-01'))) ?></span>
        </div>
        <div class="pcard-body">
            <form method="GET" action="<?= h($viaPretty ? $basePath . '/employee/secure-access/' . $tokenStr : $basePath . '/employee/secure-access/index.php') ?>" class="noprint">
                <?php if (!$viaPretty): ?><input type="hidden" name="t" value="<?= h($tokenStr) ?>"><?php endif; ?>
                <input type="hidden" name="view" value="attendance">
                <label class="plabel" for="m">Select month</label>
                <select class="pinput" id="m" name="m" onchange="this.form.submit()">
                    <?php foreach ($attMonths as $m): ?>
                        <option value="<?= h($m) ?>" <?= $m === $attMonth ? 'selected' : '' ?>>
                            <?= h(date('F Y', strtotime($m . '-01'))) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <noscript><button class="pbtn" style="margin-top:10px" type="submit">Show</button></noscript>
            </form>

            <div class="pstats" style="margin-top:14px">
                <div class="pstat"><b><?= (int) $attStats['present'] ?></b><span>Present</span></div>
                <div class="pstat"><b><?= (int) $attStats['half'] ?></b><span>Half Day</span></div>
                <div class="pstat"><b><?= (int) $attStats['absent'] ?></b><span>Absent</span></div>
                <?php if ($attStats['leave']): ?>
                <div class="pstat"><b><?= (int) $attStats['leave'] ?></b><span>Paid Leave</span></div>
                <?php endif; ?>
                <div class="pstat"><b><?= (int) $attStats['weekoff'] ?></b><span>Week Off</span></div>
                <div class="pstat"><b><?= number_format($attStats['hours'], 1) ?></b><span>Hours</span></div>
            </div>
        </div>
    </div>

    <div class="pcard">
        <?php if ($attRows): ?>
        <div class="pscroll">
            <table class="ptable">
                <thead>
                <tr>
                    <th>Date</th>
                    <th>Check In</th>
                    <th>Check Out</th>
                    <th class="num">Hours</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($attRows as $r): ?>
                <tr>
                    <td><?= h(date('d M (D)', strtotime($r['att_date']))) ?></td>
                    <td><?= $r['in_time']  ? h(date('h:i A', strtotime($r['in_time'])))  : '—' ?></td>
                    <td><?= $r['out_time'] ? h(date('h:i A', strtotime($r['out_time']))) : '—' ?></td>
                    <td class="num"><?= $r['_hours'] !== null ? number_format((float) $r['_hours'], 2) : '—' ?></td>
                    <td>
                        <span class="pill <?= portal_status_pill((string) $r['_label']) ?>"><?= h($r['_label']) ?></span>
                        <?php if ($r['_note'] !== ''): ?>
                            <div class="pmuted" style="font-size:11px;margin-top:3px"><?= h($r['_note']) ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <div class="pempty">No attendance records for this month.</div>
        <?php endif; ?>
    </div>

<?php endif; ?>

</div>
<?php portal_foot(); ?>
