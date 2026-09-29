<?php
$root = dirname(__DIR__);
$violations = [];
$patterns = [
    'PDO access' => '/\$pdo\b/',
    'SQL' => '/\b(?:SELECT|INSERT|UPDATE|DELETE|REPLACE)\b/i',
    'request globals' => '/\$_(?:GET|POST|REQUEST|SERVER|COOKIE|SESSION)\b/',
    'response control' => '/\b(?:header|http_response_code|setcookie|session_start)\s*\(/i',
    'filesystem inspection' => '/\b(?:getimagesize|file_get_contents|file_put_contents|fopen|unlink|rename|mkdir|rmdir)\s*\(/i',
    'network/process execution' => '/\b(?:curl_exec|fsockopen|exec|shell_exec|system|passthru|proc_open)\s*\(/i',
];
foreach (glob($root . '/skins/tilez/*.php') as $file) {
    $source = (string)file_get_contents($file);
    if (strpos($source, "defined('SNAPSMACK_SKIN_RENDER') || exit;") === false) {
        $violations[] = basename($file) . ': missing direct-execution guard';
    }
    foreach ($patterns as $label => $pattern) {
        if (preg_match($pattern, $source)) $violations[] = basename($file) . ': ' . $label;
    }
}
if ($violations) {
    fwrite(STDERR, "FAIL: TILEZ presentation boundary violated:\n- " . implode("\n- ", $violations) . "\n");
    exit(1);
}
$manifest = json_decode((string)file_get_contents($root . '/skins/tilez/manifest.json'), true);
if (($manifest['cms_controller'] ?? '') !== 'smacktalk') {
    fwrite(STDERR, "FAIL: TILEZ does not delegate to the CMS SMACKTALK controller.\n");
    exit(1);
}
echo "PASS: TILEZ is presentation-only under the CMS SMACKTALK controller.\n";
// ===== SNAPSMACK EOF =====
