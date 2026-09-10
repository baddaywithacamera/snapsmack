<?php
/** GRAMOFSMACK menu must never offer, persist, or render Archive. */
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */
$root = dirname(__DIR__);
$menu = (string) file_get_contents($root . '/smack-menu.php');
$nav  = (string) file_get_contents($root . '/core/gram-nav-links.php');

$checks = [
    'menu identifies carousel mode' => str_contains($menu, '$menu_is_gramofsmack'),
    'saved menu is recursively scrubbed' => str_contains($menu, 'smack_menu_without_archive($decoded)'),
    'loaded menu is recursively scrubbed' => str_contains($menu, 'smack_menu_without_archive($current_menu)'),
    'default menu skips archive' => str_contains($menu, 'if (!$menu_is_gramofsmack && $archive_layout'),
    'available pool skips archive' => str_contains($menu, 'if ($menu_is_gramofsmack ||'),
    'public renderer suppresses archive' => str_contains($nav, "=== 'carousel'") && str_contains($nav, '$_gn_archive_off'),
];

$failed = false;
foreach ($checks as $name => $ok) {
    if ($ok) continue;
    fwrite(STDERR, "FAIL: {$name}\n");
    $failed = true;
}
if ($failed) exit(1);
echo "PASS: GRAMOFSMACK excludes Archive from menu editing, persistence and rendering.\n";
// ===== SNAPSMACK EOF =====
