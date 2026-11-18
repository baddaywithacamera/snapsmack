<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * MINTING AN API KEY NEEDS THE PASSWORD AND A 2FA CODE. SS-1410.
 *
 * An API key acts for this site from anywhere, for weeks or months. Until
 * 0.7.998D an authenticated admin session minted one with no further proof:
 * reach smack-api-keys.php with a live session and you got a key, of any
 * type. Three other actions on the SAME PAGE were already gated -- device
 * activation, bulk-import authorisation and offline posting all call
 * reauth_verify() -- and the key generator did not. Worse, the password and
 * 2FA boxes sitting visibly above it belong to ENABLE OFFLINE POSTING, so the
 * page LOOKED as though it asked. Sean found it on foundtextures.ca.
 *
 * TWO HALVES, AND THEY ARE LABELLED, because they prove different things.
 *
 * EXECUTED: core/reauth.php is run against a real (in-memory) user table with
 * a real TOTP secret, so the four credential outcomes are measured, not read.
 *
 * SOURCE: the handler in smack-api-keys.php needs a session, a database and a
 * POST to run, so what is proved there is structural -- that the gate is
 * called before any key material exists, that every path to the INSERT lies
 * inside the success branch, that no key type bypasses it, and that nothing
 * writes the password, the code or the plaintext key anywhere.
 */

$root = dirname(__DIR__);
$pass = 0;
$failures = [];

function ak_ok(bool $ok, string $what): void {
    global $pass, $failures;
    if ($ok) { $pass++; } else { $failures[] = $what; }
}

// ─────────────────────────────────────────────────────────────────────────
// PART 1 — EXECUTED. The helper, against a real table.
// ─────────────────────────────────────────────────────────────────────────
require_once $root . '/core/totp.php';
require_once $root . '/core/reauth.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE TABLE snap_users (id INTEGER PRIMARY KEY, password_hash TEXT,
            totp_enabled INTEGER DEFAULT 0, totp_secret TEXT, user_role TEXT)");
// SS-1399 gave reauth_verify a server-side failure budget in snap_rate_limits.
// Without the table the helper still works (it catches and degrades to the
// session budget) but it floods the log, and the cases below would be read
// against a half-working store.
$pdo->exec("CREATE TABLE snap_rate_limits (id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip TEXT NOT NULL, action TEXT NOT NULL, count INTEGER NOT NULL DEFAULT 1,
            window_start TEXT NOT NULL, UNIQUE (ip, action))");

/**
 * EACH CASE BELOW IS ITS OWN ATTEMPT, NOT THE SIXTH IN A ROW.
 *
 * SS-1399 locks the account after five failed step-ups, and this file tries
 * six wrong credentials on purpose. Without a reset between them the later
 * cases stop testing what they name and start testing the lockout -- which is
 * exactly what happened when SS-1399 landed: the unenrolled-account case
 * began returning "locked" instead of needs_2fa_enrollment, and the only
 * reason it was caught is that the assertion said what it expected.
 */
$fresh = static function (PDO $pdo): void {
    unset($_SESSION['reauth_fail_count'], $_SESSION['reauth_lock_until']);
    $pdo->exec("DELETE FROM snap_rate_limits");
};

$secret = totp_generate_secret();
$good = 'correct horse battery staple';
$hash = password_hash($good, PASSWORD_DEFAULT);

