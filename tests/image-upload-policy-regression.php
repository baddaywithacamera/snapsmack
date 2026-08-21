<?php
/** SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment. */
require_once __DIR__ . '/../core/image-upload-policy.php';
require_once __DIR__ . '/../core/svg-sanitizer.php';

$failures = [];
function policy_png(string $path, int $width, int $height): void {
    $signature = "\x89PNG\r\n\x1a\n";
    $ihdr = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);
    $chunk = pack('N', strlen($ihdr)) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr));
    file_put_contents($path, $signature . $chunk);
}

$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'snapsmack-policy-' . bin2hex(random_bytes(5));
mkdir($dir, 0700, true);
policy_png($dir . '/landscape.png', 3840, 2160);
policy_png($dir . '/portrait.png', 2160, 3840);
policy_png($dir . '/too-wide.png', 3841, 2160);
policy_png($dir . '/too-square.png', 3000, 3000);

foreach (['landscape.png', 'portrait.png'] as $name) {
    if (!snapsmack_local_image_within_4k($dir . '/' . $name, $error)) $failures[] = "$name should pass: $error";
}
foreach (['too-wide.png', 'too-square.png'] as $name) {
    if (snapsmack_local_image_within_4k($dir . '/' . $name, $error)) $failures[] = "$name should fail";
}

$safe_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><path fill="#000" d="M0 0h10v10z"/></svg>';
$bad_svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
file_put_contents($dir . '/safe.svg', $safe_svg);
file_put_contents($dir . '/bad.svg', $bad_svg);
if (snapsmack_sanitize_branding_svg($dir . '/safe.svg', $error) === null) $failures[] = "Safe SVG rejected: $error";
if (snapsmack_sanitize_branding_svg($dir . '/bad.svg', $error) !== null) $failures[] = 'Script-bearing SVG accepted';

foreach (glob($dir . '/*') as $file) unlink($file);
rmdir($dir);
if ($failures) {
    fwrite(STDERR, "FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "PASS: 4K image ceiling and SVG sanitizer policy.\n";

// ===== SNAPSMACK EOF =====
