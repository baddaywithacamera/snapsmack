<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

// A DECLARED FONT KEEPS ITS OWN CATEGORY, AND ITS OWN STACK. (SS-1519)
//
// The compiler used to answer sans-serif for every face that was not
// monospace, so a serif compiled to `"Source Serif 4", sans-serif` and a
// reader whose browser had not got the webfont read a serif-designed page in
// a sans. It was live on wateronthebrain.ca, and it reached 48 of the 61
// declared font controls in the product.
//
// THREE SOURCES, in the order the PM ruled: the option's own declared
// fallback, then a table of the families we actually offer, then NOTHING —
// an unknown face emits no generic rather than a guessed one, because the
// guess is the fault.
//
// Everything below is EXECUTED. The compiler is run; no assertion reads the
// source of the thing it is testing.

$root = dirname(__DIR__);
if (!defined('BASE_URL')) define('BASE_URL', '/');
require_once $root . '/core/font-stack.php';
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/skin-presentation.php';

$failures = [];
$check = static function (string $what, bool $ok) use (&$failures): void {
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $what);
    if (!$ok) { $failures[] = $what; }
};

echo "SS-1519: a declared font keeps its own category\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — THE LIVE DEFECT, ON THE SKIN IT WAS FOUND ON\n";
// ─────────────────────────────────────────────────────────────────────────────
// RATIONAL GEO runs wateronthebrain.ca and its body_font default is a serif.
// Driven end to end through the same function index.php renders with.
$style = static function (string $skin, array $options): string {
    return trim(strip_tags(snapsmack_declared_skin_style($skin, $options)));
};

$rg = $style('rational-geo', ['body_font' => 'Source Serif 4']);
$check('RATIONAL GEO\'s serif body font compiles with a serif fallback',
       str_contains($rg, '"Source Serif 4", serif'));
$check('  and not the sans-serif it used to emit',
       !str_contains($rg, 'sans-serif'));
$check('a sans choice on the same control still compiles sans-serif',
       str_contains($style('rational-geo', ['body_font' => 'DM Sans']), '"DM Sans", sans-serif'));
$check('and its monospace EXIF face keeps the monospace stack (SS-1214)',
       str_contains($style('rational-geo', ['exif_font' => 'DM Mono']),
                    '"DM Mono", \'Courier New\', monospace'));

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — A SKIN THAT DECLARES NO FONT CONTROL IS UNTOUCHED\n";
// ─────────────────────────────────────────────────────────────────────────────
// ALFRED is the other skin named in the ruling. It declares no font control,
// so the whole of this change must be invisible to it: a repair to a shared
// compiler that moves a skin which declared nothing would be a regression.
$alfred = $style('alfred', ['accent_color' => '#1e73be', 'post_content_width' => '800']);
$check('ALFRED still compiles exactly its accent and width, and no font',
       $alfred === ':root{--alfred-accent:#1e73be;}.post-inner{width:800px;}');

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — THE THREE SOURCES, IN ORDER\n";
// ─────────────────────────────────────────────────────────────────────────────
$check('a declared fallback is used as given',
       snapsmack_font_family_generic_fallback('Droid Serif', 'Georgia, serif') === 'Georgia, serif');
$check('  and beats the table, even when the table disagrees',
       snapsmack_font_family_generic_fallback('Georgia', 'sans-serif') === 'sans-serif');
$check('  which is how ALFRED keeps Georgia when it declares its control',
       snapsmack_font_family_generic_fallback('Droid Serif', 'Georgia, serif') !== 'serif');
$check('the table answers when nothing is declared',
       snapsmack_font_family_generic_fallback('Playfair Display') === 'serif');
$check('an unknown house face gets NO generic rather than a guess',
       snapsmack_font_family_generic_fallback('BlackCasper') === '');

