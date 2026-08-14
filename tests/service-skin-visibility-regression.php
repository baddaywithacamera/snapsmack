<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 *
 * Service-skin visibility regression.
 *
 * A skin flagged `features.fedistructure_only` belongs to a FEDISTRUCTURE
 * product — PHOTOFRI.DAY, APHOTOEVERY.DAY, SMACKCAST — and carries that
 * service's identity. On an ordinary photo blog it must not be offered: a
 * hobbyist's gallery advertising a photofri.day-branded skin is clutter at best,
 * and a site that looks like somebody else's project at worst.
 *
 * This fails in the quiet direction. Nobody files a bug saying "a skin I did not
 * want was listed"; they just have a slightly worse gallery forever. So the rule
 * is asserted rather than left to review, in BOTH places it is enforced — the
 * download gallery and the local skin picker — because a rule applied in one of
 * two places is a rule that will drift.
 */

require_once __DIR__ . '/../core/skin-registry.php';

$failures = [];
$checks   = 0;
function s_ok(bool $ok, string $msg): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) $failures[] = $msg;
}

$service = ['features' => ['fedistructure_only' => true]];
$normal  = ['features' => ['carousel' => true]];
$bare    = ['name' => 'no features key at all'];

// ── On an ordinary install ──────────────────────────────────────────────────
// SNAPSMACK_DISTRIBUTION is undefined here, which IS the ordinary case: the
// marker file only exists inside the FEDISTRUCTURE artifact.
s_ok(!defined('SNAPSMACK_DISTRIBUTION'),
     'this test assumed an ordinary install but the FEDISTRUCTURE marker is defined');

s_ok(snapsmack_skin_allowed_distribution($service) === false,
     'a service skin is offered on an ordinary install');
s_ok(snapsmack_skin_allowed_distribution($normal) === true,
     'an ordinary skin was hidden — the filter is too greedy');
s_ok(snapsmack_skin_allowed_distribution($bare) === true,
     'a skin with no features key was hidden; absence must mean "ordinary"');
s_ok(snapsmack_skin_allowed_distribution('not an array') === true,
     'a malformed manifest must not be able to hide a skin by accident');
s_ok(snapsmack_skin_allowed_distribution(['features' => ['fedistructure_only' => false]]) === true,
     'an explicit false was treated as true');

$list = ['onyx' => $service, 'new-horizon' => $normal, 'plain' => $bare];
$filtered = snapsmack_skins_for_distribution($list);
s_ok(!isset($filtered['onyx']), 'the list filter kept a service skin');
s_ok(isset($filtered['new-horizon']) && isset($filtered['plain']),
     'the list filter dropped an ordinary skin');
s_ok(count($filtered) === 2, 'the list filter changed the count unexpectedly');
s_ok(snapsmack_skins_for_distribution([]) === [], 'the list filter breaks on an empty list');

// ── ONYX ships as an ordinary skin (option A, 0.7.5xx) ──────────────────────
// It used to carry features.fedistructure_only; it no longer does, so it is
// offered on every install like any other blog skin. The mechanism above stays
// in place for any FUTURE service skin — it simply has no skin using it today.
$m = json_decode((string)file_get_contents(__DIR__ . '/../skins/onyx/manifest.json'), true);
s_ok(is_array($m), 'onyx manifest does not parse');
s_ok(empty($m['features']['fedistructure_only']),
     'ONYX regained a fedistructure_only flag — it must ship as an ordinary skin');
s_ok(snapsmack_skin_allowed_distribution($m) === true,
     'ONYX is hidden on an ordinary install — it should be a normal blog skin now');

// NO shipped skin should carry the flag — a stray copy/paste here silently
// removes a skin from every ordinary gallery. No service skin ships today.
$flagged = [];
foreach (glob(__DIR__ . '/../skins/*/manifest.json') as $mf) {
    $d = json_decode((string)file_get_contents($mf), true);
    if (is_array($d) && !empty($d['features']['fedistructure_only'])) {
        $flagged[] = basename(dirname($mf));
    }
}
s_ok($flagged === [],
     'a shipped skin carries fedistructure_only (none should): ' . implode(', ', $flagged));

// ── Both enforcement points still call the filter ───────────────────────────
$skin_admin = (string)file_get_contents(__DIR__ . '/../smack-skin.php');
s_ok(str_contains($skin_admin, 'snapsmack_skins_for_distribution($registry[\'skins\'])'),
     'the download gallery no longer filters service skins out of the registry');
s_ok(str_contains($skin_admin, 'snapsmack_skins_for_distribution($local_skins)'),
     'the gallery fallback list no longer filters service skins');
s_ok(str_contains($skin_admin, 'snapsmack_skin_allowed_distribution($temp)'),
     'the local skin picker no longer filters service skins — a hand-copied '
     . 'folder could still be activated on an ordinary blog');

// ── The flag survives packaging ─────────────────────────────────────────────
// The packager copies `features` wholesale into the registry, which is the only
// reason no packager change was needed. If that ever becomes a named-field copy,
// the flag stops travelling and the skin reappears in every gallery.
$packager = (string)file_get_contents(__DIR__ . '/../smack-central/sc-skins.php');
s_ok((bool)preg_match("/'features'\s*=>\s*\\\$manifest\['features'\]/", $packager),
     'the Skin Packager no longer copies features into the registry wholesale — '
     . 'fedistructure_only would not reach the gallery filter');

if ($failures) {
    fwrite(STDERR, "FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "PASS: service-skin visibility regression suite ({$checks} checks)\n";
// ===== SNAPSMACK EOF =====
