<?php
/** Regression: GYSS must not duplicate the media root in thumbnail URLs. */

$source = file_get_contents(__DIR__ . '/../core/gyss-api.php');
if ($source === false) {
    fwrite(STDERR, "Could not read core/gyss-api.php\n");
    exit(1);
}

$forbidden = [
    "BASE_URL . 'uploads/' . ltrim(\$img_file",
    "dirname(__DIR__) . '/uploads/' . \$thumb_rel",
];
foreach ($forbidden as $needle) {
    if (strpos($source, $needle) !== false) {
        fwrite(STDERR, "GYSS still duplicates a stored media root: {$needle}\n");
        exit(1);
    }
}

$required = [
    "str_starts_with(\$rel, 'img_uploads/')",
    "str_starts_with(\$rel, 'uploads/')",
    'i1.img_thumb_aspect',
    "gy_thumb_url((string)\$row['cover_file'], (string)\$row['cover_thumb'])",
];
foreach ($required as $needle) {
    if (strpos($source, $needle) === false) {
        fwrite(STDERR, "GYSS media URL safeguard missing: {$needle}\n");
        exit(1);
    }
}

echo "GYSS media URL regression: PASS\n";

