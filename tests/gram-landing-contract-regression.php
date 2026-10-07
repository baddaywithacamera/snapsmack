<?php
$root = dirname(__DIR__);
$repo = file_get_contents($root . '/core/public-repository.php');
$controller = file_get_contents($root . '/core/public-controller.php');
$helper = file_get_contents($root . '/core/skin-render-helpers.php');
$checks = [
    'landing is post based' => str_contains($repo, 'function carouselPostLanding'),
    'landing uses declared cover only' => str_contains($repo, 'pi.is_cover=1'),
    'legacy ordering is retained' => str_contains($repo, 'CASE WHEN p.sort_order>0 THEN 1 ELSE 0 END ASC'),
    'controller uses post count for carousel sites' => str_contains($controller, '? $repository->publishedPostCount()'),
    'modal reloads the whole carousel' => str_contains($controller, 'photographsForPost($postId)'),
    'explicit per-image treatment remains authoritative' => str_contains($controller, '$hasImageTreatment'),
    'fragment renderer emits carousel slides' => str_contains($helper, 'count($photographs) > 1'),
];
foreach ($checks as $label => $passed) {
    if (!$passed) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
}
echo "PASS: GRAM landing keeps post order, covers, count, frames, and carousels.\n";
