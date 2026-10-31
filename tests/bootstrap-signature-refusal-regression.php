<?php
/**
 * The bootstrap deployer never installs a package it could not verify.
 *
 * The verifier checked the SHA-256 checksum, then checked the Ed25519
 * signature -- UNLESS the sodium extension was missing, in which case it
 * returned true and skipped the signature, keeping only the checksum. The
 * file's own requirements line says sodium is required, so the code tolerated
 * the absence of something the documentation calls mandatory.
 *
 * WHY THE CHECKSUM ALONE IS NOT A CHECK. The expected hash comes from the
 * release manifest, over the network, from the same place as the download. A
 * party who can serve a tampered package can serve the hash that matches it,
 * and the comparison then passes with flying colours. The SIGNATURE is the
 * only part of this that proves the package came from whoever holds the
 * release key, because the public key is pinned in the file itself and does
 * not travel with the manifest. Skipping it does not weaken verification
 * slightly; it removes it.
 *
 * It is also the quietest possible failure: a host without the extension gets
 * a green install and no indication that anything was skipped.
 *
 * THE FIX IS TO REFUSE. The deployer's whole job is to put unknown code from
 * the network onto a server. If it cannot prove where that code came from, the
 * right answer is to stop and say why, not to proceed on the weaker half.
 *
 * Run:  php tests/bootstrap-signature-refusal-regression.php
 *
 * The name ends in -regression.php for a reason: tools/release-flow.php
 * runs tests/*regression.php and nothing else, so a test named anything
 * else is one nobody runs.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$root = realpath(__DIR__ . '/..');
$failures = [];
function sig_test(bool $ok, string $message): void {
    global $failures;
    if (!$ok) $failures[] = $message;
}

/** Source with comments stripped, so no check can match prose about itself. */
function sig_code(string $path): string {
    $out = '';
    foreach (token_get_all((string)file_get_contents($path)) as $t) {
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
        $out .= is_array($t) ? $t[1] : $t;
    }
    return $out;
}

$setup = sig_code("$root/setup.php");

// ── The skip is gone ───────────────────────────────────────────────────────
sig_test(!preg_match('/if\s*\(\s*!\s*function_exists\(\s*[\'"]sodium_crypto_sign_verify_detached[\'"]\s*\)\s*\)\s*\{\s*return\s+true/', $setup),
    'the verifier still returns TRUE when sodium is missing, which installs a '
  . 'package whose signature was never checked');

// ── The verifier is callable, so the refusal can be proved rather than read ─
sig_test((bool)preg_match('/function\s+setup_verify\s*\(/', $setup),
    'setup_verify() has gone; there is nothing to test');

