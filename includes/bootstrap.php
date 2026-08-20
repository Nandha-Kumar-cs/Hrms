<?php
/**
 * MagDyn HRMS — Bootstrap
 * Include this at the top of every PHP page.
 */

// Load configs
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

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
