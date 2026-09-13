<?php
/**
 * Regression guard (0.7.707D): federated replies must reach the rendered thread.
 * Inbound fediverse replies live in snap_comments (img_id-keyed); every skin
 * renders core/community-component.php, which read only snap_community_comments
 * — so replies were stored + approved and never displayed on any skin.
 */
$root = dirname(__DIR__);
$cc  = file_get_contents($root . '/core/community-component.php');
$css = file_get_contents($root . '/assets/css/ss-community.css');

$checks = [
    'reads snap_comments'            => 'FROM snap_comments',
    'fediverse rows only'            => "ap_source = 'fediverse'",
    'approved + not spam'            => 'is_approved = 1 AND is_spam = 0',
    'post-keyed gathers post images' => 'SELECT id FROM snap_images WHERE post_id = ?',
    'merged rows flagged'            => "'is_fedi'      => 1",
    'merged rows read-only'          => "'id'           => 0,",
    'date-ordered merge'             => 'usort($community_comments',
    'badge rendered'                 => 'class="ss-comment-source"',
];
foreach ($checks as $name => $needle) {
    if (strpos($cc, $needle) === false) {
        fwrite(STDERR, "Missing: {$name}\n");
        exit(1);
    }
}
if (strpos($css, '.ss-comment-source') === false) {
    fwrite(STDERR, "Missing badge CSS (.ss-comment-source)\n");
    exit(1);
}
echo "Fediverse replies render regression checks passed.\n";
// ===== SNAPSMACK EOF =====
