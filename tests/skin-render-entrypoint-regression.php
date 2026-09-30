<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/trusted-html.php';
require_once dirname(__DIR__) . '/core/skin-view-contract.php';

function sre_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'snapsmack-strict-render-' . bin2hex(random_bytes(6));
sre_expect(mkdir($dir, 0700), 'Could not create the strict-render fixture directory.');
$template = $dir . DIRECTORY_SEPARATOR . 'layout.php';
file_put_contents($template, "<?php defined('SNAPSMACK_SKIN_RENDER') || exit; echo 'guarded-layout-rendered';");

try {
    $view = snapsmack_build_skin_view(['status' => 200, 'kind' => 'landing']);
    ob_start();
    $rendered = snapsmack_render_strict_skin_template($dir, 'layout.php', $view);
    $output = (string)ob_get_clean();
    sre_expect($rendered, 'The audited strict template entry point refused a valid bounded view.');
    sre_expect($output === 'guarded-layout-rendered', 'The CMS did not establish render authority before including the guarded layout.');
} finally {
    @unlink($template);
    @rmdir($dir);
}

echo "Strict skin render entrypoint regression passed.\n";
