<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * No skin writes its own footer.
 *
 * core/footer.php renders the footer metadata bar -- copyright, contact
 * address, theme, POWERED BY SNAPSMACK and the version, the privacy link and
 * RSS -- plus a hidden h-card that tells fediverse and IndieWeb readers whose
 * site this is. The strict conversion stopped every skin running that file,
 * and each layout was left with a hand-written stand-in: one paragraph
 * carrying the site's name. Measured across the fleet, twelve of Sean's
 * twenty-five sites served that one line on EVERY page.
 *
 * Twenty-one skins carried `<p id="sig-text">`, three more carried the same
 * line without the id -- which is why a check written against the id alone
 * missed them -- and SLICKR carried a div with the bar's own class and
 * nothing but the site name inside it, which defeated a check for the class.
 * This test therefore looks for the FAILURE SHAPES, not for one spelling.
 *
 * PHOTOGRAM is the single documented exception: its stylesheet sets
 * #system-footer to display:none with a comment explaining that the fixed
 * navigation clips it.
 */

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $ok, string $what) use (&$failures): void {
    if (!$ok) $failures[] = $what;
};

$EXEMPT = ['photogram' => 'hides #system-footer in its own stylesheet, with the reason'];

$withFooter = $delegating = 0;
foreach (glob($root . '/skins/*/layout.php') ?: [] as $layout) {
    $skin = basename(dirname($layout));
    $src  = (string)file_get_contents($layout);

    // A layout either renders the shared footer, or delegates the whole page
    // to a CMS renderer that does.
    $hasFooterTag = str_contains($src, '<footer');
    $usesShared   = str_contains($src, "snap_render_component('footer'");
    $delegates    = (bool)preg_match("/snap_render_component\('[a-z0-9-]+-page'/", $src);

    if (isset($EXEMPT[$skin])) {
        $check(!$usesShared, $skin . ' is exempt and should not have gained a footer');
        continue;
    }

    // --- the failure shapes, each one a real bug that shipped -------------
    $check(!str_contains($src, 'id="sig-text"'),
        $skin . ' writes its own one-line footer (sig-text)');
    $check(!preg_match('/<footer\b[^>]*>\s*<p>\s*<\?php echo snap_escape_html\(\$view\[.site.\]\[.site_name.\]\)/', $src),
        $skin . ' writes its own one-line footer (bare paragraph)');
    $check(!preg_match('/footer-metadata-bar"[^>]*>\s*<p>\s*<\?php echo snap_escape_html\(\$view\[.site.\]\[.site_name.\]\)/', $src),
        $skin . ' fakes the metadata bar: the class with none of its contents');

    if ($hasFooterTag) {
        $withFooter++;
        $check($usesShared, $skin . ' has a footer element but does not render the shared footer');
        $check(str_contains($src, 'footer-lowercase'),
            $skin . ' must honour the footer-lowercase setting');
    } elseif ($delegates) {
        $delegating++;
    } else {
        $check(false, $skin . ' has neither a footer nor a CMS renderer to provide one');
    }
}

$check($withFooter >= 24, 'expected at least 24 skins rendering the shared footer, found ' . $withFooter);
$check($delegating >= 4, 'expected at least 4 skins delegating to a CMS renderer, found ' . $delegating);

// --- and the CMS renderers themselves ------------------------------------
foreach (['50-shades', 'chaplin', 'galleria', 'impact-printer'] as $slug) {
    $src = (string)@file_get_contents($root . '/core/' . $slug . '-page-component.php');
    $check(str_contains($src, "snap_render_component('footer'"),
        $slug . ' renderer must render the shared footer');
    $check(!str_contains($src, 'id="sig-text"'), $slug . ' renderer must not write its own');
}

printf("footers: %d skins render the shared bar, %d delegate to a CMS renderer, %d exempt.\n",
    $withFooter, $delegating, count($EXEMPT));

if ($failures) {
    fwrite(STDERR, "Skin footer contract FAILED:\n");
    foreach ($failures as $failure) fwrite(STDERR, '  - ' . $failure . "\n");
    exit(1);
}
echo "Skin footer contract regression passed.\n";

// ===== SNAPSMACK EOF =====
