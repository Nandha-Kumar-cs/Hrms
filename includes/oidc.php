<?php
/**
 * MagDyn HRMS — OpenID Connect helpers (security audit M-14).
 *
 * The SSO flow used to trust two things it had no business trusting:
 *
 *   • The TRANSPORT. Both curl calls set
 *         CURLOPT_SSL_VERIFYPEER => APP_ENV === 'production'
 *     and the shipped config says APP_ENV = 'development', so certificates were
 *     not checked. The token exchange posts SSO_CLIENT_SECRET, so anyone able to
 *     intercept that connection could take the client secret and mint their own
 *     tokens — and the userinfo response, which is the sole source of identity,
 *     could simply be rewritten in flight.
 *
 *   • The ID TOKEN. initiate.php minted a nonce, sent it to the provider, and
 *     then threw it away — it was never stored, so nothing could ever check it.
 *     The id_token itself was never read at all: no signature check, no issuer,
 *     no audience, no expiry. Identity came only from the userinfo endpoint.
 *
 * Everything here fails CLOSED: any doubt raises RuntimeException, and the
 * caller turns that into a refused login.
 */

/** Decode base64url (JWT segments) without the padding/alphabet surprises. */
function oidc_b64url_decode(string $in): string
{
    $pad = strlen($in) % 4;
    if ($pad) $in .= str_repeat('=', 4 - $pad);
    $out = base64_decode(strtr($in, '-_', '+/'), true);
    if ($out === false) throw new RuntimeException('malformed base64url segment');
    return $out;
}

/**
 * curl options shared by every call this app makes to the identity provider.
 *
 * TLS verification is ALWAYS on — it is not conditional on APP_ENV, because a
 * development flag is not a reason to hand the client secret to whoever is on
 * the wire. A private/corporate CA is supported the correct way, by pointing
 * SSO_CA_BUNDLE at the CA file, not by switching verification off.
 */
function oidc_curl_options(): array
{
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,   // validate the chain
        CURLOPT_SSL_VERIFYHOST => 2,      // and that the host matches the cert
        CURLOPT_FOLLOWLOCATION => false,  // never follow a redirect to elsewhere
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,   // https only, no http/file/gopher
        CURLOPT_REDIR_PROTOCOLS=> CURLPROTO_HTTPS,
    ];
    if (defined('SSO_CA_BUNDLE') && SSO_CA_BUNDLE && is_file(SSO_CA_BUNDLE)) {
        $opts[CURLOPT_CAINFO] = SSO_CA_BUNDLE;
    }
    return $opts;
}

/** GET a URL from the provider with verification on. Returns the body. */
function oidc_http_get(string $url): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, oidc_curl_options());
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false)  throw new RuntimeException('request to provider failed: ' . $err);
    if ($code !== 200)    throw new RuntimeException('provider returned HTTP ' . $code);
    return (string) $body;
}

/**
 * Fetch the provider's signing keys (JWKS), cached briefly in the session so a
 * login does not re-fetch them on every hop but key rotation is still picked up.
 */
function oidc_jwks(string $jwksUrl): array
{
    $slot = 'oidc_jwks_' . sha1($jwksUrl);
    if (isset($_SESSION[$slot]['at'], $_SESSION[$slot]['keys'])
        && (time() - (int) $_SESSION[$slot]['at']) < 3600) {
        return $_SESSION[$slot]['keys'];
    }
    $doc  = json_decode(oidc_http_get($jwksUrl), true);
    $keys = is_array($doc) && isset($doc['keys']) && is_array($doc['keys']) ? $doc['keys'] : [];
    if (!$keys) throw new RuntimeException('provider returned no signing keys');
    $_SESSION[$slot] = ['at' => time(), 'keys' => $keys];
    return $keys;
}

/** Build an OpenSSL public key from a JWK's RSA modulus/exponent. */
function oidc_jwk_to_pem(array $jwk): string
{
    if (($jwk['kty'] ?? '') !== 'RSA' || empty($jwk['n']) || empty($jwk['e'])) {
        throw new RuntimeException('unsupported signing key type');
    }
    // Minimal DER for SEQUENCE(INTEGER n, INTEGER e), wrapped as an RSA public key.
    $der = static function (string $bytes): string {
        if (ord($bytes[0]) > 0x7f) $bytes = "\x00" . $bytes;   // keep it positive
        $len = strlen($bytes);
        $hdr = $len < 0x80 ? chr($len)
             : ($len < 0x100 ? "\x81" . chr($len)
             : "\x82" . chr($len >> 8) . chr($len & 0xff));
        return "\x02" . $hdr . $bytes;
    };
    $seq  = $der(oidc_b64url_decode($jwk['n'])) . $der(oidc_b64url_decode($jwk['e']));
    $len  = strlen($seq);
    $hdr  = $len < 0x80 ? chr($len)
          : ($len < 0x100 ? "\x81" . chr($len)
          : "\x82" . chr($len >> 8) . chr($len & 0xff));
    $rsa  = "\x30" . $hdr . $seq;

    // Wrap in SubjectPublicKeyInfo with the rsaEncryption OID.
    $algo = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
    $bit  = "\x03" . (function (int $l) {
                return $l + 1 < 0x80 ? chr($l + 1)
                     : ($l + 1 < 0x100 ? "\x81" . chr($l + 1)
                     : "\x82" . chr(($l + 1) >> 8) . chr(($l + 1) & 0xff));
            })(strlen($rsa)) . "\x00" . $rsa;
    $spkiBody = $algo . $bit;
    $l = strlen($spkiBody);
    $spkiHdr = $l < 0x80 ? chr($l)
             : ($l < 0x100 ? "\x81" . chr($l)
             : "\x82" . chr($l >> 8) . chr($l & 0xff));
    $spki = "\x30" . $spkiHdr . $spkiBody;

    return "-----BEGIN PUBLIC KEY-----\n"
         . chunk_split(base64_encode($spki), 64, "\n")
         . "-----END PUBLIC KEY-----\n";
}

