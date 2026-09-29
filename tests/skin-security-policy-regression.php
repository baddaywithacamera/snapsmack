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
    ]));
    file_put_contents($tmp . '/template.php', '<?php echo htmlspecialchars($view["title"]);');
    if (snapsmack_skin_security_findings($tmp) !== []) {
        throw new RuntimeException('A presentation-only template was rejected.');
    }

    file_put_contents($tmp . '/bad.php', '<?php $x=$_GET["x"]; $pdo->query("SELECT id FROM secrets"); header("X: y");');
    file_put_contents($tmp . '/bad.js', 'alert(1)');
    $types = array_column(snapsmack_skin_security_findings($tmp), 'type');
    foreach (['request-global', 'database-handle', 'sql-statement', 'response-control', 'bundled-javascript'] as $type) {
        if (!in_array($type, $types, true)) throw new RuntimeException("Did not detect {$type}.");
    }
    $baseline = ['snapsmack-skin-policy-' . basename($tmp) => []];
    if (snapsmack_skin_security_gate($tmp, $baseline) === []) {
        throw new RuntimeException('Schema version 2 did not fail closed.');
    }
    echo "Skin security policy regression: PASS\n";
} finally {
    foreach (glob($tmp . '/*') ?: [] as $file) unlink($file);
    rmdir($tmp);
}
