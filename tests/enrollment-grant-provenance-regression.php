<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * SECAUDIT 061: database-write authority must not be able to enroll a spoke.
 *
 * WHAT HAPPENED. On 2026-10-19 a site joined the fleet with no password and no
 * TOTP code. The admin page did require step-up before generating a
 * registration token, and the handshake did consume the token exactly once.
 * The hole was that the token was a bare random string in
 * snap_settings.multisite_reg_token, so the row was BOTH the registration
 * state AND the only evidence that an administrator had authenticated.
 * Writing that row directly manufactured the authorization.
 *
 * This is a MUTATION test, which is what Sean asked for: every check below is
 * proved to FIRE by feeding it the thing it is supposed to refuse. A test that
 * only shows the happy path passing cannot tell you whether the gate exists.
 *
 * WHAT IT DOES NOT PROVE, said plainly because the audit was opened about an
 * overclaim. This is not root-proof. Anyone who can read this server's
 * filesystem can read the MAC key and mint a grant, exactly as they could read
 * core/db.php and take the database. SECAUDIT 061 section 4 names that as the
 * host trust boundary. What is closed here is section 4 layer one: DATABASE-
 * WRITE RESISTANCE, which is the path that was actually demonstrated.
 *
 * The verifier is pure and is EXECUTED below. The handshake and the audit
 * insert need a live database, so those are asserted against the SOURCE and
 * labelled as such -- a source assertion is weaker than an executed one and
 * this test does not pretend otherwise.
 *
 * Covers SECAUDIT 061 section 6 requirements 1, 2, 3, 4, 6 and 9.
 */

$root = dirname(__DIR__);
require_once $root . '/core/enrollment-grant.php';

$pass = 0;
$fail = 0;

