<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

/**
 * One fleet roster, and a version check that can see a straggler.
 *
 * deployed-version.sh reported the HIGHEST version anywhere and nothing else.
 * On 21 Oct 2026 it answered 0.7.927D while photofri.day sat on 917D,
 * photoblogs.fyi -- the relay and FLEET hub -- on 916D, and mirrorproject.fyi
 * on 899D, twenty-eight versions behind. The fleet updater reported "25
 * updated, 0 failed" at the same moment. Both were telling the truth about
 * what they looked at; neither looked at the whole fleet.
 *
 * All three were outside every roster we had, and the two rosters that did
 * exist were hand-kept copies of each other with a comment saying "keep the
 * two in step" -- which is not a mechanism.
 *
 * This test is offline. It never touches the network, so it can run in the
 * commit gate; the live check is the script's own --report.
 */

function roster_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root    = dirname(__DIR__);
$roster  = $root . '/tools/_build/fleet-sites.txt';
$version = $root . '/tools/_build/deployed-version.sh';
$skins   = $root . '/tools/_build/installed-skins.sh';

roster_check(is_file($roster), 'tools/_build/fleet-sites.txt is gone; both fleet scripts depend on it.');

$lines = array_values(array_filter(
    array_map('trim', (array)file($roster)),
    static fn(string $l): bool => $l !== '' && $l[0] !== '#'
));
roster_check(count($lines) >= 19, 'The fleet roster lists only ' . count($lines) . ' sites; 19 were known on 21 Oct 2026. A site has been dropped.');
foreach ($lines as $host) {
    roster_check(
        (bool)preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $host),
        "Roster entry '$host' is not a bare hostname. No scheme, no path, one per line."
    );
}
roster_check(count($lines) === count(array_unique($lines)), 'The fleet roster lists the same site twice.');

// The three that nothing could see. Named so that dropping one fails loudly.
foreach (['photofri.day', 'photoblogs.fyi', 'mirrorproject.fyi', 'snapsmack.ca'] as $host) {
    roster_check(
        in_array($host, $lines, true),
        "$host is missing from the fleet roster. It was a live installation outside every list on 21 Oct 2026 and that is how it fell behind unnoticed."
    );
}

// Neither script may carry its own copy of the list again.
foreach ([$version => 'deployed-version.sh', $skins => 'installed-skins.sh'] as $path => $name) {
    roster_check(is_file($path), "$name is missing.");
    $src = (string)file_get_contents($path);
    roster_check(
        str_contains($src, 'fleet-sites.txt'),
        "$name no longer reads the shared roster; a second hand-kept copy is how all three stragglers stayed invisible."
    );
    roster_check(
        !preg_match('/SITES="[a-z]/', $src),
        "$name has an inline site list again. The roster lives in fleet-sites.txt."
    );
}

// The straggler warning, and the one-line contract the commit gate parses.
$dv = (string)file_get_contents($version);
roster_check(str_contains($dv, '--report'), 'deployed-version.sh lost its --report mode, which is the only way to see every site.');
roster_check(
    substr_count($dv, '>&2') >= 2,
    'deployed-version.sh no longer warns on stderr. The bare call must say when a site is behind, or the next straggler is invisible again.'
);
roster_check(
    (bool)preg_match('/^echo "\$HIGHEST"$/m', $dv),
    'deployed-version.sh must still print the highest version alone on stdout; tests/agreed-rules-gate.php parses exactly that.'
);
// $(...) strips the trailing newline, so printf '%s' drops the last line --
// which silently omitted mirrorproject.fyi from the straggler warning the
// first time this ran.
roster_check(
    !str_contains($dv, <<<'NEEDLE'
        printf '%s' "$BEHIND"
        NEEDLE),
    'deployed-version.sh pipes $BEHIND through printf without a newline, which drops the last straggler from its own report.'
);

fwrite(STDOUT, 'Fleet-roster regression passed: ' . count($lines) . " sites, one roster, straggler reporting intact.\n");

// ===== SNAPSMACK EOF =====
