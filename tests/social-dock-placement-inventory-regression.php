<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

// WHICH SKINS ACTUALLY DRAW A SOCIAL DOCK, WRITTEN DOWN.
//
// SS-1555. The Social Dock settings are SITE-WIDE: smack-push-it.php offers
// social_dock_enabled and nineteen platform fields to every owner, and nothing
// gates them on the active skin. MEASURED across the tree today: 2 of 30 skins
// render anything at all.
//
//   alfred   draws the settings-driven 'social-dock' component, twice
//            (header and footer)
//   scroll   draws 'crop-circles', three times -- the older shared component
//            that also carries social links
//
// The other 28 draw neither. An owner on any of them can switch the dock on,
// save their links, reload, and see nothing, with no error anywhere. Four
// skins even DECLARE social_dock in their manifest -- ALFRED, FULL MONTY,
// GLIDE and SCROLL -- and two of those four, FULL MONTY and GLIDE, still draw
// nothing.
//
// WHY THIS FILE DOES NOT SIMPLY FAIL ON THE 28. SS-1555 says in as many words
// that fleet placement follows "one skin at a time", and that is a deliberate
// decision about where a dock belongs in each design, not a bug to be swept.
// A gate that goes red for weeks over accepted scope is a gate people learn to
// ignore, and this repository has already paid for that lesson twice.
//
// SO IT HOLDS TWO DIFFERENT THINGS:
//
//   1. A FAILURE if a skin that draws a dock today stops drawing one. That is
//      a regression -- an owner's saved links disappearing from a page that
//      showed them -- and nothing else in tests/ would notice. The existing
//      dock tests all drive ALFRED's model or the precedence rules by hand;
//      not one of them looks at which skins place the component.
//
//   2. A COUNT, printed every run, of how many skins are still outstanding.
//      That turns "most skins don't have it yet" into a number somebody can
//      watch go down.
//
// Adding a dock to a skin is a SKIN change and not mine. When one lands,
// regenerate the fixture in the same commit:
//
//     php tests/social-dock-placement-inventory-regression.php --update
//
// Run:  php tests/social-dock-placement-inventory-regression.php

$root = dirname(__DIR__);
$fixturePath = $root . '/tests/fixtures/social-dock-placement.json';
$updating = in_array('--update', $argv, true);

/** Count the component references a layout actually makes. */
$placement = static function (string $root): array {
    $rows = [];
    foreach (glob($root . '/skins/*/layout.php') ?: [] as $layout) {
        $src = (string)file_get_contents($layout);
        $rows[basename(dirname($layout))] = [
            'social_dock' => substr_count($src, "'social-dock'"),
            'crop_circles' => substr_count($src, "'crop-circles'"),
        ];
    }
    ksort($rows, SORT_STRING);
    return $rows;
};

$all = $placement($root);
$drawing = array_filter($all, static fn(array $r): bool => $r['social_dock'] > 0 || $r['crop_circles'] > 0);

