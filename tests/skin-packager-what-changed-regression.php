<?php
declare(strict_types=1);

/**
 * SS-1596: Verify Skin Packager extracts and displays a "what changed" line
 * for each skin update and records it in the published skin registry.
 *
 * Run: php tests/skin-packager-what-changed-regression.php
 *
 * SNAPSMACK_EOF_HEADER
 * // ===== SNAPSMACK EOF =====
 */

$src = file_get_contents(__DIR__ . '/../smack-central/sc-skins.php');
if ($src === false) {
    fwrite(STDERR, "FAIL: could not read smack-central/sc-skins.php\n");
    exit(1);
}

foreach (['sc_skin_first_line', 'sc_skin_manifest_what_changed', 'sc_skin_what_changed_line'] as $fn) {
    if (!preg_match('/\nfunction ' . preg_quote($fn, '/') . '\(.*?\n\}/s', $src, $m)) {
        fwrite(STDERR, "FAIL: could not extract {$fn} from smack-central/sc-skins.php\n");
        exit(1);
    }
    eval($m[0]);
}

$fail = 0;
$check = static function (string $label, $got, $want) use (&$fail): void {
    if ($got !== $want) {
        $fail++;
        fwrite(STDERR, "FAIL: {$label}\n  got:  " . json_encode($got) . "\n  want: " . json_encode($want) . "\n");
    }
};

$check('manifest what_changed takes first non-empty line',
       sc_skin_manifest_what_changed(['what_changed' => "\n  Fix mobile nav toggle  \nMore details"], '1.0.31'),
       'Fix mobile nav toggle');

$check('manifest changelog keyed by version',
       sc_skin_manifest_what_changed(['changelog' => ['1.0.30' => 'Old', '1.0.31' => 'Fix 1-column feed']], '1.0.31'),
       'Fix 1-column feed');

$check('update falls back to commit summary when manifest has no note',
       sc_skin_what_changed_line(['version' => '1.0.31', 'what_changed' => ''], true, 'ALFRED 1.0.31: fix mobile nav', '1.0.30'),
       'ALFRED 1.0.31: fix mobile nav');

$check('update falls back to version delta when commit summary is empty',
       sc_skin_what_changed_line(['version' => '1.0.31', 'what_changed' => ''], true, '', '1.0.30'),
       'Updated from 1.0.30 to 1.0.31.');

$check('unchanged skin with no manifest note emits empty string',
       sc_skin_what_changed_line(['version' => '1.0.31', 'what_changed' => ''], false, 'Some commit', '1.0.31'),
       '');

$check('select_skins UI renders What changed line',
       str_contains($src, 'What changed:') && str_contains($src, 'name="what_changed['),
       true);

$check('registry entry stores what_changed',
       str_contains($src, "\$entry['what_changed'] = \$posted_what_changed;"),
       true);

if ($fail > 0) {
    fwrite(STDERR, "FAILED: {$fail} check(s)\n");
    exit(1);
}
echo "PASS: SS-1596 Skin Packager what-changed line (7 checks).\n";
// ===== SNAPSMACK EOF =====
