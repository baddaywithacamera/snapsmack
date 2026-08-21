<?php
/**
 * Provision-key SHARED-KEY regression.
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 *
 * The multisite provision-key endpoint must support ONE shared fleet backup key
 * (the hub hands every site the same value) as well as the legacy per-site mint,
 * so backups stop drifting across 24 different keys. These are string-structure
 * checks (the live DB path can't run headless), matched to the sc_test harness so
 * they run at tag time with the rest of the suite.
 */

$root = dirname(__DIR__);
$api  = file_get_contents($root . '/core/multisite-api.php');
$fail = 0;
function sc_test(bool $ok, string $message): void {
    global $fail;
    echo ($ok ? "PASS " : "FAIL ") . $message . "\n";
    if (!$ok) $fail++;
}

sc_test(str_contains($api, "\$pv_body['key_value']"), 'provision-key accepts a caller-supplied key_value');
sc_test(str_contains($api, "preg_match('/^[a-f0-9]{64}\$/', \$pv_supplied)"), 'supplied key_value is validated as 64 hex (the Bearer-key format)');
sc_test(str_contains($api, '$pv_shared = ($pv_supplied !== \'\');'), 'shared-key mode is distinguished from a fresh mint');
sc_test(str_contains($api, "\$pv_raw     = \$pv_shared ? \$pv_supplied : bin2hex(random_bytes(32));"), 'a supplied value is installed as-is; only the legacy path mints a random key');
sc_test(str_contains($api, "key_type = ? AND label LIKE 'HUB %'"), 'installing the key retires every prior HUB key of this type (converge to one), sparing a user-made key');
sc_test(str_contains($api, 'if (!$pv_shared) $pv_out[\'api_key\'] = $pv_raw;'), 'a caller-supplied value is never echoed back (only a freshly minted key is)');
sc_test(str_contains($api, "'HUB shared key (' . \$pv_type . ')'"), 'the shared key is labeled so it is recognizable and revocable on the admin page');

exit($fail === 0 ? 0 : 1);
// ===== SNAPSMACK EOF =====