$seat = static function (PDO $pdo, bool $twoFactor, string $hash, string $secret): void {
    $pdo->exec('DELETE FROM snap_users');
    $pdo->prepare("INSERT INTO snap_users (id, password_hash, totp_enabled, totp_secret, user_role)
                   VALUES (1, ?, ?, ?, 'admin')")
        ->execute([$hash, $twoFactor ? 1 : 0, $twoFactor ? $secret : null]);
};

$_SESSION = ['user_id' => 1];

// --- correct password + valid code: the only way through -----------------
$seat($pdo, true, $hash, $secret);
$fresh($pdo);
$r = reauth_verify($pdo, $good, totp_code($secret));
ak_ok(($r['ok'] ?? false) === true,
    'a correct password and a current 2FA code were refused, so no key could '
    . 'ever be generated: ' . (string)($r['error'] ?? ''));

// --- wrong password ------------------------------------------------------
$fresh($pdo);
$r = reauth_verify($pdo, 'not the password', totp_code($secret));
ak_ok(($r['ok'] ?? true) === false, 'a wrong password was accepted');

// --- empty password ------------------------------------------------------
$fresh($pdo);
$r = reauth_verify($pdo, '', totp_code($secret));
ak_ok(($r['ok'] ?? true) === false, 'an empty password was accepted');

// --- right password, missing code ---------------------------------------
$fresh($pdo);
$r = reauth_verify($pdo, $good, '');
ak_ok(($r['ok'] ?? true) === false, 'a missing 2FA code was accepted');

// --- right password, wrong code -----------------------------------------
$wrong = str_pad((string)((((int)totp_code($secret)) + 1) % 1000000), 6, '0', STR_PAD_LEFT);
$fresh($pdo);
$r = reauth_verify($pdo, $good, $wrong);
ak_ok(($r['ok'] ?? true) === false, 'an invalid 2FA code was accepted');

// --- an expired code from an hour ago -----------------------------------
$fresh($pdo);
$r = reauth_verify($pdo, $good, totp_code($secret, -120));
ak_ok(($r['ok'] ?? true) === false, 'a code from an hour of steps ago was accepted');

// --- NO 2FA ENROLLED: refused, and told to enrol -------------------------
// Codex's acceptance matrix lists "correct password / no 2FA" as a case. On
// this product that case is a REFUSAL: core/reauth.php has had no
// password-only path since Sean's ruling of 2026-06-17, and the 30-day
// post-install 2FA grace covers LOGIN only. Asserted here so the policy is
// recorded rather than discovered by someone adding a bypass later.
$seat($pdo, false, $hash, $secret);
$fresh($pdo);
$r = reauth_verify($pdo, $good, '');
ak_ok(($r['ok'] ?? true) === false,
    'an account with no 2FA enrolled minted a key on a password alone');
ak_ok(!empty($r['needs_2fa_enrollment']),
    'an unenrolled account was refused without being told to enrol, so the '
    . 'owner is stuck with no way forward');

// --- no session at all ---------------------------------------------------
$seat($pdo, true, $hash, $secret);
$keep = $_SESSION;
$_SESSION = [];
$fresh($pdo);
$r = reauth_verify($pdo, $good, totp_code($secret));
ak_ok(($r['ok'] ?? true) === false, 'an expired session still passed step-up');
$_SESSION = $keep;

// --- the result carries no secrets ---------------------------------------
$fresh($pdo);
$r = reauth_verify($pdo, $good, totp_code($secret));
$dump = var_export($r, true);
ak_ok(!str_contains($dump, $good) && !str_contains($dump, $secret),
    'the step-up result carried the password or the TOTP secret back to the '
    . 'caller, where it would reach a page or a log');

$executed = $pass;

// ─────────────────────────────────────────────────────────────────────────
// PART 2 — SOURCE. The handler, brace-matched so every claim is about IT.
// ─────────────────────────────────────────────────────────────────────────
$src = (string)@file_get_contents($root . '/smack-api-keys.php');
if ($src === '') {
    throw new RuntimeException('could not read smack-api-keys.php');
}

/** The body of a handler, from its `if (...) {` to the matching brace. */
$handler = static function (string $src, string $needle): string {
    $at = strpos($src, $needle);
    if ($at === false) return '';
    $i = strpos($src, '{', $at);
    if ($i === false) return '';
    $depth = 0;
    for ($j = $i, $n = strlen($src); $j < $n; $j++) {
        if ($src[$j] === '{') $depth++;
        elseif ($src[$j] === '}') { $depth--; if ($depth === 0) return substr($src, $i, $j - $i + 1); }
    }
    return '';
};

$gen = $handler($src, "(\$_POST['action'] ?? '') === 'generate'");
ak_ok($gen !== '', 'the generate handler could not be isolated; every source '
    . 'assertion below would otherwise pass for the wrong reason');

if ($gen !== '') {
    // THE GATE RUNS, AND IT RUNS FIRST.
    $gatePos = strpos($gen, 'reauth_verify(');
    ak_ok($gatePos !== false,
        'the key generator does not call reauth_verify at all -- this is the '
        . 'whole of SS-1410 back again');

    foreach (['random_bytes(', 'INSERT INTO snap_ohsnap_keys'] as $after) {
        $pos = strpos($gen, $after);
        ak_ok($pos !== false && $gatePos !== false && $gatePos < $pos,
            "'{$after}' happens before the step-up check, so a refused attempt "
            . 'still produces key material');
    }

    // FAIL CLOSED: the mint must be inside the success branch. If the whole
    // INSERT sits under `if (!$ra['ok']) { ... } else {`, removing the else
    // is the mutation this catches.
    $refusal = strpos($gen, "if (!\$ra['ok'])");
    $elseAt = $refusal === false ? false : strpos($gen, '} else {', $refusal);
    $insertAt = strpos($gen, 'INSERT INTO snap_ohsnap_keys');
    ak_ok($refusal !== false, 'the handler never tests the step-up result');
    ak_ok($elseAt !== false && $insertAt !== false && $elseAt < $insertAt,
        'the INSERT is not inside the branch taken only when step-up '
        . 'succeeded, so a failed attempt can still write a key row');

    // EVERY KEY TYPE. There is one handler and one type whitelist inside it,
    // so the gate cannot be per-type -- but assert the list is in there, since
    // a second, ungated generate handler is exactly how this would come back.
    foreach (['hub', 'ohsnap', 'smackpress', 'bloggerflogger', 'flkrfckr', 'gyss',
              'unzucker', 'suyb', 'sybu', 'tyswy', 'smackthemup_publish'] as $type) {
        ak_ok(str_contains($gen, "'" . $type . "'"),
            "key type '{$type}' is no longer minted by the gated handler; if it "
            . 'moved to another one, that one needs the gate too');
    }
    ak_ok(substr_count($src, "=== 'generate'") === 1,
        'there is more than one generate handler, and only one of them was checked');

    // NOTHING IS LOGGED OR DISPLAYED.
    foreach (['error_log', 'file_put_contents', 'fwrite', 'syslog'] as $sink) {
        ak_ok(!str_contains($gen, $sink . '('),
            "the generate handler writes to {$sink}(), which is where a "
            . 'password, a code or a plaintext key leaves the request');
    }
    ak_ok(!preg_match('/\$msg\s*=.*reauth_(password|totp)/', $gen),
        'the submitted password or code is echoed back into the page message');
    ak_ok(!preg_match('/\$_SESSION\[[^\]]*\]\s*=\s*\$(raw_key|_POST\[.reauth)/', $gen),
        'key material or a submitted credential is parked in the session');
}

// THE PAGE MUST ASK FOR THEM. A server gate with no fields is an admin page
// that always refuses, which is its own outage.
$form = $handler($src, 'name="action" value="generate"');
$formBlock = (string)substr($src, (int)strpos($src, 'name="action" value="generate"'),
    (int)strpos($src, '</form>', (int)strpos($src, 'name="action" value="generate"'))
    - (int)strpos($src, 'name="action" value="generate"'));
ak_ok(str_contains($formBlock, 'name="reauth_password"'),
    'the generate form has no password field, so the gate refuses every '
    . 'attempt and no key can be made at all');
ak_ok(str_contains($formBlock, 'name="reauth_totp"'),
    'the generate form has no 2FA field');
ak_ok(str_contains($formBlock, 'type="password"'),
    'the password field is not a password input, so it renders in clear text');

// CSRF. Not added here: core/auth-smack.php calls csrf_check() for every
// admin POST. Asserted so that removing it elsewhere fails HERE too, on the
// page where it matters most.
$auth = (string)@file_get_contents($root . '/core/auth-smack.php');
ak_ok(str_contains($auth, 'csrf_check();'),
    'core/auth-smack.php no longer runs csrf_check(), so a cross-site POST '
    . 'could reach the key generator');
ak_ok(!str_contains($src, 'csrf_exempt('),
    'smack-api-keys.php exempts itself from CSRF');

if ($failures) {
    throw new RuntimeException("API key generation is not gated:\n  - "
        . implode("\n  - ", $failures));
}

echo "API key generation requires password + 2FA: {$pass} checks, {$executed} of "
    . "them executed against a real user table and a real TOTP secret: passed.\n";
// ===== SNAPSMACK EOF =====
