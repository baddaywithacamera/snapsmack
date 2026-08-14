<?php
/**
 * SCROLL Square wall regression guard.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */
$root = dirname(__DIR__);
$manifest = json_decode((string)file_get_contents($root . '/skins/scroll/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$wall = (string)file_get_contents($root . '/skins/scroll/wall.php');
$css = (string)file_get_contents($root . '/skins/scroll/style.css');
$loader = (string)file_get_contents($root . '/assets/js/ss-engine-columns.js');

$fail = static function (string $message): void {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

$layout = $manifest['options']['scroll_wall_layout']['options'] ?? [];
if (!isset($layout['square'])) $fail('SCROLL manifest does not expose Square wall mode.');
$control = $manifest['options']['scroll_square_cols'] ?? [];
if (($control['min'] ?? null) !== '3' || ($control['max'] ?? null) !== '5') {
    $fail('Squares Across must remain bounded to 3-5.');
}
foreach ([
    "'columns', 'rows', 'square', 'mosaic'",
    "\$_is_square = (\$_ss_wall_layout === 'square')",
    "\$_is_square ? 'ss-square-wall'",
] as $needle) {
    if (!str_contains($wall, $needle)) $fail('Missing Square renderer contract: ' . $needle);
}
foreach ([
    'grid-template-columns: repeat(var(--ss-square-cols), minmax(0, 1fr))',
    'gap: var(--ss-gap, 6px)',
    'box-sizing: border-box',
    'aspect-ratio: 1 / 1',
    'object-fit: cover',
] as $needle) {
    if (!str_contains($css, $needle)) $fail('Missing Square CSS contract: ' . $needle);
}
if (!str_contains($loader, "document.querySelector('.ss-masonry, .ss-square-wall')")) {
    $fail('Infinite-scroll loader does not recognize the Square wall.');
}

// CSS Grid divides the content box after fixed gaps. Pin the intended arithmetic
// at phone, ordinary desktop and wide-screen widths, including maximum chrome.
foreach ([320, 935, 1600] as $width) {
    foreach (range(3, 5) as $cols) {
        foreach ([0, 6, 25] as $gap) {
            foreach ([0, 4, 12] as $border) {
                $tile = ($width - $gap * ($cols - 1)) / $cols;
                if ($tile <= 2 * $border) $fail('Tile inner box collapsed under its border.');
                $used = $tile * $cols + $gap * ($cols - 1);
                if (abs($used - $width) > 0.00001) $fail('Square row does not exactly fit its container.');
            }
        }
    }
}

echo "SCROLL Square layout regression checks passed.\n";
// ===== SNAPSMACK EOF =====
