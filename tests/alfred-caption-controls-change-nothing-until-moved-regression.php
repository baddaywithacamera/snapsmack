<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

// ALFRED'S CAPTION CONTROLS CHANGE NOTHING UNTIL THEY ARE MOVED. (SS-1495)
//
// BIGGIE draws a post as the site will render it, and reads the site through
// sybu-data.php, which publishes a skin's DECLARED options and nothing else.
// A caption was declared nowhere, so BIGGIE had to guess. These five controls
// are what make the preview honest, and they give the owner settings that did
// not exist before.
//
// THE RISK IS THE MIGRATION, NOT THE FEATURE. Every one of these compiles a
// real CSS rule on every render, over a stylesheet that already styles
// captions. If a default here disagrees with the stylesheet by even one
// pixel, every existing ALFRED post changes the day this ships, for owners
// who touched nothing. So each default below is read out of style.css, and
// the compiled output at the defaults was measured in a browser against the
// same page without it: colour, size, style, family, padding and alignment
// identical, including inside a centred block and under a full-bleed
// photograph. Those numbers are in the commit message.
//
// NOT DECLARED HERE: the caption FONT. It waits on SS-1519, which was the
// compiler answering sans-serif for every face that was not monospace.
// Stage 4 holds that line.

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

echo "SS-1495: ALFRED's caption controls\n\n";

$manifest = json_decode((string)file_get_contents($root . '/skins/alfred/manifest.json'),
                        true, 512, JSON_THROW_ON_ERROR);
$options = (array)($manifest['options'] ?? []);
$css = (string)file_get_contents($root . '/skins/alfred/style.css');

// The skin's own figcaption block, comments stripped, so a default can be
// compared against the declaration rather than against a comment.
$at = strpos($css, "\nfigcaption {");
$block = $at === false ? '' : substr($css, $at, (int)strpos($css, '}', $at) - $at);
$block = (string)preg_replace('#/\*.*?\*/#s', '', $block);

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — EVERY DEFAULT IS THE VALUE THE STYLESHEET ALREADY SETS\n";
// ─────────────────────────────────────────────────────────────────────────────
// Read out of style.css, not chosen. If someone edits the stylesheet without
// moving the default, this fails rather than silently overriding the skin.
$check('the skin still has a figcaption block to agree with', $block !== '');

// A control the owner cannot find is a control that does nothing for them.
// The admin groups by section, so all five have to sit under one heading
// that says what they are. A mutation renaming the section survived an
// earlier version of this file: every control still worked, and none of them
// was anywhere an owner would look.
$sections = [];
foreach (['caption_color', 'caption_size', 'caption_style', 'caption_gap', 'caption_align'] as $k) {
    $sections[] = (string)($options[$k]['section'] ?? '(missing)');
}
$check('all five controls are declared, under one section named for captions',
       $sections === array_fill(0, 5, 'CAPTIONS'));
$check('caption_size default is the stylesheet\'s font-size',
       ($options['caption_size']['default'] ?? null) === '14'
       && preg_match('/font-size:\s*14px/', $block) === 1);
$check('caption_style default is the stylesheet\'s font-style',
       ($options['caption_style']['default'] ?? null) === 'italic'
       && preg_match('/font-style:\s*italic/', $block) === 1);
$check('caption_gap default is the stylesheet\'s padding-top',
       ($options['caption_gap']['default'] ?? null) === '12'
       && preg_match('/padding-top:\s*12px/', $block) === 1);
$check('caption_color default is the stylesheet\'s colour',
       strtolower((string)($options['caption_color']['default'] ?? '')) === '#666666'
       && preg_match('/color:\s*#666\b/', $block) === 1);
// The stylesheet sets NO text-align on a caption, so the honest default is to
// keep inheriting. A default of "left" would have forced left alignment on
// captions inside a centred block, which is a change, not a migration.
$check('the stylesheet sets no caption alignment',
       preg_match('/text-align/', $block) !== 1);
