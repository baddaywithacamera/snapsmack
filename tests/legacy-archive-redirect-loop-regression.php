<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * A legacy archive.php link must never be redirected to a path the CMS has
 * not registered.
 *
 * Six live sites answered https://site/archive.php with an endless 301 loop:
 * baddaywithacamera.ca, foreverphotograph.ing, hekeepsdroningon.ca,
 * pixhellated.ca, squaredstraight.ca and wateronthebrain.ca. The alias table
 * is built from each site's navigation menu, so a site with no archive menu
 * item -- or one labelled "Images" -- has no slug mapping to the archive view.
 * archive.php asked snapsmack_public_route_url() anyway, received its
 * FALLBACK (the literal slug 'archive'), and redirected there; the rewrite
 * handed /archive back to archive.php, which redirected again.
 */

require_once dirname(__DIR__) . '/core/public-route-aliases.php';

$failures = [];
$check = static function (bool $ok, string $what) use (&$failures): void {
    if (!$ok) $failures[] = $what;
};

$base = 'https://example.test';

// --- the three shapes a real site's navigation takes ----------------------
$menu_named_images = json_encode([
    ['type' => 'image_archive', 'label' => 'Images', 'active' => true],
]);
$menu_named_archive = json_encode([
    ['type' => 'image_archive', 'label' => 'Archive', 'active' => true],
]);
$menu_without_archive = json_encode([
    ['type' => 'blogroll', 'label' => 'Blogroll', 'active' => true],
]);
$manifest = ['cms_controller' => 'public'];

$aliases_images = snapsmack_public_route_aliases(['nav_menu_json' => $menu_named_images], $manifest);
$aliases_archive = snapsmack_public_route_aliases(['nav_menu_json' => $menu_named_archive], $manifest);
$aliases_none = snapsmack_public_route_aliases(['nav_menu_json' => $menu_without_archive], $manifest);

// The conditions that produced the loop, asserted so they stay understood.
$check(array_search('archive', $aliases_images, true) === 'images',
    'a menu item labelled Images should register the slug images');
$check(!isset($aliases_images['archive']),
    'that site has no /archive slug -- the state that caused the loop');
$check(array_search('archive', $aliases_none, true) === false,
    'a menu with no archive item registers no archive slug at all');

// --- the trap itself still exists in the generic resolver -----------------
// It is correct for callers that have somewhere to fall back TO; it is not a
// safe way to pick a redirect target, which is the whole point of the helper
// under test.
$check(snapsmack_public_route_url($base, $aliases_none, 'archive', 'archive')
        === $base . '/archive',
    'the generic resolver still returns its fallback slug');

// --- the legacy target must never be an unregistered path ----------------
$check(snapsmack_legacy_archive_target($base, $aliases_archive) === $base . '/archive',
    'a site that really has /archive keeps its pretty URL');
$check(snapsmack_legacy_archive_target($base, $aliases_images) === $base . '/images',
    'a site that labelled it Images is sent to /images');
// 0.7.908D: the fallback is the plain address again, and this is only safe
// because BOTH halves below now hold. Sean: "no goddamn slugs. they're bad
// for usability for visitors." He is right; a query string is a poor thing to
// hand a reader who may bookmark or share the page.
$check(snapsmack_legacy_archive_target($base, $aliases_none) === $base . '/archive',
    'a site with no archive menu item still gets a plain address');
$check(snapsmack_legacy_archive_target($base, $aliases_images, 'carousel') === $base,
    'a carousel landing page is its own archive');
$check(snapsmack_legacy_archive_target($base, [], '') === $base . '/archive',
    'an empty alias table also gets the plain address');

// Whatever the table says, the target must be an address the CMS can answer:
// either a slug this site registered, or a bare route name, which the front
// controller now resolves by name whether or not a menu item created an alias.
require_once dirname(__DIR__) . '/core/public-runtime.php';
foreach ([$aliases_images, $aliases_archive, $aliases_none, []] as $index => $aliases) {
    $target = snapsmack_legacy_archive_target($base, $aliases);
    $path = trim((string)parse_url($target, PHP_URL_PATH), '/');
    $resolved = snapsmack_public_runtime_request(['slug' => $path, 'route_aliases' => $aliases]);
    $check(isset($aliases[$path]) || ($resolved['route'] ?? '') === 'archive',
        'target for case ' . $index . ' must be an address the CMS answers, got ' . $target);
}

