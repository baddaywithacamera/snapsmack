<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * SS-1428 + SS-1429 — what must be true before a kit overwrites a site.
 *
 * SS-1428: validateManifest() was a public method with NO CALLER anywhere in
 * the codebase. A validator nobody ran.
 *
 * SS-1429: the import read the kit's version, mode, URL and UUID out of the
 * manifest, PRINTED two of them, and compared none of them to this install.
 * A kit from another site, another mode, or a newer build was applied exactly
 * like the right one. And nothing synchronised the schema afterwards, so an
 * older kit left this build's code running over older tables, with every
 * guarded reader in core/ degrading quietly because that is what they are
 * built to do.
 *
 * THE DESIGN DECISION THIS FILE PINS, because it is the one a later reader
 * will want to "tighten" and must not: A DIFFERENT site_uuid OR site_url IS
 * WARNED ABOUT AND ALLOWED. In a real disaster recovery the database is gone,
 * you install fresh, that mints a NEW uuid, and then you import the kit. The
 * kit's identity never matches the install's. Refusing a mismatch would mean
 * the recovery tool refuses to recover. Intent is proven a layer up instead:
 * SS-1430 makes the admin page require this site's own address typed out.
 *
 * Nearly everything here is EXECUTED: preflightKit() and validateManifest()
 * take plain arrays and return plain arrays. Only the two ordering facts --
 * preflight before the SQL, schema sync after it -- are read from source,
 * because driving them needs a real .tar.gz and a real database.
 *
 * Run: php tests/kit-preflight-guards-regression.php
 */

$root = dirname(__DIR__);
require_once $root . '/core/constants.php';
require_once $root . '/core/recovery-engine.php';

$failures = [];

function check(string $label, bool $ok, array &$failures): void {
    if ($ok) {
        echo "  PASS  {$label}\n";
    } else {
        echo "  FAIL  {$label}\n";
        $failures[] = $label;
    }
}

function engine(): SnapSmackRecovery {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE TABLE snap_settings (setting_key TEXT PRIMARY KEY, setting_val TEXT)");
    return new SnapSmackRecovery($pdo, dirname(__DIR__));
}

/** A manifest as core/export-engine.php actually writes one. */
function kit(array $over = []): array {
    return array_merge([
        'export_type'       => 'recovery-kit',
        'export_date'       => '2026-11-18T12:00:00+00:00',
        'snapsmack_version' => '0.7.999D',
        'site_name'         => 'Found Textures',
        'site_url'          => 'https://foundtextures.ca',
        'site_mode'         => 'photoblog',
        'site_uuid'         => 'uuid-aaa',
        'files'             => ['database.sql' => ['sha256' => 'x']],
    ], $over);
}

/** This install, as the preflight reads it out of snap_settings. */
function here(array $over = []): array {
    return array_merge([
        'installed_version' => '0.7.999D',
        'site_mode'         => 'photoblog',
        'site_url'          => 'https://foundtextures.ca',
        'site_uuid'         => 'uuid-aaa',
    ], $over);
}

$e = engine();

echo "SS-1428 + SS-1429 — kit preflight guards\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — the matching case is allowed (nothing below means anything otherwise)\n";
// ─────────────────────────────────────────────────────────────────────────────
$ok = $e->preflightKit(kit(), here());
check('a kit from this same site, build and mode is accepted',
      $ok['refusals'] === [], $failures);
