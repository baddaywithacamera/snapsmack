<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * A static page is not a photograph.
 *
 * 50 Shades rendered its static pages through skins/50-shades-of-noah-grey/
 * skin-page.php until that file was deleted on 2026-09-28. Pages then fell
 * into the photograph branch of the CMS renderer and came out wearing the
 * photo layout: an #fsog-photobox article, photo navigation, and a technical-
 * specifications slot on an About page.
 *
 * Measured on the live site before this repair, https://photowalk.ing/about
 * contained the strings .static-content, .static-page-title and .description
 * ONLY inside its <style> block -- the skin still styled all three, and the
 * elements themselves were never emitted. #photobox.page-hero and .main-photo
 * were absent everywhere, with 3 and 2 rules respectively still in the skin's
 * stylesheet waiting for markup that no longer arrived.
 */

if (!defined('SNAPSMACK_SKIN_RENDER')) define('SNAPSMACK_SKIN_RENDER', true);
if (!defined('BASE_URL')) define('BASE_URL', 'https://example.test/');
if (!defined('SNAPSMACK_VERSION_SHORT')) define('SNAPSMACK_VERSION_SHORT', '0.0.0D');

require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $ok, string $what) use (&$failures): void {
    if (!$ok) $failures[] = $what;
};

$render = static function (array $item, string $kind = 'page') use ($root): string {
    $view = [
        'site' => [
            'language' => 'en', 'direction' => 'ltr', 'site_name' => 'photowalk.ing',
            'skin_style_url' => 'https://example.test/style.css',
        ],
        'response' => ['kind' => $kind, 'page_title' => 'About', 'item' => $item, 'navigation' => []],
    ];
    ob_start();
    include $root . '/core/50-shades-page-component.php';
    return (string)ob_get_clean();
};

$content = snapsmack_trusted_html('<p>Hello</p>');

// --- a page with a hero image gets the structure its CSS expects ---------
$withHero = $render(['title' => 'About Sean', 'content' => $content, 'image_asset' => 'uploads/about.jpg']);
foreach ([
    'class="static-content"'        => 'the static content wrapper',
    'class="static-page-title"'     => 'the page title',
    'id="photobox" class="page-hero"' => 'the hero wrapper the skin styles',
    'class="main-photo"'            => 'the hero image wrapper',
    'class="description'            => 'the content wrapper',
] as $needle => $what) {
    $check(str_contains($withHero, $needle), 'a static page must emit ' . $what);
}
$check(str_contains($withHero, 'https://example.test/uploads/about.jpg'),
    'the hero image must resolve against the site base');

// --- and none of the photograph furniture -------------------------------
foreach (['fsog-photobox' => 'the photograph article',
          'TECHNICAL SPECIFICATIONS' => 'the EXIF table',
          'photo-navigation' => 'photograph navigation'] as $needle => $what) {
    $check(!str_contains($withHero, $needle), 'a static page must not carry ' . $what);
}

// --- no hero declared, no empty hero markup -----------------------------
$noHero = $render(['title' => 'About Sean', 'content' => $content, 'image_asset' => '']);
$check(str_contains($noHero, 'class="static-content"'), 'a page without a hero still gets its wrapper');
$check(!str_contains($noHero, 'page-hero'), 'no hero image means no hero markup');
$check(!str_contains($noHero, 'main-photo'), 'no hero image means no photo wrapper');

// --- escaping ------------------------------------------------------------
$nasty = $render(['title' => '<script>x</script>', 'content' => $content,
                  'image_asset' => 'javascript:alert(1)']);
$check(!str_contains($nasty, '<script>x</script>'), 'the page title must be escaped');
// The hero path is always joined to the site base, so it cannot become a
// bare javascript: url; what matters is that the src stays an http(s) address
// under this site, and snap_escape_url() would blank anything else.
preg_match('/<img src="([^"]*)"/', $nasty, $src);
$check(!empty($src[1]) && str_starts_with($src[1], 'https://example.test/'),
    'the hero src must stay under the site base, got ' . ($src[1] ?? 'nothing'));
$check(!preg_match('/src="javascript:/', $nasty), 'the src must never carry a script scheme');

// --- a photograph is still a photograph ---------------------------------
$photo = $render(['img_title' => 'A robin', 'img_file' => 'https://example.test/r.jpg'], 'photo');
$check(str_contains($photo, 'fsog-photobox'), 'a photograph still renders the photograph article');

// --- the classes the skin styles must be the ones emitted ---------------
$renderer = (string)file_get_contents($root . '/core/50-shades-page-component.php');
$check(!preg_match("/\['photo', 'post', 'page', 'collection'\]/", $renderer),
    "'page' must not share the photograph branch again");

if ($failures) {
    fwrite(STDERR, "Static page presentation regression FAILED:\n");
    foreach ($failures as $failure) fwrite(STDERR, '  - ' . $failure . "\n");
    exit(1);
}
echo "Static page presentation regression passed.\n";

// ===== SNAPSMACK EOF =====
