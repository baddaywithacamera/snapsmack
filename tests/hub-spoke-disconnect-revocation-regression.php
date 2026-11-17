<?php
declare(strict_types=1);

/**
 * SS-1054. Hub-side spoke removal must not forget a spoke while that spoke still
 * holds a live key.
 *
 * The hub's 'disconnect' handler POSTs multisite/disconnect to the spoke so the
 * spoke wipes its own node row (which revokes api_key_local / _remote / _backup
 * at once). It used to discard the curl result and DELETE the hub's record
 * regardless, so a spoke that was down, slow or erroring kept its row and a live
 * key while the hub reported "disconnected and removed" — a revocation the owner
 * believed happened but did not.
 *
 * The fix gates the hub-side DELETE on the spoke CONFIRMING with HTTP 200, and
 * on any other outcome keeps the relationship and sets $err. This is a static
 * check (the real path needs a hub, a spoke and the network); it reads the
 * handler block and asserts the shape of the gate so a later edit cannot quietly
 * restore fire-and-forget.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;
function dc_test(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

$src = (string) file_get_contents($root . '/smack-multisite.php');

// Isolate the hub 'disconnect' handler body (not 'disconnect_hub': the closing
// ] after disconnect distinguishes them). Brace-walk from its opening brace.
$at = strpos($src, "if (isset(\$_POST['disconnect']))");
dc_test($at !== false, "the hub disconnect handler is present");

$body = '';
if ($at !== false) {
    $i = strpos($src, '{', $at);
    $depth = 0; $end = $i;
    for ($n = strlen($src); $i !== false && $i < $n; $i++) {
        if ($src[$i] === '{') $depth++;
        elseif ($src[$i] === '}') { $depth--; if ($depth === 0) { $end = $i; break; } }
    }
    $body = substr($src, $at, $end - $at + 1);
}

// It must read the spoke's HTTP status rather than discarding the result.
dc_test(str_contains($body, 'CURLINFO_HTTP_CODE'),
    "the handler captures the spoke's HTTP status (not fire-and-forget)");

// The hub-side DELETE must be gated on a 200 confirmation, and must come AFTER
// that check in the source — never unconditionally.
$check200 = strpos($body, '=== 200');
$delete   = strpos($body, 'DELETE FROM snap_multisite_nodes WHERE id');
dc_test($check200 !== false, "there is an HTTP 200 confirmation check");
dc_test($delete !== false && $check200 !== false && $delete > $check200,
    "the hub-side DELETE happens only after the 200 check, not unconditionally");

// A non-confirming spoke must be reported, not silently removed.
dc_test((bool) preg_match('/else\s*\{[^}]*\$err\s*=/s', $body),
    "a non-200 outcome sets \$err (the spoke is not silently forgotten)");

// The old fire-and-forget promise must be gone.
dc_test(!str_contains($body, 'regardless of whether the spoke responds'),
    "the old 'delete regardless' comment is gone");

echo "\n  {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "Hub spoke disconnect revocation: FAIL ({$fail}).\n";
    exit(1);
}
echo "Hub spoke disconnect revocation: PASS ({$pass} assertions).\n";

// ===== SNAPSMACK EOF =====
