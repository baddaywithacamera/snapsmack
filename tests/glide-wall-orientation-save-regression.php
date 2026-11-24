<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

define('SNAPSMACK_ROOT', dirname(__DIR__));
require_once SNAPSMACK_ROOT . '/core/skin-presentation.php';

$failures = [];
function gwo_check(bool $ok, string $message): void
{
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
    if (!$ok) $failures[] = $message;
}

$resolvedDefault = snapsmack_declared_skin_options([], 'glide');
$resolvedMirrored = snapsmack_declared_skin_options(['glide_wall_orientation' => 'mirrored'], 'glide');
$resolvedUpright = snapsmack_declared_skin_options(['glide_wall_orientation' => 'upright'], 'glide');
$resolvedInvalid = snapsmack_declared_skin_options(['glide_wall_orientation' => 'sideways'], 'glide');

gwo_check(($resolvedDefault['glide_wall_orientation'] ?? '') === 'current',
    'a site with no saved value keeps the current orientation');
gwo_check(($resolvedMirrored['glide_wall_orientation'] ?? '') === 'mirrored',
    'a saved mirrored value survives a fresh settings resolution');
gwo_check(($resolvedUpright['glide_wall_orientation'] ?? '') === 'upright',
    'a saved upright value survives a fresh settings resolution');
gwo_check(($resolvedInvalid['glide_wall_orientation'] ?? '') === 'current',
    'an unrecognised stored value falls back to current');

$css = snapsmack_declared_skin_style('glide', $resolvedUpright);
gwo_check(str_contains($css, '.glide-page{--glide-wall-orientation:upright;}'),
    'the saved upright value reaches generated public CSS');
gwo_check(!str_contains($css, 'sideways'), 'invalid orientation text never reaches public CSS');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " GLIDE orientation save-path checks failed.\n");
    exit(1);
}
echo "GLIDE wall orientation save path: PASS\n";
// ===== SNAPSMACK EOF =====