/**
 * Verify an id_token and return its claims.
 *
 * $expect = ['issuer' => …, 'audience' => …, 'nonce' => …, 'jwks_url' => …]
 * Throws RuntimeException on ANY failure — never returns partial trust.
 */
function oidc_verify_id_token(string $jwt, array $expect, ?int $now = null): array
{
    $now = $now ?? time();
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) throw new RuntimeException('id_token is not a JWS');

    $header = json_decode(oidc_b64url_decode($parts[0]), true);
    $claims = json_decode(oidc_b64url_decode($parts[1]), true);
    if (!is_array($header) || !is_array($claims)) throw new RuntimeException('id_token segments are not JSON');

    /* Algorithm confusion is the classic JWT break: "none" skips signing
     * entirely, and an HMAC alg would let a forger sign with the provider's
     * PUBLIC key as the shared secret. Only asymmetric RSA is accepted. */
    $alg = strtoupper((string) ($header['alg'] ?? ''));
    $map = ['RS256' => OPENSSL_ALGO_SHA256, 'RS384' => OPENSSL_ALGO_SHA384, 'RS512' => OPENSSL_ALGO_SHA512];
    if (!isset($map[$alg])) throw new RuntimeException('unacceptable id_token algorithm: ' . ($header['alg'] ?? 'none'));

    // Pick the advertised key; fall back to trying each only when no kid is given.
    $keys = oidc_jwks((string) $expect['jwks_url']);
    $kid  = (string) ($header['kid'] ?? '');
    $cand = [];
    foreach ($keys as $k) {
        if ($kid !== '' && (string) ($k['kid'] ?? '') !== $kid) continue;
        $cand[] = $k;
    }
    if (!$cand) throw new RuntimeException('no signing key matches the id_token kid');

    $signed = $parts[0] . '.' . $parts[1];
    $sig    = oidc_b64url_decode($parts[2]);
    $okSig  = false;
    foreach ($cand as $k) {
        $pem = oidc_jwk_to_pem($k);
        if (openssl_verify($signed, $sig, $pem, $map[$alg]) === 1) { $okSig = true; break; }
    }
    if (!$okSig) throw new RuntimeException('id_token signature is not valid');

    // Issuer / audience / lifetime / nonce — all mandatory.
    if ((string) ($claims['iss'] ?? '') !== (string) $expect['issuer'])
        throw new RuntimeException('id_token issuer mismatch');

    $aud = $claims['aud'] ?? '';
    $aud = is_array($aud) ? $aud : [$aud];
    if (!in_array((string) $expect['audience'], array_map('strval', $aud), true))
        throw new RuntimeException('id_token audience mismatch');

    // azp must name us when the token was issued for several audiences.
    if (count($aud) > 1 && (string) ($claims['azp'] ?? '') !== (string) $expect['audience'])
        throw new RuntimeException('id_token azp mismatch');

    $leeway = 60;   // tolerate small clock skew, nothing more
    if (!isset($claims['exp']) || $now >= ((int) $claims['exp'] + $leeway))
        throw new RuntimeException('id_token has expired');
    if (isset($claims['nbf']) && $now < ((int) $claims['nbf'] - $leeway))
        throw new RuntimeException('id_token is not valid yet');
    if (isset($claims['iat']) && $now < ((int) $claims['iat'] - $leeway))
        throw new RuntimeException('id_token was issued in the future');

    $expectNonce = (string) ($expect['nonce'] ?? '');
    if ($expectNonce === '') throw new RuntimeException('no nonce was recorded for this login');
    if (!hash_equals($expectNonce, (string) ($claims['nonce'] ?? '')))
        throw new RuntimeException('id_token nonce does not match this login attempt');

    if (empty($claims['sub'])) throw new RuntimeException('id_token has no subject');

    return $claims;
}