// THE FIRST HALF of what makes a plain /archive safe: the front controller
// answers it. The loop happened because nothing did -- the alias table is
// built from the menu, so a site with no archive item had no /archive, and
// the rewrite handed it straight back to archive.php.
$check((snapsmack_public_runtime_request(['slug' => 'archive'])['route'] ?? '') === 'archive',
    'a bare /archive must resolve to the archive route with no alias at all');
foreach (['blogroll', 'albums', 'collections'] as $name) {
    $check((snapsmack_public_runtime_request(['slug' => $name])['route'] ?? '') === $name,
        'a bare /' . $name . ' must resolve to its own route');
}

// THE SECOND HALF: archive.php still refuses to redirect to the address it
// was asked for. With the ^archive$ rewrite still present on an existing
// site, /archive reaches archive.php, the target is /archive, the guard
// declines, and the legacy archive renders. No bounce. install.php no longer
// writes that rewrite, so a new install gets the controller archive instead.
$check(!str_contains((string)file_get_contents(dirname(__DIR__) . '/install.php'),
        "
RewriteRule ^archive$ archive.php"),
    'install.php must not send /archive back to the legacy file');

// --- ?view=archive must KEEP working: old links and bookmarks exist -------
$request = snapsmack_public_runtime_request(['view' => 'archive']);
$check(($request['route'] ?? '') === 'archive',
    'the public controller must accept view=archive as the archive route');

// --- archive.php must use the guarded helper, not the generic resolver ----
$archive = (string)file_get_contents(dirname(__DIR__) . '/archive.php');
$check(str_contains($archive, 'snapsmack_legacy_archive_target('),
    'archive.php must resolve its legacy target through the guarded helper');
$check(!preg_match("/snapsmack_public_route_url\(\s*BASE_URL[^;]*'archive',\s*'archive'/", $archive),
    'archive.php must not ask the generic resolver for a fallback slug');
$check(str_contains($archive, "REQUEST_URI") && str_contains($archive, 'true, 302'),
    'archive.php must compare its target with the current request before redirecting, '
    . 'and answer 302: the address comes from the site menu and changes when an item '
    . 'is renamed, and a 301 a reader has cached cannot be taken back');
$check(str_contains($archive, "QUERY_STRING"),
    'the redirect must pass the incoming query to the helper');

// --- THE QUESTION MUST SURVIVE THE REDIRECT -----------------------------
// An album card links to /archive?album=232. Carrying only the path answered
// with the WHOLE archive instead of that album -- and every album, category,
// search, filter and layout link travels in the query, so all of them landed
// on the same unfiltered page. Measured on foreverphotograph.ing:
// /archive?album=232 -> 301 -> /?view=archive, query gone.
$carried = snapsmack_legacy_archive_target('https://example.test/', [], '', 'album=232');
parse_str((string)parse_url($carried, PHP_URL_QUERY), $q);
$check(($q['album'] ?? '') === '232', 'an album must survive the redirect');
// The destination is the archive because of the PATH now, not a view
// parameter, so there is nothing for a crafted ?view= to displace.
$check(trim((string)parse_url($carried, PHP_URL_PATH), '/') === 'archive',
    'and the destination must still be the archive');

$aliased = snapsmack_legacy_archive_target('https://example.test/', ['images' => 'archive'], '', 'album=232');
$check($aliased === 'https://example.test/images?album=232',
    'a site with a real archive slug keeps the album too, got ' . $aliased);

// A crafted query cannot send the reader anywhere else: the archive is in
// the path, and a stray ?view= in the query cannot move it.
$hostile = snapsmack_legacy_archive_target('https://example.test/', [], '', 'view=elsewhere&album=7');
$check(trim((string)parse_url($hostile, PHP_URL_PATH), '/') === 'archive',
    'an incoming view must not displace the archive destination');
parse_str((string)parse_url($hostile, PHP_URL_QUERY), $hq);
$check(($hq['album'] ?? '') === '7', 'and the rest of the query still rides along');

$check(snapsmack_legacy_archive_target('https://example.test/', [], '', '')
    === 'https://example.test/archive', 'no query, no stray question mark');
$check(snapsmack_legacy_archive_target('https://example.test/', ['images' => 'archive'], '', '')
    === 'https://example.test/images', 'no query and a real slug, no stray question mark');

if ($failures) {
    fwrite(STDERR, "Legacy archive redirect regression FAILED:\n");
    foreach ($failures as $failure) fwrite(STDERR, '  - ' . $failure . "\n");
    exit(1);
}
echo "Legacy archive redirect loop regression passed.\n";

// ===== SNAPSMACK EOF =====
