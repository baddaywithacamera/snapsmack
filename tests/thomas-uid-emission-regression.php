<?php
// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
declare(strict_types=1);

/**
 * THE CMS EMITS THE ANONYMOUS THOMAS INSTALL ID AS A HIDDEN DATA ATTRIBUTE,
 * AND DOES IT FROM THE ONE HOOK EVERY SKIN REACHES. (Ping restore, 2026-11-08.)
 *
 * The Easter-egg discover ping reads its install id from #ss-thomas-uid in the
 * DOM, not from an inline window.ssUid (blocked by the strict public CSP and
 * absent from the strict render path). This proves the server emits that
 * attribute with the stored id, and that the emission is wired into the
 * registered-assets component -- the same universal point the consent banner
 * uses, so all 30 renderable skins get it, the five SMACKTALK skins included.
 */

$root = dirname(__DIR__);
require_once $root . '/core/thomas-uid.php';

$pass = 0; $fail = 0;
function tt(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

// A real PDO (in-memory SQLite) seeded with a stored id, so the read+emit path
// is exercised end to end against an actual database driver.
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE snap_settings (setting_key TEXT PRIMARY KEY, setting_val TEXT)');
$seeded = 'abcdef0123456789abcdef0123456789';
$pdo->prepare('INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?)')
    ->execute(['thomas_uid', $seeded]);
$GLOBALS['pdo'] = $pdo;

echo "THE STORED ID IS READ AND EMITTED\n";
tt(snapsmack_thomas_uid() === $seeded, 'snapsmack_thomas_uid() returns the stored 32-hex id');
ob_start(); snapsmack_thomas_uid_tag(); $out = (string)ob_get_clean();
tt(str_contains($out, 'id="ss-thomas-uid"'), 'the tag emits the #ss-thomas-uid element the engine looks for');
tt(str_contains($out, 'data-uid="' . $seeded . '"'), 'it carries the stored id in data-uid');
tt(str_contains($out, 'hidden'), 'the element is hidden (no visible change on the page)');
tt(!str_contains($out, '<script'), 'it emits NO inline script -- CSP-safe');

echo "\nNOTHING IDENTIFYING IS IN THE PAYLOAD OR ITS SOURCE\n";
$src = (string)file_get_contents($root . '/core/thomas-uid.php');
tt(str_contains($src, 'random_bytes'), 'the id is generated from random_bytes, not derived from the site');
foreach (['site_url', 'site_name', 'HTTP_HOST', 'SERVER_NAME'] as $ident) {
    tt(!str_contains($src, $ident), "the id source references no identifying field ({$ident})");
}

echo "\nIT IS WIRED INTO THE ONE HOOK EVERY SKIN REACHES\n";
$helpers = (string)file_get_contents($root . '/core/skin-render-helpers.php');
// The registered-assets branch is where the consent banner is emitted for all
// skins; the Thomas id must ride the same branch, right beside it.
$at = strpos($helpers, "elseif (\$name === 'registered-assets')");
$end = $at === false ? false : strpos($helpers, "elseif (\$name ===", $at + 10);
$block = ($at !== false && $end !== false) ? substr($helpers, $at, $end - $at) : '';
tt($block !== '' && str_contains($block, 'snapsmack_thomas_uid_tag'),
   'the registered-assets component calls snapsmack_thomas_uid_tag (same hook as the consent banner)');
tt(str_contains($block, 'snapsmack_consent_banner_tag'),
   'and it still sits beside the consent banner, the proven universal emission point');

// It is loaded by both public controllers, like the consent runtime.
foreach (['core/public-runtime.php', 'index.php'] as $rel) {
    $c = (string)file_get_contents($root . '/' . $rel);
    tt(str_contains($c, "thomas-uid.php"), "{$rel} requires core/thomas-uid.php (reaches this controller's skins)");
}

echo "\n{$pass} passed, {$fail} failed\n";
if ($fail > 0) { echo "Thomas uid emission: FAIL ({$fail}).\n"; exit(1); }
echo "Thomas uid emission: PASS ({$pass} assertions).\n";
echo "  NOT CHECKED: the first-run generate-and-store path. It uses MySQL\n";
echo "  ON DUPLICATE KEY UPDATE, which this in-memory SQLite driver does not\n";
echo "  parse; it is restored verbatim from the original fleet-tested code and\n";
echo "  the read+emit path above is what the ping actually consumes.\n";

// ===== SNAPSMACK EOF =====
