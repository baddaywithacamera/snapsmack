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
$constants = file_get_contents($root . '/core/constants.php') ?: '';
$changelog = file_get_contents($root . '/CHANGELOG.md') ?: '';
require_once $root . '/smack-central/sc-release-sequence.php';

rel_expect(str_contains($packager, "'assets/ASSET-INVENTORY.json'"),
    'release packages must include the runtime asset inventory used by the fail-closed skin policy');

rel_expect(str_contains($policy, 'All ordinary implementation pushes go to `dev` only'),
    'policy must make dev the ordinary push branch');
rel_expect(str_contains($policy, 'Never create the plain and `D` tags together'),
    'policy must prohibit simultaneous stable/dev tagging');
rel_expect(str_contains($policy, 'unchanged saved settings')
    && str_contains($policy, 'unchanged content')
    && str_contains($policy, 'pre-strip reference'),
    'release policy must preserve identical rendering for unchanged skin inputs');
rel_expect(str_contains($policy, 'must not be described as')
    && str_contains($policy, '"authoritative."'),
    'release policy must prohibit relabelling a regression as authoritative output');
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
rel_expect(substr_count($guard, 'rf_require_release_gate();') === 2,
    'both development tagging and stable promotion must require the exact-commit release gate');
rel_expect(str_contains($guard, "glob(dirname(__DIR__) . '/skins/*/manifest.json')"),
    'release gate must derive the complete packaged skin inventory');
rel_expect(str_contains($guard, "['skin_inventory_sha256']"),
    'release gate must bind parity evidence to the exact packaged skin inventory');
rel_expect(str_contains($guard, 'release-reservations.json'),
    'release guard must preserve deliberately retired identifiers');
rel_expect(str_contains($guard, 'rf_require_packaged_tag_alignment($version);'),
    'tagging must refuse while Git tags are ahead of the public packaged version');
rel_expect(str_contains($guard, 'https://snapsmack.ca/releases/latest-dev.json'),
    'tagging must verify the authoritative public dev manifest and fail closed');
rel_expect(str_contains($guard, 'package and deploy every existing tag in order before creating another tag'),
    'tag refusal must explain an existing tagged/package gap');
$reservations = json_decode((string)file_get_contents($root . '/tools/release-reservations.json'), true);
rel_expect(is_array($reservations) && isset($reservations['0.7.791']),
    'the reverted 0.7.791 identifier must remain retired');
rel_expect(str_contains($guard, "'authority_review' => 'approved'"),
    'release gate must require explicit authority review approval');
rel_expect(str_contains($guard, "'skin_render_parity' => 'pass'"),
    'release gate must require exact-commit skin render parity evidence');
rel_expect(str_contains($guard, "array_key_exists('skin_render_changes', \$gate)"),
    'release gate must require an explicit intentional-render-change ledger');
foreach (['skin', 'reason', 'pre_strip_reference'] as $render_change_field) {
    rel_expect(str_contains($guard, "['skin', 'reason', 'pre_strip_reference']")
        && str_contains($guard, "\$change[\$field]"),
        'intentional skin render changes must declare ' . $render_change_field);
}
rel_expect(str_contains($packager, 'sc_release_identifier_used'),
    'packager must refuse an already-published release identifier');
rel_expect(str_contains($packager, 'release-identifiers.json'),
    'packager must retain an immutable release ledger');
rel_expect(str_contains($packager, "'source_commit'"),
    'published manifests must record the exact source commit');
rel_expect(str_contains($packager, 'sc_record_release_identifier'),
    'packager must record checksum and signature before publication');
rel_expect(str_contains($packager, 'sc_require_dev_sequence(sc_db(), $version)'),
    'dev packager must run the server-side sequential state gate before building');
rel_expect(sc_dev_predecessor('0.7.836D') === '0.7.835D',
    'development predecessor must be calculated without skipping a number');
$skip_refusal = sc_dev_sequence_refusal('0.7.836D', false, false, ['0.7.829D']);
rel_expect(str_contains($skip_refusal, 'SEQUENTIAL RELEASE GATE REFUSED 0.7.836D'),
    'an attempted skipped package must produce an explicit refusal');
rel_expect(str_contains($skip_refusal, '0.7.835D is not in dev build history'),
    'refusal must identify the missing predecessor package');
rel_expect(str_contains($skip_refusal, 'not deployed across the active dev fleet'),
    'refusal must identify predecessor deployment drift');
rel_expect(str_contains($skip_refusal, 'No override exists.'),
    'sequential release gate must not offer an override');
rel_expect(sc_dev_sequence_refusal('0.7.830D', true, true, ['0.7.829D', '0.7.829D']) === '',
    'next package may proceed only when its predecessor is packaged, deployed, and recorded');
if (preg_match("/SNAPSMACK_VERSION_SHORT',\\s*'([^']+)'/", $constants, $version_match)) {
    rel_expect(str_contains($changelog, '## ' . $version_match[1] . ' '),
        'the source version must have a versioned changelog section before tagging');
} else {
    rel_expect(false, 'source version constant must be readable');
}

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}

echo "PASS: Release workflow regression suite\n";
// ===== SNAPSMACK EOF =====
