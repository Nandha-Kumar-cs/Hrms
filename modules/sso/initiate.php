<?php
require_once '../../includes/bootstrap.php';

if (!SSO_ENABLED) {
    redirect(BASE_URL . '/login.php');
}

$state    = bin2hex(random_bytes(16));
$nonce    = bin2hex(random_bytes(16));
$_SESSION['sso_state'] = $state;

$params = http_build_query([
    'response_type' => 'code',
    'client_id'     => SSO_CLIENT_ID,
    'redirect_uri'  => SSO_REDIRECT_URI,
    'scope'         => 'openid email profile',
    'state'         => $state,
    'nonce'         => $nonce,
]);

header('Location: ' . SSO_AUTH_URL . '?' . $params);
exit;
