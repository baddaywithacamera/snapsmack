<?php
/**
 * No skin sends the whole archive on a landing (Sean, 2026-09-11, after months
 * of being told they didn't). Landings carry one batch; ?p=N carries the next;
 * ss-engine-tag-infinite.js appends it.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
function fp_check(string $label, bool $ok): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$label}\n"); exit(1); }
    echo "PASS {$label}\n";
}
$root = dirname(__DIR__);
foreach (['game-on' => 'go', 'instant-camera' => 'tg', 'sliders' => 'tg', 'sudden-impact' => 'tg', 'the-grid' => 'tg'] as $skin => $P) {
    $php = file_get_contents("$root/skins/$skin/landing.php");
    fp_check("$skin slices the feed to one batch", strpos($php, '$grid_posts  = array_slice($grid_posts, ($_feed_page - 1) * $_feed_per, $_feed_per);') !== false
        && strpos($php, "\$_feed_page  = max(1, (int)(\$_GET['p'] ?? 1));") !== false);
    fp_check("$skin emits a feed sentinel only when more exist", strpos($php, "<?php if (\$_feed_more): ?>") !== false
        && strpos($php, "id=\"$P-sentinel\" class=\"ss-feed-sentinel\" data-feed data-next=") !== false);
    $man = json_decode(file_get_contents("$root/skins/$skin/manifest.json"), true);
    fp_check("$skin loads the infinite-scroll engine", in_array('smack-tag-infinite', $man['require_scripts'], true));
}
$sl = file_get_contents("$root/skins/slickr/landing.php");
fp_check('slickr pages by justified rows', strpos($sl, '$rows           = array_slice($rows, ($_feed_page - 1) * $_feed_per_rows, $_feed_per_rows);') !== false
    && strpos($sl, 'id="justified-sentinel" class="ss-feed-sentinel" data-feed') !== false);
$slm = json_decode(file_get_contents("$root/skins/slickr/manifest.json"), true);
fp_check('slickr loads the infinite-scroll engine', in_array('smack-tag-infinite', $slm['require_scripts'], true));
$js = file_get_contents("$root/assets/js/ss-engine-tag-infinite.js");
fp_check('engine accepts feed sentinels', strpos($js, '[id$="-sentinel"][data-base][data-feed]') !== false
    && strpos($js, "var isFeed    = sentinel.hasAttribute('data-feed');") !== false);
fp_check('engine appends justified rows for slickr', strpos($js, "'#justified-grid > .justified-row'") !== false);
fp_check('engine fetches ?p=N of the same landing', strpos($js, "'p=' + nextPg") !== false);
fp_check('engine tells other engines the grid grew', strpos($js, "new CustomEvent(P + ':grid-updated')") !== false);
$css = file_get_contents("$root/assets/css/public-base.css");
fp_check('sentinel spans the grid row', strpos($css, '.ss-feed-sentinel { grid-column: 1 / -1;') !== false);
$reveal = file_get_contents("$root/assets/js/ss-engine-progressive-reveal.js");
fp_check('batch sizes agree with the reveal engine', strpos($reveal, 'var GRID_BATCH = 120;') !== false && strpos($reveal, 'var ROW_BATCH  = 25;') !== false);
echo "PASS: feed paging regression\n";
// ===== SNAPSMACK EOF =====
