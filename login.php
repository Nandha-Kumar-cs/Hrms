<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/activity_log.php';

session_name(SESSION_NAME);
session_start();

if (is_logged_in()) redirect(BASE_URL . '/index.php');

$error = '';
$timeout = !empty($_GET['timeout']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = sanitize($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $user = null;

    // Try global auth first
    if (GLOBAL_AUTH_ENABLED) {
        $user = sync_global_auth_user($email);
    }

    // Fallback to local auth
    if (!$user) {
        $user = attempt_login($email, $password);
    }

    if ($user) {
        login_user($user);
        // Update last login
        db()->prepare('UPDATE users SET last_login=NOW() WHERE id=?')->execute([$user['id']]);
        activity_log('login', 'Auth', 'Logged in: ' . ($user['name'] ?? $email));
        redirect(BASE_URL . '/index.php');
    } else {
        $error = 'Invalid email or password.';
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
            <p><?= h(COMPANY_NAME) ?></p>
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

        <div class="login-hint">
            Default admin: <strong>admin@hrms.local</strong> / <strong>Admin@1234</strong><br>
            <span style="font-size:11px; color:var(--text-light)">Change immediately after first login</span>
        </div>
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
