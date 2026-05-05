<?php
/**
 * SNAPSMACK - Migration 049
 *
 * Seeds nav_menu_json and nav dropdown appearance settings in snap_settings.
 * nav_menu_json of [] means no custom nav configured; header.php falls back
 * to the legacy flat nav links.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

$migration_name = '049_nav_menu_json';
if (!isset($pdo)) exit;

$pdo->prepare("INSERT IGNORE INTO snap_settings (setting_key, setting_val) VALUES ('nav_menu_json', '[]')") ->execute();
$pdo->prepare("INSERT IGNORE INTO snap_settings (setting_key, setting_val) VALUES ('nav_dropdown_bg', '#000000')") ->execute();
$pdo->prepare("INSERT IGNORE INTO snap_settings (setting_key, setting_val) VALUES ('nav_dropdown_opacity', '88')")  ->execute();
$pdo->prepare("INSERT IGNORE INTO snap_settings (setting_key, setting_val) VALUES ('nav_dropdown_text', '#ffffff')") ->execute();
// ===== SNAPSMACK EOF =====
