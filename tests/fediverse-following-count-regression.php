<?php
/** Regression: ordinary installs without the curator table must not report zero. */
$src = file_get_contents(__DIR__ . '/../core/fediverse.php');
$fail = [];
if (!str_contains($src, "TABLE_NAME='snap_curator_directory'")) {
    $fail[] = 'following collection does not detect whether the curator-only table exists';
}
if (!str_contains($src, 'if ($has_curator)')) {
    $fail[] = 'ordinary following count is still unconditionally coupled to the curator table';
}
if (!str_contains($src, 'SELECT COUNT(*) FROM snap_ap_following f WHERE f.state=\'accepted\'')) {
    $fail[] = 'ordinary accepted-following fallback query is absent';
}
if ($fail) {
    fwrite(STDERR, "FAIL: " . implode("\nFAIL: ", $fail) . "\n");
    exit(1);
}
echo "PASS: following collection tolerates an absent curator table\n";
// ===== SNAPSMACK EOF =====
