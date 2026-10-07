<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */
$source = file_get_contents(dirname(__DIR__) . '/core/skin-presentation.php');
$metaSource = file_get_contents(dirname(__DIR__) . '/core/meta.php');
$htaccessTemplate = file_get_contents(dirname(__DIR__) . '/core/htaccess-template');

$fail = static function (string $message): void {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

if (strpos($source, "https://snapsmack.ca/sc-assets/") === false
    || strpos($source, "preg_replace('#^assets/#'") === false) {
    $fail('Selected local fonts are not served from the canonical central asset repository.');
}

if (strpos($metaSource, "https://snapsmack.ca/sc-assets/") === false
    || strpos($metaSource, "preg_replace('#^assets/#'") === false
    || strpos($metaSource, "BASE_URL . ltrim(\$font['file']") !== false) {
    $fail('The shared page font loader must use the same canonical central asset repository.');
}

if (strpos($source, "is_file(\$localPath)") !== false) {
    $fail('Local filesystem presence must not be treated as proof that a font is publicly routable.');
}

if (strpos($htaccessTemplate, 'Access-Control-Allow-Origin "*"') === false
    || strpos($htaccessTemplate, 'ttf|otf|woff|woff2') === false) {
    $fail('The installed and repairable web-server rules do not permit fleet sites to use centrally hosted font faces.');
}

echo "local font remote fallback regression: ok\n";
// ===== SNAPSMACK EOF =====
