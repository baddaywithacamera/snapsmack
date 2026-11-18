<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 *
 * SS-1424 — the marker guard must reject the marker in ANY casing. 27 sites.
 *
 * SS-1417 made every fleet-key gate refuse the Recovery Kit marker, and
 * compared the bearer to it exactly. The comparison that decides whether a
 * row actually matches does not work that way: snap_multisite_nodes is
 * utf8mb4_unicode_ci, MariaDB's default, so
 *
 *     WHERE api_key_local = '__redacted__'
 *
 * matches a stored '__REDACTED__'. A bearer of '__redacted__' was therefore
 * not the exact marker, passed the guard, and then matched the stale row
 * anyway. Two comparisons of the same two strings, disagreeing.
 *
 * WHY A STALE ROW IS STILL REACHABLE after SS-1417: export writes empty and
 * restore clears what it finds, but a kit written by an older build and
 * restored by an older build may have left a marker on an active node, and
 * nothing goes back in time to clean it. The guard is the layer that holds
 * when the data is already wrong, so the guard is the layer that has to be
 * right about casing.
 *
 * WHAT THIS FILE CAN AND CANNOT RUN. The case-insensitive MATCH is a
 * property of the live engine's collation. SQLite compares TEXT
 * case-sensitively, so the dangerous match cannot be reproduced here; what
 * is reproduced is the guard's answer, which is the half that was wrong and
 * the half that fixes it. Section 4 simulates the ci match explicitly with
 * COLLATE NOCASE so the end-to-end story is executed rather than argued,
 * and says that is what it is doing. A MariaDB run belongs to Codex.
 *
 * Run: php tests/fleet-marker-guard-rejects-case-variants-regression.php
 */

$root = dirname(__DIR__);
require_once $root . '/core/mesh-helpers.php';
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

$REAL_KEY = str_repeat('a1b2c3d4', 8);          // 64 lowercase hex, as issued

