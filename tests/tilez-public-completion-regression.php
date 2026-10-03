<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$controller = (string)file_get_contents($root . '/core/smacktalk-public-controller.php');
$repository = (string)file_get_contents($root . '/core/public-repository.php');
$navigation = (string)file_get_contents($root . '/core/skin-view-model.php');
$layout = (string)file_get_contents($root . '/skins/tilez/layout.php');
$style = (string)file_get_contents($root . '/skins/tilez/style.css');

$expect = static function (bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
};

$expect(str_contains($repository, 'ownedPhotographsForPost'), 'Single posts lost their ownership fallback.');
$expect(str_contains($controller, "trim(strip_tags((string)\$rendered)) === ''")
    && str_contains($controller, 'smacktalk-owned-photo'), 'Empty post shells no longer recover their owned photograph.');
$expect(str_contains($controller, "\$view === 'categories' || \$view === 'albums'")
    && str_contains($layout, 'tilez-taxonomy-page'), 'Categories or albums bypass the strict TILEZ directory.');
$expect(str_contains($navigation, "'?view=categories'") || str_contains($navigation, "'categories'"),
    'Category navigation no longer resolves through the strict controller.');
$expect(str_contains($style, '.blogroll-grid { columns: 2;')
    && str_contains($style, 'break-inside: avoid'), 'Blogroll groups can create false vertical holes.');
$expect(str_contains($style, '#page > #system-footer { margin-top: auto;'), 'Footer is no longer anchored to the page.');

echo "TILEZ public completion regression passed.\n";

// ===== SNAPSMACK EOF =====
