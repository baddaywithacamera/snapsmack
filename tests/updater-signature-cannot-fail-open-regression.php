<?php
declare(strict_types=1);

/**
 * SECAUDIT 047 — the deferred updater fail-open. Closed, and executed.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 *
 * BUZZERS has carried this since August as "the narrow updater fail-open edge
 * case ... deferred low-risk ... because changing the fleet's sole repair path
 * requires a separate deliberate review". Sean asked for it on 2026-11-27.
 *
 * THE FAULT. The canonical release public key is compiled into core/updater.php.
 * But the verifier read the key off a CONSTANT, and a constant cannot be
 * redefined: when release-pubkey.php existed holding the all-zero placeholder,
 * require_once defined it as zeros, the self-heal rewrote the FILE but could
 * not correct the constant, SNAPSMACK_SIGNING_ENFORCED came out false, and
 * updater_verify_signature() returned TRUE for any signature at all. Worse, the
 * request that successfully heals the key is itself unprotected -- and that is
 * the request applying an update.
 *
 * HOW THIS TEST RUNS, because it matters. core/updater.php does real work when
 * it loads: it rewrites release-pubkey.php, reconciles .htaccess, and touches
 * the database if a handle is up. It must NEVER be required from the repository
 * during a test. So every scenario below copies core/ into its own temporary
 * directory and runs in its own PHP SUBPROCESS -- one per scenario, because
 * constants are set once per process. The self-heal's writes land in the copy.
 *
 * libsodium is present on this box, so the signatures here are REAL: a keypair
 * is generated, a checksum is signed, and the shipped verifier is asked about
 * it. Nothing is stubbed.
 */

$root   = dirname(__DIR__);
$checks = 0;
$fails  = [];
$check  = static function (string $what, bool $ok) use (&$checks, &$fails): void {
    $checks++;
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $what . "\n";
    if (!$ok) { $fails[] = $what; }
};

if (!function_exists('sodium_crypto_sign_keypair')) {
    fwrite(STDERR, "libsodium is not available in this PHP; this regression cannot run.\n");
    exit(1);
}

$PLACEHOLDER = str_repeat('0', 64);
$tmpRoot = sys_get_temp_dir() . '/ss-sec047-upd-' . bin2hex(random_bytes(6));
mkdir($tmpRoot, 0777, true);

$rmrf = static function (string $d) use (&$rmrf): void {
    if (!is_dir($d)) { return; }
    foreach (scandir($d) ?: [] as $e) {
        if ($e === '.' || $e === '..') { continue; }
        $p = $d . '/' . $e;
        if (is_dir($p) && !is_link($p)) { $rmrf($p); } else { @unlink($p); }
    }
    @rmdir($d);
};

/**
 * Copy core/ into a sandbox, optionally planting a release-pubkey.php, then run
 * a snippet in a subprocess with the sandboxed updater loaded. Returns the
 * decoded JSON the snippet printed.
 */
$scenario = static function (string $name, ?string $pubkeyOnDisk, string $snippet)
        use ($root, $tmpRoot, $rmrf): array {
    $dir = $tmpRoot . '/' . $name;
    mkdir($dir . '/core', 0777, true);
    foreach (glob($root . '/core/*.php') ?: [] as $f) {
        copy($f, $dir . '/core/' . basename($f));
    }
    $rpk = $dir . '/core/release-pubkey.php';
    if ($pubkeyOnDisk === null) {
        @unlink($rpk);                                  // the MISSING-file case
    } else {
        file_put_contents($rpk, "<?php\ndefine('SNAPSMACK_RELEASE_PUBKEY', '"
            . $pubkeyOnDisk . "');\n// ===== SNAPSMACK EOF =====\n");
    }
    $script = $dir . '/probe.php';
    file_put_contents($script,
        "<?php\nrequire_once " . var_export($dir . '/core/updater.php', true) . ";\n" . $snippet);
    $out = [];
    $rc  = 0;
    exec('php ' . escapeshellarg($script) . ' 2>' . escapeshellarg($dir . '/err.txt'), $out, $rc);
    $json = json_decode(trim(implode("\n", $out)), true);
    return is_array($json)
        ? $json + ['_rc' => $rc, '_dir' => $dir]
        : ['_rc' => $rc, '_dir' => $dir, '_raw' => implode("\n", $out),
           '_err' => (string)@file_get_contents($dir . '/err.txt')];
};

$report = <<<'PHP'
echo json_encode([
    'key'      => updater_release_pubkey(),
    'enforced' => SNAPSMACK_SIGNING_ENFORCED ? 1 : 0,
    'constant' => defined('SNAPSMACK_RELEASE_PUBKEY') ? SNAPSMACK_RELEASE_PUBKEY : null,
]);
PHP;

