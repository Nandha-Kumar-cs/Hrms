<?php
/**
 * MagDyn HRMS — Authentication Helpers
 */

function is_logged_in(): bool {
    return !empty($_SESSION['user_id']);
}

function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function require_login(): void {
    if (!is_logged_in()) {
        redirect(BASE_URL . '/login.php');
    }
    // Session timeout check
    if (isset($_SESSION['last_active']) && (time() - $_SESSION['last_active'] > SESSION_TIMEOUT)) {
        session_destroy();
        redirect(BASE_URL . '/login.php?timeout=1');
    }
    $_SESSION['last_active'] = time();

    // Forced password change. An account whose password was set by somebody
    // else — an admin reset, or the temporary one the bulk importer generates
    // (security audit H-3) — cannot reach any other page until its owner picks
    // their own. Without this, a temporary password is a permanent one.
    if (!empty($_SESSION['user']['must_change_password']) && !password_change_page()) {
        redirect(BASE_URL . '/change_password.php');
    }
}

/** True when the current request IS the forced-change page (avoids a redirect loop). */
function password_change_page(): bool {
    return basename(parse_url((string)($_SERVER['SCRIPT_NAME'] ?? ''), PHP_URL_PATH) ?: '') === 'change_password.php';
}

/**
 * Clear the forced-change flag for a user and mirror it into the live session,
 * so the very next request stops redirecting.
 */
/**
 * Step-up re-authentication: prove the person at the keyboard is the account
 * holder, not just someone holding a live session (security audit M-13).
 *
 * A session cookie says "somebody logged in as this account at some point". That
 * is not enough authority to mint a NEW credential — with it, a stolen session
 * or an unattended desk becomes permanent access. Setting a password therefore
 * asks for the actor's own current password again.
 *
 * Constant-ish timing: always runs password_verify() against a real-looking hash
 * so a missing user cannot be told apart from a wrong password by response time.
 */
function verify_actor_password(string $password): bool {
    static $dummy = '$2y$12$usesomesillystringfeuFHUFHUFHUFHUFHUFHUFHUFHUFHUFHUFHUFH';
    $user = current_user();
    if (!$user || $password === '') { password_verify($password, $dummy); return false; }

    $st = db()->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    $st->execute([(int)$user['id']]);
    $hash = (string)$st->fetchColumn();
    if ($hash === '') { password_verify($password, $dummy); return false; }

    $ok = password_verify($password, $hash);
    if (!$ok) usleep(300000);   // blunt the rate at which this can be ground down
    return $ok;
}

function clear_must_change_password(int $userId): void {
    db()->prepare('UPDATE users SET must_change_password = 0 WHERE id = ?')->execute([$userId]);
    if ((int)($_SESSION['user']['id'] ?? 0) === $userId) {
        $_SESSION['user']['must_change_password'] = 0;
    }
}

function require_permission(string $module, string $action = 'view'): void {
    require_login();
    if (has_permission($module, $action)) return;

    /* Throw away anything already drawn (security audit L-1).
     *
     * Pages that include header.php before checking their permission have
     * already produced a full layout by the time we get here. header.php buffers
     * it, so discarding those buffers un-sends the page: the status line has not
     * gone out yet, 403 still applies, and 403.php — which emits its own
     * complete HTML document — arrives as the only document in the response
     * instead of being spliced into the middle of another one. */
    while (ob_get_level() > 0) ob_end_clean();

    http_response_code(403);
    include BASE_PATH . '/includes/403.php';
    exit;
}

/**
 * Strip credential material from a users row before it is put in the session
 * (security audit M-16).
 *
 * attempt_login() unset password_hash by hand, so the ordinary login path was
 * fine — but that made it a convention rather than a rule, and
 * settings/impersonate.php did `SELECT u.*` straight into $_SESSION['user'],
 * parking the target's bcrypt hash in the session store. Session files sit on
 * disk in the open on a default XAMPP install, get copied into backups, and are
 * dumped wholesale by any var_dump of the session — none of which should ever
 * be able to yield a password hash to crack offline.
 *
 * Every path into the session now goes through here, so this cannot be
 * forgotten by the next caller.
 */
function session_safe_user(array $user): array {
    unset($user['password_hash']);
    return $user;
}

function login_user(array $user): void {
    session_regenerate_id(true);
    $user                    = session_safe_user($user);
    $_SESSION['user_id']     = $user['id'];
    $_SESSION['user']        = $user;
    $_SESSION['last_active'] = time();
}

function logout_user(): void {
    $_SESSION = [];
    session_destroy();
}

/**
 * Attempt local DB login.
 */
function attempt_login(string $email, string $password): ?array {
    $pdo  = db();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        // Load role name
        $role = $pdo->prepare('SELECT name FROM roles WHERE id = ?');
        $role->execute([$user['role_id']]);
        $user['role_name'] = $role->fetchColumn();
        // Absent on installs that predate the column — treat "unknown" as "no
        // forced change" rather than locking everyone out of every page.
        $user['must_change_password'] = (int)($user['must_change_password'] ?? 0);
        unset($user['password_hash']);
        return $user;
    }
    return null;
}

/**
 * SSO / Global auth integration point.
 * If GLOBAL_AUTH_ENABLED, look up the user in the global auth DB,
 * then sync to local users table.
 */
function sync_global_auth_user(string $email, string $password = ''): ?array {
    if (!GLOBAL_AUTH_ENABLED) return null;
    try {
        $dsn  = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', GLOBAL_AUTH_DB_HOST, GLOBAL_AUTH_DB_NAME);
        $gpdo = new PDO($dsn, GLOBAL_AUTH_DB_USER, GLOBAL_AUTH_DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $stmt = $gpdo->prepare('SELECT * FROM global_users WHERE email = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$email]);
        $gUser = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$gUser) return null;

        // SECURITY: verify the password against the global account's stored hash.
        // Never log a user in on email-existence alone. Fail closed if there is no
        // usable hash column.
        $gHash = $gUser['password_hash'] ?? $gUser['password'] ?? '';
        if ($password === '' || !is_string($gHash) || $gHash === '' || !password_verify($password, $gHash)) {
            return null;
        }

        // Upsert into local users
        $local = db()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $local->execute([$email]);
        $localId = $local->fetchColumn();
        if (!$localId) {
            $ins = db()->prepare('INSERT INTO users (email, name, role_id, is_active, sso_uid, created_at)
                                  VALUES (?, ?, 2, 1, ?, NOW())');
            $ins->execute([$gUser['email'], $gUser['name'], $gUser['uid'] ?? null]);
            $localId = db()->lastInsertId();
        }
        $user = db()->prepare('SELECT u.*, r.name AS role_name FROM users u LEFT JOIN roles r ON r.id=u.role_id WHERE u.id=?');
        $user->execute([$localId]);
        return $user->fetch() ?: null;
    } catch (Exception $e) {
        error_log('Global auth sync error: ' . $e->getMessage());
        return null;
    }
}