check('and raises no warnings', $ok['warnings'] === [], $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — validateManifest is reached, and now requires the identity fields\n";
// ─────────────────────────────────────────────────────────────────────────────
foreach (['export_type', 'export_date', 'files'] as $field) {
    $m = kit(); unset($m[$field]);
    check("a manifest with no {$field} is refused",
          $e->preflightKit($m, here())['refusals'] !== [], $failures);
}
foreach (['snapsmack_version', 'site_mode', 'site_url', 'site_uuid'] as $field) {
    $m = kit(); unset($m[$field]);
    $r = $e->preflightKit($m, here())['refusals'];
    check("a manifest with no {$field} is refused (SS-1428: absent is not skipped)",
          $r !== [], $failures);
}
$m = kit(['export_type' => 'inventory']);
check('an inventory export is refused as a recovery kit',
      $e->preflightKit($m, here())['refusals'] !== [], $failures);
$m = kit(['files' => ['notes.txt' => []]]);
check('a kit whose manifest lists no database.sql is refused',
      $e->preflightKit($m, here())['refusals'] !== [], $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — a NEWER kit is refused; an older one is the recovery case\n";
// ─────────────────────────────────────────────────────────────────────────────
$r = $e->preflightKit(kit(['snapsmack_version' => '0.7.1000D']), here(['installed_version' => '0.7.999D']));
check('a kit from a newer build is refused',
      $r['refusals'] !== [], $failures);
check('and the refusal names both versions, so the owner knows what to update',
      $r['refusals'] !== [] && str_contains(implode(' ', $r['refusals']), '0.7.1000D')
      && str_contains(implode(' ', $r['refusals']), '0.7.999D'), $failures);

check('a kit from an OLDER build is allowed — that is what recovery is',
      $e->preflightKit(kit(['snapsmack_version' => '0.7.998D']),
                       here(['installed_version' => '0.7.999D']))['refusals'] === [],
      $failures);
check('the same build is allowed',
      $e->preflightKit(kit(['snapsmack_version' => '0.7.999D']),
                       here(['installed_version' => '0.7.999D']))['refusals'] === [],
      $failures);
check('four digits compare numerically here too: 999D kit into a 1000D site is allowed',
      $e->preflightKit(kit(['snapsmack_version' => '0.7.999D']),
                       here(['installed_version' => '0.7.1000D']))['refusals'] === [],
      $failures);
check('and a 1000D kit into a 999D site is refused',
      $e->preflightKit(kit(['snapsmack_version' => '0.7.1000D']),
                       here(['installed_version' => '0.7.999D']))['refusals'] !== [],
      $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4 — a mode mismatch is refused\n";
// ─────────────────────────────────────────────────────────────────────────────
check('a smacktalk kit into a photoblog install is refused',
      $e->preflightKit(kit(['site_mode' => 'smacktalk']), here(['site_mode' => 'photoblog']))['refusals'] !== [],
      $failures);
check('a photoblog kit into a smacktalk install is refused',
      $e->preflightKit(kit(['site_mode' => 'photoblog']), here(['site_mode' => 'smacktalk']))['refusals'] !== [],
      $failures);
check('a matching mode is allowed',
      $e->preflightKit(kit(['site_mode' => 'carousel']), here(['site_mode' => 'carousel']))['refusals'] === [],
      $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 5 — DIFFERENT IDENTITY IS WARNED ABOUT AND ALLOWED\n";
// ─────────────────────────────────────────────────────────────────────────────
// The property most at risk of being "tightened" into a bug. A rebuilt
// install has a new uuid; refusing on that would make the recovery tool
// refuse to recover.
$r = $e->preflightKit(kit(['site_uuid' => 'uuid-aaa']), here(['site_uuid' => 'uuid-bbb-fresh-install']));
check('a kit from a different installation is NOT refused',
      $r['refusals'] === [], $failures);
check('but it IS warned about',
      $r['warnings'] !== [], $failures);
check('and the warning says the restored site will carry the original identity',
      str_contains(strtolower(implode(' ', $r['warnings'])), 'identity'), $failures);

$r = $e->preflightKit(kit(['site_url' => 'https://photowalk.ing']), here(['site_url' => 'https://foundtextures.ca']));
check('a kit from a different address is NOT refused',
      $r['refusals'] === [], $failures);
check('but both addresses are named in the warning',
      str_contains(implode(' ', $r['warnings']), 'photowalk.ing')
      && str_contains(implode(' ', $r['warnings']), 'foundtextures.ca'), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 6 — the guard cannot silently pass for want of its comparator\n";
// ─────────────────────────────────────────────────────────────────────────────
// The version check began as `function_exists('snap_version_compare') && ...`,
// which no-ops wherever constants.php has not been loaded -- a check that does
// nothing when its dependency is missing, which is SS-1428 again. It now loads
// the comparator, and refuses if it still cannot.
$src = (string)file_get_contents($root . '/core/recovery-engine.php');
$start = strpos($src, 'public function preflightKit');
$body  = $start === false ? '' : substr($src, $start, 3500);
check('preflightKit loads constants.php rather than hoping it is there',
      str_contains($body, "require_once __DIR__ . '/constants.php'"), $failures);
check('and refuses when the comparator is still unavailable',
      preg_match('/!function_exists\(.snap_version_compare.\)\)\s*\{\s*\$refusals/', $body) === 1,
      $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 7 — the ordering, read from source\n";
// ─────────────────────────────────────────────────────────────────────────────
// Driving these needs a real archive and a real database. The ordering is the
// whole value of the change, so it is pinned here and the weakness is stated.
$imp = strpos($src, 'public function importRecoveryKit');
$impBody = $imp === false ? '' : substr($src, $imp);
$preAt   = strpos($impBody, '$this->preflightKit(');
$sqlAt   = strpos($impBody, '$this->importSqlDump(');
$syncAt  = strpos($impBody, 'snap_schema_sync(');
check('importRecoveryKit calls preflightKit', $preAt !== false, $failures);
check('the preflight runs BEFORE the SQL dump',
      $preAt !== false && $sqlAt !== false && $preAt < $sqlAt, $failures);
check('a refusal returns before the SQL, rather than collecting errors and continuing',
      $preAt !== false && $sqlAt !== false
      && str_contains(substr($impBody, $preAt, $sqlAt - $preAt), 'return $result'), $failures);
check('the schema sync runs AFTER the SQL dump',
      $sqlAt !== false && $syncAt !== false && $sqlAt < $syncAt, $failures);
check('the schema sync is the updater\'s function, not a reimplementation',
      str_contains($src, "require_once __DIR__ . '/schema-sync.php'"), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
if ($failures) {
    fwrite(STDERR, 'SS-1428 + SS-1429 FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}
echo "SS-1428 + SS-1429 PASS — the manifest is validated before anything is\n";
echo "overwritten, a newer or wrong-mode kit is refused, a different installation\n";
echo "is warned about and still restorable, and the schema is synced afterwards.\n";
// ===== SNAPSMACK EOF =====
