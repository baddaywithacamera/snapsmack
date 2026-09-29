<?php
$root = dirname(__DIR__);
$failures = [];

function stanley_slot_ok(bool $condition, string $message): void {
    global $failures;
    if (!$condition) $failures[] = $message;
}

$header = (string)file_get_contents($root . '/skins/stanley/skin-header.php');
$core   = (string)file_get_contents($root . '/core/skin-view-model.php');
$skinUi = (string)file_get_contents($root . '/smack-skin.php');
$manifest = json_decode((string)file_get_contents($root . '/skins/stanley/manifest.json'), true);

stanley_slot_ok(strpos($header, 'snap_assets') === false, 'STANLEY must not query the Asset Repository');
stanley_slot_ok(strpos($header, 'stanley__stanley_2024_hero') === false, 'STANLEY must not persist its hero setting');
stanley_slot_ok(!preg_match('/\b(?:INSERT|UPDATE|DELETE)\b/i', $header), 'STANLEY rendering must contain no database mutation');
stanley_slot_ok(strpos($header, "['media_slots']['hero_2024']") !== false, 'STANLEY must consume the CMS media-slot view model');
stanley_slot_ok(($manifest['cms_media_slots']['hero_2024']['fallback'] ?? '') === 'latest_asset_image', 'STANLEY must declare the reusable CMS fallback');
stanley_slot_ok(strpos($core, 'function snapsmack_prepare_skin_view') !== false, 'CMS must own skin view-model preparation');
stanley_slot_ok(strpos($core, 'function snapsmack_initialize_skin_media_slots') !== false, 'CMS must own explicit media-slot initialization');
stanley_slot_ok(strpos($core, 'snapsmack_resolve_skin_media_slot($pdo, $settings, $slot, false)') !== false, 'Public view preparation must not run fallback database queries');
stanley_slot_ok(strpos($skinUi, 'snapsmack_initialize_skin_media_slots($pdo, $settings, $slug)') !== false, 'Initialization must occur in the CMS activation path');

foreach (['index.php', 'archive.php', 'page.php', 'albums.php', 'collections.php', 'collection.php', 'blogroll.php', 'gallery-wall.php', 'photochallenge-board.php', 'privacy-policy.php'] as $entry) {
    $source = (string)file_get_contents($root . '/' . $entry);
    stanley_slot_ok(strpos($source, 'snapsmack_prepare_skin_view($pdo, $settings, $active_skin)') !== false, "$entry must prepare the skin view model");
}

if ($failures) {
    fwrite(STDERR, "STANLEY CMS media-slot regression failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "STANLEY CMS media-slot regression passed.\n";
// ===== SNAPSMACK EOF =====
