<?php
/**
 * SNAPSMACK.CA — Skin Install Manifest
 *
 * Called by the SnapSmack installer during Step 5 to get the list of skins
 * appropriate for the chosen install mode. Reads modes from registry.json —
 * no hardcoded mapping. Repackaging a skin via the Skin Packager is all that's
 * needed to update what the installer fetches.
 *
 * Request (GET):
 *   mode — photoblog | carousel | smacktalk
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

header('Content-Type: application/json');
header('Cache-Control: public, max-age=300');
header('Access-Control-Allow-Origin: *');

$mode = trim($_GET['mode'] ?? 'photoblog');
if (!in_array($mode, ['photoblog', 'carousel', 'smacktalk'], true)) {
    $mode = 'photoblog';
}

// Default skin per mode — the skin set as active_skin after install.
// Updated here when a mode gets a new flagship skin.
$mode_defaults = [
    'photoblog' => 'new-horizon',
    'carousel'  => 'the-grid',
    'smacktalk' => 'alfred',
];
$default_skin = $mode_defaults[$mode] ?? 'new-horizon';

// Read registry.json — source of truth for download URLs, signatures, versions.
$registry_path = __DIR__ . '/releases/skins/registry.json';
if (!is_file($registry_path)) {
    http_response_code(503);
    echo json_encode(['error' => 'Registry unavailable']);
    exit;
}

$registry  = json_decode(file_get_contents($registry_path), true);
$reg_skins = $registry['skins'] ?? [];

// Install ONLY the mode's default skin (the one that becomes active_skin), plus
// any required mobile-only infrastructure skin (e.g. photogram, the mobile
// renderer). EVERY other registered skin is OPTIONAL and installed later from the
// in-CMS skin gallery — never shipped to a fresh install. Installing unused skins
// wastes shared-host space without the owner's consent and is needless attack
// surface. "Only ever install the skin you will use." (Sean, 2026-06-18.)
// This is what stops a non-default skin (e.g. AURORA) auto-landing on every
// GRAMOFSMACK/carousel install just because it lists the mode.
$result = [];
foreach ($reg_skins as $slug => $s) {
    $is_mobile_only = !empty($s['features']['mobile_only']);
    $is_default     = ($slug === $default_skin);
    if (!$is_default && !$is_mobile_only) continue;
    $result[] = [
        'slug'         => $slug,
        'download_url' => $s['download_url'] ?? '',
        'signature'    => $s['signature']    ?? '',
        'version'      => $s['version']      ?? '',
        'default'      => $is_default,
    ];
}

echo json_encode([
    'mode'         => $mode,
    'default_skin' => $default_skin,
    'skins'        => $result,
], JSON_UNESCAPED_SLASHES);
// ===== SNAPSMACK EOF =====
