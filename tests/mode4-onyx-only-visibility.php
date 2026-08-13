<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 *
 * Mode-4 (FEDISTRUCTURE) "Onyx only" visibility.
 *
 * The companion suite (service-skin-visibility-regression.php) proves the ORDINARY
 * direction: service skins stay out of a hobbyist's gallery. This proves the other
 * half — a FEDISTRUCTURE install (mode 4) shows ONLY the service/Onyx skins and
 * hides the ordinary blog skins entirely.
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

$service = ['features' => ['fedistructure_only' => true]];
$normal  = ['features' => ['carousel' => true]];
$bare    = ['name' => 'no features key at all'];

// On mode 4 the split inverts: Onyx in, ordinary out.
s_ok(snapsmack_skin_allowed_distribution($service) === true,
     'a service (Onyx) skin was hidden on a FEDISTRUCTURE install');
s_ok(snapsmack_skin_allowed_distribution($normal) === false,
     'an ordinary blog skin was offered on mode 4 — a FEDISTRUCTURE install must show only Onyx');
s_ok(snapsmack_skin_allowed_distribution($bare) === false,
     'a skin with no features key leaked into mode 4 — absence means "ordinary", which mode 4 hides');
s_ok(snapsmack_skin_allowed_distribution('not an array') === true,
     'a malformed manifest must never be hidden by accident, on any install');

$list = ['crimson-onyx' => $service, 'new-horizon' => $normal, 'plain' => $bare];
$filtered = snapsmack_skins_for_distribution($list);
s_ok(isset($filtered['crimson-onyx']), 'the list filter dropped the Onyx skin on mode 4');
s_ok(!isset($filtered['new-horizon']) && !isset($filtered['plain']),
     'the list filter kept ordinary skins on mode 4');
s_ok(count($filtered) === 1, 'mode 4 should have left exactly the one Onyx skin');

// The real shipped skin resolves visible on its own product.
$m = json_decode((string)file_get_contents(__DIR__ . '/../skins/crimson-onyx/manifest.json'), true);
s_ok(is_array($m) && snapsmack_skin_allowed_distribution($m) === true,
     'CRIMSON ONYX is not visible on its own FEDISTRUCTURE install');

if ($failures) {
    fwrite(STDERR, "FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "PASS: mode-4 Onyx-only visibility suite ({$checks} checks)\n";
// ===== SNAPSMACK EOF =====
