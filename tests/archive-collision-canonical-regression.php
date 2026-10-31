<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * SS-1135, the collision edge Muse found in review.
 *
 * ON A SITE WHERE OWNER CONTENT OWNS THE WORD 'archive', BOTH THE OWNER'S PAGE
 * AND THE ARCHIVE MUST STAY REACHABLE.
 *
 * The router already steps aside: a page slugged 'archive' wins /archive. But
 * the canonicalizers decide separately where an OLD address is sent, and if
 * they disagree with the router the archive becomes unreachable -- /archive-view
 * would 301 to /archive, which serves the owner's page, and nothing would reach
 * the archive at all. That is the mirror image of the fault the guard was added
 * to prevent, and it is just as bad: Sean's rule is that neither the owner's
 * page nor his archive may disappear.
 *
 * WHY IT NEEDED ITS OWN FILE. snapsmack_public_archive_slug_taken() answers once
 * per request and REMEMBERS, so the first call with a database decides the
 * answer for the whole process. A test that asks it with an empty database
 * first would poison the memo for everything after it, and the assertion would
 * pass for the wrong reason. One scenario, one process.
 *
 * WHAT MUSE SPOTTED, and he was right on both counts: the SMACKTALK 301 asks
 * for the canonical slug without passing the flag, and nothing on that path
 * primes the answer -- snapsmack_smacktalk_request() is reached from
 * index.php directly and never goes through snapsmack_public_runtime(), which
 * is the only place that primed it. The ?view= canonicalizer in index.php has
 * the same shape: it runs TWELVE LINES BEFORE the call that primes.
 */

require_once dirname(__DIR__) . '/core/public-route-aliases.php';
require_once dirname(__DIR__) . '/core/public-runtime.php';
require_once dirname(__DIR__) . '/core/smacktalk-public-controller.php';

$failures = [];
$check = static function (bool $ok, string $what) use (&$failures): void {
    if (!$ok) $failures[] = $what;
};

$base = 'https://example.test';
if (!defined('BASE_URL')) define('BASE_URL', $base . '/');

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "NOT CHECKED: this needs a PDO driver. Re-run with\n"
        . "  php -d extension_dir=C:/php/ext -d extension=php_pdo_sqlite.dll "
        . basename(__FILE__) . "\n");
    // Unknown is never a pass. SS-1096.
    exit(1);
}

// A site where the owner has a published page at the slug 'archive'.
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE snap_pages (slug TEXT, is_active INTEGER)');
$pdo->exec('CREATE TABLE snap_images (img_slug TEXT)');
$pdo->exec("INSERT INTO snap_pages (slug, is_active) VALUES ('archive', 1)");

// The default-label alias table: the archive answers at /archive-view and
// /images, and 'archive' is NOT one of its keys.
$aliases = snapsmack_public_route_aliases(
    ['nav_menu_json' => json_encode([
        ['type' => 'image_archive', 'label' => 'Archive View', 'active' => true],
    ])],
    ['cms_controller' => 'public']
);
$check(!isset($aliases['archive']),
    'the fixture must be a default-label site, where archive is not an alias key');

// --- 1. THE SMACKTALK 301 MUST NOT SEND AN OLD ADDRESS AT THE OWNER'S PAGE
//
// THIS RUNS FIRST, AND THE ORDER IS THE WHOLE TEST. The answer is remembered
// after the first call that carries a database, so asking
// snapsmack_public_archive_slug_taken($pdo) before this point would prime the
// memo by hand and the assertion would pass for a reason that does not exist
// in production. I wrote this file with the checks the other way round, it
// passed against the unfixed code, and that near-miss is exactly the failure
// mode the mutation discipline exists to catch: a test that cannot fail is not
// a test. The bug under test IS the absence of priming on this path, so
// nothing may prime it before the call being measured.
$out = snapsmack_smacktalk_request($pdo, [], [
    'view' => '', 'post_slug' => '', 'post_id' => 0, 'page_slug' => '',
    'requested_slug' => 'archive-view', 'route_aliases' => $aliases,
]);
$target = (string)($out['redirect'] ?? '');
$check(rtrim($target, '/') !== $base . '/archive',
    '/archive-view must NOT be redirected to /archive, which serves the owner page here; got '
    . ($target === '' ? 'no redirect (correct)' : $target));

