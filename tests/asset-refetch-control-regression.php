<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * A site must be allowed to look again for a file that has just arrived.
 *
 * Sean could not change the font on TRUE GRIT. The font file,
 * Marcellus-Regular.ttf, was on Smack Central and served 200, but had no row
 * in sc_assets, so asset-manifest.json -- which is generated from that table
 * with no filter -- did not list it, and asset_sync_fetch() marked it
 * "skipped: not in remote manifest; nothing we can do". The site fell back to
 * a default face whatever the setting said.
 *
 * He pressed RESCAN DISK on Smack Central. Measured immediately after: the
 * manifest regenerated and now carries the font. He pressed Save again and
 * got the same banner: "i pressed rescan disk. font still not found."
 *
 * THE SECOND FAULT, which is the one this test holds. asset_sync_get_manifest()
 * caches the remote manifest on disk for ASSET_SYNC_CACHE_TTL -- deliberately,
 * so every skin save does not hit the network; its own comment says so. The
 * only calls to asset_sync_bust_cache() are in smack-update.php, after a core
 * update. So a file registered at the other end could not reach a site for up
 * to an hour, the site reported "not in remote repo" every time, and the owner
 * had no control that would make it look again.
 *
 * Both halves were invisible: the banner appeared only in a flash message
 * after a save, so the state explaining a wrong-looking page was only on
 * screen at the moment he happened to press Save, and said nothing about what
 * to do next.
 *
 * THE CACHE IS NOT THE BUG. Clearing it on every page load would undo the
 * reason it exists. So this checks that the bust is a control the owner
 * presses, and that nothing clears the cache automatically.
 */

$root = dirname(__DIR__);
$pass = 0;
$fail = 0;

function ar_test(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

$page = (string) file_get_contents($root . '/smack-skin.php');
$sync = (string) file_get_contents($root . '/core/asset-sync.php');

echo "The control exists and does the two things in order\n";
ar_test(str_contains($page, "'refetch_assets'"),
    'smack-skin.php handles a refetch_assets action');

$at      = strpos($page, "'refetch_assets'");
$handler = $at === false ? '' : substr($page, $at, 2200);
ar_test($handler !== '', 'the handler was located, so the checks below mean something');
ar_test(str_contains($handler, 'asset_sync_bust_cache()'),
    'the handler discards the cached manifest');
$bust = strpos($handler, 'asset_sync_bust_cache()');
$run  = $bust === false ? false : strpos($handler, 'asset_sync_run()', $bust);
ar_test($run !== false,
    'the fresh sync runs AFTER the cache is discarded, not before it');

echo "\nNothing clears the cache behind the owner's back\n";
$outside = str_replace($handler, '', $page);
ar_test(!str_contains($outside, 'asset_sync_bust_cache('),
    'smack-skin.php busts the cache only inside that control, never on page load or save');
ar_test(str_contains($sync, 'ASSET_SYNC_CACHE_TTL'),
    'the cache itself is still in place: this repairs the missing door, not the lock');

echo "\nThe owner sees the state on every load, not only after a save\n";
ar_test(str_contains($page, 'asset_sync_check_all()'),
    'the page reads what is missing directly, rather than relying on a one-shot flash message');
ar_test(str_contains($page, 'THIS SITE IS MISSING'),
    'the notice says plainly that files a skin needs are absent');
ar_test(str_contains($page, 'falls back to a default face'),
    'the notice explains the symptom the owner is actually looking at');
ar_test(str_contains($page, 'FETCH MISSING ASSETS NOW'),
    'there is a control to press, beside the explanation');
ar_test(preg_match('/gallery_action"\s+value="refetch_assets"/', $page) === 1,
    'the control posts the refetch action');
ar_test(stripos($page, 'for an hour') !== false,
    'the notice names the cache, so a file registered a minute ago is not a mystery');

echo "\nA file that is genuinely not published says so, and says where to fix it\n";
ar_test(str_contains($handler, 'not in remote repo'),
    'a still-missing file after a fresh check is distinguished from a fetch failure');
ar_test(stripos($handler, 'RESCAN DISK') !== false,
    'and the owner is told the file must be registered on Smack Central first');

echo "\nThe control is as guarded as every other action on the page\n";
$before = $at === false ? '' : substr($page, 0, $at);
ar_test($at !== false && str_contains($before, 'hash_equals($_SESSION[\'csrf_token\']'),
    'the refetch action sits inside the existing CSRF check');
$notice_at = strpos($page, 'THIS SITE IS MISSING');
$notice    = $notice_at === false ? '' : substr($page, max(0, $notice_at - 1200), 2600);
ar_test($notice !== '' && preg_match('/style\s*=\s*"[^"]/', $notice) !== 1,
    'the notice carries no static style attribute');

echo "\n";
echo "  {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "Asset refetch control: FAIL ({$fail}).\n";
    exit(1);
}
echo "Asset refetch control: PASS ({$pass} assertions).\n";

// ===== SNAPSMACK EOF =====
