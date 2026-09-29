<?php
declare(strict_types=1);

/**
 * One-time/idempotent source normalizer for the skin direct-execution guard.
 * The guard shares the opening-tag line so existing finding line numbers do not
 * move. The security gate keeps future files compliant.
 */

$root = dirname(__DIR__, 2) . '/skins';
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
$changed = 0;
$failed = [];
$guard = "defined('SNAPSMACK_SKIN_RENDER') || exit;";

foreach ($iterator as $file) {
    if (!$file->isFile() || !in_array(strtolower($file->getExtension()), ['php', 'phtml', 'inc'], true)) continue;
    $path = $file->getPathname();
    $source = file_get_contents($path);
    if ($source === false || !str_starts_with($source, '<?php')) {
        $failed[] = $path;
        continue;
    }
    if (preg_match('/\A<\?php\s+defined\(\s*["\']SNAPSMACK_SKIN_RENDER["\']\s*\)\s*\|\|\s*exit\s*;/i', $source)) continue;

    // Remove the earlier TILEZ return guard while standardising the fleet.
    $source = preg_replace(
        '/\A<\?php\Rif\s*\(\s*!defined\(\s*["\']SNAPSMACK_SKIN_RENDER["\']\s*\)\s*\)\s*return\s*;/',
        '<?php',
        $source,
        1
    );
    $updated = substr_replace($source, '<?php ' . $guard, 0, 5);
    if (file_put_contents($path, $updated) === false) $failed[] = $path;
    else $changed++;
}

if ($failed) {
    foreach ($failed as $path) fwrite(STDERR, "Unable to normalize {$path}\n");
    exit(1);
}
echo "Normalized {$changed} skin PHP file(s).\n";
