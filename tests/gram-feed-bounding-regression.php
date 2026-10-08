<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$entry = (string) file_get_contents($root . '/index.php');
$controller = (string) file_get_contents($root . '/core/public-controller.php');
$repository = (string) file_get_contents($root . '/core/public-repository.php');

if (str_contains($entry, '_cms_full_landing') || str_contains($entry, "['posts_per_page'] = 5000")) {
    throw new RuntimeException('GRAM landing still emits its complete archive before the browser can reveal it.');
}
if (!str_contains($controller, '$perPageCap = 100;')
    || !str_contains($repository, 'min(100, $limit)')
    || !str_contains($controller, "'next_page' => \$skin === 'slickr'")) {
    throw new RuntimeException('GRAM server paging is not bounded end to end.');
}

$sentinels = [
    'the-grid' => 'tg', 'aurora' => 'au', 'game-on' => 'go',
    'heuristic' => 'he', 'instant-camera' => 'tg', 'jive-turkey' => 'jt',
    'parade' => 'pa', 'sliders' => 'tg', 'sudden-impact' => 'tg',
];
foreach ($sentinels as $skin => $prefix) {
    $layout = (string) file_get_contents($root . '/skins/' . $skin . '/layout.php');
    if (!str_contains($layout, 'id="' . $prefix . '-sentinel"')
        || !str_contains($layout, 'data-feed')
        || !str_contains($layout, "['next_page']")) {
        throw new RuntimeException("{$skin} cannot request the next bounded landing batch.");
    }
}

echo "GRAM landing feeds are bounded and page progressively.\n";
