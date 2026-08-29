<?php
/**
 * MagDyn HRMS — Employee ID Card / QR secure-access helpers.
 * ─────────────────────────────────────────────────────────────────────────────
 * Shared by the admin card generator (modules/employee/id_card.php) and the
 * public employee portal (employee/secure-access/index.php).
 *
 * Security model
 *   • The QR image encodes ONLY a URL containing a 64-hex random token. No
 *     salary, attendance, employee id or personal data is ever in the QR.
 *   • The token identifies WHICH employee's login screen to show — it never by
 *     itself authorises access to data.
 *   • The password (first 4 letters of the name + DDMM of the DOB) is stored as
 *     a bcrypt hash only. The plain text is never written to the database.
 *   • After a password check the employee id is held in the SERVER session, so
 *     editing the URL cannot reach another employee's data (no IDOR).
 *
 * Requires includes/bootstrap.php to have been loaded (db(), BASE_URL, h()).
 */

require_once __DIR__ . '/../lib/qrcode/QrCode.php';

/** Idle timeout for an authenticated portal session (seconds). */
const PORTAL_IDLE_TIMEOUT = 900;      // 15 minutes
/** Hard cap on a portal session regardless of activity (seconds). */
const PORTAL_ABSOLUTE_TIMEOUT = 3600; // 1 hour
/** Failed password attempts before the token is locked. */
const PORTAL_MAX_ATTEMPTS = 5;
/** Lockout duration once PORTAL_MAX_ATTEMPTS is reached (minutes). */
const PORTAL_LOCKOUT_MINUTES = 15;

/* ═══════════════════════════════════════════════════════════════════════════
   TOKEN MANAGEMENT
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * A cryptographically random, non-guessable token (64 hex chars = 256 bits).
 * random_bytes() is a CSPRNG — never use rand()/uniqid() for this.
 */
function id_card_new_token(): string
{
    return bin2hex(random_bytes(32));
}

/**
 * Fetch the QR token row for an employee, creating one on first use.
 * Returns null only when the employee does not exist.
 *
 * $regenerate = true issues a brand new token, which instantly invalidates every
 * previously printed sticker for that employee.
 */
function id_card_token_for(int $empId, bool $regenerate = false): ?array
{
    if ($empId <= 0) return null;
    $db = db();

    $emp = $db->prepare('SELECT id, name, dob FROM employees WHERE id = ? LIMIT 1');
    $emp->execute([$empId]);
    $employee = $emp->fetch();
    if (!$employee) return null;

    $sel = $db->prepare('SELECT * FROM employee_qr_tokens WHERE employee_id = ? LIMIT 1');
    $sel->execute([$empId]);
    $row = $sel->fetch();

    $userId = function_exists('current_user') && current_user() ? (int) current_user()['id'] : null;

    if (!$row) {
        $ins = $db->prepare(
            'INSERT INTO employee_qr_tokens (employee_id, token, is_active, issued_at, created_by, created_at)
             VALUES (?, ?, 1, NOW(), ?, NOW())'
        );
        $ins->execute([$empId, id_card_new_token(), $userId]);
        $sel->execute([$empId]);
        $row = $sel->fetch();
    } elseif ($regenerate) {
        $upd = $db->prepare(
            'UPDATE employee_qr_tokens
                SET token = ?, is_active = 1, failed_attempts = 0, locked_until = NULL,
                    issued_at = NOW(), created_by = ?
              WHERE id = ?'
        );
        $upd->execute([id_card_new_token(), $userId, (int) $row['id']]);
        $sel->execute([$empId]);
        $row = $sel->fetch();
    }

    // Issue a strong random password on first use, and rotate any card still
    // carrying the old name+DOB derived one. Deliberately NOT re-synced to the
    // employee's name/DOB — that is precisely what made the secret guessable
    // from the printed card (security audit M-4).
    id_card_ensure_password($row, $employee);

    $sel->execute([$empId]);
    return $sel->fetch() ?: null;
}

