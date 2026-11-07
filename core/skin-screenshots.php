<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
//     // ===== SNAPSMACK EOF =====
// Last non-empty line of this file MUST be the SNAPSMACK EOF comment.

/**
 * One source of truth for a skin's gallery screenshots and the name transition.
 *
 * The scheme going forward is positional -- screenshot-primary/secondary/
 * tertiary.png. The legacy scheme named shots by page type -- screenshot-
 * landing/archive/page.png (and a single bare screenshot.png before that). A
 * core that predates the rename reads only the legacy names, so during the
 * transition the packager publishes BOTH names for a migrated skin and the
 * reader prefers the new names but falls back to the legacy ones. Keeping this
 * in one file means the reader (smack-skin.php) and the packager
 * (smack-central/sc-skins.php) cannot drift apart.
 */

function snapsmack_skin_screenshot_new_names(): array
{
    return [
        'screenshot-primary.png'   => 'Primary',
        'screenshot-secondary.png' => 'Secondary',
        'screenshot-tertiary.png'  => 'Tertiary',
    ];
}

function snapsmack_skin_screenshot_legacy_names(): array
{
    return [
        'screenshot-landing.png' => 'Landing',
        'screenshot-archive.png' => 'Archive',
        'screenshot-page.png'    => 'Text Page',
    ];
}

/** New filename => the legacy filename it corresponds to, positionally. */
function snapsmack_skin_screenshot_legacy_alias(): array
{
    return [
        'screenshot-primary.png'   => 'screenshot-landing.png',
        'screenshot-secondary.png' => 'screenshot-archive.png',
        'screenshot-tertiary.png'  => 'screenshot-page.png',
    ];
}

/**
 * The screenshots to show for an installed skin directory, in order.
 * Prefers the new scheme AS A WHOLE (so a transition package that carries both
 * name sets is not rendered twice), falls back to the legacy set, then to a
 * single bare screenshot.png. Returns [['name' => filename, 'label' => ...], ...].
 */
function snapsmack_skin_screenshot_shots(string $abs_dir): array
{
    $new    = snapsmack_skin_screenshot_new_names();
    $legacy = snapsmack_skin_screenshot_legacy_names();

    $has_new = false;
    foreach ($new as $file => $label) {
        if (is_file($abs_dir . '/' . $file)) { $has_new = true; break; }
    }
    $set = $has_new ? $new : $legacy;

    $shots = [];
    foreach ($set as $file => $label) {
        if (is_file($abs_dir . '/' . $file)) {
            $shots[] = ['name' => $file, 'label' => $label];
        }
    }
    if ($shots === [] && is_file($abs_dir . '/screenshot.png')) {
        $shots[] = ['name' => 'screenshot.png', 'label' => 'Preview'];
    }
    return $shots;
}

/**
 * What the packager should publish for a skin directory during the transition.
 * For a migrated skin (new names present) each shot is published under BOTH its
 * new name and its legacy alias, so a core that still reads legacy names keeps
 * working. For a skin not yet migrated the legacy names (or bare screenshot.png)
 * are published as-is. Returns
 *   [['src' => abs_source_path, 'publish' => [filenames...], 'label' => ...], ...]
 * in display order; 'publish'[0] is always the canonical (new, if migrated) name.
 */
function snapsmack_skin_screenshot_package_plan(string $abs_dir): array
{
    $new    = snapsmack_skin_screenshot_new_names();
    $alias  = snapsmack_skin_screenshot_legacy_alias();
    $legacy = snapsmack_skin_screenshot_legacy_names();

    $has_new = false;
    foreach ($new as $file => $label) {
        if (is_file($abs_dir . '/' . $file)) { $has_new = true; break; }
    }

    $plan = [];
    if ($has_new) {
        foreach ($new as $file => $label) {
            if (!is_file($abs_dir . '/' . $file)) continue;
            $publish = [$file];
            if (!empty($alias[$file])) $publish[] = $alias[$file];
            $plan[] = ['src' => $abs_dir . '/' . $file, 'publish' => $publish, 'label' => $label];
        }
        return $plan;
    }

    foreach ($legacy as $file => $label) {
        if (is_file($abs_dir . '/' . $file)) {
            $plan[] = ['src' => $abs_dir . '/' . $file, 'publish' => [$file], 'label' => $label];
        }
    }
    if ($plan === [] && is_file($abs_dir . '/screenshot.png')) {
        $plan[] = ['src' => $abs_dir . '/screenshot.png', 'publish' => ['screenshot.png'], 'label' => 'Preview'];
    }
    return $plan;
}
// ===== SNAPSMACK EOF =====
