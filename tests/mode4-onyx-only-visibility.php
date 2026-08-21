<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 *
 * Mode-4 (FEDISTRUCTURE) exposes ONYX and excludes ordinary skins.
 *
 * The companion suite (service-skin-visibility-regression.php) proves the ORDINARY
 * direction: a would-be service skin stays out of a hobbyist's gallery. This proves
 * the FEDISTRUCTURE half.
 *
 * ONYX carries features.fedistructure_only. Its presence activates the strict
 * service-skin branch, keeping an incompatible service identity from leaking to
 * blogs and ordinary blog skins from leaking into the mode-4 product.
 *
 * It runs in its own process on purpose: SNAPSMACK_DISTRIBUTION is a constant, and
 * once defined it cannot be undefined, so the two directions can never be asserted
 * in a single file.
 */

// Stand in for the marker the FEDISTRUCTURE package injects via core/constants.php.
define('SNAPSMACK_DISTRIBUTION', 'fedistructure');

require_once __DIR__ . '/../core/skin-registry.php';

$failures = [];
$checks   = 0;
function s_ok(bool $ok, string $msg): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) $failures[] = $msg;
}

s_ok(defined('SNAPSMACK_DISTRIBUTION') && SNAPSMACK_DISTRIBUTION === 'fedistructure',
     'this test must run as a FEDISTRUCTURE install');

s_ok(snapsmack_any_service_skin_installed() === true,
     'ONYX is not detected as the installed FEDISTRUCTURE service skin');

$service = ['features' => ['fedistructure_only' => true]];
$normal  = ['features' => ['carousel' => true]];
$bare    = ['name' => 'no features key at all'];

s_ok(snapsmack_skin_allowed_distribution($normal) === false,
     'an ordinary skin is visible on a FEDISTRUCTURE install');
s_ok(snapsmack_skin_allowed_distribution($bare) === false,
     'a bare ordinary skin is visible on a FEDISTRUCTURE install');
s_ok(snapsmack_skin_allowed_distribution($service) === true,
     'a service skin was hidden on a FEDISTRUCTURE install');
s_ok(snapsmack_skin_allowed_distribution('not an array') === true,
     'a malformed manifest must never be hidden by accident, on any install');

$list = ['onyx' => $service, 'new-horizon' => $normal, 'plain' => $bare];
$filtered = snapsmack_skins_for_distribution($list);
s_ok(array_keys($filtered) === ['onyx'],
     'the mode-4 list is not restricted to ONYX');

// The real shipped ONYX resolves visible on its own FEDISTRUCTURE product.
$m = json_decode((string)file_get_contents(__DIR__ . '/../skins/onyx/manifest.json'), true);
s_ok(is_array($m) && !empty($m['features']['fedistructure_only'])
     && snapsmack_skin_allowed_distribution($m) === true,
     'ONYX is not visible on its own FEDISTRUCTURE install');

if ($failures) {
    fwrite(STDERR, "FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "PASS: mode-4 Onyx-only visibility suite ({$checks} checks)\n";
// ===== SNAPSMACK EOF =====
