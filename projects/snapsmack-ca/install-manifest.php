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
    'smacktalk' => 'new-horizon',
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

// Filter to skins whose modes[] includes the requested mode.
// mobile_only skins (e.g. photogram) are appended unconditionally to every
// install — they are required infrastructure, not user-selectable skins.
$result = [];
foreach ($reg_skins as $slug => $s) {
    $is_mobile_only = !empty($s['features']['mobile_only']);
    $skin_modes     = $s['modes'] ?? [];
    if (!$is_mobile_only && !in_array($mode, $skin_modes, true)) continue;
    $result[] = [
        'slug'         => $slug,
        'download_url' => $s['download_url'] ?? '',
        'signature'    => $s['signature']    ?? '',
        'version'      => $s['version']      ?? '',
        'default'      => ($slug === $default_skin),
    ];
}

echo json_encode([
    'mode'         => $mode,
    'default_skin' => $default_skin,
    'skins'        => $result,
], JSON_UNESCAPED_SLASHES);
// ===== SNAPSMACK EOF =====
