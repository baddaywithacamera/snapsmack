<?php
declare(strict_types=1);

$schemaPath = dirname(__DIR__) . '/database/schema/snapsmack_canonical.sql';
$schema = file_get_contents($schemaPath);
if ($schema === false) {
    fwrite(STDERR, "FAIL: canonical schema is missing\n");
    exit(1);
}

if (!preg_match('/CREATE TABLE IF NOT EXISTS `snap_images`\s*\((.*?)\)\s*ENGINE=/si', $schema, $match)) {
    fwrite(STDERR, "FAIL: snap_images CREATE TABLE statement is missing\n");
    exit(1);
}

$count = preg_match_all('/`img_color_mode`\s+/i', $match[1]);
if ($count !== 1) {
    fwrite(STDERR, "FAIL: snap_images must declare img_color_mode exactly once; found {$count}\n");
    exit(1);
}

echo "Installer canonical schema regression: PASS\n";
