<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * BLOG must be the image feed, not the homepage it was moved off.
 *
 * Sean, on foundtextures.ca: "blog link goes to landing page." Measured from
 * the live site the same minute, with the page cache bypassed:
 *
 *   /            -> <h1 class="static-page-title">UNLIMITED TEXTURES...
 *   /blog.php    -> the SAME h1, the SAME body classes
 *                   (homepage-static page-slug-home)
 *   /blog        -> 404
 *
 * WHY. When the owner moves the homepage to a static page, the feed needs an
 * address and the navigation adds a BLOG link saying so -- skin-view-model
 * adds it on exactly that setting. blog.php existed to serve it and sets
 * $_SERVER['SNAPSMACK_FORCE_BLOG'], which index.php reads in eight places.
 *
 * But a STRICT skin never reaches those eight places. It is dispatched to
 * snapsmack_public_runtime() hundreds of lines earlier and exits. The flag
 * was written for the legacy render path and nothing carried it across the
 * conversion, so on every strict skin BLOG and HOME were the same page. And
 * the clean /blog slug was not in the runtime's route allowlist at all, so it
 * fell through to 'resolve', found no page or photograph of that name, and
 * 404ed.
 *
 * The repair is a real route rather than a flag, and "the feed" here means
 * WHATEVER THAT SKIN'S HOME VIEW IS, not a grid. The first repair got this
 * wrong and sent the blog route down the feed path, which handed Sean a grid
 * of thumbnails on foundtextures.ca -- TRUE GRIT, features.has_landing false.
 * His words: "blog is supposed to bring up the landing page which is a solo
 * page." That is the SMACKONEOUT contract: one image, one post.
 *
 * So the blog route says latest_post and hands the decision back to the two
 * branches that already know how each skin opens. A skin with no landing
 * template gets the SOLO newest photograph; a skin that declares a landing,
 * like CHAPLIN, gets its landing. No skin list and no second contract.
 */

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;

function br_test(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

require_once $root . '/core/public-runtime.php';

echo "The clean /blog address resolves to a route instead of 404ing\n";
$r = snapsmack_public_runtime_request(['slug' => 'blog']);
br_test(($r['route'] ?? '') === 'blog', "/blog resolves to the blog route, not 'resolve'");
$r2 = snapsmack_public_runtime_request(['view' => 'blog']);
br_test(($r2['route'] ?? '') === 'blog', '?view=blog resolves to the blog route');

echo "\nAn ordinary slug is still an ordinary slug\n";
$r3 = snapsmack_public_runtime_request(['slug' => 'a-photograph-called-blogger']);
br_test(($r3['route'] ?? '') === 'resolve', 'a slug that merely contains the word is untouched');
$r4 = snapsmack_public_runtime_request([]);
br_test(($r4['route'] ?? '') === 'landing', 'the bare homepage is still the landing route');

echo "\nThe controller accepts it and treats it as the feed\n";
$controller = (string) file_get_contents($root . '/core/public-controller.php');
br_test(str_contains($controller, "'blog', 'resolve'") || preg_match("/\\\$allowed\s*=\s*\[[^\]]*'blog'/", $controller) === 1,
    'blog is in the controller allowlist, so it is not rejected as an unknown route');
br_test(preg_match("/if \(\\\$route === 'landing' \|\| \\\$route === 'blog'\)/", $controller) === 1,
    'blog enters the landing handler, so it serves the same feed by the same contract');
br_test(str_contains($controller, 'if ($isBlogRoute) $homepageMode = ' . "'latest_post';"),
    'the blog route opens the way that skin opens, by saying latest_post');
br_test(preg_match('/\$isBlogRoute\s*\?\s*null\s*:/', $controller) !== 1,
    'and it does NOT special-case the homepage lookup, which was the wrong first repair');
br_test(str_contains($controller, "if (\$homepageMode === 'static_page') {"),
    "the string photofri-presentation-recovery asserts is left exactly as it was");

// The static-page branch must be the only difference. If the blog route
// skipped the latest_post branch too it would quietly change what a
// latest_post site serves, which is not the fault being repaired.
$landingAt = strpos($controller, "if (\$route === 'landing' || \$route === 'blog')");
$block = $landingAt === false ? '' : substr($controller, $landingAt, 3200);
// Strip comments before counting, so prose about the flag is not mistaken
// for code that acts on it.
$blockCode = preg_replace('#//.*#', '', $block);
br_test($blockCode !== ''
        && substr_count($blockCode, '$isBlogRoute = ') === 1
        && substr_count($blockCode, '$isBlogRoute') === 2,
    'the flag is set once and read once: nothing else about landing changes');

br_test(str_contains($controller, '$homepageMode === ' . "'static_page'")
        && str_contains($controller, '$homepageMode === ' . "'latest_post' && !" . '$skinHasLanding'),
    'both original branches are intact, so a skin without a landing still gets its solo page');

echo "\nblog.php hands the intent to BOTH render paths\n";
$blog = (string) file_get_contents($root . '/blog.php');
br_test(str_contains($blog, "SNAPSMACK_FORCE_BLOG"),
    'the legacy flag is kept, because the legacy path still reads it');
br_test(str_contains($blog, "\$_GET['view'] = 'blog'"),
    'and the view input is set, because that is what the strict controller reads');
$flagAt = strpos($blog, "SNAPSMACK_FORCE_BLOG'] = true");
$viewAt = strpos($blog, "\$_GET['view'] = 'blog'");
$incAt  = strpos($blog, "include __DIR__ . '/index.php'");
br_test($flagAt !== false && $viewAt !== false && $incAt !== false && $flagAt < $incAt && $viewAt < $incAt,
    'both are set BEFORE index.php is included, or neither reaches the request');

echo "\nThe link the navigation offers still points somewhere real\n";
$nav = (string) file_get_contents($root . '/core/gram-nav-links.php');
br_test(str_contains($nav, "case 'blog':"),
    'the nav still resolves a blog item to a URL');
$model = (string) file_get_contents($root . '/core/skin-view-model.php');
br_test(str_contains($model, "'type' => 'blog'"),
    'and the menu still offers BLOG when the homepage is a static page');

echo "\n";
echo "  {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "Blog route reaches the feed: FAIL ({$fail}).\n";
    exit(1);
}
echo "Blog route reaches the feed: PASS ({$pass} assertions).\n";

// ===== SNAPSMACK EOF =====
