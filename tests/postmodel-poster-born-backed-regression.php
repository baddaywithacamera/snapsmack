<?php
/**
 * SNAPSMACK — regression: the SMACKONEOUT (photoblog) solo poster must create a
 * real post at post-time, so new photos are born POST-BACKED and never drift back
 * to "bare image" (which would need postmodel_repair re-run forever).
 *
 * This is the "plug" half of the post-model fix (0.7.539). The "mop" half is the
 * CONVERT PHOTOS TO POSTS button (postmodel_repair). If this test fails, new solo
 * uploads have stopped creating their post — the drift bug is back.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$solo = (string) file_get_contents(__DIR__ . '/../smack-post-solo.php');
$failures = [];

// The solo poster must create the post container, its pivot, and set post_id.
foreach ([
    'INSERT INTO snap_posts'        => 'solo poster no longer creates a snap_posts row (photos born bare again)',
    'INSERT INTO snap_post_images'  => 'solo poster no longer links the photo to its post (snap_post_images)',
    'UPDATE snap_images SET post_id' => 'solo poster no longer stamps snap_images.post_id (repair will re-list it as bare)',
    "'single'"                      => "solo poster no longer tags its post post_type='single' (repair idempotency relies on it)",
] as $needle => $why) {
    if (strpos($solo, $needle) === false) $failures[] = $why;
}

// The federation identity (img_slug) must NEVER be rewritten by the poster.
if (preg_match('/UPDATE\s+snap_images\s+SET\s+img_slug/i', $solo)) {
    $failures[] = 'solo poster rewrites img_slug — that is the federation identity and must stay stable';
}

if ($failures) {
    fwrite(STDERR, "FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "PASS: photoblog solo poster creates photos born post-backed; img_slug untouched.\n";
// ===== SNAPSMACK EOF =====
