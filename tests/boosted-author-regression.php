<?php
/** Regression guard: boosted reader cards retain both identities. */
$root = dirname(__DIR__);
$fedi = file_get_contents($root . '/core/fediverse.php');
$js = file_get_contents($root . '/assets/js/ss-pixel.js');

$checks = [
    'original actor cache join' => 'a.handle AS cached_handle',
    'booster actor cache join'  => 'b.handle AS booster_handle',
    'legacy handle repair'      => 'sv_actor_handle_fallback',
    'original actor fetch'      => 'sv_fetch_ap($original_actor, $settings)',
    'booster response identity' => "'boosted_by' => \$booster_url",
];
foreach ($checks as $name => $needle) {
    if (strpos($fedi, $needle) === false) {
        fwrite(STDERR, "Missing: {$name}\n");
        exit(1);
    }
}
foreach (['var booster = p.boosted_by || {};', 'Boosted" +', 'data-search="'] as $needle) {
    if (strpos($js, $needle) === false) {
        fwrite(STDERR, "Missing boosted-card UI: {$needle}\n");
        exit(1);
    }
}
echo "Boosted author regression checks passed.\n";
// ===== SNAPSMACK EOF =====
