<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

/**
 * Page two of the archive redirected to page two of the archive.
 *
 * archive.php's legacy redirect has a loop guard. It compared PATHS only, then
 * redirected anyway whenever the target carried a query string. The target is
 * built by snapsmack_legacy_archive_target(), which carries the incoming query
 * through on purpose so an album link keeps its ?album=232 -- so /archive?page=2
 * produced the target /archive?page=2. Same path, has a query, redirect: to the
 * address the reader was already on.
 *
 * Measured live on 0.7.921D before the fix: /archive?page=2 answered 302 with
 * Location equal to the request URL on baddaywithacamera.ca, hekeepsdroningon.ca,
 * wateronthebrain.ca and pixhellated.ca. curl gave up after 50 hops; a browser
 * shows ERR_TOO_MANY_REDIRECTS. Page one rendered, so the archive looked fine
 * until someone tried to go past the first screenful.
 */

require_once dirname(__DIR__) . '/core/public-route-aliases.php';

function archive_loop_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$base = 'https://example.test/';

// The bug, exactly: page two of the archive must not redirect to page two.
$target = snapsmack_legacy_archive_target($base, [], '', 'page=2');
archive_loop_check(
    snapsmack_is_same_address($target, '/archive', 'page=2'),
    'The /archive?page=2 redirect target is no longer recognised as the address we are on, '
    . 'which is what made page two of the archive redirect to itself forever.'
);

// Same for the other query-borne archive links, which all travel this road.
foreach (['album=232', 'category=7', 'q=barn', 'layout=masonry', 'page=2&album=232'] as $query) {
    $t = snapsmack_legacy_archive_target($base, [], '', $query);
    archive_loop_check(
        snapsmack_is_same_address($t, '/archive', $query),
        "An /archive?$query request would redirect to itself."
    );
}

// Query order must not matter: the target's is rebuilt by http_build_query().
archive_loop_check(
    snapsmack_is_same_address('https://example.test/archive?album=232&page=2', '/archive', 'page=2&album=232'),
    'Address comparison depends on query parameter order, so a reordered query reads as a different page.'
);

// A real redirect must still happen, and this block exists to prove the guard
// has not gone so far that it suppresses one.
//
// SS-1135 CHANGED WHICH ADDRESS IS CANONICAL, so it changed the fixture this
// needs, not the property. The target used to be the label-derived slug, so a
// site whose menu said "Gallery" gave /gallery and differed from /archive by
// the PATH. Sean's ruling, 31 October 2026: the canonical archive address is
// /archive on every site, whatever the menu says. So the legitimate redirect
// to measure is the one FROM THE LEGACY FILE: /archive.php asks for the target
// and must be carried to /archive, query intact.
$aliased = snapsmack_legacy_archive_target($base, ['gallery' => 'archive'], '', 'page=2');
archive_loop_check(
    $aliased === 'https://example.test/archive?page=2',
    'The canonical archive address is no longer /archive, or the page was dropped on the way.'
);
archive_loop_check(
    !snapsmack_is_same_address($aliased, '/archive.php', 'page=2'),
    'A redirect from the legacy file to the canonical address is being suppressed as a loop; '
    . 'the guard has gone too far.'
);
// AND THE SELF-REDIRECT IS STILL REFUSED, which is the actual loop. An
// /archive request that reaches archive.php on a site whose .htaccess still
// carries the old rewrite must RENDER, never bounce to itself. Six live sites
// answered /archive.php with an endless 301 in October because this held the
// other way.
archive_loop_check(
    snapsmack_is_same_address($aliased, '/archive', 'page=2'),
    'An /archive request reaching the legacy file would redirect to itself.'
);

// And a differing query is still a real move.
archive_loop_check(
    !snapsmack_is_same_address('https://example.test/archive?page=3', '/archive', 'page=2'),
    'A redirect that changes the page is being suppressed as a loop.'
);

// The guard in archive.php must use this comparison, not the path-only one.
$archive = (string)file_get_contents(dirname(__DIR__) . '/archive.php');
archive_loop_check(
    str_contains($archive, 'snapsmack_is_same_address('),
    'archive.php no longer guards its legacy redirect with a whole-address comparison.'
);
archive_loop_check(
    !preg_match('/\|\|\s*parse_url\(\$_archive_target,\s*PHP_URL_QUERY\)/', $archive),
    'archive.php redirects again whenever the target merely has a query string, which is the loop.'
);

fwrite(STDOUT, "Archive-pagination-loop regression passed.\n");

// ===== SNAPSMACK EOF =====
