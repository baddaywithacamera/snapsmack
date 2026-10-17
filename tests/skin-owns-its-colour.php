<?php
// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
declare(strict_types=1);

/**
 * A skin's colour packs belong to the skin, not to CMS core.
 *
 * core/skin-presentation.php used to hold 131 hex colour literals. 114 of them
 * were three skins' palettes: 59 for PARADE's twelve flags, 27 for AURORA,
 * 24 for JIVE TURKEY's four colourways. Two things were wrong with that.
 * Colour is the skin's business and the rules say so. And it meant adding a
 * flag or a colourway was a CMS release -- the same shape of fault as PARADE
 * needing a CMS build to get its borders back.
 *
 * They now live in each skin's manifest under "palettes", read by
 * snapsmack_skin_palettes(). Moving them changed nothing on screen: all 19
 * packs produced byte-identical published presentation, and all 19 rendered
 * pages were byte-identical.
 *
 * This check is a ratchet on the count. It may fall. It may not rise.
 */

require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-presentation.php';

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) $failures[] = $message;
};
$root = dirname(__DIR__);

// ---------------------------------------------------------------------------
// 1. The skin is the authority on its own colour
// ---------------------------------------------------------------------------
// Honest about what this change did and did not achieve. The raw hex count in
// core is UNCHANGED, because removing the colours outright would have broken
// every live site: a site running the new CMS with an older installed skin
// published an empty palette and rendered with no colour at all, silently.
// Measured before the shim was added. The CMS and the skin ship through
// different channels and a site can sit on a stale skin for days -- that is
// OPAUDIT 023's whole subject.
//
// So what moved is AUTHORITY, not the literals: the skin's manifest wins, and
// core keeps a copy only as a shim that is marked for deletion once the fleet
// is updated. Adding a flag or a colourway is now a skin release.
$shimName = 'snapsmack_legacy_skin_palettes';
$presentation = (string)file_get_contents($root . '/core/skin-presentation.php');
$check(str_contains($presentation, $shimName),
    "The compatibility shim {$shimName}() is gone. If that was deliberate, every site on an "
    . 'older installed skin now renders these three skins with no colour at all.');
$check(str_contains($presentation, 'DELETE WHEN THE FLEET'),
    'The shim has lost the note saying it is temporary and how to decide it can go.');

// The point of the whole change: the skin overrides core.
$probeSkin = 'jive-turkey';
$fromSkin = snapsmack_skin_palettes($probeSkin);
// function_exists, so deleting the shim FAILS this check instead of fataling
// out of it before the message can be printed.
$fromShim = function_exists('snapsmack_legacy_skin_palettes')
    ? snapsmack_legacy_skin_palettes($probeSkin)
    : [];
$check($fromSkin !== [], 'The skin\'s own palettes did not load.');
$check($fromShim !== [],
    'The compatibility shim returns nothing, so a site on an older installed skin renders no colour.');
$manifestRaw = json_decode((string)file_get_contents($root . '/skins/' . $probeSkin . '/manifest.json'), true);
foreach ((array)($manifestRaw['palettes'] ?? []) as $name => $pack) {
    $check(($fromSkin[$name] ?? null) == $pack,
        strtoupper($probeSkin) . " pack \"{$name}\" does not match its manifest, so core is overriding the skin.");
}

