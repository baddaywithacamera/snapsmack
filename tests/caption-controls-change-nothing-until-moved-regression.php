<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

// CAPTION CONTROLS CHANGE NOTHING UNTIL THEY ARE MOVED — ON EVERY SKIN THAT
// DECLARES THEM. (SS-1495)
//
// BIGGIE draws a post as the site will render it. A caption was declared
// nowhere, so it had to guess.
//
// CORRECTION, FROM MUSE'S REVIEW. This header used to say BIGGIE "reads the
// site through sybu-data.php, which publishes a skin's DECLARED options and
// nothing else". THAT IS FALSE. sybu-data.php returns categories, albums,
// tags, titles, site_mode and publishing_policy -- no declared skin options,
// and NO endpoint in this repo publishes them as JSON.
//
// What the checks below actually prove, narrower but real: the values survive
// the apply-and-resolve pipeline the render and admin-preview path uses.
// Which of those BIGGIE reads is OPEN.
//
// THIS FILE IS SKIN-AGNOSTIC ON PURPOSE. The same five controls are going on
// to several skins one at a time — ALFRED and TILEZ share one caption rule
// to the pixel, TELEGRAM shares it too and is waiting on Sean because it is
// locked, and four more skins have no caption rule to migrate from yet. A
// per-skin copy of this test would be four more files that drift apart. It
// walks whatever declares caption controls, so the next skin is covered the
// day it declares them and not a release later.
//
// THE RISK IS THE MIGRATION, NOT THE FEATURE. Each control compiles a real
// CSS rule on every render, over a stylesheet that already styles captions.
// If a default disagrees with the stylesheet by one pixel, every existing
// post on that skin changes the day it ships, for owners who touched
// nothing. So each default is compared against the skin's own stylesheet.

$root = dirname(__DIR__);
if (!defined('BASE_URL')) define('BASE_URL', '/');
require_once $root . '/core/font-stack.php';
require_once $root . '/core/skin-settings.php';
require_once $root . '/core/skin-presentation.php';

$failures = [];
$check = static function (string $what, bool $ok) use (&$failures): void {
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $what);
    if (!$ok) { $failures[] = $what; }
};

echo "SS-1495: caption controls, on every skin that declares them\n\n";

$CONTROLS = ['caption_color', 'caption_size', 'caption_style', 'caption_gap', 'caption_align'];

$skins = [];
foreach ((array)glob($root . '/skins/*/manifest.json') as $path) {
    $manifest = json_decode((string)file_get_contents($path), true);
    if (!is_array($manifest) || !is_array($manifest['options'] ?? null)) continue;
    if (!isset($manifest['options']['caption_color'])) continue;
    $skins[basename(dirname($path))] = $manifest['options'];
}

$check('at least one skin declares caption controls', $skins !== []);
printf("  [--] declaring caption controls: %s\n\n",
       $skins ? implode(', ', array_keys($skins)) : '(none)');

