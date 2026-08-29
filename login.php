<?php
// bootstrap.php — NOT the individual config/include files. Bootstrap is what
// calls session_set_cookie_params() with HttpOnly/SameSite/Secure BEFORE the
// session starts. Including the pieces by hand skipped that block, so the
// session_regenerate_id(true) inside login_user() re-issued the authenticated
// cookie using PHP's ini defaults — no HttpOnly, no SameSite, no Secure. Every
// page except the one that mints the cookie was hardened (security audit H-1).
require_once __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) redirect(BASE_URL . '/index.php');

$error = '';
$timeout = !empty($_GET['timeout']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // Per-IP/email failed-attempt throttle (created on demand, no migration needed).
    $LOCK_MAX = 8; $LOCK_WINDOW_MIN = 15;
    try {
        db()->exec('CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip VARCHAR(45) NOT NULL, email VARCHAR(190) NULL,
            attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (ip, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    } catch (Throwable $e) { /* non-fatal */ }

    // Reaches existing installs, whose users table predates the column that
    // drives the forced password change (security audit H-3). attempt_login()
    // does SELECT *, so once the column exists the flag rides into the session.
    db_ensure_column('users', 'must_change_password', 'TINYINT(1) NOT NULL DEFAULT 0');

    $recentFails = function () use ($ip, $email, $LOCK_WINDOW_MIN) {
        try {
            $s = db()->prepare('SELECT COUNT(*) FROM login_attempts
                                 WHERE (ip = ? OR email = ?) AND attempted_at > (NOW() - INTERVAL ? MINUTE)');
            $s->execute([$ip, $email, $LOCK_WINDOW_MIN]);
            return (int) $s->fetchColumn();
        } catch (Throwable $e) { return 0; }
    };

    if (!csrf_verify()) {
        $error = 'Your session expired. Please try again.';
    } elseif ($recentFails() >= $LOCK_MAX) {
        $error = 'Too many failed attempts. Please wait a few minutes and try again.';
    } else {
        $user = null;
        if (GLOBAL_AUTH_ENABLED) $user = sync_global_auth_user($email, $password);
        if (!$user)              $user = attempt_login($email, $password);

        if ($user) {
            try { db()->prepare('DELETE FROM login_attempts WHERE ip = ? OR email = ?')->execute([$ip, $email]); } catch (Throwable $e) {}
            login_user($user);
            db()->prepare('UPDATE users SET last_login=NOW() WHERE id=?')->execute([$user['id']]);
            activity_log('login', 'Auth', 'Logged in: ' . ($user['name'] ?? $email));
            redirect(BASE_URL . '/index.php');
        } else {
            try { db()->prepare('INSERT INTO login_attempts (ip, email) VALUES (?, ?)')->execute([$ip, $email]); } catch (Throwable $e) {}
            usleep(300000);   // small constant delay to slow guessing
            $error = 'Invalid email or password.';
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="<?= PWA_THEME_COLOR ?>">
    <link rel="manifest" href="<?= BASE_URL ?>/manifest.json">
    <title>Login — <?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/magdyn-base.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/hrms.css">
</head>
<body class="login-body">
<div class="login-wrap">
    <div class="login-card">
        <div class="login-brand">
            <div class="brand-mark big" style="margin:0 auto 14px; background:#1e3a8a; width:72px; height:72px; border-radius:14px; display:flex; align-items:center; justify-content:center;">
                <span style="font-size:36px; color:white; font-weight:800">H</span>
            </div>
            <h1><?= h(APP_NAME) ?></h1>
        </div>

        <?php if ($timeout): ?>
        <div class="alert alert-warn">Your session expired. Please log in again.</div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="alert alert-error"><?= h($error) ?></div>
        <?php endif; ?>

        <form class="login-form" method="POST" action="">
            <?= csrf_field() ?>
            <label for="email"><u>E</u>mail Address</label>
            <input type="email" id="email" name="email" required autofocus
                   value="<?= h($_POST['email'] ?? '') ?>"
                   placeholder="you@company.com"
                   accesskey="e">

            <label for="password"><u>P</u>assword</label>
            <input type="password" id="password" name="password" required
                   placeholder="••••••••"
                   accesskey="p">

            <button type="submit" class="btn btn-primary btn-block" accesskey="l" data-shortcut data-key="L">
                <u>L</u>og In
            </button>
        </form>

        <?php if (SSO_ENABLED): ?>
        <div style="text-align:center; margin-top:18px; color:var(--text-muted); font-size:13px;">— or —</div>
        <a href="<?= BASE_URL ?>/sso/initiate.php" class="btn btn-block" style="margin-top:12px; text-align:center;">
            🔐 Sign in with SSO
        </a>
        <?php endif; ?>
    </div>
</div>
<script>
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('<?= BASE_URL ?>/sw.js').catch(() => {});
}
// Alt+key highlights on login page too
document.addEventListener('keydown', e => {
    if (e.altKey && e.key.toLowerCase() === 'e') { e.preventDefault(); document.getElementById('email').focus(); }
    if (e.altKey && e.key.toLowerCase() === 'p') { e.preventDefault(); document.getElementById('password').focus(); }
});
</script>
</body>
</html>
