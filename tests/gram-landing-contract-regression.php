<?php
$root = dirname(__DIR__);
$repo = file_get_contents($root . '/core/public-repository.php');
$controller = file_get_contents($root . '/core/public-controller.php');
$helper = file_get_contents($root . '/core/skin-render-helpers.php');
$checks = [
    'landing is post based' => str_contains($repo, 'function carouselPostLanding'),
    'landing uses declared cover only' => str_contains($repo, 'pi.is_cover=1'),
    'legacy ordering is retained' => str_contains($repo, 'CASE WHEN p.sort_order>0 THEN 1 ELSE 0 END ASC'),
    'trigram fields are CMS computed' => str_contains($repo, 'END AS trigram_slot') && str_contains($repo, 'tg.orientation AS trigram_orientation'),
    'trigram provider is bounded in CMS' => str_contains($controller, 'function snapsmack_trigram_landing_provider'),
    'trigram provider supplies slice path' => str_contains($controller, "'trigram_slice_path'") && str_contains($controller, 'is_trigram_slice'),
    'trigram provider supplies tail phantoms' => str_contains($controller, "'is_phantom' => true"),
    'controller uses post count for carousel sites' => str_contains($controller, '? $repository->publishedPostCount()'),
    'modal reloads the whole carousel' => str_contains($controller, 'photographsForPost($postId)'),
    'explicit per-image treatment remains authoritative' => str_contains($controller, '$hasImageTreatment'),
    'fragment renderer emits carousel slides' => str_contains($helper, 'count($photographs) > 1'),
];
foreach ($checks as $label => $passed) {
    if (!$passed) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
}
require_once $root . '/core/public-controller.php';
$fixtureRoot = sys_get_temp_dir() . '/snapsmack-trigram-provider-' . bin2hex(random_bytes(5));
mkdir($fixtureRoot . '/trigrams', 0777, true);
file_put_contents($fixtureRoot . '/trigrams/trigram-7-L.jpg', 'fixture');
$provided = snapsmack_trigram_landing_provider([
    ['post_id'=>1, 'trigram_id'=>null, 'trigram_slot'=>null],
    ['post_id'=>2, 'trigram_id'=>7, 'trigram_slot'=>1, 'trigram_orientation'=>'h'],
    ['post_id'=>3, 'trigram_id'=>7, 'trigram_slot'=>2, 'trigram_orientation'=>'h'],
    ['post_id'=>4, 'trigram_id'=>7, 'trigram_slot'=>3, 'trigram_orientation'=>'h'],
], $fixtureRoot);
if (count($provided) !== 6 || empty($provided[1]['is_phantom']) || empty($provided[2]['is_phantom'])) {
    fwrite(STDERR, "FAIL: bounded trigram provider did not supply tail alignment phantoms\n"); exit(1);
}
if (($provided[3]['trigram_slice_path'] ?? '') !== 'trigrams/trigram-7-L.jpg' || empty($provided[3]['is_trigram_slice'])) {
    fwrite(STDERR, "FAIL: bounded trigram provider did not supply the physical slice presentation path\n"); exit(1);
}
unlink($fixtureRoot . '/trigrams/trigram-7-L.jpg');
rmdir($fixtureRoot . '/trigrams');
rmdir($fixtureRoot);
echo "PASS: GRAM landing keeps post order, covers, count, frames, and carousels.\n";
