<?php
/**
 * MagDyn HRMS — Bootstrap
 * Include this at the top of every PHP page.
 */

// Load configs
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

/* Defaults for settings that config/app.php may predate.
 *
 * config/ is a PROTECTED path in the deployer (it holds per-environment DB
 * credentials and BASE_URL), so an update package can never ship a new
 * config/app.php. Anything the code depends on must therefore have a working
 * default here, with config/app.php free to override it when present.
 */
/* ── Baseline security headers (security audit L-4) ───────────────────────────
 *
 * Only employee/secure-access/ and file.php set any headers; every other page
 * sent none at all. Setting them here covers the whole application at once.
 *
 * Both of those pages require this file BEFORE sending their own, stricter
 * values, and header() replaces a header of the same name — so their DENY and
 * their tighter CSP still win. This is the floor, not a ceiling.
 *
 * ON THE CSP, HONESTLY: this app has ~69 inline <script> blocks, ~134 inline
 * event handlers and ~796 inline style attributes, so 'unsafe-inline' is
 * unavoidable without rewriting the front end. That means this policy does NOT
 * stop cross-site scripting. What it does stop is cheap and worth having:
 *   object-src 'none'     — no Flash/Java/plugin embedding
 *   base-uri 'self'       — an injected <base> cannot re-point every relative URL
 *   form-action 'self'    — an injected form cannot post credentials off-site
 *   frame-ancestors 'self'— clickjacking, the modern X-Frame-Options
 * Removing 'unsafe-inline' is the real prize, but it is a front-end project, not
 * a header change. */
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    $_l4_cdn = 'https://cdn.jsdelivr.net https://cdn.datatables.net '
             . 'https://cdnjs.cloudflare.com https://code.jquery.com';

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()');
    header(
        "Content-Security-Policy: default-src 'self'; "
      . "script-src 'self' 'unsafe-inline' 'unsafe-eval' $_l4_cdn; "
      . "style-src 'self' 'unsafe-inline' $_l4_cdn; "
      . "font-src 'self' data: $_l4_cdn; "
      . "img-src 'self' data: blob:; "
      . "connect-src 'self'; "
      . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'"
    );

    /* HSTS only over a real TLS connection. Sending it on plain HTTP is ignored
     * by browsers, and sending it from a local http:// install would be a
     * needless foot-gun if the hostname were ever reused. */
    $_l4_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
              || (($_SERVER['SERVER_PORT'] ?? '') == 443)
              || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    if ($_l4_https) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    unset($_l4_cdn, $_l4_https);
}

/* ── Never leak errors to a browser on a real host (security audit L-3) ───────
 *
 * config/app.php ships APP_ENV='development' and APP_DEBUG=true, which turn
 * display_errors ON. config/ is a PROTECTED path — no update package can ever
 * correct it — so a server whose config was copied from the dev machine will
 * happily print PHP warnings, SQL errors and absolute filesystem paths straight
 * into the page for anyone who triggers one.
 *
 * The environment flag is therefore treated as a hint, not as authority: errors
 * are shown only when the request genuinely came from this machine. Local
 * development is unaffected; every other host gets them logged instead.
 *
 * REMOTE_ADDR is the real TCP peer and cannot be forged by the client (unlike
 * X-Forwarded-For), but behind a reverse proxy it IS the proxy — so the presence
 * of a forwarding header disqualifies the "local" verdict. */
$_l3_proxied = !empty($_SERVER['HTTP_X_FORWARDED_FOR']) || !empty($_SERVER['HTTP_X_REAL_IP']);
$_l3_local   = PHP_SAPI === 'cli'
            || (!$_l3_proxied && in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true));
if (!$_l3_local) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}
ini_set('log_errors', '1');   // suppressed on screen, but never simply discarded
unset($_l3_proxied, $_l3_local);

/* Comp-off policy (security audit M-21).
 *
 * A credit used to be granted for ANY present status on a non-working day — a
 * Half Day, or a ten-minute punch that happened to classify as one, earned a
 * whole day off — and credits never expired, so one earned years ago was still
 * spendable. These make both rules explicit and adjustable; override them in
 * config/app.php if your policy differs. */
