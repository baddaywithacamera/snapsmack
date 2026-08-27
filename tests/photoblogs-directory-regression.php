<?php
/** Regression guards for the public photoblogs.fyi directory contract. */

$root = dirname(__DIR__);
$page = file_get_contents($root . '/directory.php');
$api = file_get_contents($root . '/directory-api.php');
$spoke = file_get_contents($root . '/core/photoblogs-directory.php');

$failures = [];
$expect = function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) $failures[] = $message;
};

$expect(str_contains($page, 'class="site-header"'), 'directory must use the shared site header');
$expect(str_contains($page, 'class="site-footer"'), 'directory must use the shared site footer');
$expect(str_contains($page, 'aria-current="page"'), 'directory navigation must identify the active page');
$expect(!str_contains($page, 'class="shots"'), 'directory must not render a photograph feed');
$expect(!str_contains($page, 'class="photo-link"'), 'directory must remain a blog listing');
$expect(str_contains($page, 'Visit blog ↗'), 'directory cards must link to their blogs');
$expect(str_contains($page, "\$r['site_url']"), 'directory cards must use the registered blog URL');
$expect(str_contains($page, "strtotime('-30 days')"), 'directory must distinguish inactive blogs for fair rotation');
$expect(str_contains($page, "gmdate('Y-m-d')"), 'inactive-blog rotation must remain stable for a day');
$expect(str_contains($page, "(\$i + 1) % 4"), 'directory must weave inactive blogs among recent listings');
$expect(str_contains($spoke, "'samples'     => []"), 'directory registration must not populate feed photographs');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "photoblogs directory regression: ok\n";
