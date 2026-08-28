<?php
/** Regression guards for the public photoblogs.fyi directory contract. */

$root = dirname(__DIR__);
$page = file_get_contents($root . '/directory.php');
$renderer = file_get_contents($root . '/core/photoblogs-directory-view.php');
$parser = file_get_contents($root . '/core/parser.php');
$api = file_get_contents($root . '/directory-api.php');
$spoke = file_get_contents($root . '/core/photoblogs-directory.php');

$failures = [];
$expect = function (bool $ok, string $message) use (&$failures): void {
    if (!$ok) $failures[] = $message;
};

$expect(str_contains($page, '/page.php?slug=directory'), 'legacy directory route must redirect to the skinned CMS page');
$expect(str_contains($parser, '[photoblogs_directory]'), 'parser must expose the directory shortcode');
$expect(str_contains($renderer, 'class="pbf-directory-list"'), 'directory must render a compact text list');
$expect(!str_contains($renderer, 'class="card"'), 'directory must not use a card grid');
$expect(!str_contains($renderer, '<footer'), 'directory shortcode must not render page chrome');
$expect(str_contains($renderer, "\$row['site_url']"), 'directory entries must use the registered blog URL');
$expect(str_contains($renderer, "strtotime('-30 days')"), 'directory must distinguish inactive blogs for fair rotation');
$expect(str_contains($renderer, "gmdate('Y-m-d')"), 'inactive-blog rotation must remain stable for a day');
$expect(str_contains($renderer, "(\$i + 1) % 4"), 'directory must weave inactive blogs among recent listings');
$expect(str_contains($spoke, "'samples'     => []"), 'directory registration must not populate feed photographs');

if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "photoblogs directory regression: ok\n";
