<?php
declare(strict_types=1);

$source = (string)file_get_contents(dirname(__DIR__) . '/core/updater.php');
$functionStart = strpos($source, 'function updater_replace_file(');
$functionEnd = strpos($source, '/**', $functionStart + 1);
$replaceFunction = $functionStart === false
    ? ''
    : substr($source, $functionStart, $functionEnd === false ? null : $functionEnd - $functionStart);

if (!str_contains($replaceFunction, "function_exists('opcache_invalidate')")
    || substr_count($replaceFunction, '@opcache_invalidate($dest, true)') !== 2) {
    throw new RuntimeException('Every successful atomic updater replacement must invalidate the installed path in OPcache.');
}

echo "Updater OPcache invalidation regression: PASS\n";
// ===== SNAPSMACK EOF =====
