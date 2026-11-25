<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

$root = dirname(__DIR__);
$failures = [];
$check = static function (string $what, bool $ok) use (&$failures): void {
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $what);
    if (!$ok) $failures[] = $what;
};

$sprite = (string)file_get_contents($root . '/assets/icons/crop-circles.svg');
$manifest = json_decode((string)file_get_contents($root . '/skins/alfred/manifest.json'), true,
                        512, JSON_THROW_ON_ERROR);

echo "SS-1560 / SS-1555: one shared Social Dock glyph family\n\n";

$check('the temporary selectable outline/solid families are gone',
       !preg_match('/<symbol\s+id="[^"]+-(?:outline|solid)"/', $sprite));

foreach (['bluesky', 'youtube'] as $platform) {
    preg_match('/<symbol\s+id="' . preg_quote($platform, '/') . '"[^>]*>(.*?)<\/symbol>/s',
               $sprite, $match);
    $art = $match[1] ?? '';
    $check("{$platform} exists in the shared sprite", $art !== '');
    $check("{$platform} is drawn with a filled path", str_contains($art, '<path') && str_contains($art, 'fill="currentColor"'));
    $check("{$platform} inherits the skin colour without a competing outline",
           !str_contains($art, 'stroke=') && !str_contains($art, 'fill="none"'));
    $check("{$platform} includes no embedded hardcoded colour", !preg_match('/fill="#[a-f0-9]+"/i', $art));
}

$dock = $manifest['social_dock'] ?? [];
$check('ALFRED preserves the existing outline chip setting',
       ($dock['default_icon_style'] ?? '') === 'outline');
$check('ALFRED defaults its owner-editable dock colour to white',
       ($dock['default_color_mode'] ?? '') === 'light'
       && ($dock['default_color_light'] ?? '') === '#ffffff');
$check('ALFRED still uses default_* keys, so owner settings win',
       !array_key_exists('color_mode', $dock)
       && !array_key_exists('color_light', $dock)
       && !array_key_exists('icon_style', $dock));

if ($failures) {
    fwrite(STDERR, "Social Dock glyph family FAILED:\n  - " . implode("\n  - ", $failures) . "\n");
    exit(1);
}
echo "PASS: Bluesky and YouTube are solid brand glyphs; ALFRED defaults to a white owner-editable dock.\n";

// ===== SNAPSMACK EOF =====
