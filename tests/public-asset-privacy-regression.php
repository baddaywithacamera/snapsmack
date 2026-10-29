<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$thomas = (string)file_get_contents($root . '/assets/js/ss-engine-thomas.js');
$fontPreview = (string)file_get_contents($root . '/assets/js/ss-engine-font-preview.js');
$fontLoader = (string)file_get_contents($root . '/core/font-loader.php');
$footer = (string)file_get_contents($root . '/core/footer-scripts.php');

// TELEMETRY: still absolutely none. These two engines may not call out at all.
foreach ([$thomas, $fontPreview] as $source) {
    if (preg_match('~https?://~i', $source)) {
        throw new RuntimeException('A public presentation asset still initiates a remote request.');
    }
}

// FONTS: the owner's decision, recorded rather than assumed.
//
// 576fc6aa, "Remove public telemetry and remote font loads" (29 September),
// deleted the Google stylesheet from core/font-loader.php inside a TELEMETRY
// cleanup and added a blanket ban on any remote URL in that file. Nobody asked
// for the font half. Sean, 29 October: "i wanted them to be pulled down from
// google and not hosted by me... the asset repository was only intended for the
// handful not available from Google. Why we download and cache Google fonts is
// beyond me. I never asked for it nor would I."
//
// Only three of the ninety-nine catalogue families were ever self-hosted, so
// that blanket ban took the other ninety-six off every public page while the
// picker kept offering them and the admin kept previewing them from Google.
//
// The rule is now specific instead of blanket: public font delivery may reach
// Google's two font hosts and NOTHING else. A blanket ban is what hid a
// product decision inside a privacy commit; an allowlist states it.
$fontSources = [
    'core/font-loader.php' => $fontLoader,
    'core/skin-presentation.php' => (string)file_get_contents($root . '/core/skin-presentation.php'),
];
$permittedFontHosts = ['fonts.googleapis.com', 'fonts.gstatic.com'];
foreach ($fontSources as $label => $source) {
    preg_match_all('~https?://([a-z0-9.-]+)~i', $source, $m);
    foreach (array_unique($m[1] ?? []) as $host) {
        if (!in_array(strtolower($host), $permittedFontHosts, true)) {
            throw new RuntimeException("Public font delivery reaches an unapproved host in {$label}: {$host}");
        }
    }
}
foreach (['thomas_uid', 'window.ssUid', 'INSERT INTO', '<script>'] as $forbidden) {
    if (str_contains($footer, $forbidden)) {
        throw new RuntimeException("Public footer reintroduced inline telemetry/write behavior: {$forbidden}");
    }
}

// AND THE POLICY MUST ACTUALLY PERMIT THEM.
//
// 79233b91 emitted a correct Google stylesheet link and the page still loaded
// ZERO faces, because snapsmack_public_csp() said style-src 'self' and
// font-src 'self' data:. Every source-reading test passed. Codex caught it
// with a real browser against the real header. A link the policy forbids is
// not font delivery, so the policy is asserted here beside the markup.
require_once $root . '/core/trusted-html.php';
require_once $root . '/core/custom-code-policy.php';
$csp = snapsmack_public_csp(false);
foreach (['style-src' => 'https://fonts.googleapis.com', 'font-src' => 'https://fonts.gstatic.com'] as $directive => $host) {
    if (!preg_match('~' . preg_quote($directive, '~') . ' ([^;]*)~', $csp, $m) || !str_contains($m[1], $host)) {
        throw new RuntimeException("Public CSP no longer permits {$host} in {$directive}: the stylesheet is blocked and no face loads.");
    }
}
// And nothing WIDER crept in alongside them.
foreach (['style-src', 'font-src'] as $directive) {
    preg_match('~' . preg_quote($directive, '~') . ' ([^;]*)~', $csp, $m);
    preg_match_all('~https?://([a-z0-9.*-]+)~i', $m[1] ?? '', $hosts);
    foreach (array_unique($hosts[1] ?? []) as $host) {
        if (!in_array(strtolower($host), $permittedFontHosts, true)) {
            throw new RuntimeException("Public CSP {$directive} widened to an unapproved host: {$host}");
        }
    }
}

echo "Public asset privacy regression passed.\n";
