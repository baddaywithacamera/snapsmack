<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

/**
 * 50 SHADES static pages are one grey, like the landing page.
 *
 * photowalk.ing painted three different greys: the landing page and archive on
 * the skin's own --bg-primary (#3c3c3c under variant-medium), and then the
 * static pages on a #1a1a1a stage with a #242424 card floating on top. Sean:
 * "makes the skin look rushed." Nothing else in the skin is drawn as a box, so
 * a darker slab behind the text read as a dialog that had wandered in.
 *
 * The two controls that painted it, Static Page Stage and Static Page Card,
 * are gone rather than re-defaulted. Both already DEFAULTED to #3c3c3c — a
 * fresh install never had the box — so changing the default would have fixed
 * nothing on the live site, where the stored values were what did the damage.
 */

function fsog_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root  = dirname(__DIR__);
$skin  = $root . '/skins/50-shades-of-noah-grey';
$css   = (string)file_get_contents($skin . '/style.css');
$man   = json_decode((string)file_get_contents($skin . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$opts  = $man['options'] ?? [];

// The panel controls must stay gone. A stored value beats a changed default,
// so re-adding them with a matching default would not be a safe substitute.
foreach (['stage_bg_color', 'card_bg_color'] as $dead) {
    fsog_check(
        !isset($opts[$dead]),
        "The '$dead' control is back in 50 SHADES. It paints a separate surface behind the static "
        . 'pages, which is the box that made the skin look rushed.'
    );
}

// Nothing may paint .static-content as a surface of its own.
fsog_check(
    (bool)preg_match('/\.static-content\s*\{[^}]*background:\s*transparent\s*;/s', $css),
    '.static-content paints a background again; the static pages stop matching the landing page.'
);
fsog_check(
    !preg_match('/\.static-content\s*\{[^}]*background:\s*var\(--static-card-bg/s', $css),
    '.static-content is reading --static-card-bg again, which is the card treatment that was removed.'
);

// The stage behind it stays on the skin's own primary grey.
fsog_check(
    (bool)preg_match('/\.static-transmission\s+#scroll-stage\s*\{[^}]*background:\s*var\(--bg-primary\)/s', $css),
    'The static-page stage no longer uses --bg-primary, so it can differ from the landing page again.'
);

// No control may target either selector with a background, whatever it is called.
foreach ($opts as $key => $opt) {
    if (!is_array($opt)) continue;
    $sel  = (string)($opt['selector'] ?? '');
    $prop = (string)($opt['property'] ?? '');
    if (!str_contains($prop, 'background')) continue;
    foreach (['.static-content', '.static-transmission #scroll-stage'] as $guarded) {
        fsog_check(
            $sel !== $guarded,
            "Control '$key' paints a background on $guarded. The static pages must stay one grey "
            . 'with the rest of the skin.'
        );
    }
}

fwrite(STDOUT, "50 SHADES uniform-background regression passed.\n");

// ===== SNAPSMACK EOF =====
