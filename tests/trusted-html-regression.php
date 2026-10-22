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
$styledParagraph = (string)snapsmack_trusted_html(
    '<p class="pfd-wordmark">PHOTOFRI<span class="pfd-dot">.</span>DAY</p>'
    . '<p class="pfd-tagline">The Federated Weekly Photo Challenge</p>'
    . '<p class="pfd-lede">A weekly photo challenge.</p>'
    . '<p class="pfd-label">Participate through the Fediverse</p>'
);
foreach (['pfd-wordmark', 'pfd-tagline', 'pfd-lede', 'pfd-label', 'pfd-dot'] as $class) {
    if (!str_contains($styledParagraph, 'class="' . $class . '"')) {
        throw new RuntimeException("Trusted static-page paragraph presentation was stripped: {$class}");
    }
}
$unsafeLightbox = (string)snapsmack_trusted_html('<img src="/media/x.jpg" data-lightbox-src="javascript:alert(3)">');
if (str_contains($unsafeLightbox, 'data-lightbox-src')) {
    throw new RuntimeException('Trusted HTML retained an unsafe lightbox URL.');
}
$countdown = (string)snapsmack_trusted_html(
    '<section class="pfd-countdowns" aria-label="Challenge countdown">'
    . '<p class="pfd-countdown smack-countdown" data-until="2026-10-23T10:00:00Z" data-every="7d" data-roll-caption="Next prompt drops in" data-done="Live">'
    . '<span class="pfd-cd-caption" data-cd-caption>First prompt drops in</span>'
    . '<time class="pfd-cd-date" data-cd-date datetime="2026-10-23">2026-10-23</time>'
    . '<span class="pfd-cd-clock" data-cd-clock aria-hidden="true"><b data-cd="d">--</b></span>'
    . '</p></section>'
);
foreach (['data-until="2026-10-23T10:00:00Z"', 'data-every="7d"', 'data-roll-caption="Next prompt drops in"',
          'data-done="Live"', 'data-cd-caption', 'data-cd-date', 'data-cd-clock',
          'data-cd="d"', 'datetime="2026-10-23"', 'aria-hidden="true"', 'aria-label="Challenge countdown"'] as $safe) {
    if (!str_contains($countdown, $safe)) throw new RuntimeException("Trusted HTML lost countdown markup: {$safe}");
}
$countdownAttack = (string)snapsmack_trusted_html(
    '<p class="smack-countdown" data-until="2026-10-23T10:00:00Z" style="display:none" onclick="steal()" data-evil="yes">x</p>'
);
foreach (['style=', 'onclick=', 'data-evil='] as $unsafe) {
    if (str_contains($countdownAttack, $unsafe)) throw new RuntimeException("Trusted countdown HTML retained {$unsafe}");
}
$styledList = (string)snapsmack_trusted_html(
    '<ol class="pfd-steps" style="display:none"><li class="pfd-step" onclick="steal()">One</li></ol>'
);
foreach (['<ol class="pfd-steps">', '<li class="pfd-step">'] as $safe) {
    if (!str_contains($styledList, $safe)) throw new RuntimeException("Trusted list presentation was stripped: {$safe}");
}
foreach (['style=', 'onclick='] as $unsafe) {
    if (str_contains($styledList, $unsafe)) throw new RuntimeException("Trusted list HTML retained {$unsafe}");
}
$directory = (string)snapsmack_trusted_html(
    '<header class="pbf-directory-head"><h1>Directory</h1></header>'
    . '<form class="pbf-directory-controls" method="get" action="/page.php" onsubmit="steal()">'
    . '<input type="hidden" name="slug" value="directory"><input type="search" name="q" placeholder="Search" aria-label="Search the directory">'
    . '<button>Search</button></form>'
    . '<table class="pbf-directory-table"><thead><tr><th scope="col">Name</th></tr></thead>'
    . '<tbody><tr><th scope="row"><a href="https://example.test">Example</a><small>example.test</small></th></tr></tbody></table>'
);
foreach (['<header class="pbf-directory-head">', '<form class="pbf-directory-controls" method="get" action="/page.php">',
          '<input type="hidden" name="slug" value="directory">', '<input type="search" name="q" placeholder="Search" aria-label="Search the directory">',
          '<button type="submit">Search</button>', '<table class="pbf-directory-table">', '<thead>', '<tbody>', '<tr>',
          '<th scope="col">', '<th scope="row">', '<small>example.test</small>'] as $safe) {
    if (!str_contains($directory, $safe)) throw new RuntimeException("Trusted HTML lost directory markup: {$safe}");
}
if (str_contains($directory, 'onsubmit=')) throw new RuntimeException('Trusted directory HTML retained an event handler.');
$unsafeDirectory = (string)snapsmack_trusted_html(
    '<form method="delete" action="https://evil.test/steal"><input type="file" name="payload"><button type="reset">Go</button></form>'
);
foreach (['method="get"', 'action="/"', 'type="search"', 'type="submit"'] as $safeFallback) {
    if (!str_contains($unsafeDirectory, $safeFallback)) throw new RuntimeException("Directory sanitizer missed safe fallback: {$safeFallback}");
}
$mosaic = (string)snapsmack_trusted_html(
    '<div class="snap-mosaic" data-mosaic="[]" data-gap="4" data-emphasis="landscape" data-template="one-left" onclick="steal()"></div>'
);
foreach (['data-mosaic="[]"', 'data-gap="4"', 'data-emphasis="landscape"', 'data-template="one-left"'] as $safe) {
    if (!str_contains($mosaic, $safe)) throw new RuntimeException("Trusted HTML lost mosaic geometry: {$safe}");
}
if (str_contains($mosaic, 'onclick=')) throw new RuntimeException('Trusted mosaic HTML retained an event handler.');
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
