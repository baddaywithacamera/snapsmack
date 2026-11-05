<?php
declare(strict_types=1);

/**
 * A SITE WITH A STALE REGISTRY URL MUST STILL REACH THE REGISTRY.
 *
 * pixhellated.ca had snap_settings.skin_registry_url pointing at
 * https://snapsmack.ca/skins/registry.json -- the path from before the
 * registry moved. It 404s. Every skin setting write on that site was refused
 * for want of a signed-package integrity record, and the recovery that would
 * have restored it could not read the registry, so Sean could not change a
 * font on his own site. Codex measured the cause.
 *
 * The stored setting OVERRIDES the default constant, and the default is
 * already correct. So one wrong row stranded a whole site, silently, and
 * nobody knows how many other sites carry the same row.
 *
 * So: when the configured URL fails AND differs from the default, try the
 * default once. SAY SO, because a site quietly working off a different URL
 * than its settings claim is its own kind of lie. NEVER REWRITE THE SETTING --
 * that is the owner's row, and a self-healing fetch is not permission to edit
 * configuration.
 *
 * These tests make REAL requests to the real registry, including the real
 * wrong URL from pixhellated. They need outbound HTTPS; if this host has none
 * they say so rather than passing.
 */

$root = dirname(__DIR__);
if (!defined('SNAPSMACK_VERSION_SHORT')) define('SNAPSMACK_VERSION_SHORT', '0.7.977D');
require_once $root . '/core/skin-registry.php';

$pass = 0; $fail = 0;
function cx(bool $ok, string $what): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  ok    {$what}\n"; }
    else     { $fail++; echo "  FAIL  {$what}\n"; }
}

$GOOD = SKIN_REGISTRY_DEFAULT_URL;                              // the real one
$STALE = 'https://snapsmack.ca/skins/registry.json';            // pixhellated's actual row
$DEAD  = 'https://127.0.0.1:1/registry.json';                   // nothing listens

// Can this host make the requests at all? Said out loud, because every
// assertion below is meaningless otherwise.
$probe = skin_registry_fetch($GOOD, true);
$online = !isset($probe['error']);
echo 'THIS HOST CAN REACH THE REGISTRY: ' . ($online ? 'yes' : 'NO') . "\n";
if (!$online) {
    echo "  Refusing to assert anything about fallback behaviour from a host\n";
    echo "  that cannot make the request. Reported, not skipped silently.\n";
    echo "  (" . (string)($probe['error'] ?? '') . ")\n";
    echo "\n0 checks, 1 failed\n";
    exit(1);
}

echo "\nA STALE CONFIGURED URL FALLS BACK TO THE DEFAULT\n";
$r = skin_registry_fetch($STALE, true);
cx(!isset($r['error']), 'the stale URL no longer ends in an error');
cx(isset($r['skins']) && is_array($r['skins']) && $r['skins'] !== [],
   'the registry is actually returned, with skins in it');
cx(isset($r['skins']['impact-printer']),
   'and it is the real registry: impact-printer is listed');
cx(!empty($r['notice']), 'IT SAYS SO: a notice is attached');
cx(!empty($r['notice']) && str_contains((string)$r['notice'], $STALE),
   'the notice names the configured URL that failed');
cx(!empty($r['notice']) && str_contains((string)$r['notice'], $GOOD),
   'and the default it fell back to');
if (!empty($r['notice'])) echo '        -> ' . (string)$r['notice'] . "\n";

echo "\nA WORKING CONFIGURED URL IS LEFT ALONE\n";
$r2 = skin_registry_fetch($GOOD, true);
cx(!isset($r2['error']), 'the default URL reads cleanly');
cx(empty($r2['notice']), 'and carries NO notice, because nothing was recovered');

echo "\nANY UNREACHABLE CONFIGURED URL RECOVERS, NOT JUST A WRONG PATH\n";
// I first wrote this section expecting an error, on the assumption that
// 127.0.0.1:1 was "the same as the default". IT IS NOT -- it is a different
// URL, so it falls back and succeeds, which is correct and is the whole point.
// The wrong expectation was mine; a connection refused is no different from a
// 404 as far as a stranded site is concerned.
$dead = skin_registry_fetch($DEAD, true);
cx(!isset($dead['error']), 'a configured URL that refuses the connection still recovers');
cx(isset($dead['skins']['impact-printer']), 'and returns the real registry');
cx(!empty($dead['notice']) && str_contains((string)$dead['notice'], $DEAD)
   && str_contains((string)$dead['notice'], $GOOD),
   'with a notice naming both the dead URL and the default');

echo "\nTHE SAME URL IS NEVER RETRIED\n";
// A fallback to the identical URL would just double the wait on an already
// slow failure. The failing-default case cannot be produced against a live
// registry that works, so this is a SOURCE check and labelled as one.
$src2 = (string)file_get_contents($root . '/core/skin-registry.php');
cx(str_contains($src2, "\$default !== '' && \$default !== \$registry_url"),
   'the retry is guarded on the default differing from the configured URL');
cx(empty($r2['notice']),
   'and calling with the default itself produced no notice, so no retry happened');

echo "\nTHE SETTING IS NEVER REWRITTEN\n";
// The fetch takes no PDO and must never acquire one: it cannot write even if
// somebody later wanted it to.
$ref = new ReflectionFunction('skin_registry_fetch');
$types = [];
foreach ($ref->getParameters() as $p) {
    $types[] = $p->getName() . ':' . ((string)($p->getType() ?? 'mixed'));
}
cx(!str_contains(strtolower(implode(',', $types)), 'pdo'),
   'skin_registry_fetch() takes no database handle, so it cannot edit settings: '
   . implode(', ', $types));
$src = (string)file_get_contents($root . '/core/skin-registry.php');
$fn = substr($src, (int)strpos($src, 'function skin_registry_fetch('), 3000);
cx(!str_contains($fn, 'UPDATE snap_settings') && !str_contains($fn, 'INSERT INTO snap_settings'),
   'and writes nothing to snap_settings');

echo "\n{$pass} checks, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
// ===== SNAPSMACK EOF =====
