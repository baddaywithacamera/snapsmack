<?php
declare(strict_types=1);

/**
 * SS-1606: Test that album/category descriptions containing raw or escaped HTML
 * normalize to clean plain text before skin escaping in smacktalk-public-controller.php.
 *
 * Run: php core/tests/test_album_description_html.php
 *
 * SNAPSMACK_EOF_HEADER
 * // ===== SNAPSMACK EOF =====
 */

$src = file_get_contents(__DIR__ . '/../smacktalk-public-controller.php');
if ($src === false || !preg_match('/\nfunction snapsmack_plain_taxonomy_description\(.*?\n\}/s', $src, $m)) {
    fwrite(STDERR, "FAIL: could not extract snapsmack_plain_taxonomy_description from core/smacktalk-public-controller.php\n");
    exit(1);
}
eval($m[0]);

if (!str_contains($src, "snapsmack_plain_taxonomy_description(")) {
    fwrite(STDERR, "FAIL: core/smacktalk-public-controller.php does not wire snapsmack_plain_taxonomy_description()\n");
    exit(1);
}

$fail = 0;
$check = static function (string $label, string $got, string $want) use (&$fail): void {
    if ($got !== $want) {
        $fail++;
        fwrite(STDERR, "FAIL: {$label}\n  got:  " . json_encode($got) . "\n  want: " . json_encode($want) . "\n");
    }
};

$check('empty string', snapsmack_plain_taxonomy_description(''), '');
$check('raw paragraph and emphasis tags',
       snapsmack_plain_taxonomy_description('<p>Evening storm over <em>Strathmore</em></p>'),
       'Evening storm over Strathmore');
$check('br and block boundaries become single spaces',
       snapsmack_plain_taxonomy_description('<p>First line<br />Second line</p><p>Third line</p>'),
       'First line Second line Third line');
$check('entity-escaped HTML and double-encoded ampersand',
       snapsmack_plain_taxonomy_description('&lt;p&gt;Cars &amp;amp; Trucks&lt;/p&gt;'),
       'Cars & Trucks');
$check('anchor tag preserves link text without raw markup',
       snapsmack_plain_taxonomy_description('See <a href="https://example.com">Flickr set</a>.'),
       'See Flickr set.');
$check('plain text with entities decodes cleanly once',
       snapsmack_plain_taxonomy_description('Sean&rsquo;s 100% &amp; more'),
       "Sean\xE2\x80\x99s 100% & more");

if ($fail > 0) {
    fwrite(STDERR, "FAILED: {$fail} check(s)\n");
    exit(1);
}
echo "PASS: SS-1606 album description HTML normalization (6 checks).\n";
// ===== SNAPSMACK EOF =====
