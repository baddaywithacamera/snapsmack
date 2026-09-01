<?php
/**
 * Test for smackpress_bucket_ids() — the pure image-id collector that feeds a
 * migrated SMACKTALK/longform post's image bucket (snap_bucket_items).
 *
 * Extracts the real function from core/smackpress-api.php and evals it (no copy,
 * so the test can't drift from the source). Proves [img:gID]/[img:ID] parsing,
 * featured-cover-leads, dedup/order, explicit-list precedence, and the no-op guard.
 *
 * Run: php core/tests/test_smackpress_bucket_ids.php   (exit 0 = all pass)
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$src = file_get_contents(__DIR__ . '/../smackpress-api.php');
if ($src === false || !preg_match('/\nfunction smackpress_bucket_ids\(.*?\n\}/s', $src, $m)) {
    fwrite(STDERR, "FAIL: could not extract smackpress_bucket_ids from source\n");
    exit(1);
}
eval($m[0]);

$fail = 0;
function check($label, $got, $want) {
    global $fail;
    if ($got !== $want) {
        $fail++;
        fwrite(STDERR, "FAIL: $label\n  got:  " . json_encode($got) . "\n  want: " . json_encode($want) . "\n");
    }
}

// 1. [img:gID] gallery form (what SMACKPRESS writes).
check('gallery form', smackpress_bucket_ids([], 'a [img:g12] b [img:g34]', null), [[12, 34], false]);

// 2. plain [img:ID] form.
check('plain form', smackpress_bucket_ids([], 'x [img:56] y', null), [[56], false]);

// 3. featured cover leads the bucket.
check('featured leads', smackpress_bucket_ids([], '[img:g12][img:34]', 99), [[99, 12, 34], false]);

// 4. featured already present in content -> deduped, still leads.
check('featured dedup', smackpress_bucket_ids([], '[img:g12][img:g34]', 12), [[12, 34], false]);

// 5. explicit image_ids list wins over content.
check('explicit image_ids', smackpress_bucket_ids(['image_ids' => [7, 8]], '[img:g99]', null), [[7, 8], true]);

// 6. bucket_image_ids preferred name.
check('explicit bucket_image_ids', smackpress_bucket_ids(['bucket_image_ids' => [1, 2]], '', null), [[1, 2], true]);

// 7. no images, no explicit list -> empty + had_explicit false (no-op guard).
check('nothing -> no-op', smackpress_bucket_ids([], 'just text, no images', null), [[], false]);

// 8. explicit EMPTY list -> empty + had_explicit true (clears the bucket).
check('explicit empty clears', smackpress_bucket_ids(['image_ids' => []], '[img:g5]', null), [[], true]);

// 9. size/align suffix ignored, only the id captured.
check('suffix ignored', smackpress_bucket_ids([], '[img:g5|full|center]', null), [[5], false]);

// 10. dedup preserves first-seen order.
check('dedup order', smackpress_bucket_ids([], '[img:g3][img:g3][img:g1]', null), [[3, 1], false]);

// 11. whitespace + optional g with spaces.
check('whitespace tolerant', smackpress_bucket_ids([], '[img: g 7 ]', null), [[7], false]);

if ($fail === 0) {
    echo "OK - 11 checks passed\n";
    exit(0);
}
fwrite(STDERR, "$fail check(s) FAILED\n");
exit(1);
// ===== SNAPSMACK EOF =====
