<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * SS-1135. The archive answers at /archive, and every older address still
 * works and arrives there.
 *
 * SEAN, 31 October 2026: "/archive must be the DEFAULT in the NEXT release."
 * He had asked for it before and been told it was not available. It was never
 * refused and never decided -- the public segment is the navigation LABEL
 * slugified (core/public-route-aliases.php), and the label the CMS itself
 * supplies is the words "Archive View" (core/skin-view-model.php), so the
 * automatic result was /archive-view. Nobody chose that URL.
 *
 * WHAT THIS FILE PROVES, by calling the real decision functions with fixture
 * request shapes rather than by reading them:
 *   - /archive is canonical on every menu shape, including a carousel site
 *     where the rule is different and must stay different;
 *   - an older address is still a registered alias, still resolves, and is
 *     answered with a 301 to the canonical one, so a bookmark never 404s;
 *   - the redirect carries the query, because album, category, search and
 *     filter links all travel there;
 *   - owner content wins the word 'archive', so a page the owner wrote can
 *     never be made unreachable by a reserved route name;
 *   - and the redirect cannot loop, which is this area's history: six live
 *     sites answered /archive.php with an endless 301 in October.
 *
 * WHAT IT DOES NOT PROVE, stated so nobody reads more into it: this is
 * function-level measurement on fixture input, not curl against a running
 * install. It cannot see .htaccess, a real database, or a real skin.
 */

require_once dirname(__DIR__) . '/core/public-route-aliases.php';
require_once dirname(__DIR__) . '/core/public-runtime.php';
require_once dirname(__DIR__) . '/core/smacktalk-public-controller.php';

$failures = [];
$check = static function (bool $ok, string $what) use (&$failures): void {
    if (!$ok) $failures[] = $what;
};

$base = 'https://example.test';
$manifest = ['cms_controller' => 'public'];
$menu = static fn(string $label): string => json_encode([
    ['type' => 'image_archive', 'label' => $label, 'active' => true],
]);

$shapes = [
    'default label'  => snapsmack_public_route_aliases(['nav_menu_json' => $menu('Archive View')], $manifest),
    'renamed Archive' => snapsmack_public_route_aliases(['nav_menu_json' => $menu('Archive')], $manifest),
    'labelled Images' => snapsmack_public_route_aliases(['nav_menu_json' => $menu('Images')], $manifest),
    'no archive item' => snapsmack_public_route_aliases(
        ['nav_menu_json' => json_encode([['type' => 'blogroll', 'label' => 'Blogroll', 'active' => true]])],
        $manifest
    ),
];

// --- 1. CANONICAL IS /archive, WHATEVER THE MENU SAYS --------------------
foreach ($shapes as $name => $aliases) {
    $check(snapsmack_public_canonical_slug('archive', $aliases, 'archive') === 'archive',
        'canonical archive slug on a "' . $name . '" site must be archive');
    $check(snapsmack_public_route_url($base, $aliases, 'archive', 'archive') === $base . '/archive',
        'the emitted archive URL on a "' . $name . '" site must be /archive');
}

// --- 2. THE CAROUSEL RULE IS DIFFERENT AND MUST STAY DIFFERENT -----------
// A GRAMOFSMACK landing page already IS its archive, so it answers at the
// site root. Changing the canonical form must not manufacture a second
// archive address on those sites.
$carousel = snapsmack_public_route_aliases(
    ['nav_menu_json' => $menu('Archive View')],
    ['cms_controller' => 'public', 'site_mode' => 'carousel']
);
$check(!in_array('archive', $carousel, true),
    'a carousel site registers no archive view at all');
$check(snapsmack_legacy_archive_target($base, $carousel, 'carousel') === $base,
    'a carousel landing page still answers at the site root');

// --- 3. OLD ADDRESSES STILL RESOLVE. NOTHING MAY 404. -------------------
$default = $shapes['default label'];
foreach (['archive-view', 'images'] as $old) {
    $check(isset($default[$old]) && $default[$old] === 'archive',
        '/' . $old . ' must stay a registered alias on a default-label site');
    $resolved = snapsmack_public_runtime_request(['slug' => $old, 'route_aliases' => $default]);
    $check(($resolved['route'] ?? '') === 'archive',
        '/' . $old . ' must still resolve to the archive route');
}
$check((snapsmack_public_runtime_request(['slug' => 'archive', 'route_aliases' => $default])['route'] ?? '')
        === 'archive',
    '/archive must resolve to the archive route even where no alias declares it');

