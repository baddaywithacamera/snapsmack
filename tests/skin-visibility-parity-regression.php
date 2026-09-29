<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/core/public-visibility.php';
$root = dirname(__DIR__);
$fixture = json_decode((string)file_get_contents(__DIR__ . '/fixtures/public-visibility.json'), true, 512, JSON_THROW_ON_ERROR);
$now = new DateTimeImmutable($fixture['now']);
$skins = glob($root . '/skins/*/manifest.json') ?: [];
$checks = 0;
foreach ($skins as $manifest) {
    foreach ($fixture['cases'] as $case) {
        $actual = snapsmack_public_row_visible($case['kind'], $case['row'], $now);
        if ($actual !== $case['visible']) {
            throw new RuntimeException(basename(dirname($manifest)) . ' changed visibility for ' . $case['name']);
        }
        $checks++;
    }
}
if (!$skins) throw new RuntimeException('No skins participated in visibility parity fixtures.');

$baseline = require $root . '/core/skin-visibility-legacy.php';
if ($baseline !== []) throw new RuntimeException('Visibility query debt remains; appearance can still broaden public content.');
$current = [];
foreach ($skins as $manifest) {
    $dir = dirname($manifest);
    $slug = basename($dir);
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
        $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
        $findings = snapsmack_skin_visibility_query_findings($file->getPathname(), $rel);
        if ($findings) $current[$slug][$rel] = $findings;
    }
}
if ($current !== $baseline) throw new RuntimeException('Visibility query inventory changed; regenerate only after reviewing and repairing the difference.');
$controllerFindings = snapsmack_skin_visibility_query_findings(
    $root . '/core/smacktalk-public-controller.php',
    'core/smacktalk-public-controller.php'
);
if ($controllerFindings !== []) throw new RuntimeException('CMS SMACKTALK controller has a public visibility query gap.');
echo 'Visibility parity fixtures passed across ' . count($skins) . " skins ({$checks} decisions); query debt inventory is stable.\n";