/** Look up a token row by its token string (portal entry point). */
function id_card_find_by_token(string $token): ?array
{
    // Cheap shape check before touching the database.
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;

    $st = db()->prepare(
        'SELECT t.*, e.name, e.employee_id AS emp_code, e.photo, e.dob, e.status AS emp_status,
                e.department_id, e.designation_id, e.join_date, e.email,
                d.name AS dept_name, des.name AS desig_name
           FROM employee_qr_tokens t
           JOIN employees e        ON e.id = t.employee_id
           LEFT JOIN departments d  ON d.id = e.department_id
           LEFT JOIN designations des ON des.id = e.designation_id
          WHERE t.token = ? AND t.is_active = 1
          LIMIT 1'
    );
    $st->execute([$token]);
    return $st->fetch() ?: null;
}

/* ═══════════════════════════════════════════════════════════════════════════
   PORTAL PASSWORD

   The password is a RANDOM secret, generated once and stored only as a hash.
   It used to be DERIVED as "first 4 letters of the name" + "DOB as DDMM" — but
   the name is printed on the card and the QR code is on the card, so the whole
   secret reduced to the birthday: 366 guesses against a 5-try / 15-minute
   lockout, and a single guess for a colleague who knows it. Behind that door sit
   the bank account, IFSC, PAN, UAN, ESIC number and every payslip
   (security audit M-4).
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * A random, readable portal password.
 *
 * 10 characters from a 31-symbol alphabet ≈ 49 bits of entropy — against the
 * portal's 5-try / 15-minute lockout that is unguessable, versus the 366
 * possibilities the derived scheme actually had.
 *
 * The alphabet deliberately omits 0/O/1/l/I: employees type this on a phone
 * from something written down, and an ambiguous character costs a lockout.
 */
function id_card_generate_password(): string
{
    $alphabet = 'abcdefghijkmnpqrstuvwxyz23456789';   // no 0 O 1 l I
    $max      = strlen($alphabet) - 1;
    $out      = '';
    for ($i = 0; $i < 10; $i++) $out .= $alphabet[random_int(0, $max)];
    return $out;
}

/**
 * LEGACY ONLY — reproduces the old derived password so an existing stored hash
 * can be RECOGNISED as insecure and replaced. Never call this to set or verify
 * a password; it exists solely so id_card_ensure_password() can spot the weak
 * scheme still in place on cards issued before the M-4 fix.
 */
function id_card_legacy_derived_password(?string $name, ?string $dob): ?string
{
    $dob = trim((string) $dob);
    if ($dob === '' || $dob === '0000-00-00') return null;

    $ts = strtotime($dob);
    if ($ts === false) return null;

    $letters = preg_replace('/[^a-z]/', '', strtolower((string) $name));
    if ($letters === '') return null;
    $letters = str_pad(substr($letters, 0, 4), 4, 'x');

    return $letters . date('dm', $ts);
}

/**
 * Make sure the token has a strong password, and reveal it exactly once.
 *
 * A new password is issued when either:
 *   • there is no hash yet (a freshly issued card), or
 *   • the stored hash still matches the old DERIVED value — i.e. this card is
 *     carrying the insecure scheme and must be rotated off it.
 *
 * The plaintext is stashed in the session for a single read by the admin page
 * (id_card_take_revealed_password), because it can never be recovered from the
 * hash afterwards. An already-strong password is left completely alone — this
 * is NOT re-run on every page view, unlike the old sync-on-every-load behaviour
 * that kept forcing the password back to name+DOB.
 */
function id_card_ensure_password(array $tokenRow, array $employee): void
{
    $stored = (string) ($tokenRow['password_hash'] ?? '');
    $reason = '';

    if ($stored === '') {
        $reason = 'new';
    } else {
        $legacy = id_card_legacy_derived_password($employee['name'] ?? null, $employee['dob'] ?? null);
        if ($legacy !== null && password_verify($legacy, $stored)) {
            $reason = 'legacy';   // still on the derivable scheme — rotate it off
        }
    }
    if ($reason === '') return;   // already a strong, admin-issued password

    id_card_set_password((int) $tokenRow['id'], (int) $tokenRow['employee_id'], $reason);
}

/**
 * Issue a fresh random password for a token, clear any lockout, and stash the
 * plaintext for one-time display. Returns the plaintext.
 */
function id_card_set_password(int $tokenId, int $employeeId, string $reason = 'reset'): string
{
    $plain = id_card_generate_password();
    db()->prepare(
        'UPDATE employee_qr_tokens
            SET password_hash = ?, failed_attempts = 0, locked_until = NULL
          WHERE id = ?'
    )->execute([password_hash($plain, PASSWORD_DEFAULT), $tokenId]);

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['idcard_revealed'][$employeeId] = ['password' => $plain, 'reason' => $reason];
    }
    return $plain;
}

