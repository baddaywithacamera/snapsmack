<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

require_once dirname(__DIR__) . '/core/trusted-html.php';

$trusted = snapsmack_trusted_html('<p onclick="steal()">Hello <strong>world</strong><script>alert(1)</script>'
    . '<a href="javascript:alert(2)" style="color:red">bad</a><img src="/media/x.jpg" data-lightbox-src="https://example.test/media/full.jpg" onerror="steal()"></p>');
if (!$trusted instanceof SnapTrustedHtml) throw new RuntimeException('Sanitizer did not return the opaque trusted type.');
$html = (string)$trusted;
foreach (['<script', 'onclick', 'onerror', 'javascript:', 'style='] as $unsafe) {
    if (stripos($html, $unsafe) !== false) throw new RuntimeException("Trusted HTML retained {$unsafe}");
}
foreach (['<p>', '<strong>world</strong>', 'src="/media/x.jpg"', 'data-lightbox-src="https://example.test/media/full.jpg"'] as $safe) {
    if (!str_contains($html, $safe)) throw new RuntimeException("Trusted HTML lost safe markup: {$safe}");
}
$unsafeLightbox = (string)snapsmack_trusted_html('<img src="/media/x.jpg" data-lightbox-src="javascript:alert(3)">');
if (str_contains($unsafeLightbox, 'data-lightbox-src')) {
    throw new RuntimeException('Trusted HTML retained an unsafe lightbox URL.');
}
$reflection = new ReflectionClass(SnapTrustedHtml::class);
if (!$reflection->getConstructor()?->isPrivate()) throw new RuntimeException('Trusted HTML constructor is public.');

require_once dirname(__DIR__) . '/core/skin-security-policy.php';
$tmp = tempnam(sys_get_temp_dir(), 'trusted-html-policy-');
try {
    file_put_contents($tmp, "<?php defined('SNAPSMACK_SKIN_RENDER') || exit; echo SnapTrustedHtml::__snapsmackCmsOnly('<b>x</b>');");
    if (snapsmack_skin_policy_scan_template_v2($tmp, 'fixture.php') === []) {
        throw new RuntimeException('Template grammar can manufacture trusted HTML.');
    }
} finally {
    if (is_file($tmp)) unlink($tmp);
}
echo "CMS trusted-HTML boundary regression passed.\n";
// ===== SNAPSMACK EOF =====
