<?php
require_once '../../includes/bootstrap.php';

if (!SSO_ENABLED) {
    redirect(BASE_URL . '/login.php');
}

$state = bin2hex(random_bytes(16));
$nonce = bin2hex(random_bytes(16));

/* The nonce used to be minted, sent to the provider, and then dropped on the
 * floor — only the state was kept, so nothing could ever check that the returned
 * id_token belonged to THIS login attempt. Both are stored now, with the time,
 * so callback.php can bind the response back to this request and expire a login
 * that was started and never finished (security audit M-14). */
$_SESSION['sso_state'] = $state;
$_SESSION['sso_nonce'] = $nonce;
$_SESSION['sso_at']    = time();

$params = http_build_query([
    'response_type' => 'code',
    'client_id'     => SSO_CLIENT_ID,
    'redirect_uri'  => SSO_REDIRECT_URI,
    'scope'         => defined('SSO_SCOPE') && SSO_SCOPE ? SSO_SCOPE : 'openid email profile',
    'state'         => $state,
    'nonce'         => $nonce,
]);

header('Location: ' . SSO_AUTH_URL . '?' . $params);
exit;