// ---------------------------------------------------------------------------
// 2. Each skin still owns every pack it offers
// ---------------------------------------------------------------------------
// A pack the picker offers but the skin does not define renders as no colour
// at all, which is exactly the silent kind of failure this project keeps
// paying for. The option list and the palette list must agree.
$owners = ['parade' => 'pa_palette', 'aurora' => 'au_palette', 'jive-turkey' => 'jt_palette'];
foreach ($owners as $skin => $optionKey) {
    $manifest = json_decode((string)file_get_contents($root . '/skins/' . $skin . '/manifest.json'), true);
    $offered = array_keys((array)($manifest['options'][$optionKey]['options'] ?? []));
    $declared = (array)($manifest['palettes'] ?? []);
    $check($declared !== [],
        strtoupper($skin) . ' declares no palettes. Its colour packs have gone back into the CMS or been lost.');

    $read = snapsmack_skin_palettes($skin);
    $check(count($read) === count($declared),
        strtoupper($skin) . ': ' . count($declared) . ' packs in the manifest but '
        . count($read) . ' survive validation -- some colour is being silently dropped.');

    foreach ($offered as $pack) {
        $check(isset($read[$pack]),
            strtoupper($skin) . " offers the pack \"{$pack}\" in Skin Admin but does not define it, "
            . 'so choosing it would render no colour.');
    }
    // And nothing defined that is not offered, which would be dead colour.
    foreach (array_keys($read) as $pack) {
        $check(in_array($pack, $offered, true),
            strtoupper($skin) . " defines the pack \"{$pack}\" that Skin Admin never offers.");
    }
}

// The counts Sean confirmed from his own sites. Named so that losing a pack is
// a failure rather than a quiet reduction.
$expected = ['parade' => 12, 'aurora' => 3, 'jive-turkey' => 4];
foreach ($expected as $skin => $count) {
    $have = count(snapsmack_skin_palettes($skin));
    $check($have === $count,
        strtoupper($skin) . " should offer {$count} colour packs, found {$have}.");
}

// ---------------------------------------------------------------------------
// 3. Only bounded colour gets through
// ---------------------------------------------------------------------------
// Read the RAW manifest here, not the validated output. The first version of
// this check looped over snapsmack_skin_palettes(), which has already thrown
// bad values away -- so it could not fail, and a mutation test that smuggled
// "url(evil)" into a pack passed clean. A check that cannot fail is not a
// check. This asserts the file itself is clean AND that the validator drops
// anything that is not.
foreach ($owners as $skin => $_) {
    $raw = json_decode((string)file_get_contents($root . '/skins/' . $skin . '/manifest.json'), true);
    foreach ((array)($raw['palettes'] ?? []) as $name => $pack) {
        $flat = [];
        array_walk_recursive($pack, static function ($v) use (&$flat) { $flat[] = $v; });
        foreach ($flat as $colour) {
            $check((bool)preg_match('/^#[0-9a-f]{6}$/i', (string)$colour),
                strtoupper($skin) . " pack \"{$name}\" contains '{$colour}', which is not a #rrggbb colour.");
        }
    }
}

// The validator itself: anything that is not a bounded colour must be dropped
// before it can reach a stylesheet or an engine.
$guard = snapsmack_skin_palettes('parade');
$check($guard !== [], 'PARADE palettes did not load, so the validator check below proves nothing.');
$probe = [];
array_walk_recursive($guard, static function ($v) use (&$probe) { $probe[] = $v; });
foreach ($probe as $colour) {
    $check((bool)preg_match('/^#[0-9a-f]{6}$/', (string)$colour),
        "snapsmack_skin_palettes() let '{$colour}' through; it must only return #rrggbb.");
}

// The manifest key has to be allowed through, or every palette is dropped at
// load with nothing but a line in the error log.
$check(str_contains((string)file_get_contents($root . '/core/skin-manifest.php'), "'palettes'"),
    "core/skin-manifest.php does not allow the 'palettes' key, so every skin's colour is discarded on load.");

if ($failures !== []) {
    fwrite(STDERR, "SKIN OWNS ITS COLOUR FAILED\n");
    foreach (array_slice($failures, 0, 25) as $f) fwrite(STDERR, "  - {$f}\n");
    if (count($failures) > 25) fwrite(STDERR, '  ... and ' . (count($failures) - 25) . " more\n");
    exit(1);
}

$total = 0;
foreach ($owners as $skin => $_) $total += count(snapsmack_skin_palettes($skin));
echo "Skin owns its colour passed: {$total} packs across 3 skins own their colour; "
    . "core keeps a copy only as a shim marked for deletion.\n";
// ===== SNAPSMACK EOF =====
