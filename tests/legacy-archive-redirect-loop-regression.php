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
$check(snapsmack_legacy_archive_target($base, $aliases_none) === $base . '/?view=archive',
    'a site with no archive route is sent to the supported view parameter');
$check(snapsmack_legacy_archive_target($base, $aliases_images, 'carousel') === $base,
    'a carousel landing page is its own archive');
$check(snapsmack_legacy_archive_target($base, [], '') === $base . '/?view=archive',
    'an empty alias table never yields a bare /archive');

// Whatever the table says, the target is a registered route or ?view=archive.
foreach ([$aliases_images, $aliases_archive, $aliases_none, []] as $index => $aliases) {
    $target = snapsmack_legacy_archive_target($base, $aliases);
    $path = trim((string)parse_url($target, PHP_URL_PATH), '/');
    $query = (string)parse_url($target, PHP_URL_QUERY);
    $check($query === 'view=archive' || isset($aliases[$path]),
        'target for case ' . $index . ' must be registered or ?view=archive, got ' . $target);
}

// --- ?view=archive must actually render the archive -----------------------
require_once dirname(__DIR__) . '/core/public-runtime.php';
$request = snapsmack_public_runtime_request(['view' => 'archive']);
$check(($request['route'] ?? '') === 'archive',
    'the public controller must accept view=archive as the archive route');

// --- archive.php must use the guarded helper, not the generic resolver ----
$archive = (string)file_get_contents(dirname(__DIR__) . '/archive.php');
$check(str_contains($archive, 'snapsmack_legacy_archive_target('),
    'archive.php must resolve its legacy target through the guarded helper');
$check(!preg_match("/snapsmack_public_route_url\(\s*BASE_URL[^;]*'archive',\s*'archive'/", $archive),
    'archive.php must not ask the generic resolver for a fallback slug');
$check(str_contains($archive, "REQUEST_URI") && str_contains($archive, 'true, 301'),
    'archive.php must compare its target with the current request before redirecting');

if ($failures) {
    fwrite(STDERR, "Legacy archive redirect regression FAILED:\n");
    foreach ($failures as $failure) fwrite(STDERR, '  - ' . $failure . "\n");
    exit(1);
}
echo "Legacy archive redirect loop regression passed.\n";

// ===== SNAPSMACK EOF =====
