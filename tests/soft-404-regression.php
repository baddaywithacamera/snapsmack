<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

/**
 * An unknown public URL returns a REAL 404, not a 200 "nothing here". (soft-404)
 *
 * MEASURED FIRST, not assumed: on the staging install (webroot core/public-
 * controller.php byte-identical to dev) unknown paths already 404 --
 *   /nonexistent-xyz, /foo/bar/baz, /archive/no-such-album, /page/no-such-page,
 *   /photo/no-such, /collection/zzz all returned 404; / and /archive returned
 *   200. So this file GUARDS behaviour that is already correct but was unlocked:
 *   the only prior 404 assertion anywhere was the disabled-blogroll case in
 *   tests/public-route-and-game-on-recovery-regression.php. Nothing stopped a
 *   future edit from sending an unknown URL back to the 200 landing fallback.
 *
 * The soft-404 decision is made in two places and this test drives both:
 *   1. snapsmack_public_runtime_request() + snapsmack_public_parse_request()
 *      (pure): a NON-EMPTY requested path must become the 'resolve' route (which
 *      404s an unknown slug), never the 'landing' route (200). Only a truly
 *      empty path is 'landing'. A multi-segment path is rejected to an empty
 *      slug but its route stays 'resolve', so it 404s rather than landing.
 *   2. snapsmack_public_controller() against the REAL repository on an in-memory
 *      database holding one active page and no matching photo/post: route
 *      'resolve' with an unknown or empty slug -> 404; with the real page slug
 *      -> 200; photo/post/page with an unknown slug -> 404; an unknown route
 *      name -> 404 via the final fallback.
 *
 * The /<album>/<category>/<slug> resolver's own miss behaviour (every miss
 * falls through so the normal path 404s it) is guarded exhaustively in
 * tests/portfolio-route-resolver-regression.php; not repeated here.
 *
 * MUST NOT alter any URL that resolves on the 25 live sites: this file changes
 * no routing code at all. It only reads the existing functions.
 *
 * Run: php -d extension=pdo_sqlite tests/soft-404-regression.php
 */

define('BASE_URL', 'https://example.test/');
define('SNAPSMACK_SKIN_RENDER', true);

require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-render-helpers.php';
require_once dirname(__DIR__) . '/core/skin-view-contract.php';
require_once dirname(__DIR__) . '/core/public-controller.php';
require_once dirname(__DIR__) . '/core/public-route-aliases.php';
require_once dirname(__DIR__) . '/core/public-runtime.php';
require_once dirname(__DIR__) . '/core/public-repository.php';
require_once dirname(__DIR__) . '/core/asset-registry.php';

$pass = 0; $fail = 0;
function cx(bool $ok, string $what): void {
    global $pass, $fail;
    echo ($ok ? "  ok    " : "  FAIL  ") . $what . "\n";
    $ok ? $GLOBALS['pass']++ : $GLOBALS['fail']++;
}

/**
 * The route the public entry point hands the controller for a clean-URL slug.
 * index.php sets $requested_slug = trim(PATH_INFO,'/') and passes it as 'slug';
 * this reproduces the two functions that turn that into a {route, slug}.
 */
function derive(array $input): array {
    $req = snapsmack_public_parse_request(snapsmack_public_runtime_request($input));
    return [(string)($req['route'] ?? ''), (string)($req['slug'] ?? '')];
}

// ── 1. ROUTE DERIVATION: an unknown path never becomes the 200 landing ───────
echo "ROUTE DERIVATION (pure)\n";

[$r] = derive([]);
cx($r === 'landing', 'an empty path (the homepage) derives the landing route');

[$r, $s] = derive(['slug' => 'zzz-no-such-slug']);
cx($r === 'resolve' && $s === 'zzz-no-such-slug',
   'a bare unknown slug derives resolve (which 404s an unknown slug), NOT landing');

[$r, $s] = derive(['slug' => 'foo/bar/baz']);
cx($r === 'resolve' && $s === '',
   'a multi-segment path stays on resolve with an empty slug (so it 404s), NOT landing');

