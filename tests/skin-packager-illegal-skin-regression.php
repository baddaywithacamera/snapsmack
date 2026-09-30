<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/core/skin-security-policy.php';
if (!defined('SNAPSMACK_ROOT')) define('SNAPSMACK_ROOT', dirname(__DIR__));
if (!defined('SKINS_DIR')) define('SKINS_DIR', dirname(__DIR__) . '/skins');
require_once dirname(__DIR__) . '/core/skin-registry.php';

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
        'sql' => '<?php defined("SNAPSMACK_SKIN_RENDER") || exit; $pdo->query("SELECT id FROM snap_posts");',
        'request' => '<?php defined("SNAPSMACK_SKIN_RENDER") || exit; echo $_GET["page"];',
        'helper-function' => '<?php defined("SNAPSMACK_SKIN_RENDER") || exit; function tilez_columns() {}',
        'variable-function' => '<?php defined("SNAPSMACK_SKIN_RENDER") || exit; $fn($view);',
        'globals' => '<?php defined("SNAPSMACK_SKIN_RENDER") || exit; echo $GLOBALS["settings"];',
        'dynamic-include' => '<?php defined("SNAPSMACK_SKIN_RENDER") || exit; include $view["template"];',
    ];
    foreach ($illegal as $name => $body) {
        file_put_contents($tmp . '/layout.php', $body);
        if (snapsmack_skin_security_gate($tmp, $root) === []) throw new RuntimeException("Illegal declared template {$name} passed the package gate.");
        if (snapsmack_skin_validate_staged_package($tmp) === null) throw new RuntimeException("Installer accepted illegal declared template {$name}.");
    }
    // TILEZ-style mixed helper: reusable function, request state, and SQL in
    // the one declared template must be rejected by both lifecycle gates.
    file_put_contents($tmp . '/layout.php', '<?php defined("SNAPSMACK_SKIN_RENDER") || exit; function tilez_columns(){ global $pdo; return $pdo->query("SELECT * FROM snap_posts WHERE id=".(int)$_GET["id"]); }');
    if (snapsmack_skin_security_gate($tmp, $root) === [] || snapsmack_skin_validate_staged_package($tmp) === null) throw new RuntimeException('TILEZ-style executable template passed a lifecycle gate.');
    echo "Illegal skin packaging regression passed.\n";
} finally {
    foreach (glob($tmp . '/*') ?: [] as $file) unlink($file);
    @rmdir($tmp);
}
