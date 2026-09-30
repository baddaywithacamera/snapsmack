<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/core/skin-security-policy.php';

$root = dirname(__DIR__);
$publisher = (string)file_get_contents($root . '/smack-central/sc-skins.php');
$gateAt = strpos($publisher, 'snapsmack_skin_security_gate($skin_dir, $sc_authority_root)');
$zipAt = strpos($publisher, 'new ZipArchive()', $gateAt === false ? 0 : $gateAt);
$publishAt = strpos($publisher, '$existing_registry[' . "'skins'" . '][$slug]', $gateAt === false ? 0 : $gateAt);
if ($gateAt === false || $zipAt === false || $publishAt === false || $gateAt > $zipAt || $gateAt > $publishAt) {
    throw new RuntimeException('Skin Packager can create or publish an archive before the authority gate.');
}
foreach (['$sc_authority_root = dirname(__DIR__)', '$sc_policy = $sc_authority_root'] as $needle) {
    if (!str_contains($publisher, $needle)) throw new RuntimeException('Skin Packager lets the candidate supply its own policy.');
}

$tmp = sys_get_temp_dir() . '/snapsmack-illegal-skin-' . bin2hex(random_bytes(5));
mkdir($tmp, 0700, true);
try {
    file_put_contents($tmp . '/manifest.json', json_encode([
        'schema_version'=>2, 'security_policy'=>2, 'cms_controller'=>'public',
        'view_model'=>'snapsmack.public.v1', 'templates'=>['default'=>'layout.php'],
    ]));
    file_put_contents($tmp . '/layout.php', '<?php defined("SNAPSMACK_SKIN_RENDER") || exit; ?>');
    $illegal = [
        'functions.php' => '<?php function tilez_query() {}',
        'database.php' => '<?php $pdo->query("SELECT id FROM snap_posts");',
        'request.php' => '<?php echo $_GET["page"];',
        'payload.php5' => '<?php echo "executable";',
        '.htaccess' => 'AddHandler application/x-httpd-php .jpg',
        'skin.js' => 'fetch("/admin")',
    ];
    foreach ($illegal as $name => $body) {
        file_put_contents($tmp . '/' . $name, $body);
        if (snapsmack_skin_security_gate($tmp, $root) === []) throw new RuntimeException("Illegal skin file {$name} passed the package gate.");
        unlink($tmp . '/' . $name);
    }
    echo "Illegal skin packaging regression passed.\n";
} finally {
    foreach (glob($tmp . '/*') ?: [] as $file) unlink($file);
    @rmdir($tmp);
}
