<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * The masthead is a link, and a link the skin does not style is a blue one.
 *
 * Sean, on pixhellated.ca: "why is masthead link underlined and is the
 * underline purple?" Measured on the live page, the wordmark span was styled
 * exactly right -- colour rgb(42,37,32), text-decoration none -- while the
 * <a> wrapping it had no styling at all: colour rgb(0,0,238) and
 * text-decoration underline. A descendant cannot cancel an underline drawn by
 * its ancestor, so the span's rule never had a chance, and the underline was
 * painted in the browser's link colour: blue, or purple once visited.
 *
 * Eleven skins were in that state. Three of them -- IMPACT PRINTER,
 * NEW HORIZON and TRUE GRIT -- did style a masthead anchor, but as
 * ".logo-area a": an anchor INSIDE a container, which is the older markup.
 * The renderer emits the anchor AS the container,
 * <a class="site-title logo-area">, so the selector matched nothing. The
 * other eight never styled it.
 *
 * This asserts the pairing rather than any particular rule: whatever classes
 * a renderer puts on the masthead anchor, some selector in that skin must
 * reach THAT ELEMENT and settle its colour and decoration.
 */

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $ok, string $what) use (&$failures): void {
    if (!$ok) $failures[] = $what;
};

/** Templates that draw the masthead: a skin's layout, or its CMS renderer. */
$templates = array_merge(
    glob($root . '/skins/*/layout.php') ?: [],
    glob($root . '/core/*-page-component.php') ?: []
);

$checked = 0;
foreach ($templates as $template) {
    $src = (string)file_get_contents($template);
    if (!preg_match('/<a[^>]*class="([^"]*(?:site-title|logo-area)[^"]*)"/', $src, $m)) continue;

    if (str_contains($template, '/core/')) {
        $slug = str_replace('-page-component.php', '', basename($template));
        if ($slug === '50-shades') $slug = '50-shades-of-noah-grey';
    } else {
        $slug = basename(dirname($template));
    }
    $css = (string)@file_get_contents($root . '/skins/' . $slug . '/style.css');
    if ($css === '') continue;
    $checked++;

    // Every class on the anchor, including site-title-text where the anchor
    // itself carries it -- two skins put the wordmark class on the link.
    $classes = array_values(array_filter(
        preg_split('/\s+/', trim($m[1])) ?: [],
        static fn(string $c): bool => $c !== ''
    ));
    $check($classes !== [], $slug . ': masthead anchor carries no class to style');

    // A rule reaches the anchor only if the LAST simple selector in one of
    // its comma-separated parts names one of the anchor's own classes.
    // ".logo-area a" ends in "a" and so reaches a child, not the anchor --
    // which is exactly the mismatch that left eleven mastheads unstyled.
    $reaches = false;
    if (preg_match_all('/([^{}]+)\{([^}]*)\}/', $css, $rules, PREG_SET_ORDER)) {
        foreach ($rules as $rule) {
            if (!preg_match('/text-decoration|color\s*:/', $rule[2])) continue;
            foreach (explode(',', str_replace("
", ' ', $rule[1])) as $part) {
                $part = trim(preg_replace('/\s*>\s*/', ' ', $part) ?? '');
                if ($part === '') continue;
                $tokens = preg_split('/\s+/', $part) ?: [];
                $last = preg_replace('/:{1,2}[a-z-]+(\([^)]*\))?/', '', (string)end($tokens));
                foreach ($classes as $class) {
                    // A class name must end where it ends: ".site-title-text"
                    // CONTAINS ".site-title", and matching on substring made
                    // this test pass while the anchor was still unstyled.
                    if (preg_match('/\.' . preg_quote($class, '/') . '(?![\w-])/', (string)$last)) {
                        $reaches = true; break 3;
                    }
                }
            }
        }
    }
    $check($reaches,
        $slug . ': nothing styles the masthead anchor itself (class="' . $m[1] . '"). '
        . 'A rule for ".logo-area a" does not reach it -- the anchor IS the '
        . 'container here -- so the link renders in the browser default: blue, '
        . 'underlined, purple once visited');
}

$check($checked >= 14, 'expected at least 14 templates drawing a masthead, found ' . $checked);

printf("masthead anchors: %d templates draw one, every anchor is styled by its own skin.\n", $checked);

if ($failures) {
    fwrite(STDERR, "Masthead anchor regression FAILED:\n");
    foreach ($failures as $failure) fwrite(STDERR, '  - ' . $failure . "\n");
    exit(1);
}
echo "Masthead anchor regression passed.\n";

// ===== SNAPSMACK EOF =====
