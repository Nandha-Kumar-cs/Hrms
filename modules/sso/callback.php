<?php
require_once '../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/oidc.php';

if (!SSO_ENABLED) redirect(BASE_URL . '/login.php');

/** Refuse the login, log the real reason server-side, tell the user nothing useful. */
$deny = function (string $why): void {
    error_log('SSO login refused: ' . $why);
    if (function_exists('activity_log')) activity_log('updated', 'Auth', 'REFUSED an SSO login: ' . $why);
    unset($_SESSION['sso_state'], $_SESSION['sso_nonce'], $_SESSION['sso_at']);
    flash('danger', 'Single sign-on could not be completed. Please try again.');
    redirect(BASE_URL . '/login.php');
};

$code  = $_GET['code']  ?? '';
$state = $_GET['state'] ?? '';
$error = $_GET['error'] ?? '';

if ($error) $deny('provider returned error: ' . $error);

/* State: constant-time, and single-use. It is consumed before anything else
 * happens so a replayed callback cannot be retried against a live session. */
$expectState = (string) ($_SESSION['sso_state'] ?? '');
$expectNonce = (string) ($_SESSION['sso_nonce'] ?? '');
$startedAt   = (int)    ($_SESSION['sso_at']    ?? 0);
unset($_SESSION['sso_state'], $_SESSION['sso_nonce'], $_SESSION['sso_at']);

if (!$code || $expectState === '' || !hash_equals($expectState, (string) $state)) $deny('state mismatch');
if ($startedAt <= 0 || (time() - $startedAt) > 600)                               $deny('login attempt expired');

/* SSO cannot be verified without knowing who the issuer is and where its signing
 * keys live. Rather than fall back to "trust whatever comes back", refuse. Both
 * live in config/app.php next to the other SSO_* settings. */
$issuer = defined('SSO_ISSUER')   ? (string) SSO_ISSUER   : '';
$jwks   = defined('SSO_JWKS_URL') ? (string) SSO_JWKS_URL : '';
if ($issuer === '' || $jwks === '') {
    $deny('SSO_ISSUER / SSO_JWKS_URL are not configured — cannot validate the id_token');
}

// ── Exchange the code for tokens (TLS verified — see includes/oidc.php) ───────
$ch = curl_init(SSO_TOKEN_URL);
curl_setopt_array($ch, oidc_curl_options() + [
    CURLOPT_POST       => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => SSO_REDIRECT_URI,
        'client_id'     => SSO_CLIENT_ID,
        'client_secret' => SSO_CLIENT_SECRET,
    ]),
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
]);
$response = curl_exec($ch);
$curlErr  = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false) $deny('token exchange transport failure: ' . $curlErr);
if ($httpCode !== 200)   $deny('token exchange returned HTTP ' . $httpCode);

$tokens = json_decode((string) $response, true);
if (!is_array($tokens)) $deny('token response was not JSON');

// ── Validate the id_token: signature, issuer, audience, expiry, nonce ─────────
$idToken = (string) ($tokens['id_token'] ?? '');
if ($idToken === '') $deny('provider returned no id_token');

try {
    $claims = oidc_verify_id_token($idToken, [
        'issuer'   => $issuer,
        'audience' => SSO_CLIENT_ID,
        'nonce'    => $expectNonce,
        'jwks_url' => $jwks,
    ]);
} catch (Throwable $e) {
    $deny('id_token rejected: ' . $e->getMessage());
}

/* Identity comes from the VALIDATED id_token. The userinfo endpoint is only
 * consulted to fill in display details, and never to decide who this is — its
 * response is an unsigned document that previously WAS the whole identity. */
$email = strtolower(trim((string) ($claims['email'] ?? '')));
$sub   = (string) $claims['sub'];