if ($updating) {
    $payload = [
        'schema_version' => 1,
        'purpose' => 'Which skins actually DRAW a social dock, and by which component. The '
            . 'Social Dock settings are site-wide and offered on every skin, but only the '
            . 'skins listed here render anything, so on the others an owner can save links '
            . 'and see nothing. SS-1555 places the dock one skin at a time; this records '
            . 'where that has got to, so a skin LOSING its dock is a failure and the '
            . 'outstanding count is a number rather than a feeling.',
        'components' => [
            'social-dock' => 'the settings-driven shared dock',
            'crop-circles' => 'the older shared navigation component that also draws social links',
        ],
        'drawn_by' => $drawing,
        'total_skins' => count($all),
    ];
    file_put_contents($fixturePath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    printf("Updated: %d of %d skins draw a dock.\n", count($drawing), count($all));
    echo "Read the diff. A skin DISAPPEARING from this list is an owner's links\n";
    echo "vanishing from a page that used to show them.\n";
    exit(0);
}

$failures = [];
$check = static function (string $what, bool $ok) use (&$failures): void {
    printf("  [%s] %s\n", $ok ? 'ok' : 'FAIL', $what);
    if (!$ok) { $failures[] = $what; }
};

echo "Social dock placement inventory\n\n";

$recorded = json_decode((string)file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
$was = is_array($recorded['drawn_by'] ?? null) ? $recorded['drawn_by'] : [];

// ─────────────────────────────────────────────────────────────────────────────
echo "NO SKIN LOSES A DOCK IT ALREADY HAD\n";
// ─────────────────────────────────────────────────────────────────────────────
// The one thing here that is a hard failure, because it is the only one that
// takes something away from somebody who already has it.

foreach ($was as $skin => $before) {
    $now = $all[$skin] ?? null;
    if ($now === null) {
        $check("{$skin}: still exists in skins/", false);
        continue;
    }
    $drew = (int)($before['social_dock'] ?? 0) + (int)($before['crop_circles'] ?? 0);
    $draws = $now['social_dock'] + $now['crop_circles'];
    $check("{$skin}: still draws a dock ({$drew} reference(s) recorded, {$draws} now)",
           $draws > 0);
}
$check('the recorded list is not empty, which would make the loop above vacuous',
       $was !== []);

// ─────────────────────────────────────────────────────────────────────────────
echo "\nAND A NEW PLACEMENT IS RECORDED RATHER THAN SLIPPING IN\n";
// ─────────────────────────────────────────────────────────────────────────────
// Not a failure of the skin -- adding a dock is the work SS-1555 asks for --
// but the fixture has to move with it, or the regression check above silently
// stops covering the newest skin to get one.

$unrecorded = array_diff(array_keys($drawing), array_keys($was));
foreach ($unrecorded as $skin) {
    $check("{$skin}: draws a dock but is not in the inventory -- run --update in that commit",
           false);
}
if ($unrecorded === []) { $check('every skin that draws a dock is in the inventory', true); }

// ─────────────────────────────────────────────────────────────────────────────
echo "\nWHERE THE FLEET HAS GOT TO\n";
// ─────────────────────────────────────────────────────────────────────────────
// Reported, never failed. This is accepted scope, one skin at a time.

$outstanding = array_diff(array_keys($all), array_keys($drawing));
printf("        drawing a dock : %d of %d\n", count($drawing), count($all));
foreach ($drawing as $skin => $r) {
    $by = [];
    if ($r['social_dock'] > 0) { $by[] = "social-dock x{$r['social_dock']}"; }
    if ($r['crop_circles'] > 0) { $by[] = "crop-circles x{$r['crop_circles']}"; }
    printf("            %-22s %s\n", $skin, implode(', ', $by));
}
printf("        still outstanding: %d\n", count($outstanding));

// The four that ASK for a dock in their manifest are worth naming separately:
// a manifest declaration is a statement that this skin has a place for one.
$declared = [];
foreach (glob($root . '/skins/*/manifest.json') ?: [] as $m) {
    $manifest = json_decode((string)file_get_contents($m), true);
    if (is_array($manifest['social_dock'] ?? null)) { $declared[] = basename(dirname($m)); }
}
$declaredButBlank = array_values(array_diff($declared, array_keys($drawing)));
printf("        declare social_dock in the manifest but draw nothing: %s\n",
       $declaredButBlank === [] ? '(none)' : implode(', ', $declaredButBlank));

// ─────────────────────────────────────────────────────────────────────────────
echo "\n";
if ($failures) {
    echo 'FAIL (' . count($failures) . "):\n";
    foreach ($failures as $f) { echo "  - $f\n"; }
    exit(1);
}
echo "PASS — no skin lost a dock, and every skin that draws one is recorded.\n";
echo "NOT A PASS FOR THE FLEET: the outstanding count above is real work that\n";
echo "SS-1555 places one skin at a time. This file does not fail on it, so a\n";
echo "green run here must never be read as the dock being finished.\n";
exit(0);

// ===== SNAPSMACK EOF =====
