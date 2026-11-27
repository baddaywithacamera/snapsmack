<?php
declare(strict_types=1);

/**
 * Print, and optionally write, the version re-baseline mapping.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 *
 * The table is DERIVED from the manifests and tool versions in the build, not
 * typed. A hand-written mapping of 43 packages is a list of typos waiting to
 * be published, and it goes stale the moment a skin is bumped.
 *
 *   php tools/_build/version-rebaseline-table.php            # print
 *   php tools/_build/version-rebaseline-table.php --write    # and write the JSON
 *
 * The rule it applies lives in core/version-series.php, so this file decides
 * nothing: it reads what is in the tree and asks that one place what each
 * version becomes.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/core/constants.php';
require_once $root . '/core/version-series.php';

$current = [];
foreach (glob($root . '/skins/*/manifest.json') ?: [] as $manifest) {
    $data = json_decode((string)file_get_contents($manifest), true);
    $current['skin: ' . basename(dirname($manifest))] = (string)($data['version'] ?? '');
}
foreach (glob($root . '/tools/*/_version.py') ?: [] as $versionFile) {
    if (preg_match('/[0-9]+\.[0-9]+\.[0-9]+/', (string)file_get_contents($versionFile), $m) === 1) {
        $current['tool: ' . basename(dirname($versionFile))] = $m[0];
    }
}
$current['core'] = SNAPSMACK_VERSION_SHORT;
ksort($current);

$rows = snap_version_mapping($current);
$changed = array_values(array_filter($rows, static fn (array $r): bool => (bool)$r['changed']));

printf("%-32s %-10s    %-10s %s\n", 'PACKAGE', 'FROM', 'TO', 'EPOCH');
foreach ($rows as $row) {
    printf("%-32s %-10s %s %-10s %d\n",
        $row['name'], $row['from'], $row['changed'] ? '->' : '  ', $row['to'], $row['epoch']);
}
printf("\n%d of %d packages move. The other %d are already under 0.8 and keep their numbers.\n",
    count($changed), count($rows), count($rows) - count($changed));
echo "Nothing is republished by this script.\n";

if (in_array('--write', array_slice($argv, 1), true)) {
    $out = $root . '/opaudits/evidence/1007D/version-rebaseline-mapping.json';
    $payload = [
        'generated_by' => 'tools/_build/version-rebaseline-table.php',
        'rule' => 'at 0.8 or above becomes ' . SNAP_VERSION_SERIES . '.(patch+1), carrying '
            . 'version_epoch ' . SNAP_VERSION_EPOCH . '; anything already under 0.8 keeps its number',
        'authority' => "Sean's delegated mapping, relayed by the PM in room message 8393, "
            . 'with his one condition: everything stays under 1.0',
        'rows' => $rows,
    ];
    if (@file_put_contents($out, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
        fwrite(STDERR, "could not write {$out}\n");
        exit(1);
    }
    echo "wrote {$out}\n";
}

// ===== SNAPSMACK EOF =====
