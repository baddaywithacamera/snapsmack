<?php
// SNAPSMACK_EOF_HEADER
declare(strict_types=1);
require_once __DIR__ . '/../core/skin-view-contract.php';
require_once __DIR__ . '/../core/skin-render-helpers.php';
$layout = file_get_contents(__DIR__ . '/../skins/tilez/layout.php');
preg_match('/<dl class="post-facts">.*?<\/dl>/s', $layout, $match);
foreach ([0, 127] as $total) {
    $view = snapsmack_build_skin_view(['kind' => 'single', 'published_post_count' => $total, 'photo_count' => 2, 'word_count' => 140]);
    ob_start();
    eval('?>' . $match[0]);
    $html = ob_get_clean();
    if (!str_contains($html, '<dt>Posts</dt><dd>' . $total . '</dd></div><div><dt>Photos</dt><dd>2</dd>')) {
        throw new RuntimeException('Published blog total must survive the view contract and precede the distinct per-post photo count.');
    }
}
echo "TILEZ post total: PASS (zero and nonzero totals, view contract, rendered row order, independent photo count).\n";
// ===== SNAPSMACK EOF =====
