<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 *
 * Mode-4 (FEDISTRUCTURE) skin-picker is never empty.
 *
 * The companion suite (service-skin-visibility-regression.php) proves the ORDINARY
 * direction: a would-be service skin stays out of a hobbyist's gallery. This proves
 * the FEDISTRUCTURE half.
 *
 * As of option A (0.7.5xx) ONYX ships as an ordinary skin and NO shipped skin
 * carries features.fedistructure_only. The symmetric "mode 4 shows only service
 * skins" split therefore has nothing to filter TO — so the guarantee that matters
 * now is the empty-picker fallback: a FEDISTRUCTURE install that ships no service
 * skin must fall back to showing the ordinary skins, never a blank picker. (The
 * "service skin present → hide ordinary" branch still exists in the filter for a
 * future service skin; it is only exercised when such a skin is actually installed.)
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

// No shipped skin is a service skin any more, so the fallback is the live path.
s_ok(snapsmack_any_service_skin_installed() === false,
     'a shipped skin still carries fedistructure_only — option A removed them all');

$service = ['features' => ['fedistructure_only' => true]];
$normal  = ['features' => ['carousel' => true]];
$bare    = ['name' => 'no features key at all'];

// With no service skin installed, mode 4 must not hide the ordinary skins —
// otherwise the picker is empty and the site has nothing to choose.
s_ok(snapsmack_skin_allowed_distribution($normal) === true,
     'an ordinary skin was hidden on a FEDISTRUCTURE install with no service skin — the picker would be empty');
s_ok(snapsmack_skin_allowed_distribution($bare) === true,
     'a bare skin was hidden on a FEDISTRUCTURE install with no service skin — the picker would be empty');
s_ok(snapsmack_skin_allowed_distribution($service) === true,
     'a service skin was hidden on a FEDISTRUCTURE install');
s_ok(snapsmack_skin_allowed_distribution('not an array') === true,
     'a malformed manifest must never be hidden by accident, on any install');

$list = ['onyx' => $normal, 'new-horizon' => $normal, 'plain' => $bare];
$filtered = snapsmack_skins_for_distribution($list);
s_ok(count($filtered) === 3 && $filtered !== [],
     'the list filter blanked a FEDISTRUCTURE picker that ships no service skin');

// The real shipped ONYX resolves visible on its own FEDISTRUCTURE product.
$m = json_decode((string)file_get_contents(__DIR__ . '/../skins/onyx/manifest.json'), true);
s_ok(is_array($m) && snapsmack_skin_allowed_distribution($m) === true,
     'ONYX is not visible on its own FEDISTRUCTURE install');

if ($failures) {
    fwrite(STDERR, "FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "PASS: mode-4 Onyx-only visibility suite ({$checks} checks)\n";
// ===== SNAPSMACK EOF =====