echo "SS-1424 — the fleet marker guard rejects every casing of the marker\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — the guard refuses the marker however it is written\n";
// ─────────────────────────────────────────────────────────────────────────────
$variants = [
    '__REDACTED__'       => 'the exact marker',
    '__redacted__'       => 'all lower case',
    '__Redacted__'       => 'mixed case',
    '__REDACTed__'       => 'mixed case, late',
    '__rEdAcTeD__'       => 'alternating',
    '  __REDACTED__  '   => 'padded with spaces',
    "\t__redacted__\n"   => 'padded with a tab and a newline',
    ' __ReDaCtEd__ '     => 'padded and mixed',
];
foreach ($variants as $variant => $label) {
    check("guard rejects {$label}",
          ms_is_authenticating_fleet_key($variant) === false, $failures);
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — and still lets real keys through\n";
// ─────────────────────────────────────────────────────────────────────────────
// A guard that refuses too much is a fleet outage, which is worse than the
// hole it closes. These are the shapes a real key takes.
$allowed = [
    $REAL_KEY                      => 'a 64-hex key as issued',
    strtoupper($REAL_KEY)          => 'the same key upper-cased',
    'REDACTED'                     => 'a key that merely contains the word',
    '__REDACTED'                   => 'the marker missing its trailing underscores',
    'REDACTED__'                   => 'the marker missing its leading underscores',
    '__REDACTED__x'                => 'the marker with a character appended',
    'x__REDACTED__'                => 'the marker with a character prepended',
    '__REDACTED___'                => 'the marker with a third underscore',
    str_repeat('f', 64)            => 'an all-f key',
];
foreach ($allowed as $key => $label) {
    check("guard allows {$label}", ms_is_authenticating_fleet_key($key) === true, $failures);
}
check('guard still rejects an empty key', ms_is_authenticating_fleet_key('') === false, $failures);
check('guard still rejects whitespace only', ms_is_authenticating_fleet_key("  \t ") === false, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — ms_resolve_peer refuses a variant even against a live row\n";
// ─────────────────────────────────────────────────────────────────────────────
function fleet_db_1424(string $real_key, string $stored): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("CREATE TABLE snap_multisite_nodes (
        id INTEGER PRIMARY KEY, role TEXT, site_url TEXT, site_name TEXT,
        api_key_local TEXT NOT NULL DEFAULT '' COLLATE NOCASE,
        api_key_remote TEXT NOT NULL DEFAULT '',
        api_key_backup TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'active')");
    $pdo->exec("CREATE TABLE snap_settings (setting_key TEXT PRIMARY KEY, setting_val TEXT)");
    $pdo->prepare("INSERT INTO snap_multisite_nodes
        (id, role, site_url, site_name, api_key_local, api_key_remote, api_key_backup, status)
        VALUES (1,'spoke','https://spoke.example','Spoke',?,?,?,'active')")
        ->execute([$stored, $real_key, $real_key]);
    return $pdo;
}

// COLLATE NOCASE on api_key_local reproduces MariaDB's utf8mb4_unicode_ci
// for this column. It is a stand-in, stated as one: the live engine does
// this to every text column by default, and SQLite does it to none.
$pdo = fleet_db_1424($REAL_KEY, '__REDACTED__');

$matched = $pdo->prepare("SELECT id FROM snap_multisite_nodes WHERE api_key_local = ?");
$matched->execute(['__redacted__']);
check('fixture is honest: the ci column DOES match a differently cased marker',
      $matched->fetchColumn() !== false, $failures);

foreach (array_keys($variants) as $variant) {
    check('resolve_peer refuses ' . json_encode($variant),
          ms_resolve_peer($pdo, $variant) === null, $failures);
}

$live = fleet_db_1424($REAL_KEY, $REAL_KEY);
check('resolve_peer still finds the peer for a real key',
      is_array(ms_resolve_peer($live, $REAL_KEY)), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4 — restore clears a variant, not only the exact literal\n";
// ─────────────────────────────────────────────────────────────────────────────
// A dump can carry any casing. Clearing only the exact literal would leave a
// lower-case marker sitting on an active node after a recovery, which is the
// row STAGE 3 then has to defend against forever.
$pdo2 = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo2->exec("CREATE TABLE snap_multisite_nodes (
    id INTEGER PRIMARY KEY, role TEXT, site_url TEXT, site_name TEXT,
    api_key_local TEXT NOT NULL DEFAULT '', api_key_remote TEXT NOT NULL DEFAULT '',
    api_key_backup TEXT NOT NULL DEFAULT '', status TEXT NOT NULL DEFAULT 'active')");
$pdo2->exec("CREATE TABLE snap_settings (setting_key TEXT PRIMARY KEY, setting_val TEXT)");

$dump = $root . '/tests/_ss1424-mixed-case-dump.sql';
file_put_contents($dump, implode("\n", [
    "INSERT INTO snap_multisite_nodes (id, role, site_url, site_name, api_key_local, api_key_remote, api_key_backup, status)",
    "VALUES (1,'spoke','https://spoke.example','Spoke','__redacted__','__Redacted__','__REDACTED__','active');",
    "INSERT INTO snap_settings (setting_key, setting_val) VALUES ('fleet_api_key','__rEdAcTeD__');",
    "",
]));
$result = (new SnapSmackRecovery($pdo2, $root))->importSqlDump($dump);
@unlink($dump);

check('restore: the mixed-case dump imported cleanly',
      ($result['imported'] ?? 0) === 2 && empty($result['errors']), $failures);

$row = $pdo2->query("SELECT * FROM snap_multisite_nodes WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
foreach (['api_key_local', 'api_key_remote', 'api_key_backup'] as $col) {
    check("restore: {$col} cleared although its casing differed",
          ($row[$col] ?? null) === '', $failures);
}
$setting = $pdo2->query("SELECT setting_val FROM snap_settings WHERE setting_key='fleet_api_key'")->fetchColumn();
check('restore: the settings marker cleared although its casing differed',
      $setting === '', $failures);

// A real key must survive a restore untouched, or recovery costs the owner
// their fleet.
$pdo3 = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo3->exec("CREATE TABLE snap_multisite_nodes (
    id INTEGER PRIMARY KEY, api_key_local TEXT NOT NULL DEFAULT '',
    api_key_remote TEXT NOT NULL DEFAULT '', api_key_backup TEXT NOT NULL DEFAULT '')");
$pdo3->exec("CREATE TABLE snap_settings (setting_key TEXT PRIMARY KEY, setting_val TEXT)");
$pdo3->prepare("INSERT INTO snap_multisite_nodes (id, api_key_local, api_key_remote, api_key_backup)
                VALUES (1, ?, ?, ?)")->execute([$REAL_KEY, $REAL_KEY, $REAL_KEY]);
$empty = $root . '/tests/_ss1424-empty.sql';
file_put_contents($empty, "-- nothing to import\n");
(new SnapSmackRecovery($pdo3, $root))->importSqlDump($empty);
@unlink($empty);
check('restore: a real key is left alone',
      $pdo3->query("SELECT api_key_local FROM snap_multisite_nodes WHERE id=1")->fetchColumn() === $REAL_KEY,
      $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 5 — the comparison is normalised, in source\n";
// ─────────────────────────────────────────────────────────────────────────────
// Behaviour above is produced by normalising both sides. Pin that, because
// an exact-match comparison looks correct to a reader who does not know the
// column's collation -- which is how it got written the first time.
$mesh = (string)file_get_contents($root . '/core/mesh-helpers.php');
$start = strpos($mesh, 'function ms_is_authenticating_fleet_key(');
$body  = $start === false ? '' : substr($mesh, $start, 400);
check('the guard compares case-normalised values',
      $body !== '' && preg_match('/hash_equals\(\s*strtoupper\(.*?strtoupper\(/s', $body) === 1,
      $failures);
check('the guard no longer compares the raw marker to the raw bearer',
      $body !== '' && !preg_match('/hash_equals\(\s*MS_REDACTED_MARKER\s*,\s*\$k\s*\)/', $body),
      $failures);

$rec = (string)file_get_contents($root . '/core/recovery-engine.php');
check('restore normalises both sides of its WHERE too',
      substr_count($rec, 'UPPER(') >= 2, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
if ($failures) {
    fwrite(STDERR, 'SS-1424 FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}
echo "SS-1424 PASS — no casing of the marker authenticates, a restore clears\n";
echo "every casing it finds, and real keys are untouched.\n";
// ===== SNAPSMACK EOF =====
