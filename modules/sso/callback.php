<?php
require_once '../../includes/bootstrap.php';

if (!SSO_ENABLED) redirect(BASE_URL . '/login.php');

$code  = $_GET['code']  ?? '';
$state = $_GET['state'] ?? '';
$error = $_GET['error'] ?? '';

if ($error) {
    flash('danger', 'SSO error: ' . htmlspecialchars($error));
    redirect(BASE_URL . '/login.php');
}

if (!$code || $state !== ($_SESSION['sso_state'] ?? '')) {
    flash('danger', 'Invalid SSO state. Please try again.');
    redirect(BASE_URL . '/login.php');
}
unset($_SESSION['sso_state']);

// Exchange code for token
$tokenData = [
    'grant_type'    => 'authorization_code',
    'code'          => $code,
    'redirect_uri'  => SSO_REDIRECT_URI,
    'client_id'     => SSO_CLIENT_ID,
    'client_secret' => SSO_CLIENT_SECRET,
];

$ch = curl_init(SSO_TOKEN_URL);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($tokenData),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_SSL_VERIFYPEER => APP_ENV === 'production',
]);
$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    flash('danger', 'SSO token exchange failed.');
    redirect(BASE_URL . '/login.php');
}

$tokens    = json_decode($response, true);
$accessToken = $tokens['access_token'] ?? '';

// Get user info
$ch = curl_init(SSO_USERINFO_URL);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ["Authorization: Bearer $accessToken"],
    CURLOPT_SSL_VERIFYPEER => APP_ENV === 'production',
]);
$userInfoJson = curl_exec($ch);
curl_close($ch);
$userInfo = json_decode($userInfoJson, true);

if (!$userInfo || empty($userInfo['email'])) {
    flash('danger', 'Could not retrieve user info from SSO provider.');
    redirect(BASE_URL . '/login.php');
}

// Find or sync user
$email = strtolower(trim($userInfo['email']));
$user  = db()->query("SELECT u.*, r.name AS role FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.email='".addslashes($email)."' AND u.is_active=1")->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    // Auto-create from SSO if enabled
    $firstName = $userInfo['given_name'] ?? explode('@',$email)[0];
    $lastName  = $userInfo['family_name'] ?? '';
    $fullName  = trim("$firstName $lastName") ?: $email;
    $defaultRole = db()->query("SELECT id FROM roles WHERE name='Employee' LIMIT 1")->fetchColumn();
    if (!$defaultRole) {
        flash('danger', 'No matching account found for this SSO identity.');
        redirect(BASE_URL . '/login.php');
    }
    db()->prepare("INSERT INTO users (email,name,role_id,is_active,sso_uid,created_at)
        VALUES (:em,:nm,:rid,1,:sid,NOW())")
        ->execute([':em'=>$email,':nm'=>$fullName,':rid'=>$defaultRole,':sid'=>$userInfo['sub']??'']);
    $uid = db()->lastInsertId();
    $user = db()->query("SELECT u.*, r.name AS role FROM users u LEFT JOIN roles r ON u.role_id = r.id WHERE u.id=$uid")->fetch(PDO::FETCH_ASSOC);
}

// Log in
login_user($user);
db()->prepare("UPDATE users SET last_login=NOW() WHERE id=:id")->execute([':id'=>$user['id']]);

flash('success', 'Welcome back, ' . ($user['name'] ?? $user['email']) . '! (SSO Login)');
redirect(BASE_URL . '/index.php');
