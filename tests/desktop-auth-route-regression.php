<?php
/** Regression: installed HQ 0.7.40 and newer clients must reach the same auth handler. */
$root = dirname(__DIR__);
$live = file_get_contents($root . '/.htaccess');
$installer = file_get_contents($root . '/install.php');
$template = file_get_contents($root . '/core/htaccess-template');
$route = 'api.php?route=desktop-auth/$1';

if ($live === false || strpos($live, $route) === false) {
    fwrite(STDERR, "Root .htaccess is missing the legacy desktop-auth route\n");
    exit(1);
}
if ($installer === false || strpos($installer, $route) === false) {
    fwrite(STDERR, "Installer-generated .htaccess is missing the legacy desktop-auth route\n");
    exit(1);
}
if ($template === false || strpos($template, $route) === false) {
    fwrite(STDERR, "Updater .htaccess template is missing the legacy desktop-auth route\n");
    exit(1);
}
foreach (['activate', 'enroll', 'status', 'renew'] as $action) {
    if (strpos($live, 'desktop-auth/(activate|enroll|status|renew)') === false) {
        fwrite(STDERR, "Desktop-auth compatibility route does not cover {$action}\n");
        exit(1);
    }
}
echo "PASS: desktop authorization legacy/new route compatibility\n";
// ===== SNAPSMACK EOF =====
