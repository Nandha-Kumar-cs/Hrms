<?php
/**
 * MagDyn HRMS — Forced / voluntary password change.
 *
 * require_login() sends every user whose users.must_change_password is set here
 * and refuses to let them reach any other page until they pick their own
 * password (security audit H-3). It is also linkable for a normal, voluntary
 * change.
 *
 * The page deliberately does NOT include header.php/sidebar.php: a user who is
 * mid-forced-change should not be shown navigation into pages they are being
 * held out of.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_login();

$user   = current_user();
$forced = !empty($user['must_change_password']);
$error  = '';
$done   = false;

// Minimum length only — no composition rules, which push users toward
// predictable substitutions. Long is what matters.
// Shared with settings/users.php via config/app.php so the admin form cannot
// issue a password shorter than this page will accept (security audit M-13).
const PW_MIN_LEN = PASSWORD_MIN_LENGTH;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = (string)($_POST['current_password'] ?? '');
    $new     = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    if (!csrf_verify()) {
        $error = 'Your session expired. Please try again.';
    } else {
        // Re-verify the current password against the stored hash — the session
        // is not proof enough to change the credential that guards it.
        $st = db()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $st->execute([$user['id']]);
        $hash = (string)$st->fetchColumn();

        if ($hash === '' || !password_verify($current, $hash)) {
            $error = 'Your current password is not correct.';
            usleep(300000);
        } elseif (strlen($new) < PW_MIN_LEN) {
            $error = 'Your new password must be at least ' . PW_MIN_LEN . ' characters.';
        } elseif ($new !== $confirm) {
            $error = 'The two new passwords do not match.';
        } elseif (password_verify($new, $hash)) {
            $error = 'Please choose a password you have not used here before.';
        } else {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]), (int)$user['id']]);
            clear_must_change_password((int)$user['id']);
            // New credential ⇒ new session id, so a token captured before the
            // change cannot ride along afterwards.
            session_regenerate_id(true);
            activity_log('updated', 'Auth', 'Changed own password');
            $done = true;
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="<?= PWA_THEME_COLOR ?>">
    <title>Change Password — <?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/magdyn-base.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/hrms.css">
</head>
<body class="login-body">
<div class="login-wrap">
    <div class="login-card">
        <div class="login-brand">
            <h1>Change Password</h1>
            <p class="muted" style="margin-top:6px"><?= h($user['name'] ?? '') ?></p>
        </div>

        <?php if ($done): ?>
            <div class="alert alert-success">Your password has been changed.</div>
            <a href="<?= BASE_URL ?>/index.php" class="btn btn-primary btn-block" style="text-align:center">Continue to HRMS</a>
        <?php else: ?>
            <?php if ($forced): ?>
            <div class="alert alert-warn">
                Your password was set for you. Please choose your own before continuing.
            </div>
            <?php endif; ?>
            <?php if ($error): ?>
            <div class="alert alert-error"><?= h($error) ?></div>
            <?php endif; ?>

            <form class="login-form" method="POST" action="">
                <?= csrf_field() ?>
                <label for="current_password">Current Password</label>
                <input type="password" id="current_password" name="current_password" required autofocus autocomplete="current-password">

                <label for="new_password">New Password</label>
                <input type="password" id="new_password" name="new_password" required minlength="<?= PW_MIN_LEN ?>" autocomplete="new-password">

                <label for="confirm_password">Confirm New Password</label>
                <input type="password" id="confirm_password" name="confirm_password" required minlength="<?= PW_MIN_LEN ?>" autocomplete="new-password">

                <button type="submit" class="btn btn-primary btn-block">Change Password</button>
            </form>

            <div style="text-align:center;margin-top:16px">
                <a href="<?= BASE_URL ?>/logout.php" class="small muted">Log out</a>
            </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
