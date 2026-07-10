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
