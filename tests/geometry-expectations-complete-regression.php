<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * Every skin appears in the geometry expectations exactly once.
 *
 * The expectations fixture dropped HIP 2 B SQUARE on its first write. Nobody
 * would have noticed: the file looked complete, the counts looked plausible,
 * and the skin would simply never have been judged. That is the same failure
 * as the archive check that quietly reported "21 skins" while hiding four,
 * and the same one this whole audit is about -- a check that silently skips
 * what it cannot see.
 *
 * So the fixture is required to account for every skin that has a layout, in
 * exactly one of its three lists, and a skin recorded as having no capture is
 * required to really have none.
 */

$root = dirname(__DIR__);
$fail = 0;
function ge_test(bool $ok, string $message): void {
    global $fail;
    echo ($ok ? "PASS " : "FAIL ") . $message . "\n";
    if (!$ok) $fail++;
}

$fixture = json_decode((string)file_get_contents($root . '/tests/fixtures/skin-geometry-expectations.json'), true);
ge_test(is_array($fixture), 'the expectations fixture parses');

$verified   = array_keys($fixture['skins'] ?? []);
$unverified = (array)($fixture['unverified_skins'] ?? []);
$noCapture  = (array)($fixture['no_capture_at_all'] ?? []);
$listed     = array_merge($verified, $unverified, $noCapture);

$present = [];
foreach (glob($root . '/skins/*/layout.php') ?: [] as $file) $present[] = basename(dirname($file));
sort($present);

$missing = array_values(array_diff($present, $listed));
ge_test($missing === [], 'every skin with a layout is in the fixture' . ($missing ? ': missing ' . implode(', ', $missing) : ''));

$ghosts = array_values(array_diff($listed, $present));
ge_test($ghosts === [], 'the fixture names no skin that does not exist' . ($ghosts ? ': ' . implode(', ', $ghosts) : ''));

$dupes = array_values(array_unique(array_diff_assoc($listed, array_unique($listed))));
ge_test($dupes === [], 'no skin appears in two lists' . ($dupes ? ': ' . implode(', ', $dupes) : ''));

// A skin recorded as having no capture must really have none, or it is being
// excused from judgement on a false premise.
$index = json_decode((string)file_get_contents($root . '/opaudits/evidence/027/2026-10-27-owner-reference-captures.json'), true);
$haveCapture = array_keys($index['skins'] ?? []);
$wrongly = array_values(array_intersect($noCapture, $haveCapture));
ge_test($wrongly === [], 'no skin is recorded as having no capture when one exists' . ($wrongly ? ': ' . implode(', ', $wrongly) : ''));

// And a verified entry must cite a capture file that is actually there.
foreach (($fixture['skins'] ?? []) as $skin => $kinds) {
    foreach ((array)$kinds as $kind => $entry) {
        $file = (string)($entry['capture'] ?? '');
        ge_test($file !== '' && is_file($file), "{$skin}/{$kind} cites a capture that exists");
        ge_test(($entry['status'] ?? '') === 'verified', "{$skin}/{$kind} is marked verified");
    }
}

printf("     %d verified, %d with an unread capture, %d with no capture, %d skins in all.\n",
    count($verified), count($unverified), count($noCapture), count($present));

if ($fail > 0) {
    fwrite(STDERR, "Geometry expectations do not account for every skin. {$fail} check(s) failed.\n");
    exit(1);
}
echo "Geometry expectations account for every skin: PASS.\n";

// ===== SNAPSMACK EOF =====
