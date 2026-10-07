<?php
/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */
$source = file_get_contents(dirname(__DIR__) . '/core/skin-presentation.php');
$metaSource = file_get_contents(dirname(__DIR__) . '/core/meta.php');
$providerSource = file_get_contents(dirname(__DIR__) . '/font.php');

$fail = static function (string $message): void {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

if (strpos($source, "/font.php?family=") === false
    || strpos($source, 'rawurlencode((string)$family)') === false) {
    $fail('Selected local fonts are not routed through the bounded same-origin provider.');
}

if (strpos($metaSource, "/font.php?family=") === false
    || strpos($metaSource, 'rawurlencode((string)$family)') === false) {
    $fail('The shared page font loader must use the bounded same-origin provider.');
}

if (strpos($source, "is_file(\$localPath)") !== false) {
    $fail('Local filesystem presence must not be treated as proof that a font is publicly routable.');
}

if (strpos($providerSource, "isset(\$fonts[\$family])") === false
    || strpos($providerSource, "CURLOPT_PROTOCOLS => CURLPROTO_HTTPS") === false
    || strpos($providerSource, "https://snapsmack.ca/sc-assets/") === false) {
    $fail('The font provider is not bounded to inventory families and the canonical HTTPS asset source.');
}

echo "local font remote fallback regression: ok\n";
// ===== SNAPSMACK EOF =====