// --- 4. AND THE ARCHIVE MUST STILL BE REACHABLE SOMEWHERE -------------
// Either /archive-view renders the archive itself (no redirect), or it is sent
// to another address that resolves to the archive. What it may never do is
// point at the owner's page or nowhere.
// ONE ASSERTION, NO PLACEHOLDER. This was written as an if/else whose empty
// branch held $check(true, 'unused') -- a check that asserts nothing, inflates
// the count and reads as coverage. Muse caught it in review. The property is
// single and should be stated once: whichever way the alias behaves, it has to
// end up at the archive.
$_reaches_archive = $target === ''
    // No redirect: the alias renders the archive itself, which is correct here.
    ? true
    : (snapsmack_public_runtime_request([
          'slug' => trim((string)parse_url($target, PHP_URL_PATH), '/'),
          'route_aliases' => $aliases, 'owner_slug_taken' => true,
      ])['route'] ?? '') === 'archive';
$check($_reaches_archive,
    '/archive-view must reach the archive, by rendering it or by redirecting somewhere that '
    . 'resolves to it -- never the owner page and never nowhere; got '
    . ($target === '' ? 'no redirect' : $target));

// --- 4b. NOW the direct questions, which also prime the memo -----------
// Safe to ask only after the measurement above.
$check(snapsmack_public_archive_slug_taken($pdo) === true,
    'an active page slugged archive must be reported as owning the word');
$check(snapsmack_public_canonical_slug('archive', $aliases, 'archive') !== 'archive',
    'the canonical archive address must not be /archive on a site where the owner owns it');

// --- 5. THE OWNER'S PAGE KEEPS /archive -------------------------------
$owner = snapsmack_public_runtime_request([
    'slug' => 'archive', 'route_aliases' => $aliases, 'owner_slug_taken' => true,
]);
$check(($owner['route'] ?? '') === 'resolve' && ($owner['slug'] ?? '') === 'archive',
    'the owner page must still win /archive');

// --- 6. BOTH CANONICALIZERS MUST ASK BEFORE THEY DECIDE ---------------
// The SMACKTALK path is measured above. The ?view= canonicalizer lives inline
// in index.php and no test can call it, so it is guarded the way this repo
// already guards archive.php's redirect: by asserting the source does the
// right thing in the right ORDER. Order is the whole fault here -- index.php
// also primes further down inside snapsmack_public_runtime(), which is too
// late to inform a redirect decided above it.
$index = (string)file_get_contents(dirname(__DIR__) . '/index.php');
$prime = strpos($index, 'snapsmack_public_archive_slug_taken($pdo)');
$decide = strpos($index, 'snapsmack_public_canonical_slug($_legacy_public_view');
$check($prime !== false,
    'index.php must ask whether owner content owns the archive slug');
$check($decide !== false,
    'index.php must still canonicalize ?view= through the canonical function');
$check($prime !== false && $decide !== false && $prime < $decide,
    'index.php must ask BEFORE it decides: the prime has to precede the ?view= canonicalizer');

$controller = (string)file_get_contents(dirname(__DIR__) . '/core/smacktalk-public-controller.php');
$cPrime = strpos($controller, 'snapsmack_public_archive_slug_taken($pdo)');
$cDecide = strpos($controller, 'snapsmack_public_canonical_slug($view, $route_aliases');
$check($cPrime !== false && $cDecide !== false && $cPrime < $cDecide,
    'the SMACKTALK controller must ask before it decides, in that order');

if ($failures) {
    fwrite(STDERR, "Archive collision canonical regression FAILED:\n");
    foreach ($failures as $failure) fwrite(STDERR, '  - ' . $failure . "\n");
    exit(1);
}
echo "Archive collision canonical regression passed.\n";

// ===== SNAPSMACK EOF =====
