<?php
/**
 * SS-1417 — a Recovery Kit placeholder must never become a usable fleet credential.
 *
 * Recovery Kit exports strip live fleet credentials. Before this guard they were
 * replaced with the public literal __REDACTED__, restored verbatim into an active
 * node row, and accepted by every fleet-key gate, because each gate is a plain
 * equality match on a non-empty string. The literal is published in this
 * repository and in CHANGELOG.md, so presenting it is not a guessing problem.
 *
 * This runs the whole chain for real against sqlite — export redaction, then a
 * restore of a dump that carries the historical marker, then authentication —
 * rather than grepping the source. Each stage fails on the pre-SS-1417 code.
 *
 * Run: php tests/recovery-kit-placeholder-credential-regression.php
 */

$root = dirname(__DIR__);
require_once $root . '/core/mesh-helpers.php';
require_once $root . '/core/export-engine.php';
require_once $root . '/core/recovery-engine.php';

$MARKER   = '__REDACTED__';
$REAL_KEY = str_repeat('a1b2c3d4', 8);          // 64 lowercase hex, as issued
$failures = [];

function check(string $label, bool $ok, array &$failures): void {
    if ($ok) {
        echo "  PASS  {$label}\n";
    } else {
        echo "  FAIL  {$label}\n";
        $failures[] = $label;
    }
}

