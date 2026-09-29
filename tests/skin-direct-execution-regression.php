<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$guard = '/\A<\?php\s+defined\(\s*["\']SNAPSMACK_SKIN_RENDER["\']\s*\)\s*\|\|\s*exit\s*;/i';
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/skins', FilesystemIterator::SKIP_DOTS)
);
$checked = 0;
foreach ($files as $file) {
    if (!$file->isFile() || !in_array(strtolower($file->getExtension()), ['php', 'phtml', 'inc'], true)) continue;
    $skinDir = $file->getPath();
    $manifestPath = $skinDir . '/manifest.json';
    if (!is_file($manifestPath)) {
        $failures[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1))
            . ' is executable skin code outside a manifested package';
    } else {
        $manifest = json_decode((string)file_get_contents($manifestPath), true);
        $templates = is_array($manifest['templates'] ?? null)
            ? array_values(array_unique(array_filter($manifest['templates'], 'is_string')))
            : [];
        if (($manifest['schema_version'] ?? null) !== 2 || !in_array($file->getFilename(), $templates, true)) {
            $failures[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1))
                . ' is not a declared schema-v2 presentation template';
        }
    }
    $source = (string)file_get_contents($file->getPathname());
    if (!preg_match($guard, $source)) {
        $failures[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1))
            . ' lacks the exact first-statement render guard';
    }
    $checked++;
}
if ($checked === 0) $failures[] = 'No skin PHP files were checked.';

$policy = (string)file_get_contents($root . '/core/skin-security-policy.php');
if (substr_count($policy, "'template-render-guard'") < 2) {
    $failures[] = 'Both legacy and schema-v2 policy paths must enforce the guard.';
}
$bootstrap = (string)file_get_contents($root . '/core/skin-view-model.php');
if (!str_contains($bootstrap, "define('SNAPSMACK_SKIN_RENDER', true)")) {
    $failures[] = 'CMS render bootstrap does not establish skin render authority.';
}

$apache = (string)file_get_contents($root . '/core/htaccess-template');
$deny = 'RewriteRule ^(?:skins|core)(?:/.*)?\\.php$ - [R=404,L,NC]';
$denyAt = strpos($apache, $deny);
$routerAt = strpos($apache, '# ─── CLEAN URL ROUTER');
if ($denyAt === false || $routerAt === false || $denyAt > $routerAt) {
    $failures[] = 'Apache must deny PHP below skins/ and core/ before public routing.';
}

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}
echo "Skin direct-execution regression passed ({$checked} guarded files).\n";