function eg_test(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

const EG_SPOKE = 'https://hal.photoblogs.fyi';
const EG_HUB   = 'https://foundtextures.ca';

// The real install's key must survive this test run untouched.
$keyPath = snap_enrollment_key_path();
$keyPreexisted = is_file($keyPath);

echo "The MAC key lives in a file, not in the database\n";
$issued = snap_enrollment_grant_issue(EG_SPOKE, EG_HUB, 7, time());
eg_test(($issued['ok'] ?? false) === true,
    'a grant is issued after step-up: ' . ($issued['error'] ?? ''));
eg_test(is_file($keyPath), 'the key file exists after issuance');
$keyFileRaw = is_file($keyPath) ? (string) file_get_contents($keyPath) : '';
eg_test(str_starts_with($keyFileRaw, '<?php'),
    'the key file is PHP, so fetching it over HTTP executes and prints nothing');
eg_test(strpos($keyFileRaw, 'return') !== false, 'and it returns the key rather than printing it');
$key = snap_enrollment_key(false);
eg_test(preg_match('/^[0-9a-f]{64}$/', $key) === 1, 'the key is 32 bytes of hex');

$gitignore = (string) file_get_contents($root . '/.gitignore');
eg_test(strpos($gitignore, 'core/enrollment-key.php') !== false,
    'the key file is gitignored, so no two installs can share a signing authority');

// Sign arbitrary claims with the REAL key, so expiry and field mutations can
// be tested as a correctly-MACed grant rather than only as a bad MAC.
function eg_sign(array $claims, string $key): string {
    $body = rtrim(strtr(base64_encode((string) json_encode($claims, JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');
    $mac  = rtrim(strtr(base64_encode(hash_hmac('sha256', $body, $key, true)), '+/', '-_'), '=');
    return SNAP_ENROLLMENT_GRANT_PREFIX . $body . '.' . $mac;
}
function eg_claims(array $over = []): array {
    $now = time();
    return array_merge([
        'ver' => 1, 'act' => 'fleet.enroll', 'nonce' => bin2hex(random_bytes(16)),
        'spoke' => EG_SPOKE, 'hub' => EG_HUB, 'admin' => 7,
        'stepup_at' => $now, 'iat' => $now, 'exp' => $now + 900,
    ], $over);
}
$expect = ['spoke' => EG_SPOKE, 'hub' => EG_HUB];

echo "\nREQUIREMENT 2: a correctly signed grant succeeds\n";
$grant = (string)($issued['grant'] ?? '');
$v = snap_enrollment_grant_verify($grant, $expect);
eg_test(($v['ok'] ?? false) === true, 'the grant this install minted verifies here');
eg_test((string)($v['claims']['act'] ?? '') === 'fleet.enroll',
    'and it permits exactly one action, fleet.enroll');

echo "\nREQUIREMENTS 1 and 9: database-write authority alone manufactures nothing\n";
// This is the demonstrated attack, reproduced. An operator with MariaDB access
// writes whatever they like into the settings row. None of it is a grant.
$writable = [
    'a fresh random token of the old shape' => bin2hex(random_bytes(16)),
    'a 64-hex value'                        => bin2hex(random_bytes(32)),
    'the literal word token'                => 'token',
    'an empty value'                        => '',
    'a digest of a real grant'              => snap_enrollment_grant_digest($grant),
    'a grant-shaped string with no MAC'     => SNAP_ENROLLMENT_GRANT_PREFIX . 'eyJhIjoxfQ',
];
foreach ($writable as $label => $value) {
    $r = snap_enrollment_grant_verify($value, $expect);
    eg_test(($r['ok'] ?? true) === false, "refused: {$label}");
}
// And the key really is what stops them: the same claims signed with a key an
// attacker chose are refused, which is the whole of finding A.
$forged = eg_sign(eg_claims(), bin2hex(random_bytes(32)));
$r = snap_enrollment_grant_verify($forged, $expect);
eg_test(($r['ok'] ?? true) === false && $r['reason'] === 'bad_mac',
    'a grant signed with an attacker-chosen key is refused on the MAC');

echo "\nREQUIREMENT 3: mutating any bound field invalidates the grant\n";
// Correctly signed with the REAL key, one field changed each time. These are
// not bad MACs -- they are valid signatures over the wrong claims, which is
// what an attacker who stole a grant for one hub would present to another.
$mutations = [
    'hub_mismatch'   => ['hub'   => 'https://someone-elses-hub.example'],
    'spoke_mismatch' => ['spoke' => 'https://a-different-spoke.example'],
    'bad_action'     => ['act'   => 'fleet.update'],
    'bad_version'    => ['ver'   => 2],
    'no_admin'       => ['admin' => 0],
    'bad_nonce'      => ['nonce' => 'not-a-nonce'],
    'expired'        => ['exp'   => time() - 1],
    'not_yet_valid'  => ['iat'   => time() + 3600],
];
foreach ($mutations as $wantReason => $over) {
    $m = eg_sign(eg_claims($over), $key);
    $r = snap_enrollment_grant_verify($m, $expect);
    eg_test(($r['ok'] ?? true) === false && ($r['reason'] ?? '') === $wantReason,
        "mutating " . array_key_first($over) . " is refused as {$wantReason}"
        . (($r['reason'] ?? '') === $wantReason ? '' : ' (got ' . ($r['reason'] ?? '?') . ')'));
}
// Tampering the payload without re-signing must die on the MAC, not on a field.
$parts = explode('.', substr($grant, strlen(SNAP_ENROLLMENT_GRANT_PREFIX)));
$tampered = SNAP_ENROLLMENT_GRANT_PREFIX
    . rtrim(strtr(base64_encode((string) json_encode(eg_claims(['admin' => 99]))), '+/', '-_'), '=')
    . '.' . ($parts[1] ?? '');
$r = snap_enrollment_grant_verify($tampered, $expect);
eg_test(($r['ok'] ?? true) === false && ($r['reason'] ?? '') === 'bad_mac',
    'swapping the payload under an untouched MAC is refused on the MAC');

echo "\nREQUIREMENT 4: an expired grant fails, and so does one with no key present\n";
$expired = eg_sign(eg_claims(['exp' => time() - 1, 'iat' => time() - 902]), $key);
eg_test((snap_enrollment_grant_verify($expired, $expect)['ok'] ?? true) === false,
    'an expired grant is refused even though its MAC is perfect');
// Verification must never mint a key: a caller who can delete the file would
// otherwise get to install an authority of their own choosing.
$stashed = $keyFileRaw;
@unlink($keyPath);
$r = snap_enrollment_grant_verify($grant, $expect);
eg_test(($r['ok'] ?? true) === false && ($r['reason'] ?? '') === 'no_key',
    'with the key file removed, verification fails closed instead of minting a new key');
eg_test(!is_file($keyPath), 'and verification did NOT create a key file');
if ($stashed !== '') { @file_put_contents($keyPath, $stashed); }

echo "\nThe caller learns nothing from the refusal\n";
// A prober must not be able to tell "no grant outstanding" from "wrong MAC".
$messages = [];
foreach (['', 'garbage', $forged, $expired, SNAP_ENROLLMENT_GRANT_PREFIX . 'x.y'] as $probe) {
    $messages[] = (string) snap_enrollment_grant_verify($probe, $expect)['error'];
}
eg_test(count(array_unique($messages)) === 1,
    'every rejection returns one identical message, whatever the real reason');

echo "\nThe stored row holds a digest, and a digest cannot enroll\n";
$digest = snap_enrollment_grant_digest($grant);
eg_test(str_starts_with($digest, SNAP_ENROLLMENT_DIGEST_PREFIX),
    'the digest is prefixed, so a legacy token is distinguishable at a glance');
eg_test(strpos($digest, $grant) === false, 'the digest does not contain the grant');
eg_test(strlen($grant) > 0 && $digest !== $grant, 'and is not merely the grant relabelled');
eg_test((snap_enrollment_grant_verify($digest, $expect)['ok'] ?? true) === false,
    'presenting the digest itself does not enroll');

echo "\nSOURCE ASSERTIONS -- these need a live database to execute, and are read\n";
$api   = (string) file_get_contents($root . '/core/multisite-api.php');
$admin = (string) file_get_contents($root . '/smack-multisite.php');

$verifyAt = strpos($api, 'snap_enrollment_grant_verify(');
$claimAt  = strpos($api, "UPDATE snap_settings SET setting_val = '' WHERE setting_key = 'multisite_reg_token'");
eg_test($verifyAt !== false, 'the handshake calls the verifier');
eg_test($verifyAt !== false && $claimAt !== false && $verifyAt < $claimAt,
    'and verifies the MAC BEFORE it compares anything with the database');
eg_test($verifyAt !== false && strpos($api, "'spoke' => BASE_URL") !== false
        && strpos($api, "'hub'   => \$remote_url") !== false,
    'it binds the grant to this spoke and to the calling hub, not just to a value');
eg_test(strpos($api, '$claim->execute([$_digest])') !== false,
    'the one-time claim is made against the DIGEST, so a stolen grant cannot be replayed past it');
eg_test(strpos($admin, "\$stmt->execute(['multisite_reg_token', \$_issued['digest'], \$_issued['digest']])") !== false,
    'issuance writes the digest into snap_settings and never the grant');
eg_test(strpos($admin, "\$_SESSION['multisite_reg_token'] = \$_issued['grant']") !== false,
    'the grant exists only in the session of the admin who passed step-up');
eg_test(strpos($admin, 'reauth_verify($pdo') !== false
        && strpos($admin, 'snap_enrollment_grant_issue(') > strpos($admin, 'reauth_verify($pdo'),
    'and is minted only after step-up, not before it');
eg_test(strpos($admin, "name=\"grant_hub_url\"") !== false,
    'the admin names the hub the grant is for, so it cannot be presented elsewhere');

echo "\nREQUIREMENT 6: the audit records attempts and cannot hold a secret\n";
$store = (string) file_get_contents($root . '/core/enrollment-grant.php');
$tableStart = strpos($store, 'CREATE TABLE IF NOT EXISTS `snap_enrollment_audit`');
$tableSql = $tableStart === false ? '' : substr($store, $tableStart, 1800);
eg_test($tableSql !== '', 'the audit table is declared');
foreach (['api_key', 'token`', 'secret', 'password', '`grant`'] as $forbidden) {
    eg_test(stripos($tableSql, $forbidden) === false,
        "the audit table has no column that could hold a {$forbidden}");
}
eg_test(strpos($tableSql, 'grant_digest') !== false,
    'it identifies an attempt by digest, which cannot be used to enroll');
eg_test(substr_count($api, 'snap_enrollment_audit($pdo') >= 3,
    'the handshake records rejection, non-outstanding replay and success');
eg_test(strpos($admin, "snap_enrollment_audit(\$pdo, 'issued'") !== false,
    'and issuance is recorded too, so both halves of the enrollment are on the record');

$canonical = (string) file_get_contents($root . '/database/schema/snapsmack_canonical.sql');
eg_test(strpos($canonical, 'snap_enrollment_audit') !== false,
    'the table is in the canonical schema, so a fresh install has it');

// Leave the install exactly as it was found.
if (!$keyPreexisted && is_file($keyPath)) { @unlink($keyPath); }

echo "\n  {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "Enrollment grant provenance: FAIL ({$fail}).\n";
    exit(1);
}
echo "Enrollment grant provenance: PASS ({$pass} assertions).\n";

// ===== SNAPSMACK EOF =====
