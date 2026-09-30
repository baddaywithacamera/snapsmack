<?php
require_once dirname(__DIR__) . '/core/skin-security-policy.php';

$tmp = sys_get_temp_dir() . '/snapsmack-skin-policy-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700, true);
try {
    file_put_contents($tmp . '/manifest.json', json_encode([
        'schema_version' => 2,
        'cms_controller' => 'public',
        'view_model' => 'public.v1',
        'templates' => ['public' => 'template.php'],
        'security_policy' => 2,
    ]));
    file_put_contents($tmp . '/template.php', <<<'PHP'
<?php defined('SNAPSMACK_SKIN_RENDER') || exit; ?>
<h1><?= snap_escape_html($view['title']) ?></h1>
<?php foreach ($view['items'] as $item): ?>
<span><?= snap_escape_html($item['label']) ?></span>
<?php endforeach; ?>
PHP);
    if (snapsmack_skin_security_findings($tmp) !== []) {
        throw new RuntimeException('A presentation-only template was rejected.');
    }
    // TILEZ-style functionality must be rejected even if it is parked in an
    // otherwise valid schema-v2 package or disguised with another extension.
    file_put_contents($tmp . '/helper.php', '<?php defined("SNAPSMACK_SKIN_RENDER") || exit; ?>');
    $types = array_column(snapsmack_skin_security_gate($tmp), 'type');
    if (!in_array('undeclared-php-template', $types, true)) throw new RuntimeException('Undeclared skin PHP was packageable.');
    unlink($tmp . '/helper.php');
    file_put_contents($tmp . '/helper.php5', '<?php echo "code";');
    $types = array_column(snapsmack_skin_security_gate($tmp), 'type');
    if (!in_array('executable-file-type', $types, true)) throw new RuntimeException('Alternate PHP extension was packageable.');
    unlink($tmp . '/helper.php5');
    file_put_contents($tmp . '/.htaccess', 'AddType application/x-httpd-php .jpg');
    $types = array_column(snapsmack_skin_security_gate($tmp), 'type');
    if (!in_array('server-configuration', $types, true)) throw new RuntimeException('Skin server configuration was packageable.');
    unlink($tmp . '/.htaccess');
    $manifest=json_decode(file_get_contents($tmp.'/manifest.json'),true);$manifest['require_scripts']=['asset:admin:ss-engine-admin-ui'];$manifest['require_styles']=['asset:admin:admin-theme-geometry-master'];file_put_contents($tmp.'/manifest.json',json_encode($manifest));
    $assetTypes=array_column(snapsmack_skin_security_findings($tmp),'type');if(count(array_filter($assetTypes,fn($type)=>$type==='manifest-asset-handle'))!==2)throw new RuntimeException('A strict skin could request admin or unknown asset handles.');
    unset($manifest['require_scripts'],$manifest['require_styles']);file_put_contents($tmp.'/manifest.json',json_encode($manifest));
    $manifest = json_decode(file_get_contents($tmp . '/manifest.json'), true);
    unset($manifest['security_policy']);
    file_put_contents($tmp . '/manifest.json', json_encode($manifest));
    $missing_policy = array_column(snapsmack_skin_security_findings($tmp), 'type');
    if (!in_array('manifest-policy-version', $missing_policy, true)) {
        throw new RuntimeException('A v2 manifest without a policy version did not fail closed.');
    }
    $manifest['security_policy'] = 2;
    file_put_contents($tmp . '/manifest.json', json_encode($manifest));
    file_put_contents($tmp . '/include-test.php', <<<'PHP'
<?php defined('SNAPSMACK_SKIN_RENDER') || exit; ?>
<?php include __DIR__ . '/template.php'; ?>
PHP);
    if (snapsmack_skin_policy_scan_template_v2($tmp . '/include-test.php', 'include-test.php') !== []) {
        throw new RuntimeException('A literal same-package template include was rejected.');
    }
    unlink($tmp . '/include-test.php');

    // Legacy detection remains available for the shrinking schema-v1 inventory.
    file_put_contents($tmp . '/manifest.json', '{"schema_version":1}');
    file_put_contents($tmp . '/bad.php', '<?php $x=$_GET["x"]; $pdo->query("SELECT id FROM secrets"); header("X: y");');
    file_put_contents($tmp . '/bad.js', 'alert(1)');
    $types = array_column(snapsmack_skin_security_findings($tmp), 'type');
    foreach (['request-global', 'database-handle', 'sql-statement', 'response-control', 'bundled-javascript'] as $type) {
        if (!in_array($type, $types, true)) throw new RuntimeException("Did not detect {$type}.");
    }
    // Schema v2 is default-deny: representative unlisted PHP mechanisms fail.
    file_put_contents($tmp . '/manifest.json', json_encode([
        'schema_version' => 2, 'cms_controller' => 'public',
        'view_model' => 'public.v1', 'templates' => ['public' => 'template.php'],
        'security_policy' => 2,
    ]));
    $attacks = [
        'global' => '<?php defined("SNAPSMACK_SKIN_RENDER") || exit; global $x;',
        'globals' => '<?php defined("SNAPSMACK_SKIN_RENDER") || exit; echo $GLOBALS["x"];',
        'variable-call' => '<?php defined("SNAPSMACK_SKIN_RENDER") || exit; $fn($view);',
        'variable-variable' => '<?php defined("SNAPSMACK_SKIN_RENDER") || exit; echo $$name;',
        'reflection' => '<?php defined("SNAPSMACK_SKIN_RENDER") || exit; new ReflectionClass("X");',
        'dynamic-include' => '<?php defined("SNAPSMACK_SKIN_RENDER") || exit; include $view["path"];',
        'backticks' => '<?php defined("SNAPSMACK_SKIN_RENDER") || exit; echo `id`;',
        'namespace' => '<?php defined("SNAPSMACK_SKIN_RENDER") || exit; namespace Bad;',
        'construction' => '<?php defined("SNAPSMACK_SKIN_RENDER") || exit; new stdClass();',
        'assignment' => '<?php defined("SNAPSMACK_SKIN_RENDER") || exit; $x = 1;',
        'raw-output' => '<?php defined("SNAPSMACK_SKIN_RENDER") || exit; echo $view["title"];',
    ];
    foreach ($attacks as $name => $source) {
        $path = $tmp . '/attack-' . $name . '.php';
        file_put_contents($path, $source);
        if (snapsmack_skin_policy_scan_template_v2($path, basename($path)) === []) {
            throw new RuntimeException("Default-deny grammar accepted {$name}.");
        }
        unlink($path);
    }

    if (snapsmack_skin_security_gate($tmp) === []) {
        throw new RuntimeException('Schema version 2 did not fail closed.');
    }
    echo "Skin security policy regression: PASS\n";
} finally {
    foreach (glob($tmp . '/*') ?: [] as $file) unlink($file);
    rmdir($tmp);
}
