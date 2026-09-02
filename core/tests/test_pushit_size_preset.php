<?php
/**
 * Test for pushit_size_preset_vals() (smack-push-it.php) — the fleet image-size
 * preset -> settings map used by the "PUSH IMAGE SIZE TO FLEET" 4K button.
 *
 * Extracts the real function from source and evals it (no copy, can't drift).
 * Guards the exact key set so a rename never silently drops a field from the push.
 *
 * Run: php core/tests/test_pushit_size_preset.php   (exit 0 = all pass)
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$src = file_get_contents(__DIR__ . '/../../smack-push-it.php');
if ($src === false || !preg_match('/\nfunction pushit_size_preset_vals\(.*?\n\}/s', $src, $m)) {
    fwrite(STDERR, "FAIL: could not extract pushit_size_preset_vals from source\n");
    exit(1);
}
eval($m[0]);

$fail = 0;
function check($label, $got, $want) {
    global $fail;
    if ($got !== $want) {
        $fail++;
        fwrite(STDERR, "FAIL: $label\n  got:  " . json_encode($got) . "\n  want: " . json_encode($want) . "\n");
    }
}

// 1. 4K -> 3840 across every field, preset '4k', resize on.
check('4k', pushit_size_preset_vals('4k'), [
    'image_max_resolution' => '4k',
    'max_width_landscape'  => '3840',
    'max_height_portrait'  => '3840',
    'max_long_edge'        => '3840',
    'image_resize_enabled' => '1',
]);

// 2. Full HD -> 1920.
check('fullhd', pushit_size_preset_vals('fullhd'), [
    'image_max_resolution' => 'fullhd',
    'max_width_landscape'  => '1920',
    'max_height_portrait'  => '1920',
    'max_long_edge'        => '1920',
    'image_resize_enabled' => '1',
]);

// 3. Empty / unknown -> no change.
check('empty', pushit_size_preset_vals(''), []);
check('garbage', pushit_size_preset_vals('8k'), []);

// 4. Regression: the push MUST carry all five keys (the ingest preset, the legacy
//    pair, the canonical long-edge, and the resize flag) — never fewer.
$keys = array_keys(pushit_size_preset_vals('4k'));
sort($keys);
check('all five keys present', $keys, [
    'image_max_resolution', 'image_resize_enabled', 'max_height_portrait',
    'max_long_edge', 'max_width_landscape',
]);

if ($fail === 0) { echo "OK - 5 checks passed\n"; exit(0); }
fwrite(STDERR, "$fail check(s) FAILED\n");
exit(1);
// ===== SNAPSMACK EOF =====