/** Fresh in-memory fleet DB with one active spoke holding real keys. */
function fleet_db(string $real_key): PDO {
    $pdo = new PDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo->exec("CREATE TABLE snap_multisite_nodes (
        id INTEGER PRIMARY KEY, role TEXT, site_url TEXT, site_name TEXT,
        api_key_local TEXT NOT NULL DEFAULT '',
        api_key_remote TEXT NOT NULL DEFAULT '',
        api_key_backup TEXT NOT NULL DEFAULT '',
        status TEXT NOT NULL DEFAULT 'active'
    )");
    $pdo->exec("CREATE TABLE snap_settings (
        setting_key TEXT PRIMARY KEY, setting_val TEXT
    )");
    $pdo->prepare("INSERT INTO snap_multisite_nodes
        (id, role, site_url, site_name, api_key_local, api_key_remote, api_key_backup, status)
        VALUES (1, 'spoke', 'https://spoke.example', 'Spoke', ?, ?, ?, 'active')")
        ->execute([$real_key, $real_key, $real_key]);
    $pdo->exec("INSERT INTO snap_settings (setting_key, setting_val)
                VALUES ('site_uuid', 'uuid-for-test'), ('fleet_api_key', 'live-secret-value')");
    return $pdo;
}

echo "SS-1417 Recovery Kit placeholder credential — export -> restore -> auth round trip\n\n";

// ─────────────────────────────────────────────────────────────────────────────
echo "STAGE 1 — EXPORT must not write an authenticating value\n";
// ─────────────────────────────────────────────────────────────────────────────
$pdo    = fleet_db($REAL_KEY);
$export = new SnapSmackExport($pdo, $root);

// Private since it is an internal dump step; reaching it directly is the point
// of this test. PHP >= 8.1 needs no setAccessible() and 8.5 deprecates it.
$redact = new ReflectionMethod(SnapSmackExport::class, 'redactSecrets');

$node_row = $pdo->query("SELECT * FROM snap_multisite_nodes WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
$dumped   = $redact->invoke($export, 'snap_multisite_nodes', $node_row);

foreach (['api_key_local', 'api_key_remote', 'api_key_backup'] as $col) {
    check("export: {$col} is not the live key", ($dumped[$col] ?? null) !== $REAL_KEY, $failures);
    check("export: {$col} is not the marker",   ($dumped[$col] ?? null) !== $MARKER,   $failures);
    check("export: {$col} is empty",            ($dumped[$col] ?? null) === '',        $failures);
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 2 — RESTORE must clear a historical marker already on disk\n";
// ─────────────────────────────────────────────────────────────────────────────
// Fixing export does nothing for dumps written by older builds, and those are
// the dumps a real recovery restores. Simulate exactly that file.
$pdo2 = fleet_db($REAL_KEY);
// Clear BOTH seeded tables. Leaving the seeded fleet_api_key row in place made
// the dump's settings INSERT collide on the primary key, so the marker never
// landed and the settings assertion below passed for the wrong reason.
$pdo2->exec("DELETE FROM snap_multisite_nodes");
$pdo2->exec("DELETE FROM snap_settings");

$dump = $root . '/tests/_ss1417-historical-dump.sql';
file_put_contents($dump, implode("\n", [
    "-- Recovery Kit dump written by a pre-SS-1417 build",
    "INSERT INTO snap_multisite_nodes (id, role, site_url, site_name, api_key_local, api_key_remote, api_key_backup, status)",
    "VALUES (1, 'spoke', 'https://spoke.example', 'Spoke', '{$MARKER}', '{$MARKER}', '{$MARKER}', 'active');",
    "INSERT INTO snap_settings (setting_key, setting_val) VALUES ('fleet_api_key', '{$MARKER}');",
    "",
]));

$recovery = new SnapSmackRecovery($pdo2, $root);
$result   = $recovery->importSqlDump($dump);
@unlink($dump);

check('restore: both dump statements executed with no errors',
      ($result['imported'] ?? 0) === 2 && empty($result['errors']), $failures);
if (!empty($result['errors'])) {
    foreach ($result['errors'] as $err) echo "        import error: {$err}
";
}

$restored = $pdo2->query("SELECT * FROM snap_multisite_nodes WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
check('restore: a node row was actually restored', is_array($restored), $failures);
foreach (['api_key_local', 'api_key_remote', 'api_key_backup'] as $col) {
    check("restore: {$col} no longer holds the marker", ($restored[$col] ?? null) !== $MARKER, $failures);
    check("restore: {$col} is empty",                   ($restored[$col] ?? null) === '',      $failures);
}
$setting = $pdo2->query("SELECT setting_val FROM snap_settings WHERE setting_key = 'fleet_api_key'")->fetchColumn();
check('restore: settings marker cleared too', $setting !== $MARKER, $failures);
check('restore: the clearing is reported to the caller',
      !empty($result['cleared_placeholders']), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 3 — AUTH must reject the marker even if a row still holds it\n";
// ─────────────────────────────────────────────────────────────────────────────
// The layer that holds for a dump we never get to rewrite: force the marker
// into an active row and confirm it still authenticates as nothing.
$pdo3 = fleet_db($REAL_KEY);
$pdo3->exec("UPDATE snap_multisite_nodes SET api_key_local = '{$MARKER}' WHERE id = 1");

check('auth: the marker does not resolve a peer',
      ms_resolve_peer($pdo3, $MARKER) === null, $failures);
check('auth: the marker is rejected by the key guard',
      ms_is_authenticating_fleet_key($MARKER) === false, $failures);
check('auth: an empty bearer is rejected',
      ms_is_authenticating_fleet_key('') === false, $failures);
check('auth: whitespace around the marker does not slip past',
      ms_is_authenticating_fleet_key("  {$MARKER}  ") === false, $failures);

// The guard must not break real keys — a lockout here would take the fleet down.
$pdo4 = fleet_db($REAL_KEY);
check('auth: a real key still resolves its peer',
      is_array(ms_resolve_peer($pdo4, $REAL_KEY)), $failures);
check('auth: a real key passes the guard',
      ms_is_authenticating_fleet_key($REAL_KEY) === true, $failures);
check('auth: a real key is canonical 64-hex',
      ms_is_canonical_fleet_key($REAL_KEY) === true, $failures);
check('auth: the marker is not canonical 64-hex',
      ms_is_canonical_fleet_key($MARKER) === false, $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nSTAGE 4 — every fleet-key gate carries the guard\n";
// ─────────────────────────────────────────────────────────────────────────────
// multisite-api.php authenticates at four separate points. A guard on some of
// them is not a fix, so count them rather than trusting one spot-check.
$api   = file_get_contents($root . '/core/multisite-api.php');
$gates = preg_match_all('/ms_is_authenticating_fleet_key\s*\(/', $api);
check("multisite-api: all 4 bearer gates guarded (found {$gates})", $gates >= 4, $failures);

$mesh = file_get_contents($root . '/core/mesh-helpers.php');
check('mesh-helpers: ms_resolve_peer uses the guard, not a bare empty check',
      !preg_match('/function ms_resolve_peer.*?\n\s*if \(\$bearer === \'\'\) return null;/s', $mesh), $failures);

$exp = file_get_contents($root . '/core/export-engine.php');
check('export-engine: no marker is written into node key columns',
      !preg_match("/api_key_backup'\].*?__REDACTED__/s", $exp), $failures);

// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
if ($failures) {
    fwrite(STDERR, 'SS-1417 FAILED — ' . count($failures) . " check(s):\n");
    foreach ($failures as $f) fwrite(STDERR, "  - {$f}\n");
    exit(1);
}
echo "SS-1417 PASS — the placeholder cannot become a usable fleet credential.\n";
echo "Export writes nothing authenticating, restore clears historical markers,\n";
echo "and every fleet-key gate rejects the marker while real keys still work.\n";
// ===== SNAPSMACK EOF =====