// TWO ENTRIES I GOT WRONG, PINNED BY WHAT THE FONT FILES SAY. I classified
// both of these keyboard faces as monospace from their names, which is the
// exact reasoning this ticket exists to stop. Muse read KeyBinds and caught
// it; checking that sent me to the other one. Read out of the bytes in
// assets/fonts/: KeyBinds has post.isFixedPitch 0 and two advance widths
// across 215 glyphs (1916, 2048); the Linux Biolinum keyboard cut has 134
// widths across 687. Both are proportional. Both fell back to sans-serif
// before SS-1519, so my entries were a regression, on HIP TO BE SQUARE's
// default body font.
$check('KeyBinds is proportional, not monospace (the font file says so)',
       snapsmack_font_family_generic_fallback('KeyBinds') === 'sans-serif');
$check('  and so is the Linux Biolinum keyboard cut',
       snapsmack_font_family_generic_fallback('LinuxBiolinumKeyboard') === 'sans-serif');
// DotMatrix stays monospace and that is not an oversight: one file carries
// one spacing width (DotMatrix-Regular, 1223 for every non-zero glyph). The
// family shows several widths only because condensed and expanded are
// separate faces, which is what they are meant to be.
$check('DotMatrix is still monospace, which its own file supports',
       snapsmack_font_family_generic_fallback('DotMatrix') === "'Courier New', monospace");
$check('  and takes a declared stack when the skin gives it one',
       snapsmack_font_family_generic_fallback('BlackCasper', 'Impact, sans-serif') === 'Impact, sans-serif');
$check('an empty family asks for nothing',
       snapsmack_font_family_generic_fallback('') === '');

// A face with no generic must not emit a dangling comma, which would be a
// CSS parse error and would take the whole declaration with it.
$check('a face with no generic emits no trailing comma',
       str_contains($style('rational-geo', ['body_font' => 'Victorious']),
                    '{font-family:"Victorious";}'));

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4 — A DECLARED STACK, THROUGH A REAL MANIFEST\n";
// ─────────────────────────────────────────────────────────────────────────────
// Stage 3 drives the helper. This drives the whole compiler against a skin
// that really declares a fallback, because a helper that works and a
// compiler that never passes the declaration to it would both pass Stage 3.
//
// IMPACT PRINTER's stylesheet reads 'DotMatrix', 'Courier New', monospace,
// and all 29 of its choices are the one house monospace family, so the
// declaration is right for every one of them. Before this, choosing a
// variant dropped the base family: the look fell straight to Courier New
// instead of to plain DotMatrix.
$ip = static fn(string $k, string $v): string => $style('impact-printer', [$k => $v]);

$check('a chosen variant falls back to the base family first',
       str_contains($ip('body_font_family', 'DotMatrix-Condensed-Bold'),
                    '"DotMatrix-Condensed-Bold", \'DotMatrix\', \'Courier New\', monospace'));
$check('  the masthead keeps the stack its stylesheet states',
       str_contains($ip('header_font_family', 'DotMatrix-Expanded-Bold'),
                    '"DotMatrix-Expanded-Bold", \'DotMatrix\', \'Courier New\', monospace'));
$check('choosing the base family itself does not repeat it',
       str_contains($ip('body_font_family', 'DotMatrix'),
                    '"DotMatrix", \'Courier New\', monospace')
       && !str_contains($ip('body_font_family', 'DotMatrix'), '"DotMatrix", \'DotMatrix\''));
$check('  so a site on the default compiles exactly what it did before',
       str_contains($ip('body_font_family', 'DotMatrix'),
                    '.description, .static-content{font-family:"DotMatrix", \'Courier New\', monospace;}'));
// The third IMPACT PRINTER control, which I missed on the first pass and the
// PM approved from the decision list. Its selector list includes
// .photo-title-footer, whose stylesheet rule is 'DotMatrix-Bold',
// 'DotMatrix', 'Courier New', monospace — so a chosen variant has to fall
// back to the base family before Courier, like the other two.
$check('the static heading control keeps its stack too',
       str_contains($ip('static_heading_font', 'DotMatrix-Bold'),
                    '"DotMatrix-Bold", \'DotMatrix\', \'Courier New\', monospace'));