// --- 4. AND THEY ARE ANSWERED WITH A 301 TO THE CANONICAL ONE ------------
// Measured through the controller that actually decides, not read.
// The controller takes a PDO, but the canonicalization it is being asked about
// returns before any query runs, so an in-memory SQLite connection is enough
// to exercise the real decision. If the driver is absent this section says so
// out loud: a block that quietly skips itself is not a check, and this repo has
// been burned by exactly that (SS-1096).
if (!defined('BASE_URL')) define('BASE_URL', $base . '/');
$pdo = null;
if (in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $pdo = new PDO('sqlite::memory:');
}
if ($pdo === null) {
    fwrite(STDERR, "NOT CHECKED: the 301 behaviour needs a PDO driver. Re-run with\n"
        . "  php -d extension_dir=C:/php/ext -d extension=php_pdo_sqlite.dll "
        . basename(__FILE__) . "\n");
    $failures[] = 'the 301 behaviour was NOT CHECKED (no PDO driver) -- unknown is never a pass';
} else {
    $ask = static function (string $slug, array $aliases, array $extra = []) use ($pdo): array {
        return snapsmack_smacktalk_request($pdo, [], array_merge([
            'view' => '', 'post_slug' => '', 'post_id' => 0, 'page_slug' => '',
            'requested_slug' => $slug, 'route_aliases' => $aliases,
        ], $extra));
    };
    foreach (['archive-view', 'images'] as $old) {
        $out = $ask($old, $default);
        $check(($out['redirect_status'] ?? 0) === 301,
            '/' . $old . ' must be answered with a 301, not a 302 or a render');
        $check(rtrim((string)($out['redirect'] ?? ''), '/') === $base . '/archive',
            '/' . $old . ' must be sent to ' . $base . '/archive, got ' . ($out['redirect'] ?? 'nothing'));
    }
    // The question has to survive: an album card links to ?album=232.
    $out = $ask('archive-view', $default, ['album' => 232]);
    $check(str_contains((string)($out['redirect'] ?? ''), 'album=232'),
        'the album must survive the redirect to the canonical archive');
    // THE FIXED POINT, and the reason this cannot become the October loop:
    // asking for the canonical address is never redirected anywhere.
    $out = $ask('archive', ['archive' => 'archive'] + $default);
    $check(empty($out['redirect']),
        '/archive must never be redirected anywhere -- that is how the loop starts');
}

// --- 5. OWNER CONTENT WINS THE WORD ------------------------------------
// Sean's standing instruction: never make owner content unreachable. The
// resolver honours a bare route name, so without this guard a page the owner
// slugged 'archive' was silently shadowed -- and it was, on every site, long
// before this change.
$taken = snapsmack_public_runtime_request([
    'slug' => 'archive', 'route_aliases' => $default, 'owner_slug_taken' => true,
]);
$check(($taken['route'] ?? '') === 'resolve' && ($taken['slug'] ?? '') === 'archive',
    'a page the owner slugged archive must resolve as content, not as the archive route');
$check(snapsmack_public_canonical_slug('archive', $default, 'archive', true) !== 'archive',
    'and the canonical archive URL must step aside on that site rather than collide');
// The guard is scoped to the archive on purpose; the other reserved names are
// logged, not quietly changed at a release tip.
foreach (['albums', 'blogroll', 'collections'] as $name) {
    $still = snapsmack_public_runtime_request([
        'slug' => $name, 'route_aliases' => $default, 'owner_slug_taken' => true,
    ]);
    $check(($still['route'] ?? '') === $name,
        '/' . $name . ' keeps its existing behaviour; only the archive changed');
}

// --- 6. THE REWRITE SOURCES MUST AGREE --------------------------------
// install.php commented the legacy rule out in October; core/htaccess-template
// did not, and updater_reconcile_htaccess() rebuilds the block FROM THE
// TEMPLATE, so every updated site had the disabled rule put back. That is why
// /archive was still served by archive.php on live sites while /images was
// served by the controller on the same sites.
foreach (['install.php', 'core/htaccess-template'] as $source) {
    $check(!preg_match('/^\s*RewriteRule \^archive\$ archive\.php/m',
            (string)file_get_contents(dirname(__DIR__) . '/' . $source)),
        $source . ' must not route /archive to the legacy file');
}

// --- 7. THE CALENDAR MUST NOT HAND OUT LEGACY ADDRESSES ----------------
$check(!preg_match("/\\\$archive_url\s*=\s*BASE_URL\s*\.\s*'archive\.php'/",
        (string)file_get_contents(dirname(__DIR__) . '/api-calendar.php')),
    'api-calendar.php must not build its month links from archive.php');

if ($failures) {
    fwrite(STDERR, "Archive canonical URL regression FAILED:\n");
    foreach ($failures as $failure) fwrite(STDERR, '  - ' . $failure . "\n");
    exit(1);
}
echo "Archive canonical URL regression passed.\n";

// ===== SNAPSMACK EOF =====
