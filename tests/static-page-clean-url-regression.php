<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$header = file_get_contents($root . '/core/header.php');
$gram = file_get_contents($root . '/core/gram-nav-links.php');
$page = file_get_contents($root . '/page.php');

$checks = [
    'desktop navigation no longer emits query-string page slugs' => !str_contains($header, "page.php?slug="),
    'GRAM navigation no longer emits query-string page slugs' => !str_contains($gram, "page.php?slug="),
    'legacy page entrance permanently redirects to the clean root slug' =>
        str_contains($page, "rawurlencode((string)\$slug), true, 301"),
];

$failed = 0;
foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
    if (!$passed) $failed++;
}
exit($failed === 0 ? 0 : 1);