$check('  and the base family still does not repeat itself',
       str_contains($ip('static_heading_font', 'DotMatrix'),
                    '"DotMatrix", \'Courier New\', monospace')
       && !str_contains($ip('static_heading_font', 'DotMatrix'), '\'DotMatrix\', \'DotMatrix\''));

// WRITING WITH IMPACT, the other control the PM approved. All 29 choices are
// the same house monospace family, and its masthead stack carries a second
// house face before the base one.
$wwi = static fn(string $v): string => $style('writing-with-impact', ['header_font_family' => $v]);
$check('WRITING WITH IMPACT keeps the two house faces in its masthead stack',
       str_contains($wwi('DotMatrix-Expanded-Bold'),
                    '"DotMatrix-Expanded-Bold", \'Tiny5-Matrix\', \'DotMatrix\', monospace'));
$check('  and a face chosen from inside its own tail is not repeated',
       str_contains($wwi('Tiny5-Matrix'), '"Tiny5-Matrix", \'DotMatrix\', monospace'));

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 5 — EVERY FACE EVERY SKIN OFFERS, SWEPT\n";
// ─────────────────────────────────────────────────────────────────────────────
// The point of the ticket was breadth: 48 of 61 controls were exposed. This
// walks every font choice in every manifest and fails on any that still
// answers sans-serif while naming itself a serif, which is the exact shape
// of the original fault.
$offered = [];
foreach ((array)glob($root . '/skins/*/manifest.json') as $path) {
    $manifest = json_decode((string)file_get_contents($path), true);
    if (!is_array($manifest)) continue;
    foreach ((array)($manifest['options'] ?? []) as $definition) {
        if (!is_array($definition)) continue;
        if (($definition['property'] ?? '') !== 'font-family' && empty($definition['is_font'])) continue;
        foreach (array_keys((array)($definition['options'] ?? [])) as $face) {
            $offered[(string)$face] = true;
        }
        if (($definition['default'] ?? '') !== '') $offered[(string)$definition['default']] = true;
    }
}
$offered = array_keys($offered);
$check('the sweep found the font choices to walk', count($offered) > 100);

$miscategorised = [];
$unclassified = [];
foreach ($offered as $face) {
    if ($face === 'inherit') continue;
    $tail = snapsmack_font_family_generic_fallback($face);
    if ($tail === '') { $unclassified[] = $face; continue; }
    // A face that says "serif" in its name and is not a sans must not be
    // answered with sans-serif. This is a narrow, deliberate check: it is the
    // original fault's signature, not a general classifier.
    $saysSerif = stripos($face, 'serif') !== false && stripos($face, 'sans') === false;
    if ($saysSerif && $tail === 'sans-serif') $miscategorised[] = $face;
}
$check('no face naming itself a serif answers sans-serif',
       $miscategorised === []);
if ($miscategorised) { echo '        ' . implode(', ', $miscategorised) . "\n"; }

// Unclassified faces are allowed — they emit no generic, which is the safe
// answer — but the list is printed so it stays visible rather than growing
// quietly.
printf("  [--] %d of %d offered faces are unclassified and emit no generic:\n        %s\n",
       count($unclassified), count($offered),
       $unclassified ? implode(', ', $unclassified) : '(none)');

echo "\n";
if ($failures) {
    fwrite(STDERR, 'SS-1519 FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
    exit(1);
}
echo "SS-1519 PASS — a serif compiles with a serif fallback, a declared stack\n";
echo "wins over the table, an unknown face gets no guess, a skin that declares\n";
echo "no font is untouched, and no offered face still answers sans-serif while\n";
echo "naming itself a serif.\n";
echo "NOT CHECKED: a rendered glyph. This reads compiled CSS, not a browser.\n";
echo "Whether each classification is RIGHT is a judgement about typefaces, and\n";
echo "any entry in that table can be corrected.\n";
// ===== SNAPSMACK EOF =====
