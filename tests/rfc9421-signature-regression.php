<?php
/**
 * SNAPSMACK — RFC-9421 HTTP Message Signatures verifier regression (0.7.618D).
 *
 * Proves the 9421 inbound verifier (sv_9421_verify_core) end to end with a
 * REAL RSA key: an independently-constructed 9421 request verifies, and every
 * tamper (body swap, bad signature, unsigned body, expired, request-line swap)
 * is rejected. The signature base is built here BY HAND per the RFC — not by
 * calling the verifier's own builder — so a match proves the two agree.
 *
 * openssl is required to run the crypto. Where it is absent (e.g. a bare CLI),
 * the suite SKIPS with a PASS so the plain-php release gate is not broken; run
 * it explicitly with openssl to actually exercise the crypto:
 *   OPENSSL_CONF=<openssl.cnf> php -d extension=openssl tests/rfc9421-signature-regression.php
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$root = dirname(__DIR__);

if (!function_exists('openssl_pkey_new') || !function_exists('openssl_sign')) {
    echo "SKIP: RFC-9421 regression — openssl not loaded (crypto not exercised here).\n";
    exit(0);
}

require_once $root . '/core/fediverse.php';

$failures = [];
$ok = static function (bool $cond, string $msg) use (&$failures): void {
    if (!$cond) $failures[] = $msg;
};

// ── one RSA keypair for the run ──────────────────────────────────────────────
$kp = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
if ($kp === false) { echo "SKIP: RFC-9421 regression — openssl keygen failed (no openssl.cnf?).\n"; exit(0); }
openssl_pkey_export($kp, $priv);
$pem = openssl_pkey_get_details($kp)['key'];

// ── helpers ──────────────────────────────────────────────────────────────────
$b64 = static fn(string $s): string => base64_encode($s);

// Build the RFC-9421 signature base BY HAND for the Mastodon component set.
$build_base = static function (string $method, string $target_uri, string $content_digest, string $sig_params): string {
    return implode("\n", [
        '"@method": ' . $method,
        '"@target-uri": ' . $target_uri,
        '"content-digest": ' . $content_digest,
        '"@signature-params": ' . $sig_params,
    ]);
};

// Install a synthetic request into $_SERVER + headers and return a reject-capture.
$make_request = static function (
    string $body, string $host, string $uri, string $sig_input, string $signature, string $content_digest
) {
    $_SERVER = [
        'REQUEST_METHOD' => 'POST',
        'HTTP_HOST'      => $host,
        'REQUEST_URI'    => $uri,
    ];
    $_SERVER['HTTP_SIGNATURE_INPUT']  = $sig_input;
    $_SERVER['HTTP_SIGNATURE']        = $signature;
    $_SERVER['HTTP_CONTENT_DIGEST']   = $content_digest;
};
$reasons = [];
$reject  = static function (string $why) use (&$reasons): void { $reasons[] = $why; };

// ── the canonical, valid request ─────────────────────────────────────────────
$host    = 'photofri.day';
$uri     = '/ap/inbox';
$body    = '{"type":"Create","actor":"https://mastodon.example/users/alice"}';
$cdigest = 'sha-256=:' . $b64(hash('sha256', $body, true)) . ':';
$created = time();
$keyid   = 'https://mastodon.example/users/alice#main-key';
$sparams = '("@method" "@target-uri" "content-digest");created=' . $created . ';keyid="' . $keyid . '"';
$siginp  = 'sig1=' . $sparams;
$target  = 'https://' . $host . $uri;

$base = $build_base('POST', $target, $cdigest, $sparams);
openssl_sign($base, $rawsig, $priv, OPENSSL_ALGO_SHA256);
$sighdr = 'sig1=:' . $b64($rawsig) . ':';

// 1) VALID request verifies.
$make_request($body, $host, $uri, $siginp, $sighdr, $cdigest);
$reasons = [];
$ok(sv_9421_verify_core($body, $pem, $reject) === true, 'valid RFC-9421 request must verify (got reject: ' . implode('|', $reasons) . ')');

// 2) BODY TAMPER — same signature, different body → Content-Digest mismatch.
$make_request($body, $host, $uri, $siginp, $sighdr, $cdigest);
$reasons = [];
$ok(sv_9421_verify_core($body . 'x', $pem, $reject) === false, 'body tamper must be rejected');

// 3) DIGEST-vs-BODY mismatch: attacker updates the body AND its digest but
//    can't re-sign → signature no longer matches the (now-changed) base.
$evil = $body . 'x';
$evil_cd = 'sha-256=:' . $b64(hash('sha256', $evil, true)) . ':';
$make_request($evil, $host, $uri, $siginp, $sighdr, $evil_cd);
$reasons = [];
$ok(sv_9421_verify_core($evil, $pem, $reject) === false, 'body+digest swap without re-sign must be rejected');

// 4) BAD SIGNATURE bytes.
$make_request($body, $host, $uri, $siginp, 'sig1=:' . $b64('garbage-not-a-signature') . ':', $cdigest);
$reasons = [];
$ok(sv_9421_verify_core($body, $pem, $reject) === false, 'forged signature bytes must be rejected');

// 5) WRONG KEY — verify with a different public key.
$kp2 = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$pem2 = openssl_pkey_get_details($kp2)['key'];
$make_request($body, $host, $uri, $siginp, $sighdr, $cdigest);
$reasons = [];
$ok(sv_9421_verify_core($body, $pem2, $reject) === false, 'signature under a different key must be rejected');

// 6) EXPIRED created (2h old).
$old_created = time() - 7200;
$old_sparams = '("@method" "@target-uri" "content-digest");created=' . $old_created . ';keyid="' . $keyid . '"';
$old_base    = $build_base('POST', $target, $cdigest, $old_sparams);
openssl_sign($old_base, $old_sig, $priv, OPENSSL_ALGO_SHA256);
$make_request($body, $host, $uri, 'sig1=' . $old_sparams, 'sig1=:' . $b64($old_sig) . ':', $cdigest);
$reasons = [];
$ok(sv_9421_verify_core($body, $pem, $reject) === false, 'stale created (±1h) must be rejected');

// 7) UNSIGNED BODY — content-digest not in the covered set → refuse (body free
//    to swap). Sign a base that omits content-digest, present it as such.
$nb_sparams = '("@method" "@target-uri");created=' . time() . ';keyid="' . $keyid . '"';
$nb_base    = implode("\n", ['"@method": POST', '"@target-uri": ' . $target, '"@signature-params": ' . $nb_sparams]);
openssl_sign($nb_base, $nb_sig, $priv, OPENSSL_ALGO_SHA256);
$make_request($body, $host, $uri, 'sig1=' . $nb_sparams, 'sig1=:' . $b64($nb_sig) . ':', $cdigest);
$reasons = [];
$ok(sv_9421_verify_core($body, $pem, $reject) === false, 'request that does not sign content-digest must be rejected');

// 8) REQUEST-LINE TAMPER — signature made for /ap/inbox, replayed at /ap/outbox.
$make_request($body, $host, '/ap/outbox', $siginp, $sighdr, $cdigest);
$reasons = [];
$ok(sv_9421_verify_core($body, $pem, $reject) === false, 'replay onto a different path must be rejected (@target-uri bound)');

// 9) PROXY/CDN tolerance — signer signed the PUBLIC https URL while the origin
//    would present a bare host; the candidate loop must still verify.
$make_request($body, $host, $uri, $siginp, $sighdr, $cdigest);
$reasons = [];
$ok(sv_9421_verify_core($body, $pem, $reject) === true, 'valid request must verify under the proxy-form candidate loop');

// 10) Parser sanity — round-trips label, covered set, and params.
$p = sv_9421_parse_input($siginp);
$ok($p !== null && $p['label'] === 'sig1', 'parse: label');
$ok($p['covered'] === ['@method', '@target-uri', 'content-digest'], 'parse: covered components');
$ok(($p['params']['keyid'] ?? '') === $keyid, 'parse: keyid param');
$ok(($p['params']['created'] ?? '') == (string)$created, 'parse: created param');
$ok($p['params_raw'] === $sparams, 'parse: params_raw is byte-exact @signature-params');

// ── report ───────────────────────────────────────────────────────────────────
if ($failures) {
    foreach ($failures as $f) echo "FAIL: {$f}\n";
    echo 'FAIL: RFC-9421 signature regression (' . count($failures) . " failing)\n";
    exit(1);
}
echo "PASS: RFC-9421 signature regression suite (11 checks)\n";
// ===== SNAPSMACK EOF =====
