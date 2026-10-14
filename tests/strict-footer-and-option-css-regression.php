<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * The footer the strict skins lost, and the option CSS that never applied.
 *
 * 1. THE FOOTER. Every skin used to end with core/footer.php, which renders a
 *    metadata bar -- copyright, contact email, theme, POWERED BY SNAPSMACK and
 *    the version, the privacy policy when enabled, and RSS -- plus a hidden
 *    h-card so fediverse and IndieWeb readers can tell whose site it is. The
 *    four skin renderers in core/ replaced all of it with one line,
 *    <p id="sig-text">site name</p>. Measured on the live site, the entire
 *    footer of photowalk.ing was that one line. The component and the view
 *    model that feed the bar already existed and were already used correctly
 *    by GAME ON and TILEZ; the four renderers simply never called them.
 *
 * 2. THE OPTION CSS. 143 of the 145 css values in the 30 skin manifests carry
 *    their own braces, because the admin compiler in smack-skin.php joins the
 *    selector and the css with nothing between them. The strict compiler added
 *    a second pair, so a site's own settings reached the page as
 *    "img.post-image {{ border: 1px solid #555555 !important; }}" -- invalid,
 *    and the browser discards the rule. Every custom- control on every strict
 *    skin was dead. Sean saw it as an About page whose photograph had lost the
 *    thin grey border he had chosen.
 */

if (!defined('SNAPSMACK_SKIN_RENDER')) define('SNAPSMACK_SKIN_RENDER', true);
if (!defined('BASE_URL')) define('BASE_URL', 'https://example.test/');
if (!defined('SNAPSMACK_VERSION')) define('SNAPSMACK_VERSION', 'Alpha 0.0.0D');
if (!defined('SNAPSMACK_VERSION_SHORT')) define('SNAPSMACK_VERSION_SHORT', '0.0.0D');

require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';
require_once dirname(__DIR__) . '/core/skin-presentation.php';

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $ok, string $what) use (&$failures): void {
    if (!$ok) $failures[] = $what;
};

// --- every strict renderer must render the shared footer ----------------
$renderers = ['50-shades', 'chaplin', 'galleria', 'impact-printer'];
foreach ($renderers as $slug) {
    $src = (string)@file_get_contents($root . '/core/' . $slug . '-page-component.php');
    $check($src !== '', $slug . ' renderer must exist');
    $check(str_contains($src, "snap_render_component('footer'"),
        $slug . ' must render the shared footer, not a bare site name');
    $check(!str_contains($src, 'id="sig-text"'),
        $slug . ' must not replace the footer with a one-line signature');
    $check(str_contains($src, "footer']['lowercase']"),
        $slug . ' must honour the footer-lowercase setting, as GAME ON and TILEZ do');
}

// --- the bar itself ------------------------------------------------------
$footer = [
    'lowercase' => false,
    'site_name' => 'photowalk.ing',
    'site_url'  => 'https://example.test/',
    'site_note' => 'Photographs & <b>rust</b>',
    'slots'     => [
        ['kind' => 'copyright', 'year' => '2026', 'site_name' => 'photowalk.ing'],
        ['kind' => 'email', 'email' => 'sean@example.test'],
        ['kind' => 'theme', 'name' => '50 SHADES OF NOAH GREY'],
        ['kind' => 'powered', 'version' => 'Alpha 0.7.874D'],
        ['kind' => 'link', 'label' => 'RSS', 'url' => 'https://example.test/feed'],
    ],
];
$bar = (string)snap_render_component('footer', ['footer' => $footer]);
foreach ([
    'footer-metadata-bar'  => 'the metadata bar',
    '&copy; 2026'          => 'the copyright year',
    'EMAIL: '              => 'the contact email',
    'THEME: '              => 'the theme name',
    'POWERED BY '          => 'the SnapSmack credit',
    'Alpha 0.7.874D'       => 'the version',
    '>RSS<'                => 'the RSS link',
    '<span class="sep">|</span>' => 'the separators between slots',
] as $needle => $what) {
    $check(str_contains($bar, $needle), 'the footer must carry ' . $what);
}

// --- the h-card identity -------------------------------------------------
$check(str_contains($bar, 'class="h-card'), 'the footer must publish an h-card');
$check(str_contains($bar, 'class="p-name" value="photowalk.ing"'), 'the h-card must name the site');
$check(str_contains($bar, 'class="u-url" href="https://example.test/"'), 'the h-card must link the site');
$check(str_contains($bar, 'hidden aria-hidden="true"'), 'the h-card is data, not something to read aloud');
$check(!str_contains($bar, '<b>rust</b>'), 'the h-card note must be escaped');
$check(!str_contains((string)snap_render_component('footer', ['footer' => ['slots' => []]]), 'h-card'),
    'a footer with no site name emits no empty h-card');

// --- escaping ------------------------------------------------------------
$nasty = (string)snap_render_component('footer', ['footer' => [
    'site_name' => '"><script>x</script>', 'site_url' => 'javascript:alert(1)',
    'slots' => [['kind' => 'text', 'text' => '<script>y</script>'],
                ['kind' => 'link', 'label' => 'z', 'url' => 'javascript:alert(2)']]]]);
$check(!str_contains($nasty, '<script>x'), 'the site name must be escaped');
$check(!str_contains($nasty, '<script>y'), 'custom slot text must be escaped');
$check(!str_contains($nasty, 'javascript:'), 'the footer must never emit a script scheme');

// --- one set of braces, never two ---------------------------------------
$check(snapsmack_skin_css_block('img.x', '{ border: 1px solid #555 !important; }')
    === 'img.x{border: 1px solid #555 !important;}',
    'a manifest css value that brings its own braces must not be wrapped again');
$check(snapsmack_skin_css_block('a.y', 'color:red') === 'a.y{color:red}',
    'a manifest css value without braces must still be wrapped');
$check(snapsmack_skin_css_block('b.z', '  {  }  ') === '',
    'an empty css value must produce no rule at all');

// --- and the real manifests must compile clean --------------------------
$checked = 0;
foreach (glob($root . '/skins/*/manifest.json') ?: [] as $path) {
    $manifest = json_decode((string)@file_get_contents($path), true);
    if (!is_array($manifest)) continue;
    $defs = $manifest['options'] ?? $manifest['controls'] ?? [];
    if (!is_array($defs)) continue;
    // Drive every select control to its first option. A site that picks any
    // option at all takes this same path through the compiler.
    $options = [];
    foreach ($defs as $key => $definition) {
        if (!is_array($definition) || !is_array($definition['options'] ?? null)) continue;
        $first = array_key_first($definition['options']);
        if ($first !== null) $options[$key] = (string)$first;
    }
    if (!$options) continue;
    $style = snapsmack_declared_skin_style(basename(dirname($path)), $options);
    $check(!str_contains($style, '{{'),
        basename(dirname($path)) . ' compiles a doubled brace, which voids the whole rule');
    $checked++;
}
$check($checked >= 20, 'expected to compile at least 20 skins, compiled ' . $checked);

printf("strict footer restored in %d renderers; %d skins compile without a doubled brace.\n",
    count($renderers), $checked);

if ($failures) {
    fwrite(STDERR, "Strict footer / option css regression FAILED:\n");
    foreach ($failures as $failure) fwrite(STDERR, '  - ' . $failure . "\n");
    exit(1);
}
echo "Strict footer and option css regression passed.\n";

// ===== SNAPSMACK EOF =====