/**
 * Read and clear the one-time plaintext for an employee, or null if there is
 * none pending. Reading it removes it — it is never shown twice.
 */
function id_card_take_revealed_password(int $employeeId): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) return null;
    $v = $_SESSION['idcard_revealed'][$employeeId] ?? null;
    unset($_SESSION['idcard_revealed'][$employeeId]);
    return is_array($v) ? $v : null;
}

/** Constant-time password check against the stored bcrypt hash. */
function id_card_verify_password(array $tokenRow, string $submitted): bool
{
    $hash = (string) ($tokenRow['password_hash'] ?? '');
    if ($hash === '') return false;
    return password_verify(strtolower(trim($submitted)), $hash);
}

/* ═══════════════════════════════════════════════════════════════════════════
   URLS / RENDERING
   ═══════════════════════════════════════════════════════════════════════════ */

/** The public secure-access URL encoded into the QR code. */
function id_card_portal_url(string $token): string
{
    return BASE_URL . '/employee/secure-access/' . $token;
}

/**
 * QR code for a token as an inline data URI. Inlining (rather than a second HTTP
 * request) keeps the image available to print preview and to html2canvas, and
 * means the QR is never fetchable as a standalone guessable URL.
 *
 * $margin is the quiet zone baked INTO the image, in modules. The spec asks for
 * 4; pass a smaller value only when the surrounding layout already supplies
 * white space (the ID card sits the QR on a white plate on a white panel), which
 * lets the modules themselves be printed larger and so scan more easily.
 */
function id_card_qr_data_uri(string $token, int $scale = 6, int $margin = 4): string
{
    return QrCode::dataUri(id_card_portal_url($token), $scale, $margin);
}

/**
 * Employee photo as a data URI so the printed card and the PNG export do not
 * depend on a second network fetch. Returns null when there is no usable photo.
 */
