<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * Record what the live skin registry currently publishes.
 *
 * The no-skipped-versions gate needs to know each skin's PUBLISHED version,
 * and the only authority for that is the registry SMACK CENTRAL writes when
 * Sean packages a skin. A gate cannot fetch it -- a check that fails because
 * a connection timed out is a check people learn to ignore -- so the numbers
 * live in a committed fixture and this tool is what refreshes them.
 *
 * RUN IT AFTER PACKAGING. That is the moment the published numbers change.
 *
 *   php tools/_build/refresh-skin-published-versions.php            write it
 *   php tools/_build/refresh-skin-published-versions.php --dry-run  show only
 *   php tools/_build/refresh-skin-published-versions.php --url URL  another registry
 *
 * It only ever reads a public JSON file and writes one fixture. It sends no
 * credentials and it does not touch any site.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
$fixture = $root . '/tests/fixtures/skin-published-versions.json';

$url = 'https://snapsmack.ca/releases/skins/registry.json';
$dry = in_array('--dry-run', $argv, true);
foreach ($argv as $i => $arg) {
    if ($arg === '--url' && isset($argv[$i + 1])) { $url = (string)$argv[$i + 1]; }
}

$context = stream_context_create(['http' => [
    'method' => 'GET',
    'timeout' => 20,
    // A generic client name. The forum sits behind a filter that refuses
    // unnamed clients, and the releases host may do the same; nothing here
    // identifies anybody.
    'header' => "User-Agent: SnapSmack-Build-Tool/1.0\r\nAccept: application/json\r\n",
]]);

$body = @file_get_contents($url, false, $context);
if ($body === false) {
    fwrite(STDERR, "Could not read the registry at {$url}.\n"
        . "Nothing was written. The existing fixture is left exactly as it was,\n"
        . "because a half-refreshed record is worse than an old one.\n");
    exit(1);
}

$registry = json_decode($body, true);
if (!is_array($registry) || !is_array($registry['skins'] ?? null)) {
    fwrite(STDERR, "The registry did not parse as JSON with a 'skins' map. Nothing written.\n");
    exit(1);
}

$skins = [];
foreach ($registry['skins'] as $slug => $row) {
    if (!is_string($slug) || !is_array($row)) continue;
    $version = trim((string)($row['version'] ?? ''));
    if ($version === '') continue;
    $skins[$slug] = $version;
}
if ($skins === []) {
    fwrite(STDERR, "The registry carried no skin versions at all. Nothing written --\n"
        . "an empty record would silently switch the gate off for every skin.\n");
    exit(1);
}
ksort($skins, SORT_STRING);

// Say what is CHANGING before writing it. The whole point of this file is to
// be read by a person who is about to trust it.
$before = [];
if (is_file($fixture)) {
    $old = json_decode((string)file_get_contents($fixture), true);
    if (is_array($old['skins'] ?? null)) {
        foreach ($old['skins'] as $k => $v) { $before[(string)$k] = (string)$v; }
    }
}
$moved = [];
foreach ($skins as $slug => $version) {
    $was = $before[$slug] ?? null;
    if ($was === null) { $moved[] = sprintf('  + %-22s %s (new to the record)', $slug, $version); }
    elseif ($was !== $version) { $moved[] = sprintf('  * %-22s %s -> %s', $slug, $was, $version); }
}
foreach ($before as $slug => $was) {
    if (!isset($skins[$slug])) { $moved[] = sprintf('  - %-22s %s (no longer in the registry)', $slug, $was); }
}

printf("Registry: %s\n", $url);
printf("Generated: %s\n", (string)($registry['generated'] ?? '(not stated)'));
printf("Skins published: %d\n", count($skins));
echo $moved === [] ? "No published version changed.\n"
                   : "Changes:\n" . implode("\n", $moved) . "\n";

if ($dry) {
    echo "\nDRY RUN - nothing written.\n";
    exit(0);
}

$payload = [
    'schema_version' => 1,
    'purpose' => 'The version each skin is PUBLISHED at, as the live skin registry '
        . "reported it. A skin's version on dev must be this, or this plus one. "
        . 'Published versions never skip a number: a second change to an unpublished '
        . 'skin keeps the number and regenerates the content baseline. Refresh with '
        . 'php tools/_build/refresh-skin-published-versions.php after packaging.',
    'source' => $url,
    'registry_generated' => (string)($registry['generated'] ?? ''),
    'skins' => $skins,
];
file_put_contents($fixture, json_encode(
    $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
printf("\nWrote %s\n", $fixture);
echo "Commit it with the change that made it necessary.\n";
exit(0);

// ===== SNAPSMACK EOF =====