try {
    // ── 1. THE BUG'S OWN SCENARIO: the placeholder is on disk ──────────────
    echo "\n1. release-pubkey.php EXISTS holding the all-zero placeholder\n";
    $r = $scenario('placeholder', $PLACEHOLDER, $report);
    $check('the sandboxed updater loaded', isset($r['key']));
    if (!isset($r['key'])) { echo '    subprocess said: ' . ($r['_raw'] ?? '') . ' / ' . ($r['_err'] ?? '') . "\n"; }
    $check('THE KEY IN USE IS NOT THE PLACEHOLDER', ($r['key'] ?? $PLACEHOLDER) !== $PLACEHOLDER);
    $check('and signing reports ENFORCED, which is what was false before',
        ($r['enforced'] ?? 0) === 1);
    // The constant itself is STILL the placeholder in that request. That is the
    // whole point: the fix stopped depending on it rather than pretending a
    // constant can be redefined.
    $check('the constant is indeed still the placeholder, so the fix is not '
        . 'relying on redefining it', ($r['constant'] ?? '') === $PLACEHOLDER);

    // ── 2. ROTATION MUST STILL WIN ────────────────────────────────────────
    echo "\n2. a ROTATED real key on disk must be preferred over the compiled-in one\n";
    $rotated = str_repeat('ab', 32);
    $r2 = $scenario('rotated', $rotated, $report);
    $check('the rotated key on disk is the one used', ($r2['key'] ?? '') === $rotated);
    $check('and it is not silently replaced by the canonical key',
        ($r2['key'] ?? '') !== (string)($r['key'] ?? ''));
    $check('signing is enforced with a rotated key too', ($r2['enforced'] ?? 0) === 1);

    // ── 3. NO FILE AT ALL ─────────────────────────────────────────────────
    echo "\n3. release-pubkey.php MISSING entirely\n";
    $r3 = $scenario('missing', null, $report);
    $check('a real key is still available', ($r3['key'] ?? $PLACEHOLDER) !== $PLACEHOLDER);
    $check('and it is the canonical one', ($r3['key'] ?? '') === (string)($r['key'] ?? 'x'));
    $check('signing is enforced', ($r3['enforced'] ?? 0) === 1);

    // ── 4. REAL SIGNATURES, THROUGH THE SHIPPED VERIFIER ──────────────────
    // A genuine keypair, a genuine signature, and the real function asked about
    // it. This is the check that proves verification HAPPENS rather than that a
    // constant has a value.
    echo "\n4. real Ed25519 signatures through updater_verify_signature()\n";
    $pair = sodium_crypto_sign_keypair();
    $pub  = sodium_bin2hex(sodium_crypto_sign_publickey($pair));
    $sec  = sodium_crypto_sign_secretkey($pair);
    $checksum = hash('sha256', 'a release package');
    $goodSig  = sodium_bin2hex(sodium_crypto_sign_detached($checksum, $sec));
    // flip one hex digit — still well-formed, no longer valid
    $badSig   = substr($goodSig, 0, -1) . (substr($goodSig, -1) === 'a' ? 'b' : 'a');

    $verify = 'echo json_encode(['
        . "'good' => updater_verify_signature(" . var_export($checksum, true) . ', '
        . var_export($goodSig, true) . ') ? 1 : 0,'
        . "'bad' => updater_verify_signature(" . var_export($checksum, true) . ', '
        . var_export($badSig, true) . ') ? 1 : 0,'
        . "'other_payload' => updater_verify_signature(" . var_export(hash('sha256','something else'), true) . ', '
        . var_export($goodSig, true) . ') ? 1 : 0,'
        . ']);';
    $r4 = $scenario('realkey', $pub, $verify);
    $check('a VALID signature is accepted', ($r4['good'] ?? 0) === 1);
    $check('a TAMPERED signature is refused', ($r4['bad'] ?? 1) === 0);
    $check('a valid signature over a DIFFERENT payload is refused',
        ($r4['other_payload'] ?? 1) === 0);

    // ── 5. THE FAIL-OPEN ITSELF ───────────────────────────────────────────
    // The scenario that used to wave anything through: the placeholder on disk.
    // The signature below is valid for a key this install does not have, so the
    // only correct answer is REFUSE. Before the fix this returned true for any
    // input at all.
    echo "\n5. THE FAIL-OPEN: placeholder on disk, signature from an unknown key\n";
    $r5 = $scenario('failopen', $PLACEHOLDER, $verify);
    $check('a signature from an unknown key is REFUSED, not waved through',
        ($r5['good'] ?? 1) === 0);
    $check('and so is a tampered one', ($r5['bad'] ?? 1) === 0);

    // ── 6. THE KEY IS NOT DUPLICATED IN SOURCE ────────────────────────────
    echo "\n6. one source for the canonical key\n";
    $src = (string) @file_get_contents($root . '/core/updater.php');
    $check('core/updater.php is readable', $src !== '');
    $canon = (string)($r3['key'] ?? 'nope');
    $check('the canonical key appears ONCE in core/updater.php, not twice '
        . '(found ' . substr_count($src, $canon) . ')',
        substr_count($src, $canon) === 1);
    $code = (string) preg_replace('#/\*.*?\*/#s', '', $src);
    $code = (string) preg_replace('#^\s*//.*$#m', '', $code);
    $check('the verifier no longer reads the key off the constant directly',
        !str_contains($code, 'sodium_hex2bin(SNAPSMACK_RELEASE_PUBKEY)'));
} finally {
    $rmrf($tmpRoot);
}

echo "\n";
if ($fails) {
    echo 'FAILED (' . count($fails) . " of {$checks}):\n";
    foreach ($fails as $f) { echo "  - $f\n"; }
    fwrite(STDERR, "\nSECAUDIT 047: the updater can still accept an unverified package.\n");
    exit(1);
}
echo "SECAUDIT 047 PASS — the updater always has a real key, verification always "
   . "happens, rotation still wins, and a package signed by an unknown key is "
   . "refused ({$checks} checks).\n";
echo "NOT CHECKED: a host with NO libsodium. It cannot be simulated in a process "
   . "that has the extension loaded, and the branch is a source-level refusal "
   . "rather than a behaviour this box can exercise. Nor is the live fleet's "
   . "libsodium availability known — that needs a running site. Nothing was "
   . "installed, updated or deployed.\n";

// ===== SNAPSMACK EOF =====
