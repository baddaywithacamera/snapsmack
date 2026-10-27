<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * A page the owner created is on the site, and a hidden one can be brought back.
 *
 * Every public reader of snap_pages is gated on is_active = 1 -- the nav in
 * core/header.php and core/skin-view-model.php, the strict controller via
 * core/public-repository.php, sitemap.php, the consent banner's privacy lookup.
 * Measured on curbappeal.photoblogs.fyi: nav showed Home / Archive View /
 * Floating Gallery / Blogroll and no pages, the sitemap listed 9 photographs
 * and no pages, and /about returned 404 -- three independent readers agreeing
 * that the site had no active page.
 *
 * Two holes put a site there and kept it there:
 *
 *   1. smack-pages.php INSERTed without is_active, so a new page took whatever
 *      the column default happened to be on THAT install. The canonical schema
 *      says 1; an install whose table predates it says whatever it says.
 *   2. The Page Manager could not SEE is_active, let alone change it. A page at
 *      0 was listed looking perfectly normal and 404'd on the site, and the
 *      only ways back were SMACKPRESS or a hand-written UPDATE.
 *
 * The second is what made it a disaster rather than a bug: there was no way for
 * the owner to find out, and no control to press.
 */

$root  = dirname(__DIR__);
$pages = (string)file_get_contents($root . '/smack-pages.php');

$fail = 0;
function sp_test(bool $ok, string $message): void {
    global $fail;
    echo ($ok ? "PASS " : "FAIL ") . $message . "\n";
    if (!$ok) $fail++;
}

// 1. A new page is created visible, stated, not inherited.
sp_test(preg_match('/INSERT INTO snap_pages \([^)]*\bis_active\b[^)]*\)/', $pages) === 1,
    'creating a page writes is_active explicitly instead of inheriting a column default');

// 2. The owner can see which pages are off the site.
sp_test(str_contains($pages, "HIDDEN"),
    'the Page Manager says when a page is not on the site');

// 3. The owner can turn one back on, and it is CSRF-gated like the delete beside it.
sp_test(preg_match('/isset\(\$_GET\[\'toggle\'\]\)/', $pages) === 1
     && preg_match('/UPDATE snap_pages SET is_active/', $pages) === 1,
    'the Page Manager can turn a hidden page back on');
sp_test(preg_match('/isset\(\$_GET\[\'toggle\'\]\)\)\s*\{\s*csrf_verify\(\);/s', $pages) === 1,
    'the visibility toggle verifies the CSRF token, like the delete beside it');

// 4. NULL is not "on". 1 - NULL is NULL in SQL, which would turn a toggle into
//    a one-way trip to invisible for any row whose column was never written.
sp_test(str_contains($pages, 'COALESCE(is_active, 0)'),
    'a NULL is_active toggles to visible rather than to NULL');

// 5. The readers this protects are still gated -- if one ever stops checking
//    is_active, this test is reading the wrong contract and should be revisited.
foreach ([
    'core/public-repository.php' => 'the strict controller',
    'sitemap.php'                => 'the sitemap',
    'core/skin-view-model.php'   => 'the skin navigation',
] as $file => $what) {
    $src = (string)file_get_contents($root . '/' . $file);
    sp_test(preg_match('/snap_pages WHERE[^"\']*is_active\s*=\s*1/i', $src) === 1,
        "{$what} still only shows active pages");
}

if ($fail > 0) {
    fwrite(STDERR, "Static pages can go missing with no way back. {$fail} check(s) failed.\n");
    exit(1);
}

echo "Static pages are visible and fixable: PASS.\n";

// ===== SNAPSMACK EOF =====