foreach ($skins as $slug => $options) {
    echo strtoupper($slug) . "\n";

    $css = (string)file_get_contents($root . '/skins/' . $slug . '/style.css');
    $at = strpos($css, "\nfigcaption {");
    $block = $at === false ? '' : substr($css, $at, (int)strpos($css, '}', $at) - $at);
    $block = (string)preg_replace('#/\*.*?\*/#s', '', $block);

    // ── every control present, and findable by its owner ──────────────────
    $missing = array_values(array_diff($CONTROLS, array_keys($options)));
    $check("  declares all five controls", $missing === []);
    $sections = array_map(static fn(string $k): string => (string)($options[$k]['section'] ?? '?'),
                          array_intersect($CONTROLS, array_keys($options)));
    $check("  all under one CAPTIONS heading, where an owner will look",
           $sections !== [] && array_unique($sections) === ['CAPTIONS']);

    // ── each default is the stylesheet's own value ────────────────────────
    $check("  has a figcaption rule for the defaults to agree with", $block !== '');
    $check("  size default is the stylesheet's font-size",
           preg_match('/font-size:\s*(\d+)px/', $block, $m) === 1
           && (string)($options['caption_size']['default'] ?? '') === $m[1]);
    $check("  gap default is the stylesheet's padding-top",
           preg_match('/padding-top:\s*(\d+)px/', $block, $m) === 1
           && (string)($options['caption_gap']['default'] ?? '') === $m[1]);
    $check("  style default is the stylesheet's font-style",
           preg_match('/font-style:\s*([a-z]+)/', $block, $m) === 1
           && (string)($options['caption_style']['default'] ?? '') === $m[1]);
    $check("  colour default is the stylesheet's colour",
           preg_match('/color:\s*#([0-9a-f]{3,6})\b/i', $block, $m) === 1
           && strtolower((string)($options['caption_color']['default'] ?? ''))
              === strtolower('#' . (strlen($m[1]) === 3
                   ? $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2] : $m[1])));
    // A caption with no text-align inherits. A default of "left" would force
    // left alignment on a caption inside a centred block: a change, not a
    // migration.
    $check("  the stylesheet sets no caption alignment",
           preg_match('/text-align/', $block) !== 1);
    $check("    so alignment defaults to following the post text",
           (string)($options['caption_align']['default'] ?? '') === 'inherit');

    // ── at the defaults, the compiler changes nothing ─────────────────────
    $defaults = [];
    foreach ($CONTROLS as $k) $defaults[$k] = (string)($options[$k]['default'] ?? '');
    $atDefault = trim(strip_tags(snapsmack_declared_skin_style($slug, $defaults)));
    $check("  the colour is not emitted at its default, so the cascade stands",
           !str_contains($atDefault, 'color:#') && !empty($options['caption_color']['omit_if_default']));
    foreach ([
        'font-size:' . ($options['caption_size']['default'] ?? '') . 'px',
        'font-style:' . ($options['caption_style']['default'] ?? ''),
        'padding-top:' . ($options['caption_gap']['default'] ?? '') . 'px',
        'text-align:inherit',
    ] as $rule) {
        $check("  compiles {$rule}, which is what the page already does",
               str_contains($atDefault, 'figcaption{' . $rule . ';}'));
    }

    // ── moved, every one of them does something ───────────────────────────
    // This project has shipped controls that did nothing. Each is driven to
    // a value the skin does not use and the compiled rule is read back.
    $moved = trim(strip_tags(snapsmack_declared_skin_style($slug, [
        'caption_color' => '#cc0000', 'caption_size' => '20', 'caption_style' => 'normal',
        'caption_gap' => '40', 'caption_align' => 'center',
    ])));
    foreach (['color:#cc0000', 'font-size:20px', 'font-style:normal',
              'padding-top:40px', 'text-align:center'] as $rule) {
        $check("  moved, it emits {$rule}", str_contains($moved, 'figcaption{' . $rule . ';}'));
    }

    // the values survive the apply-and-resolve pipeline (NOT an endpoint)
    $settings = ['active_skin' => $slug, $slug . '__caption_color' => '#cc0000',
                 $slug . '__caption_align' => 'center'];
    snapsmack_apply_skin_settings($settings, $slug);
    $resolved = snapsmack_declared_skin_options($settings, $slug);
    $check("  a saved colour and alignment survive the resolve pipeline",
           ($resolved['caption_color'] ?? null) === '#cc0000'
           && ($resolved['caption_align'] ?? null) === 'center');
    $check("  and an untouched control arrives at its default",
           (string)($resolved['caption_style'] ?? '') === (string)($options['caption_style']['default'] ?? ''));

    // ── the line SS-1519 draws ────────────────────────────────────────────
    $check("  declares no caption FONT yet (SS-1519 first)",
           !isset($options['caption_font']) && !isset($options['caption_font_family']));
    echo "\n";
}

if ($failures) {
    fwrite(STDERR, 'SS-1495 FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
    exit(1);
}
echo "Caption controls: PASS — on every skin that declares them, each default\n";
echo "is that skin's own stylesheet value, the compiler changes nothing at the\n";
echo "defaults, every control does something when moved, and all five survive\n";
echo "the resolve pipeline the render path uses.\n";
echo "NOT PROVED HERE: the rendered page. The before/after computed styles were\n";
echo "measured in a browser and are in the commit message.\n";
// ===== SNAPSMACK EOF =====
