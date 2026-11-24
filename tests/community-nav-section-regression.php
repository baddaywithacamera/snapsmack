<?php
declare(strict_types=1);
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$root = dirname(__DIR__);
if (!function_exists('smack_is_admin')) {
    function smack_is_admin(): bool { return true; }
}
if (!function_exists('snapsmack_load_manifest')) {
    function snapsmack_load_manifest(string $path): array { return []; }
}

function cns_render(string $page, string $mode): DOMXPath
{
    global $root;
    $_SERVER['PHP_SELF'] = '/' . $page;
    $_SESSION['user_ui_mode'] = $mode;
    $settings = [
        'active_skin' => '',
        'distribution_profile' => '',
        'photochallenge_enabled' => '0',
        'site_mode' => 'photoblog',
        'enable_longform' => '0',
    ];
    ob_start();
    include $root . '/core/sidebar.php';
    $html = (string)ob_get_clean();
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<!doctype html><html><body>' . $html . '</body></html>');
    libxml_clear_errors();
    return new DOMXPath($dom);
}

$failures = [];
function cns_check(bool $ok, string $message): void
{
    global $failures;
    echo ($ok ? 'PASS ' : 'FAIL ') . $message . PHP_EOL;
    if (!$ok) $failures[] = $message;
}

foreach (['smack-community-settings.php', 'smack-community-users.php'] as $page) {
    $xp = cns_render($page, 'pimpmobile');
    cns_check($xp->query("//div[@data-section='boring' and contains(concat(' ',normalize-space(@class),' '),' open ')]")->length === 1,
        "{$page} opens BORING ASS STUFF");
    cns_check($xp->query("//div[@data-section='good-shit']//a[@href='smack-community-settings.php']")->length === 0,
        "{$page} has no Community entry under THE GOOD SHIT");
    cns_check($xp->query("//div[@data-section='boring']//li[contains(concat(' ',normalize-space(@class),' '),' active ')]/a[@href='smack-community-settings.php']")->length === 1,
        "{$page} activates the Community entry under BORING ASS STUFF");
}

$bigWheel = cns_render('smack-community-settings.php', 'bigwheel');
cns_check($bigWheel->query("//a[@href='smack-community-settings.php']")->length === 0,
    'Big Wheel visibility is unchanged; the Community entry remains a Pimpmobile control');

if ($failures !== []) {
    fwrite(STDERR, count($failures) . " Community navigation checks failed.\n");
    exit(1);
}
echo "Community navigation section: PASS\n";
// ===== SNAPSMACK EOF =====