// ── EXECUTED. Pull the verifier out and run it with sodium "absent". ───────
// setup.php cannot be included here (it renders a page and refuses when core/
// exists), so the function is extracted and given a seam: the test defines the name it checks for. That seam has to exist in production code, not just here.
if (preg_match('/(function\s+setup_verify\s*\(.*?\n\})/s', $setup, $fnM)) {
    $fn = $fnM[1];

    // The function must ASK something it can be told, rather than calling
    // function_exists() inline -- otherwise the refusal cannot be exercised on
    // a machine that has sodium, which is every machine we develop on.
    sig_test((bool)preg_match('/setup_has_sodium\s*\(/', $fn),
        'setup_verify() decides on sodium inline, so the refusal path cannot be '
      . 'run on a machine that has sodium -- which is every machine we test on');

    if (!function_exists('SETUP_RELEASE_PUBKEY')) { /* constant, not function */ }
    if (!defined('SETUP_RELEASE_PUBKEY')) {
        if (preg_match("/define\(\s*'SETUP_RELEASE_PUBKEY'\s*,\s*'([0-9a-f]+)'/i", $setup, $pk)) {
            define('SETUP_RELEASE_PUBKEY', $pk[1]);
        }
    }
    // A stand-in for the seam, defaulting to "sodium is here".
    if (!function_exists('setup_has_sodium')) {
        eval('function setup_has_sodium(): bool { return $GLOBALS["sig_sodium"] ?? true; }');
    }
    if (!function_exists('setup_verify')) eval($fn);

    $tmp = sys_get_temp_dir() . '/ss-sig-' . bin2hex(random_bytes(6)) . '.zip';
    file_put_contents($tmp, str_repeat('package bytes ', 2000));
    $realHash = hash_file('sha256', $tmp);

    // 1. NO SODIUM -> REFUSE, even though the checksum is perfect.
    $GLOBALS['sig_sodium'] = false;
    $r = setup_verify($tmp, $realHash, str_repeat('ab', 64));
    sig_test($r !== true,
        'with sodium absent and a CORRECT checksum, the package was accepted; '
      . 'the signature was never checked and nothing said so');
    sig_test(is_string($r) && stripos($r, 'signature') !== false,
        'the refusal does not mention the signature, so the operator cannot tell '
      . 'what is wrong: ' . var_export($r, true));

    // 2. Sodium present, checksum wrong -> still refuses on the checksum.
    $GLOBALS['sig_sodium'] = true;
    $r = setup_verify($tmp, str_repeat('0', 64), str_repeat('ab', 64));
    sig_test(is_string($r) && stripos($r, 'checksum') !== false,
        'a wrong checksum is no longer refused with a checksum message');

    // 3. Sodium present, checksum right, signature malformed -> refuses.
    $r = setup_verify($tmp, $realHash, 'not-a-signature');
    sig_test($r !== true, 'a malformed signature was accepted');

    // 3b. A manifest with NO signature in it. The length check in front of
    //     sodium_hex2bin is what makes this say so: without it the call
    //     throws, the catch turns it into 'Signature check error: ...', and
    //     the operator is shown a library message about hex decoding when
    //     the actual problem is that the release was published unsigned.
    //     Tested because the refusal survives either way and only the
    //     message changes, so nothing else here would notice it going.
    $r = setup_verify($tmp, $realHash, '');
    sig_test(is_string($r) && stripos($r, 'missing') !== false,
        'an absent signature is not reported as missing from the manifest, so '
      . 'an unsigned release looks like a broken one: ' . var_export($r, true));

    // 4. A missing file is still refused and does not fatal. hash_file warns
    //    on a path that is not there, in production as well as here; what is
    //    under test is that the function returns a message instead of
    //    continuing, so the warning is muted rather than left to clutter the
    //    run.
    $r = @setup_verify($tmp . '-gone', $realHash, str_repeat('ab', 64));
    sig_test($r !== true, 'a package that could not be read was accepted');

    @unlink($tmp);
} else {
    $failures[] = 'could not extract setup_verify() from setup.php to test it';
}

// ── It refuses BEFORE downloading, and does not offer the button ──────────
//
// These two are read off the source rather than executed: setup.php renders a
// page and refuses to run at all once core/ exists, so the POST handler and
// the button cannot be driven from here. Stated plainly because a source read
// is the weaker kind of check -- it proves the code says this, not that a
// browser saw it.

sig_test((bool)preg_match('/elseif\s*\(\s*!\s*setup_has_sodium\(\s*\)\s*\)\s*\{\s*\$error\s*=/', $setup),
    'the POST handler does not refuse on a missing sodium before fetching: a '
  . 'host that cannot verify a package would still download one');

$pos = strpos($setup, 'name="deploy"');
if ($pos === false) {
    $failures[] = 'the install form has gone from setup.php';
} else {
    $guard = strrpos(substr($setup, 0, $pos), '<?php if (');
    $cond  = $guard === false ? '' : substr($setup, $guard, $pos - $guard);
    sig_test(strpos($cond, '$has_sodium') !== false,
        'the Install button is still offered on a server without sodium; the '
      . 'condition that guards it is: ' . trim(explode("\n", $cond)[0]));
}

sig_test(stripos($setup, 'checksum only') === false,
    'the page still tells the operator the package will be verified by '
  . 'checksum only, which is now not what happens');

// ── The seam must be real production code, not a test fiction ─────────────
sig_test((bool)preg_match('/function\s+setup_has_sodium\s*\(/', $setup),
    'setup.php does not define setup_has_sodium(); the test invented the seam '
  . 'it then tested, which proves nothing about what ships');

if ($failures) {
    fwrite(STDERR, "FAIL bootstrap signature refusal (" . count($failures) . ")\n  - "
        . implode("\n  - ", $failures) . "\n");
    exit(1);
}
echo "PASS the bootstrap refuses a package it cannot verify\n";
// ===== SNAPSMACK EOF =====
