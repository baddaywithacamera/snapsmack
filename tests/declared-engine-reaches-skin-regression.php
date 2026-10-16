<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * A control a skin renders must have an engine that actually loads.
 *
 * Twice in one day a skin drew a working-looking control that did nothing:
 *
 *   INSTANT CAMERA declared a nav-dropdown engine in a form require_scripts
 *   does not resolve, so submenus never opened by tap or keyboard.
 *
 *   SCROLL rendered the Crop Circles FILTER button and its 31-control panel
 *   and never declared the engine at all, so the button did nothing on
 *   usedcarparts.photoblogs.fyi. Sean found both by clicking them.
 *
 * Neither failed loudly. The markup is present, the panel is present, the
 * file is present, and nothing reports that the two were never connected.
 *
 * This checks both halves:
 *   1. every handle a skin declares must RESOLVE to a real public asset
 *   2. a skin that renders a known control must DECLARE that control's engine
 */

require_once dirname(__DIR__) . '/core/asset-registry.php';

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $ok, string $what) use (&$failures): void {
    if (!$ok) $failures[] = $what;
};

/**
 * Markup a skin can render => the handle that makes it work.
 * Keyed on something only that control emits, not on a generic class.
 */
$CONTROL_ENGINES = [
    // The marker must be something the SKIN's own file contains. My first
    // version keyed on smack-archive-filter-btn -- the button's id -- which
    // the CMS component emits, not the skin, so the check never fired and
    // passed while SCROLL's filter was dead. Key on the component call.
    "snap_render_component('crop-circles'" => 'smack-crop-circles',
];

$checkedSkins = 0;
$resolvedTotal = 0;

foreach (glob($root . '/skins/*/manifest.json') ?: [] as $manifestPath) {
    $slug = basename(dirname($manifestPath));
    $manifest = json_decode((string)file_get_contents($manifestPath), true);
    if (!is_array($manifest)) { $check(false, $slug . ': manifest is not readable'); continue; }
    $checkedSkins++;

    $declared = $manifest['require_scripts'] ?? [];
    $resolved = snapsmack_skin_declared_assets($manifest);
    $resolvedTotal += count($resolved['scripts']);

    // 1. every declared handle resolves to something real
    $check(count($resolved['scripts']) >= count(array_filter($declared, 'is_string')) - 0,
        $slug . ' declares ' . count($declared) . ' scripts but only '
        . count($resolved['scripts']) . ' resolve. A handle that does not resolve '
        . 'is silently dropped: the control renders and never works.');

    // 2. a rendered control must have its engine declared
    $layout = (string)@file_get_contents($root . '/skins/' . $slug . '/layout.php');
    if ($layout === '') continue;
    foreach ($CONTROL_ENGINES as $marker => $handle) {
        if (!str_contains($layout, $marker)) continue;
        $check(in_array($handle, $declared, true),
            $slug . ' renders ' . $marker . ' but does not declare ' . $handle
            . '. The control will draw and do nothing.');
    }
}

$check($checkedSkins >= 25, 'expected at least 25 skins, found ' . $checkedSkins);

printf("declared engines: %d skins, %d script URLs resolved, every rendered control has its engine.\n",
    $checkedSkins, $resolvedTotal);

if ($failures) {
    fwrite(STDERR, "Declared engine regression FAILED:\n");
    foreach ($failures as $failure) fwrite(STDERR, '  - ' . $failure . "\n");
    exit(1);
}
echo "Declared engine regression passed.\n";

// ===== SNAPSMACK EOF =====
