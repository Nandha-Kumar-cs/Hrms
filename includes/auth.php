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
}

function require_permission(string $module, string $action = 'view'): void {
    require_login();
    if (!has_permission($module, $action)) {
        http_response_code(403);
        include BASE_PATH . '/includes/403.php';
        exit;
    }
}

function login_user(array $user): void {
    session_regenerate_id(true);
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
function sync_global_auth_user(string $email): ?array {
    if (!GLOBAL_AUTH_ENABLED) return null;
    try {
        $dsn  = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', GLOBAL_AUTH_DB_HOST, GLOBAL_AUTH_DB_NAME);
        $gpdo = new PDO($dsn, GLOBAL_AUTH_DB_USER, GLOBAL_AUTH_DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $stmt = $gpdo->prepare('SELECT * FROM global_users WHERE email = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$email]);
        $gUser = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$gUser) return null;

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