if (!defined('COMP_OFF_MIN_HOURS_FULL')) define('COMP_OFF_MIN_HOURS_FULL', 6.0);  // ≥ this → 1.0 day credit
if (!defined('COMP_OFF_MIN_HOURS_HALF')) define('COMP_OFF_MIN_HOURS_HALF', 3.0);  // ≥ this → 0.5 day credit
if (!defined('COMP_OFF_EXPIRY_DAYS'))    define('COMP_OFF_EXPIRY_DAYS', 90);      // 0 = never expires

if (!defined('PASSWORD_MIN_LENGTH')) {
    // Minimum length only — composition rules push people toward predictable
    // substitutions (NIST SP 800-63B). Shared by change_password.php and
    // settings/users.php so neither can undercut the other (security audit M-13).
    define('PASSWORD_MIN_LENGTH', 10);
}

if (!defined('SESSION_NAME')) {
    // config/app.php normally defines this, but config/ is a PROTECTED path that
    // no update package can write. A server whose config predates the constant
    // would fatal here on the very first request, with no way to deploy a fix.
    define('SESSION_NAME', 'HRMS_SESSION');
}

// Session init — harden the session cookie (HttpOnly, SameSite, Secure on HTTPS).
if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || (($_SERVER['SERVER_PORT'] ?? '') == 443)
           || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,        // not readable by JavaScript (XSS-stolen cookies)
        'secure'   => $secure,     // only sent over HTTPS when the request is HTTPS
        'samesite' => 'Lax',       // mitigates CSRF on cross-site requests
    ]);
    session_name(SESSION_NAME);
    session_start();
}

// Helpers
require_once __DIR__ . '/helpers.php';

// uploads/ and storage/ must stay closed to direct web access. Done here, at
// runtime, because the deployment module refuses to write those directories —
// see hrms_harden_data_dirs() in helpers.php (security audit H-2).
hrms_harden_data_dirs();
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/activity_log.php';
require_once __DIR__ . '/scope.php';
require_once __DIR__ . '/salary_sync.php';
require_once __DIR__ . '/payslip_render.php';
require_once __DIR__ . '/deployer.php';

/* ── Maintenance mode ─────────────────────────────────────────────────────────
 * Set only while a LIVE deployment is running (see modules/deployment/).
 * A flag FILE, not a DB row, so it still works if a deployment breaks the data
 * layer. Super Admins and the deployment module itself are never locked out —
 * otherwise a failed deployment would leave nobody able to roll it back. */
if (deploy_maintenance_on()) {
    $_m_uri     = str_replace('\\', '/', (string) ($_SERVER['REQUEST_URI'] ?? ''));
    $_m_exempt  = strpos($_m_uri, '/modules/deployment/') !== false
               || strpos($_m_uri, '/login.php') !== false
               || strpos($_m_uri, '/logout.php') !== false
               || strpos($_m_uri, '/assets/') !== false;

    if (!$_m_exempt && !(function_exists('is_super_admin') && is_super_admin())) {
        http_response_code(503);
        header('Retry-After: 300');
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><head><meta charset="utf-8">'
           . '<meta name="viewport" content="width=device-width,initial-scale=1">'
           . '<title>Maintenance</title></head>'
           . '<body style="font-family:system-ui,sans-serif;background:#f4f5f7;margin:0;'
           . 'display:flex;align-items:center;justify-content:center;min-height:100vh">'
           . '<div style="max-width:420px;background:#fff;border-radius:14px;padding:32px;'
           . 'text-align:center;box-shadow:0 6px 24px rgba(15,23,42,.12)">'
           . '<div style="font-size:38px">&#128295;</div>'
           . '<h1 style="font-size:19px;margin:14px 0 8px">System update in progress</h1>'
           . '<p style="color:#6b7280;font-size:14px;margin:0">'
           . 'The HRMS is briefly unavailable while an update is applied.<br>Please try again in a few minutes.</p>'
           . '</div></body></html>';
        exit;
    }
}
