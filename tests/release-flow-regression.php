<?php
/**
 * SNAPSMACK - Release workflow regression checks.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$root = dirname(__DIR__);
$failures = [];

function rel_expect(bool $condition, string $message): void {
    global $failures;
    if (!$condition) $failures[] = $message;
}

$policy = file_get_contents($root . '/RELEASING.md') ?: '';
$notes = file_get_contents($root . '/CLAUDE.md') ?: '';
$packager = file_get_contents($root . '/smack-central/sc-release.php') ?: '';
$updater = file_get_contents($root . '/core/updater.php') ?: '';
$fedup = file_get_contents($root . '/fedup.php') ?: '';
$guard = file_get_contents($root . '/tools/release-flow.php') ?: '';

rel_expect(str_contains($policy, 'All ordinary implementation pushes go to `dev` only'),
    'policy must make dev the ordinary push branch');
rel_expect(str_contains($policy, 'Never create the plain and `D` tags together'),
    'policy must prohibit simultaneous stable/dev tagging');
rel_expect(!str_contains($notes, 'Always force-move the version tag on'),
    'working notes must not retain the stale force-move instruction');
rel_expect(str_contains($packager, 'latest-fedistructure-dev.json'),
    'packager must publish a separate FEDISTRUCTURE dev manifest');
rel_expect(str_contains($packager, 'Dev releases require matching D-suffixed tag and version.'),
    'packager must reject non-D dev builds');
rel_expect(str_contains($packager, 'Stable releases require a plain tag and version.'),
    'packager must reject D tags in the stable panel');
rel_expect(str_contains($updater, 'UPDATER_API_URL_FEDISTRUCTURE_DEV'),
    'FEDISTRUCTURE updater must know its dev channel');
rel_expect(str_contains($fedup, "\$_GET['track']"),
    'FEDUP must require an explicit dev-track request');
rel_expect(str_contains($guard, "if (\$command === 'push-dev')"),
    'release guard must provide ordinary dev pushes');
rel_expect(str_contains($guard, "if (\$command === 'promote-stable')"),
    'release guard must provide guarded stable promotion');

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}

echo "PASS: Release workflow regression suite\n";
// ===== SNAPSMACK EOF =====
