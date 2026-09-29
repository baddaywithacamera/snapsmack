<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$thomas = (string)file_get_contents($root . '/assets/js/ss-engine-thomas.js');
$fontPreview = (string)file_get_contents($root . '/assets/js/ss-engine-font-preview.js');
$fontLoader = (string)file_get_contents($root . '/core/font-loader.php');
$footer = (string)file_get_contents($root . '/core/footer-scripts.php');

foreach ([$thomas, $fontPreview, $fontLoader] as $source) {
    if (preg_match('~https?://~i', $source)) {
        throw new RuntimeException('A public presentation asset still initiates a remote font or telemetry request.');
    }
}
foreach (['thomas_uid', 'window.ssUid', 'INSERT INTO', '<script>'] as $forbidden) {
    if (str_contains($footer, $forbidden)) {
        throw new RuntimeException("Public footer reintroduced inline telemetry/write behavior: {$forbidden}");
    }
}

echo "Public asset privacy regression passed.\n";
