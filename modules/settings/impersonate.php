<?php
/**
 * User impersonation (admin only).
 *   ?action=start (POST, id) → become another user (view the app as them)
 *   ?action=stop             → return to your own account
 *
 * The original admin identity is kept in $_SESSION['impersonator'] and restored
 * on stop. Impersonating a Super Admin is blocked; nesting is not allowed.
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$me     = current_user();
$dash   = BASE_URL . '/index.php';
$users  = BASE_URL . '/modules/settings/users.php';
$active = !empty($_SESSION['impersonator']);
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Stop: restore the original account ───────────────────────────────────────
if ($action === 'stop') {
    if ($active) {
        $orig = $_SESSION['impersonator'];
        activity_log('impersonate_stop', 'Auth',
            'Stopped impersonating ' . ($me['email'] ?? '') . ' (back to ' . ($orig['email'] ?? '') . ')');
        $_SESSION['user']    = session_safe_user($orig);
        $_SESSION['user_id'] = $orig['id'] ?? null;
        unset($_SESSION['impersonator']);
        flash('success', 'Returned to your account.');
    }
    redirect($dash);
}

// ── Start: only a real admin, via POST + CSRF, when not already impersonating ─
if ($action === 'start') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
        flash('error', 'Invalid request.'); redirect($users);
    }
    if ($active) { flash('error', 'Already impersonating — return to your account first.'); redirect($dash); }
    if (!is_admin()) { http_response_code(403); flash('error', 'Only an administrator can impersonate users.'); redirect($dash); }

    $targetId = (int)($_POST['id'] ?? 0);
    if ($targetId === (int)($me['id'] ?? 0)) { redirect($users); }   // no self-impersonation

    $t = $db = db();
    $st = $db->prepare('SELECT u.*, r.name AS role_name FROM users u LEFT JOIN roles r ON r.id = u.role_id
                         WHERE u.id = ? AND u.is_active = 1 LIMIT 1');
    $st->execute([$targetId]);
    $target = $st->fetch();

    if (!$target) { flash('error', 'User not found or inactive.'); redirect($users); }
    if (($target['role_name'] ?? '') === 'Super Admin') {
        flash('error', 'You cannot impersonate a Super Admin account.'); redirect($users);
    }

    // SELECT u.* carries password_hash. Both of these land in the session store,
    // so strip credential material from each (security audit M-16).
    $_SESSION['impersonator'] = session_safe_user($me);       // remember who we really are
    $_SESSION['user']         = session_safe_user($target);   // become the target
    $_SESSION['user_id']      = $target['id'];
    activity_log('impersonate_start', 'Auth',
        ($me['email'] ?? '') . ' started impersonating ' . ($target['email'] ?? '') . ' (#' . $targetId . ')');
    flash('success', 'You are now viewing the app as ' . ($target['name'] ?? $target['email'] ?? 'this user') . '.');
    redirect($dash);
}

redirect($users);
