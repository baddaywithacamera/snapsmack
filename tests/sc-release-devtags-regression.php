<?php
/**
 * Release Packager dev-tag listing (0.7.648D) — GitHub's /tags order is not
 * newest-first; a single-page fetch silently dropped the newest dev tags from
 * the packager dropdown once the repo passed ~650 tags (v0.7.644D–647D went
 * missing, 2026-09-05). The lister must sweep every page.
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

$src = file_get_contents(dirname(__DIR__) . '/smack-central/sc-release.php');
$fn  = substr($src, strpos($src, 'function sc_list_dev_tags'), 1600);

$fail = 0;
function dt_test(bool $ok, string $message): void {
    global $fail;
    echo ($ok ? "PASS " : "FAIL ") . $message . "\n";
    if (!$ok) $fail++;
}

dt_test(str_contains($fn, "per_page=100&page=' . \$page"), 'dev-tag lister pages through the tag list');
dt_test(str_contains($fn, 'count($data) < 100'), 'paging stops on the last page, not on a tag count — newest can sit on ANY page');
dt_test(!str_contains($fn, "per_page=50"), 'the old single 50-tag page fetch is gone');

echo $fail === 0 ? "ALL PASS\n" : ("{$fail} FAILURE(S)\n");
exit($fail === 0 ? 0 : 1);

// ===== SNAPSMACK EOF =====
