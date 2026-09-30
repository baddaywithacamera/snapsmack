<?php
declare(strict_types=1);

$controller = (string)file_get_contents(dirname(__DIR__) . '/core/public-controller.php');
$repository = (string)file_get_contents(dirname(__DIR__) . '/core/public-repository.php');
$layout = (string)file_get_contents(dirname(__DIR__) . '/skins/glide/layout.php');

foreach (["randomPhotographs(200)", 'array_fill(0, 9, [])', "['presentation_aspect']", '$width . \'/\' . $height'] as $needle) {
    if (!str_contains($controller, $needle)) throw new RuntimeException('Glide CMS model lost: ' . $needle);
}
if (!str_contains($repository, 'function randomPhotographs(')
    || !str_contains($repository, "img_status='published' AND img_date <= NOW()")) {
    throw new RuntimeException('Glide random pool escaped the public repository boundary.');
}
if (!str_contains($layout, "['presentation_aspect']") || !str_contains($layout, '--glide-aspect:')) {
    throw new RuntimeException('Glide layout no longer consumes the CMS-derived aspect metadata.');
}

echo "Glide controller presentation regression passed.\n";
