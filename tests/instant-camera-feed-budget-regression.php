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
    hash('sha256', $mayhem) === '09753ea46dd6665ff0fe74b1dc5315b4758e3d48955384f8912d2d301b3c8060',
    'Organized Mayhem must remain byte-for-byte identical to the September 19 known-good engine'
);
$expect(
    str_contains($profile, "data-initial-count=\"<?php echo (int)(\$settings['mayhem_initial_count'] ?? 90); ?>\""),
    'Instant Camera must preserve the September 19 tabletop pool wiring without a 30-photo cap'
);

if ($failures) {
    fwrite(STDERR, "INSTANT CAMERA feed budget regression failed:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "INSTANT CAMERA feed budget regression passed.\n";

// ===== SNAPSMACK EOF =====
