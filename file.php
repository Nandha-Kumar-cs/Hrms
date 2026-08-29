<?php
/**
 * MagDyn HRMS — authenticated file gateway.
 *
 * uploads/ and storage/ hold employee documents, photos, leave attachments and
 * the authorised-signatory signature images used on letters and payslips. They
 * used to be linked as plain static URLs; uploads/.htaccess blocked EXECUTION
 * but never READING, so anyone holding a URL — logged in or not, permissioned or
 * not — could fetch the file. The only defence was filename entropy, which leaks
 * through referrer headers, browser history and proxy logs (security audit H-2).
 *
 * Both directories now deny direct web access. Every read comes through here,
 * which resolves the path, confirms it is inside a known directory, and applies
 * the same permission + self-scope rules the owning module applies to its page.
 *
 *   /file.php?p=uploads/employee_docs/12/doc_abc.pdf        → inline
 *   /file.php?p=uploads/employee_docs/12/doc_abc.pdf&dl=1   → download
 *
 * Build the URL with file_url() (includes/helpers.php) — never by hand.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

/** Refuse without leaking whether the file exists. */
function file_gateway_deny(int $code = 404): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $code === 403 ? 'Forbidden' : 'Not found';
    exit;
}

// ── Resolve and contain the path ────────────────────────────────────────────
$rel = str_replace('\\', '/', (string)($_GET['p'] ?? ''));
$rel = ltrim($rel, '/');
if ($rel === '' || strpos($rel, "\0") !== false) file_gateway_deny();

$real = realpath(BASE_PATH . '/' . $rel);
$root = realpath(BASE_PATH);
// realpath() has already collapsed any ".." — this confirms where it landed,
// which is what actually stops traversal (a "../" check on the raw string does
// not, because of encodings and symlinks).
if ($real === false || $root === false || !is_file($real)) file_gateway_deny();
$realN = str_replace('\\', '/', $real);
$rootN = rtrim(str_replace('\\', '/', $root), '/') . '/';
if (strncmp($realN, $rootN, strlen($rootN)) !== 0) file_gateway_deny();

// Canonical project-relative path — authorization is decided on THIS, not on
// the requested string.
$path = substr($realN, strlen($rootN));

$db = db();

// ── Authorize by directory ──────────────────────────────────────────────────
$m = [];
if (preg_match('#^uploads/employee_docs/(\d+)/[^/]+$#', $path, $m)) {
    // Employee documents — the Documents module's own gate, plus self-scope so
    // an employee cannot read a colleague's file by id.
    if (!can('documents', 'view') && !can('employee', 'view')) file_gateway_deny(403);
    require_own_employee((int)$m[1]);

} elseif (preg_match('#^uploads/photos/([^/]+)$#', $path, $m)) {
    // Profile photos. Same shape as every other branch: first a permission that
    // entitles you to see employee records at all, then self-scope narrows it to
    // your own. The four listed permissions are the gates on the pages that
    // actually render a photo (employee view/edit, the Documents list, the ID
    // card), so no legitimate caller loses its image and an account holding none
    // of them cannot pull photo bytes at all.
    if (!can('employee', 'view') && !can('employee', 'edit')
        && !can('documents', 'view') && !can('idcard', 'view')) {
        file_gateway_deny(403);
    }
    if (is_self_scoped()) {
        $s = $db->prepare('SELECT id FROM employees WHERE photo = ? LIMIT 1');
        $s->execute([$m[1]]);
        require_own_employee((int)($s->fetchColumn() ?: 0));
    }

} elseif (preg_match('#^uploads/leave_docs/([^/]+)$#', $path, $m)) {
    // Leave attachments (medical certificates and the like).
    if (!can('attendance', 'view')) file_gateway_deny(403);
    if (is_self_scoped()) {
        $s = $db->prepare('SELECT employee_id FROM leave_requests WHERE document = ? LIMIT 1');
        $s->execute([$m[1]]);
        require_own_employee((int)($s->fetchColumn() ?: 0));
    }

} elseif (preg_match('#^storage/(entities|branding)/[^/]+$#', $path)) {
    // Company logos and authorised-signatory signatures. Not per-employee data,
    // but a signature image is a forgery ingredient — a session is required.
    // require_login() above is the whole check.

} else {
    file_gateway_deny();   // any other directory is not servable at all
}

// ── Serve ───────────────────────────────────────────────────────────────────
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = (string)$finfo->file($real);

// Only render types that cannot carry script inline. Anything else (SVG, HTML,
// or an unrecognised blob) is forced to download as an opaque octet-stream, so
// the gateway can never become a same-origin XSS vector.
$inlineSafe = ['application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$wantsDownload = !empty($_GET['dl']);
if (!in_array($mime, $inlineSafe, true)) {
    $mime = 'application/octet-stream';
    $wantsDownload = true;
}

$name = basename($path);
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($real));
header('Content-Disposition: ' . ($wantsDownload ? 'attachment' : 'inline')
     . '; filename="' . str_replace('"', '', $name) . '"');
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; img-src \'self\'; object-src \'none\'');
header('Referrer-Policy: no-referrer');
// Private: cacheable in the requesting browser, never in a shared proxy.
header('Cache-Control: private, max-age=600, no-transform');
header_remove('Pragma');

readfile($real);