function id_card_photo_data_uri(?string $photoFile): ?string
{
    $photoFile = trim((string) $photoFile);
    if ($photoFile === '') return null;

    // basename() defends against a stored value like "../../config/app.php".
    $path = UPLOAD_PATH . '/photos/' . basename($photoFile);
    if (!is_file($path) || !is_readable($path)) return null;

    $info = @getimagesize($path);
    if (!$info || empty($info['mime'])) return null;
    if (!in_array($info['mime'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) return null;

    $bin = @file_get_contents($path);
    if ($bin === false) return null;

    return 'data:' . $info['mime'] . ';base64,' . base64_encode($bin);
}

/**
 * Company logo for the card header, as a data URI — read ONLY from the entity's
 * own `entities.logo` upload.
 *
 * Deliberately has no fallback to another entity's logo, the global Branding
 * upload, or the bundled default_brand.png: an ID card must never carry a mark
 * belonging to a different company. Returns null when the entity has not
 * uploaded one, and the card then shows a monogram built from that entity's own
 * name (see id_card_monogram_data_uri) rather than somebody else's logo.
 */
function id_card_logo_data_uri(?string $entityLogo = null): ?string
{
    if (empty($entityLogo)) return null;

    $path = BASE_PATH . '/storage/entities/' . basename($entityLogo);
    if (!is_file($path) || !is_readable($path)) return null;

    $info = @getimagesize($path);
    if (!$info || empty($info['mime'])) return null;
    if (!in_array($info['mime'], ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) return null;

    $bin = @file_get_contents($path);
    if ($bin === false) return null;

    return 'data:' . $info['mime'] . ';base64,' . base64_encode($bin);
}

/**
 * Last-resort logo: the company's initials on a rounded tile, as an inline SVG
 * data URI. Guarantees the header always pairs a mark with the company name.
 */
function id_card_monogram_data_uri(string $companyName, string $colour = '#C8102E'): string
{
    $words = preg_split('/\s+/', trim($companyName)) ?: [];
    $mark  = '';
    foreach ($words as $w) {
        $w = preg_replace('/[^A-Za-z]/', '', $w);
        if ($w === '') continue;
        $mark .= strtoupper($w[0]);
        if (strlen($mark) === 2) break;
    }
    if ($mark === '') $mark = 'CO';

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64">'
         . '<rect width="64" height="64" rx="14" fill="' . h($colour) . '"/>'
         . '<text x="32" y="32" fill="#fff" font-family="Arial,Helvetica,sans-serif" font-size="27"'
         . ' font-weight="700" text-anchor="middle" dominant-baseline="central">' . h($mark) . '</text>'
         . '</svg>';

    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

/** Initials fallback shown when an employee has no photo on file. */
function id_card_initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $out   = '';
    foreach ($parts as $p) {
        if ($p === '') continue;
        $out .= strtoupper(substr($p, 0, 1));
        if (strlen($out) === 2) break;
    }
    return $out !== '' ? $out : '?';
}

/* ═══════════════════════════════════════════════════════════════════════════
   AUDIT
   ═══════════════════════════════════════════════════════════════════════════ */

/** Record a portal event. Never allowed to break the page. */
function portal_log(string $event, ?int $empId, ?int $tokenId, bool $success = false): void
{
    try {
        db()->prepare(
            'INSERT INTO employee_portal_access_log
                (employee_id, token_id, event, success, ip_address, user_agent, created_at)
             VALUES (?,?,?,?,?,?,NOW())'
        )->execute([
            $empId,
            $tokenId,
            substr($event, 0, 40),
            $success ? 1 : 0,
            substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
            substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
        ]);
    } catch (Throwable $e) { /* auditing must never take the portal down */ }
}

/* ═══════════════════════════════════════════════════════════════════════════
   PORTAL SESSION
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * The authenticated portal employee id, or 0. This is the ONLY source of truth
 * for whose data may be shown — never trust an id or token from the URL.
 * Enforces both the idle and the absolute timeout.
 */
function portal_employee_id(): int
{
    $s = $_SESSION['emp_portal'] ?? null;
    if (!is_array($s) || empty($s['employee_id'])) return 0;

    $now = time();
    if ($now - (int) ($s['last_active'] ?? 0) > PORTAL_IDLE_TIMEOUT) { portal_logout(); return 0; }
    if ($now - (int) ($s['auth_at'] ?? 0)     > PORTAL_ABSOLUTE_TIMEOUT) { portal_logout(); return 0; }

    $_SESSION['emp_portal']['last_active'] = $now;
    return (int) $s['employee_id'];
}

/** Start an authenticated portal session for a verified token row. */
function portal_login(array $tokenRow): void
{
    // New session id on privilege change — blocks session fixation via a link.
    session_regenerate_id(true);
    $_SESSION['emp_portal'] = [
        'employee_id' => (int) $tokenRow['employee_id'],
        'token_id'    => (int) $tokenRow['id'],
        'token'       => (string) $tokenRow['token'],
        'auth_at'     => time(),
        'last_active' => time(),
    ];
}

/** Drop the portal session without disturbing any HRMS admin session. */
function portal_logout(): void
{
    unset($_SESSION['emp_portal']);
}

/** True when the session was authenticated with THIS token. */
function portal_session_matches_token(string $token): bool
{
    $s = $_SESSION['emp_portal'] ?? null;
    return is_array($s) && hash_equals((string) ($s['token'] ?? ''), $token);
}

/* ═══════════════════════════════════════════════════════════════════════════
   ATTENDANCE
   ═══════════════════════════════════════════════════════════════════════════ */

/**
 * Net worked hours for one attendance row, using the same shift/break resolver
 * the attendance report and payroll use, so the employee sees exactly the hours
 * HR sees. Returns null when the day has no in/out pair.
 */
function portal_worked_hours(array $row, int $empId): ?float
{
    if (empty($row['in_time']) || empty($row['out_time'])) return null;

    // Payroll may already have stamped the authoritative value on the row.
    if (isset($row['worked_hours']) && $row['worked_hours'] !== null && $row['worked_hours'] !== '') {
        return round((float) $row['worked_hours'], 2);
    }

    $in  = time_to_mins(substr((string) $row['in_time'], 0, 5));
    $out = time_to_mins(substr((string) $row['out_time'], 0, 5));
    if ($out <= $in) return 0.0;

    $timing = attendance_row_timing(
        isset($row['shift_id']) && $row['shift_id'] !== null ? (int) $row['shift_id'] : null,
        $empId
    );
    $breaks = break_minutes_within($in, $out, $timing['lunch'] ?? null, $timing['breaks'] ?? null);

    return round(max(0, ($out - $in) - $breaks) / 60, 2);
}