$check('  so caption_align defaults to following the post text',
       ($options['caption_align']['default'] ?? null) === 'inherit');

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — AT THE DEFAULTS, THE COMPILER CHANGES NOTHING\n";
// ─────────────────────────────────────────────────────────────────────────────
$defaults = [];
foreach (['caption_color', 'caption_size', 'caption_style', 'caption_gap', 'caption_align'] as $k) {
    $defaults[$k] = (string)($options[$k]['default'] ?? '');
}
$atDefault = trim(strip_tags(snapsmack_declared_skin_style('alfred', $defaults)));

$check('the colour is not emitted at its default, so the skin\'s cascade stands',
       !str_contains($atDefault, 'color:#'));
$check('  which is what omit_if_default is declared for',
       !empty($options['caption_color']['omit_if_default']));
$check('the size compiles to the stylesheet\'s own 14px',
       str_contains($atDefault, 'figcaption{font-size:14px;}'));
$check('the style compiles to the stylesheet\'s own italic',
       str_contains($atDefault, 'figcaption{font-style:italic;}'));
$check('the gap compiles to the stylesheet\'s own 12px',
       str_contains($atDefault, 'figcaption{padding-top:12px;}'));
$check('alignment compiles to inherit, not to a forced side',
       str_contains($atDefault, 'figcaption{text-align:inherit;}'));

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — MOVED, EVERY ONE OF THEM DOES SOMETHING\n";
// ─────────────────────────────────────────────────────────────────────────────
// This project has shipped controls that did nothing. Each is driven to a
// value the skin does not already use, and the compiled rule is read back.
$moved = trim(strip_tags(snapsmack_declared_skin_style('alfred', [
    'caption_color' => '#cc0000', 'caption_size' => '20', 'caption_style' => 'normal',
    'caption_gap' => '40', 'caption_align' => 'center',
])));
foreach ([
    'colour'    => 'figcaption{color:#cc0000;}',
    'size'      => 'figcaption{font-size:20px;}',
    'style'     => 'figcaption{font-style:normal;}',
    'gap'       => 'figcaption{padding-top:40px;}',
    'alignment' => 'figcaption{text-align:center;}',
] as $label => $rule) {
    $check("the {$label} control reaches the page when moved", str_contains($moved, $rule));
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4 — THE VALUES REACH sybu-data, AND THE FONT DOES NOT YET\n";
// ─────────────────────────────────────────────────────────────────────────────
// The resolution sybu-data.php performs: scoped settings, applied, resolved.
$settings = ['active_skin' => 'alfred', 'alfred__caption_color' => '#cc0000',
             'alfred__caption_size' => '20', 'alfred__caption_align' => 'center'];
snapsmack_apply_skin_settings($settings, 'alfred');
$resolved = snapsmack_declared_skin_options($settings, 'alfred');

$check('an owner\'s saved caption colour reaches the endpoint',
       ($resolved['caption_color'] ?? null) === '#cc0000');
$check('  and their alignment', ($resolved['caption_align'] ?? null) === 'center');
$check('  and a control they never touched arrives at its default',
       (string)($resolved['caption_style'] ?? '') === 'italic');
$check('every caption control is published, so BIGGIE need not guess',
       count(array_intersect(['caption_color', 'caption_size', 'caption_style',
                              'caption_gap', 'caption_align'],
                             array_keys($resolved))) === 5);

// THE LINE SS-1519 DRAWS. A caption font control compiled before that fix
// would hand every serif a sans-serif fallback, on captions this time.
$check('no caption FONT control is declared yet (SS-1519 first)',
       !isset($options['caption_font']) && !isset($options['caption_font_family']));

echo "\n";
if ($failures) {
    fwrite(STDERR, 'SS-1495 FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) { fwrite(STDERR, "  - {$f}\n"); }
    exit(1);
}
echo "SS-1495 PASS — each default is the stylesheet's own value, the compiler\n";
echo "changes nothing at the defaults, every control does something when moved,\n";
echo "and all five reach the endpoint BIGGIE reads.\n";
echo "NOT PROVED HERE: the rendered page. The before/after computed styles were\n";
echo "measured in a browser and are in the commit message.\n";
// ===== SNAPSMACK EOF =====
