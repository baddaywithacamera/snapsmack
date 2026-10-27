<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * A skin's maturity is one of three words, and only three.
 *
 * `status` is not decoration. core/skin-registry.php withholds a skin from
 * installation when it reads exactly "development", and SMACK CENTRAL's
 * packager pre-ticks a skin into the registry when it reads exactly "stable".
 * Both comparisons are string-exact.
 *
 * Measured 26 October 2026 across 30 skins: seven words were in use --
 * stable 16, beta 7, alpha 3, available 1, dev 1, experimental 1,
 * development 1 -- against the three declared in skin-registry.php's own
 * comment. CHAPLIN said "dev" and therefore counted as shippable; SHOW N TELL
 * said "development" and did not. Same intent, opposite outcome, three
 * letters apart. Three of the four extra words were never asked for.
 *
 * This holds the vocabulary from both sides:
 *   1. no manifest may carry a word outside the three;
 *   2. the alias map may not grow a target outside the three, which is what
 *      would let an eighth word back in through the front door;
 *   3. the canonicaliser must fold an unknown word to "development", so a
 *      typo withholds a skin instead of shipping it.
 */

require_once dirname(__DIR__) . '/core/skin-manifest.php';

$failures = 0;
function ssv_fail(string $what): void
{
    global $failures;
    $failures++;
    fwrite(STDERR, "FAIL: {$what}\n");
}

$legal = SNAPSMACK_SKIN_STATUSES;
sort($legal);
if ($legal !== ['beta', 'development', 'stable']) {
    ssv_fail('the legal vocabulary is no longer the three words: ' . implode(', ', $legal));
}

// 1. Every shipped manifest.
$root = dirname(__DIR__);
$seen = 0;
foreach (glob($root . '/skins/*/manifest.json') ?: [] as $path) {
    $slug = basename(dirname($path));
    $raw  = json_decode((string)file_get_contents($path), true);
    if (!is_array($raw)) { ssv_fail("{$slug}: manifest.json is not readable JSON"); continue; }
    $seen++;
    $declared = $raw['status'] ?? null;
    if ($declared === null) {
        ssv_fail("{$slug}: declares no status. A skin with no opinion about its own "
            . 'maturity used to default to "stable" and ship.');
        continue;
    }
    if (!in_array($declared, SNAPSMACK_SKIN_STATUSES, true)) {
        $would = snapsmack_skin_status((string)$declared);
        ssv_fail("{$slug}: status \"{$declared}\" is not one of "
            . implode(' | ', SNAPSMACK_SKIN_STATUSES)
            . " (it would be read as \"{$would}\"). Write the word you mean.");
    }
}
if ($seen < 25) ssv_fail("only {$seen} manifests were read; the sweep is not covering the skins");

// 2. The alias map cannot smuggle a fourth word in.
foreach (SNAPSMACK_SKIN_STATUS_ALIASES as $alias => $target) {
    if (!in_array($target, SNAPSMACK_SKIN_STATUSES, true)) {
        ssv_fail("alias \"{$alias}\" maps to \"{$target}\", which is not a legal status.");
    }
    if (in_array($alias, SNAPSMACK_SKIN_STATUSES, true)) {
        ssv_fail("\"{$alias}\" is both a legal status and an alias; it cannot be both.");
    }
}

// 3. The canonicaliser's own behaviour.
$cases = [
    'stable'       => 'stable',
    'beta'         => 'beta',
    'development'  => 'development',
    'STABLE'       => 'stable',       // case is not a different word
    '  beta  '     => 'beta',
    'alpha'        => 'beta',
    'experimental' => 'beta',
    'dev'          => 'beta',
    'available'    => 'beta',
    ''             => 'development',  // no opinion must not mean "ship it"
    'stabel'       => 'development',  // a typo withholds, never ships
    'ready'        => 'development',
];
foreach ($cases as $input => $expected) {
    $got = snapsmack_skin_status($input);
    if ($got !== $expected) {
        ssv_fail("snapsmack_skin_status(\"{$input}\") gave \"{$got}\", expected \"{$expected}\"");
    }
}
if (snapsmack_skin_status(null) !== 'development') {
    ssv_fail('a null status must be read as "development", not shipped');
}

// 4. The two call sites that act on the word must still be the only meanings.
$registry = (string)file_get_contents($root . '/core/skin-registry.php');
if (!str_contains($registry, "!== 'development'")) {
    ssv_fail('core/skin-registry.php no longer withholds "development" from installation; '
        . 'if that rule moved, this test must follow it.');
}

if ($failures > 0) {
    fwrite(STDERR, "\nSkin status vocabulary regression FAILED with {$failures} problem(s).\n");
    exit(1);
}
printf("Skin status vocabulary: %d manifests, three words, aliases fold safely.\n", $seen);
exit(0);
// ===== SNAPSMACK EOF =====
