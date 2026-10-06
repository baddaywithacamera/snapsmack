<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 *
 * Regression: crawler files and the sitemap route must be reconciled by every
 * CMS lifecycle path, not only after an operator manually saves settings.
 */

$root = dirname(__DIR__);
$failures = [];
$checks = 0;

function crawler_lifecycle_check(bool $ok, string $message): void {
    global $failures, $checks;
    $checks++;
    if (!$ok) $failures[] = $message;
}

$template = (string)file_get_contents($root . '/core/htaccess-template');
$install = (string)file_get_contents($root . '/install.php');
$updater = (string)file_get_contents($root . '/core/updater.php');
$local = (string)file_get_contents($root . '/smack-update.php');
$fleet = (string)file_get_contents($root . '/core/multisite-api.php');
$sitemap = (string)file_get_contents($root . '/sitemap.php');

$route = 'RewriteRule ^sitemap\\.xml$ sitemap.php [L,QSA]';
$route_pos = strpos($template, $route);
$catchall_pos = strpos($template, 'RewriteRule ^([a-zA-Z0-9_-]+)$ index.php?name=$1 [L,QSA]');
crawler_lifecycle_check($route_pos !== false, 'canonical .htaccess template is missing /sitemap.xml');
crawler_lifecycle_check($catchall_pos !== false && $route_pos < $catchall_pos, '/sitemap.xml route must precede the public catchall');
crawler_lifecycle_check(str_contains($install, "'/core/htaccess-template'"), 'fresh install does not consume the canonical .htaccess template');
crawler_lifecycle_check(str_contains($install, 'snapsmack_reconcile_site_files($pdo)'), 'fresh install does not generate crawler files');
crawler_lifecycle_check(str_contains($updater, 'function updater_reconcile_public_site(PDO $pdo)'), 'shared update reconciliation helper is missing');
crawler_lifecycle_check(str_contains($local, 'updater_reconcile_public_site($pdo)'), 'local update path does not reconcile public site files');
crawler_lifecycle_check(substr_count($local, 'updater_reconcile_public_site($pdo)') >= 2, 'uploaded-package path does not reconcile public site files');
crawler_lifecycle_check(str_contains($fleet, 'updater_reconcile_public_site($pdo)'), 'fleet update path does not reconcile public site files');
crawler_lifecycle_check(str_contains($sitemap, "'sitemap.xml?p='"), 'sitemap index advertises the implementation filename instead of the public route');

require_once $root . '/core/site-files.php';
$llms = snapsmack_generate_llms([
    'site_name' => 'Lifecycle Test',
    'site_url' => 'https://example.test',
    'installed_version' => '0.7.820D',
]);
crawler_lifecycle_check(str_contains($llms, 'SnapSmack 0.7.820D'), 'llms.txt does not prefer the freshly stamped installed version');

if ($failures) {
    fwrite(STDERR, "FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "PASS: crawler-file lifecycle regression suite ({$checks} checks)\n";
// ===== SNAPSMACK EOF =====
