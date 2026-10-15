<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * A monochrome skin must be incapable of showing colour.
 *
 * Sean, on IMPACT PRINTER: "no colour should ever appear in that skin for any
 * reason. i am gobsmacked you're letting them in." He was looking at a purple
 * underline -- the browser's visited-link colour, showing because the masthead
 * anchor was reached by no rule at all. Patching each unstyled link as it is
 * found is how that kept happening, so the skin now makes every link inherit
 * its colour: a rule has to go out of its way to introduce one.
 *
 * This checks two things, and the second is the one that matters. The first is
 * that the guard exists. The second is that the stylesheet declares no colour
 * outside a short, named list of exceptions -- because an audit is only worth
 * anything if it reads the WHOLE file. My first pass at this missed
 * "border: 1px solid red" entirely: I searched for a colour after a colon,
 * and that one sits after a width and a style.
 */

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $ok, string $what) use (&$failures): void {
    if (!$ok) $failures[] = $what;
};

/**
 * Skins that are monochrome by design, and the colour each is allowed to
 * keep. An exception is named here or it is a failure -- the list is the
 * argument, not a silent pass.
 */
$MONOCHROME = [
    'impact-printer' => [
        // The SQL error notice. An error that cannot be seen is worse than
        // a colour in a grey skin, so it keeps its red until Sean says
        // otherwise; it appears only when the database has already failed.
        '#200', '#f99', 'red',
    ],
];

foreach ($MONOCHROME as $skin => $allowed) {
    $css = (string)@file_get_contents($root . '/skins/' . $skin . '/style.css');
    $check($css !== '', $skin . ': stylesheet not found');
    if ($css === '') continue;

    // --- no unstyled link may fall back to a browser default -------------
    foreach ([':link', ':visited', ':hover', ':active'] as $state) {
        $check(preg_match('/a' . preg_quote($state, '/') . '\b[^{]*\{[^}]*color\s*:/', $css) === 1,
            $skin . ': a' . $state . ' must be given a colour, or the browser '
            . 'supplies one -- blue, and purple once visited');
    }
    $check(preg_match('/a:visited\b[^{]*\{[^}]*color\s*:\s*inherit/', $css) === 1,
        $skin . ': a:visited must inherit, which is what makes purple impossible');

    // --- every colour the stylesheet declares, read from the WHOLE file --
    // Comments first: this check flagged "blue" and "purple" out of the very
    // comment explaining why they must not appear. A stylesheet's prose is
    // not its styling.
    $declared = preg_replace('#/\*.*?\*/#s', ' ', $css) ?? $css;
    $offenders = [];

    // hex, anywhere
    if (preg_match_all('/#([0-9a-fA-F]{3,8})\b/', $declared, $m)) {
        foreach ($m[1] as $hex) {
            $full = strlen($hex) <= 4
                ? $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2]
                : substr($hex, 0, 6);
            if (strlen($full) < 6) continue;
            $r = hexdec(substr($full, 0, 2));
            $g = hexdec(substr($full, 2, 2));
            $b = hexdec(substr($full, 4, 2));
            if ($r === $g && $g === $b) continue;              // greyscale
            if (in_array('#' . $hex, $allowed, true)) continue; // named exception
            $offenders[] = '#' . $hex;
        }
    }

    // rgb()/rgba(), anywhere
    if (preg_match_all('/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/', $declared, $m, PREG_SET_ORDER)) {
        foreach ($m as $c) {
            if ($c[1] === $c[2] && $c[2] === $c[3]) continue;
            $offenders[] = 'rgb(' . $c[1] . ',' . $c[2] . ',' . $c[3] . ')';
        }
    }

    // named colours ANYWHERE -- not only after a colon. "border: 1px solid
    // red" is the one my first audit walked straight past.
    $names = 'red|green|blue|orange|purple|yellow|pink|cyan|magenta|teal|navy'
        . '|lime|maroon|olive|aqua|fuchsia|gold|coral|salmon|crimson|indigo|violet';
    if (preg_match_all('/\b(' . $names . ')\b/i', $declared, $m)) {
        foreach ($m[1] as $name) {
            if (in_array(strtolower($name), array_map('strtolower', $allowed), true)) continue;
            $offenders[] = strtolower($name);
        }
    }

    $offenders = array_values(array_unique($offenders));
    $check($offenders === [],
        $skin . ' is a monochrome skin and declares colour: ' . implode(', ', $offenders)
        . '. Add it to the allowed list with a reason, or take it out.');
}

printf("monochrome skins: %d checked, every link inherits, no unlisted colour declared.\n",
    count($MONOCHROME));

if ($failures) {
    fwrite(STDERR, "Monochrome skin colour regression FAILED:\n");
    foreach ($failures as $failure) fwrite(STDERR, '  - ' . $failure . "\n");
    exit(1);
}
echo "Monochrome skin colour regression passed.\n";

// ===== SNAPSMACK EOF =====
