<?php
/**
 * Regression: the longform cover picker can scope choices to this post's bucket.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
$post = file_get_contents(__DIR__ . '/../smack-post-long.php');
$gallery = file_get_contents(__DIR__ . '/../smack-gallery.php');
$js = file_get_contents(__DIR__ . '/../assets/js/smack-longform-gallery-picker.js');

$checks = [
    'post editor renders bucket source choice' => str_contains($post, "THIS POST'S BUCKET"),
    'post id is exposed to picker' => str_contains($post, 'data-post-id='),
    'gallery endpoint accepts bucket post id' => str_contains($gallery, "\$_GET['bucket_post_id']"),
    'gallery endpoint scopes through bucket items' => str_contains($gallery, 'snap_bucket_items bi'),
    'cover picker requests bucket scope' => str_contains($js, "bucket_post_id="),
    'cover picker defaults saved posts to bucket' => str_contains($js, "postId > 0 ? 'bucket' : 'all'"),
];

$failed = array_keys(array_filter($checks, static fn($ok) => !$ok));
if ($failed) {
    fwrite(STDERR, "FAIL: " . implode('; ', $failed) . PHP_EOL);
    exit(1);
}
echo "OK: longform cover picker offers current-post bucket and all Gallery images." . PHP_EOL;

// ===== SNAPSMACK EOF =====
