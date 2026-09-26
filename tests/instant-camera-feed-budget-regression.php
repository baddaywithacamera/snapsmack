<?php
/**
 * SNAPSMACK - INSTANT CAMERA feed budget regression
 *
 * Keeps the landing page from returning to its old pattern of selecting the
 * entire archive in PHP while also mounting a second full feed as decoration.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

$root = dirname(__DIR__);
$landing = file_get_contents($root . '/skins/instant-camera/landing.php');
$profile = file_get_contents($root . '/skins/instant-camera/skin-profile.php');
$mayhem = file_get_contents($root . '/assets/js/ss-engine-organized-mayhem.js');

$failures = [];
$expect = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) $failures[] = $message;
};

$expect(str_contains($landing, 'LIMIT :feed_limit OFFSET :feed_offset'), 'feed query must page in SQL');
$expect(str_contains($landing, "PDO::PARAM_INT"), 'feed LIMIT and OFFSET must use integer bindings');
$expect(!str_contains($landing, 'array_slice($grid_posts'), 'feed must not fetch the archive and slice it in PHP');
$expect(str_contains($landing, '$_feed_total = $post_count;'), 'paging total must come from the count query');

$expect(
    str_contains($profile, "max(40, min(400, (int)(\$settings['mayhem_initial_count'] ?? 90)))"),
    'INSTANT CAMERA decorative pool must preserve the configured tabletop density'
);
$expect(
    str_contains($mayhem, 'var cardW = Math.max(cellW, cellH) * 1.7;')
        && !str_contains($mayhem, 'var cardW = Math.min(maxWidth, Math.max(cellW, cellH) * 1.7);'),
    'ambient Mayhem cards must expand to cover the viewport without gaps'
);

if ($failures) {
    fwrite(STDERR, "INSTANT CAMERA feed budget regression failed:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "INSTANT CAMERA feed budget regression passed.\n";

// ===== SNAPSMACK EOF =====