$userInfo = [];
if (defined('SSO_USERINFO_URL') && SSO_USERINFO_URL && !empty($tokens['access_token'])) {
    try {
        $ch = curl_init(SSO_USERINFO_URL);
        curl_setopt_array($ch, oidc_curl_options() + [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $tokens['access_token']],
        ]);
        $body = curl_exec($ch);
        curl_close($ch);
        $decoded = is_string($body) ? json_decode($body, true) : null;
        if (is_array($decoded) && (string) ($decoded['sub'] ?? '') === $sub) {
            $userInfo = $decoded;              // same subject → safe to use for names
            if ($email === '') $email = strtolower(trim((string) ($userInfo['email'] ?? '')));
        }
    } catch (Throwable $e) {
        error_log('SSO userinfo lookup failed (non-fatal): ' . $e->getMessage());
    }
}

if ($email === '') $deny('no verified email on the identity');

/* Where the provider says so, insist the address is verified — otherwise an IdP
 * that lets users type any address would let them claim someone else's account. */
if (array_key_exists('email_verified', $claims) && !filter_var($claims['email_verified'], FILTER_VALIDATE_BOOLEAN)) {
    $deny('provider reports the email address is not verified');
}

// ── Find the account ─────────────────────────────────────────────────────────
$uStmt = db()->prepare(
    'SELECT u.*, r.name AS role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id
      WHERE u.email = ? AND u.is_active = 1'
);
$uStmt->execute([$email]);
$user = $uStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    /* Auto-provisioning is opt-in. It used to create an account for ANY address
     * the provider returned, which — with the transport unverified and the token
     * unchecked — meant an attacker on the wire could mint themselves a login. */
    $autoCreate = defined('SSO_AUTO_CREATE') ? (bool) SSO_AUTO_CREATE : false;
    if (!$autoCreate) $deny('no local account for ' . $email . ' and auto-create is off');

    $allowed = defined('SSO_ALLOWED_DOMAINS') ? array_filter(array_map('trim', explode(',', (string) SSO_ALLOWED_DOMAINS))) : [];
    if ($allowed) {
        $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));
        if (!in_array($domain, array_map('strtolower', $allowed), true)) $deny('domain not allowed: ' . $domain);
    }

    $first = (string) ($claims['given_name']  ?? $userInfo['given_name']  ?? explode('@', $email)[0]);
    $last  = (string) ($claims['family_name'] ?? $userInfo['family_name'] ?? '');
    $full  = trim($first . ' ' . $last) ?: $email;

    $defaultRole = db()->query("SELECT id FROM roles WHERE name='Employee' LIMIT 1")->fetchColumn();
    if (!$defaultRole) $deny('no Employee role exists to assign');

    db()->prepare('INSERT INTO users (email,name,role_id,is_active,sso_uid,created_at) VALUES (?,?,?,1,?,NOW())')
        ->execute([$email, $full, $defaultRole, $sub]);
    $uid = (int) db()->lastInsertId();

    $uStmt = db()->prepare(
        'SELECT u.*, r.name AS role_name FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id = ?'
    );
    $uStmt->execute([$uid]);
    $user = $uStmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) $deny('account creation failed');
    activity_log('created', 'User', 'Created user from SSO: ' . $email);
}

/* Bind the local account to this provider subject. If it is already bound to a
 * DIFFERENT subject, someone else now controls that address at the IdP — refuse
 * rather than silently hand over the existing account. */
$known = (string) ($user['sso_uid'] ?? '');
if ($known !== '' && !hash_equals($known, $sub)) $deny('SSO subject changed for ' . $email);
if ($known === '') db()->prepare('UPDATE users SET sso_uid = ? WHERE id = ?')->execute([$sub, (int) $user['id']]);

unset($user['password_hash']);
login_user($user);                       // regenerates the session id
db()->prepare('UPDATE users SET last_login=NOW() WHERE id=?')->execute([(int) $user['id']]);
activity_log('updated', 'Auth', 'Signed in via SSO');

flash('success', 'Welcome back, ' . ($user['name'] ?? $user['email']) . '! (SSO Login)');
redirect(BASE_URL . '/index.php');