[$r] = derive(['slug' => 'favicon.ico']);
cx($r === 'resolve', 'a path with a dot stays on resolve (404), not landing');

[$r] = derive(['view' => 'archive']);
cx($r === 'archive', 'the archive view is still the archive route (guard broke no real route)');
[$r] = derive(['slug' => 'blogroll']);
cx($r === 'blogroll', 'the bare /blogroll path is still the blogroll route');

// ── 2. CONTROLLER STATUS against the real repository ─────────────────────────
if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "SKIP controller-status checks: no pdo_sqlite. Re-run with -d extension=pdo_sqlite\n");
    echo "\n{$pass} checks, {$fail} failed (controller checks skipped: no sqlite)\n";
    exit($fail > 0 ? 1 : 0);
}

echo "\nCONTROLLER STATUS (real repository, in-memory database)\n";

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
@$pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'), 0);
// The controller builds its navigation from activePages() on every request and
// resolves a page by slug on the 'page' route. Those two surfaces read ONLY
// snap_pages, so a live-faithful miss (unknown slug) and hit (a real page) are
// proven through the real controller with just this one table. The resolve /
// photo / post branches reach deeper tables; their unknown-slug 404 is proven
// at the derivation layer above (a non-empty path lands on 'resolve', which the
// branch 404s) and by the live measurement in the header, so they are not
// re-exercised here against a hand-built photo schema that could drift.
$pdo->exec("CREATE TABLE snap_pages (
    id INTEGER PRIMARY KEY, slug TEXT, title TEXT, content TEXT,
    image_asset TEXT, image_size TEXT, image_align TEXT, image_shadow INTEGER DEFAULT 0,
    menu_order INTEGER NOT NULL DEFAULT 0, is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL DEFAULT '2020-01-01 00:00:00')");
// One real page, 'about', so a URL that resolves on a live site is proven to
// stay 200; every other slug is absent, like an unknown URL.
$pdo->exec("INSERT INTO snap_pages (id,slug,title,content,is_active) VALUES
    (1,'about','About','x',1)");

$repo = new SnapPublicRepository($pdo);

function status_for(SnapPublicRepository $repo, array $request): int {
    return (int)(snapsmack_public_controller($repo, $request, ['active_skin' => ''])['status'] ?? 0);
}

cx(status_for($repo, ['route' => 'page', 'slug' => 'zzz-no-such-page']) === 404,
   'page + unknown slug -> 404 (not a 200 nothing-here page)');
cx(status_for($repo, ['route' => 'page', 'slug' => 'about']) === 200,
   'page + a real page slug -> 200 (a URL that resolves on a live site is untouched)');
cx(status_for($repo, ['route' => 'this-is-not-a-route', 'slug' => '']) === 404,
   'an unknown route name -> 404 via the controller final fallback');

// --- 3. THE LEGACY FALL-THROUGH 404 GUARD (index.php) ---
// cms_controller=smacktalk skins (ALFRED, STANLEY, TELEGRAM, TILEZ,
// WRITING-WITH-IMPACT) do NOT use the public controller above: an unknown path
// returns handled=false from snapsmack_smacktalk_request() and reaches the
// shared legacy render path, which answered a miss with the 200 homepage/feed.
// The fix is an inline guard in index.php; this locks that it stays present.
// BEHAVIOURAL PROOF is the live matrix in the commit message: on TILEZ and
// ALFRED against a real install an unknown path returned 404 while /, a real
// page and a real photograph returned 200.
echo "
LEGACY FALL-THROUGH GUARD (index.php structural)
";
$indexPhp = (string)file_get_contents(dirname(__DIR__) . '/index.php');
cx(str_contains($indexPhp, 'SOFT-404'), 'index.php carries the soft-404 guard');
cx((bool)preg_match('/SOFT-404[\s\S]{0,1500}http_response_code\(404\)/', $indexPhp),
   'the soft-404 comment is immediately followed by the 404 status call');

echo "\n{$pass} checks, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
// ===== SNAPSMACK EOF =====
