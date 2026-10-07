<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */
$source = file_get_contents(dirname(__DIR__) . '/core/skin-presentation.php');

$fail = static function (string $message): void {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

if (strpos($source, "is_file(\$localPath)") === false
    || strpos($source, "https://snapsmack.ca/sc-assets/") === false
    || strpos($source, "preg_replace('#^assets/#'") === false) {
    $fail('Selected local fonts have no central-asset fallback when an install is missing the file.');
}

echo "local font remote fallback regression: ok\n";
// ===== SNAPSMACK EOF =====
