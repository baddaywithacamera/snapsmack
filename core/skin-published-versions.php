<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * A published skin version never skips a number.
 *
 * SEAN'S STANDING RULE, 24 November 2026, relayed by the PM: a skin's version
 * on dev is its LAST PUBLISHED version plus one, however many commits touch it
 * before it is published. A second change to a skin that has not shipped yet
 * keeps the same number and regenerates the content baseline; it does not bump
 * again.
 *
 * WHY IT MATTERS, and it is the same reason the content gate exists. A site
 * installs a skin BY VERSION. Owners see the version in the admin skin picker
 * and in the registry, and they use it to tell whether they have the current
 * one. A dev tree sitting at 1.3.45 while the registry serves 1.3.42 means two
 * numbers were never published at all: anybody comparing them is comparing
 * against versions that do not exist and cannot be installed.
 *
 * MEASURED when this was written, against the live registry: NEW HORIZON was
 * 1.3.45 on dev with 1.3.42 published, and TRUE GRIT was 1.3.32 with 1.3.30
 * published. Two skins, three skipped numbers between them. Every other skin
 * was already correct -- 13 at published+1 and the rest untouched -- so this
 * is a real slip and not a rule nobody was following.
 *
 * WHERE THE PUBLISHED NUMBER COMES FROM, and the honest limitation. The only
 * authority is the live registry that SMACK CENTRAL writes when Sean packages
 * a skin. A gate cannot reach the network: a test that fails because a
 * connection timed out teaches people to ignore it. So the published numbers
 * are kept in tests/fixtures/skin-published-versions.json and refreshed with
 *
 *     php tools/_build/refresh-skin-published-versions.php
 *
 * which fetches the registry and rewrites the fixture. Run it after packaging.
 *
 * IF THAT FIXTURE GOES STALE, which it will, this gate FAILS LOUDLY rather
 * than passing. A record behind reality makes it demand a number lower than
 * the true one, and the failure names both numbers and the refresh command.
 * That direction is deliberate and it is the difference between this and
 * SS-1566: that gate went QUIET when its record fell behind, and quiet is how
 * a gate stops protecting anything without anyone noticing.
 */

/**
 * The published version of each skin, as last recorded from the registry.
 *
 * @return array{skins:array<string,string>,registry_generated:string,source:string}
 */
function snapsmack_skin_published_versions(string $root): array
{
    $path = $root . '/tests/fixtures/skin-published-versions.json';
    if (!is_file($path)) {
        return ['skins' => [], 'registry_generated' => '', 'source' => ''];
    }
    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data)) {
        return ['skins' => [], 'registry_generated' => '', 'source' => ''];
    }
    $skins = [];
    foreach (($data['skins'] ?? []) as $slug => $version) {
        if (is_string($slug) && (is_string($version) || is_numeric($version))) {
            $skins[$slug] = (string)$version;
        }
    }
    return [
        'skins' => $skins,
        'registry_generated' => (string)($data['registry_generated'] ?? ''),
        'source' => (string)($data['source'] ?? ''),
    ];
}

/**
 * The one version that may follow this published one: its last part plus one.
 *
 * Returns '' when the version is not a dotted number, because inventing a
 * successor for something like "2.0-beta" would be a guess, and a gate that
 * guesses is worse than a gate that says it cannot tell.
 */
function snapsmack_skin_next_version(string $published): string
{
    $published = trim($published);
    if ($published === '' || !preg_match('/^\d+(\.\d+)*$/', $published)) return '';
    $parts = explode('.', $published);
    $parts[count($parts) - 1] = (string)((int)$parts[count($parts) - 1] + 1);
    return implode('.', $parts);
}

/**
 * Complaints about skins whose version skips past the published one.
 *
 * @param array<string,string> $published slug => published version
 * @param array<string,string> $current   slug => version in the tree
 * @return list<string>
 */
function snapsmack_skin_version_skip_failures(array $published, array $current): array
{
    $failures = [];
    foreach ($current as $slug => $version) {
        $version = trim((string)$version);
        if ($version === '') continue;      // the content gate reports this one

        // A SKIN THE REGISTRY HAS NEVER CARRIED. Nothing is published, so
        // there is no number to follow on from and any version is acceptable.
        // Saying "I cannot tell" is the only honest answer, and inventing a
        // rule here would block every new skin on its first commit.
        if (!array_key_exists($slug, $published)) continue;

        // An empty recorded version used to be skipped on its own line here.
        // Mutating that line away changed no verdict, because an empty string
        // has no computable successor and the $next === '' check below already
        // declines it. A guard that can never be the deciding condition looks
        // like safety and is not, so it is written down instead.
        $was = trim((string)$published[$slug]);
        if ($version === $was) continue;    // untouched since it shipped

        $next = snapsmack_skin_next_version($was);
        if ($next === '') {
            // Not a dotted number, so the successor is not computable. The
            // gate declines rather than guessing.
            continue;
        }
        if ($version === $next) continue;   // the one correct bump

        if (version_compare($version, $was, '<')) {
            $failures[] = "{$slug}: the tree says {$version} but {$was} is already PUBLISHED. "
                . 'A version cannot go backwards -- owners already have '
                . "{$was} installed. Either the manifest is wrong, or "
                . 'tests/fixtures/skin-published-versions.json is out of date: refresh it with '
                . 'php tools/_build/refresh-skin-published-versions.php';
            continue;
        }

        $failures[] = "{$slug}: version {$version} SKIPS a published number. {$was} is published, "
            . "so the next one is {$next} -- however many commits touch this skin before it "
            . 'ships. A second change to an unpublished skin keeps its number and regenerates '
            . 'the content baseline. Set the manifest to ' . $next . ' and regenerate with '
            . 'php tools/_build/generate-skin-version-baseline.php, or if '
            . $was . ' is no longer what the registry serves, refresh the published record with '
            . 'php tools/_build/refresh-skin-published-versions.php';
    }
    return $failures;
}

// ===== SNAPSMACK EOF =====
