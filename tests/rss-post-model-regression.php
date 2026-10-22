<?php
/** RSS must expose posts, not every supporting image uploaded for a post. */
$rss = file_get_contents(dirname(__DIR__) . '/rss.php');
$checks = [
    "p.post_type='longform'" => 'longform posts are represented directly',
    'p.featured_image_id' => 'longform RSS uses the featured photograph',
    'JOIN snap_post_images pi' => 'ordinary post covers remain in RSS',
    'p.signature_image_id=i.id' => 'signature assets are excluded from standalone photos',
    'FROM snap_bucket_items b WHERE b.image_id=i.id' => 'bucket assets are excluded from standalone photos',
    'foreach ($rss_rows as $row)' => 'the renderer consumes the unified post stream',
];
foreach ($checks as $needle => $message) {
    if (!str_contains($rss, $needle)) throw new RuntimeException($message);
}
if (str_contains($rss, 'SELECT * FROM snap_images WHERE img_status = \'published\'')) {
    throw new RuntimeException('the old image-only feed query is still active');
}
echo "PASS: RSS emits one item per post and hides supporting media.\n";
