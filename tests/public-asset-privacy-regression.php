<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$thomas = (string)file_get_contents($root . '/assets/js/ss-engine-thomas.js');
$fontPreview = (string)file_get_contents($root . '/assets/js/ss-engine-font-preview.js');
$fontLoader = (string)file_get_contents($root . '/core/font-loader.php');
$footer = (string)file_get_contents($root . '/core/footer-scripts.php');

// TELEMETRY: one deliberate, documented exception and nothing else.
//
// Sean chose to restore the Thomas the Bear discover ping (08:16, 2026-11-08):
// a counter ping when the Easter egg is first found, carrying only a random
// per-install id and which part was triggered. projects/snapsmack-ca/tnb.php
// documents it. So the Thomas engine may reach THAT ONE receiver and no other
// remote URL; the font-preview engine may still not call out at all.
$thomasPingEndpoint = 'https://snapsmack.ca/releases/thomas-ping.php';
if (preg_match('~https?://~i', $fontPreview)) {
    throw new RuntimeException('The font-preview engine still initiates a remote request.');
}
$thomasOther = str_replace($thomasPingEndpoint, '', $thomas);
if (preg_match('~https?://~i', $thomasOther)) {
    throw new RuntimeException('The Thomas engine initiates a remote request other than the documented discover ping.');
}
// Nothing identifying may ride that ping -- only the random uid, the type, and
// the first-in-session flag. The site's URL or name must never be referenced.
foreach (['location.href', 'location.hostname', 'location.host', 'document.domain',
          'document.referrer', 'site_url', 'siteUrl', 'site_name', 'siteName'] as $ident) {
    if (stripos($thomas, $ident) !== false) {
        throw new RuntimeException("The Thomas discover ping may carry identifying data: {$ident}");
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
// The id behind that ping is RANDOM and non-identifying, and it does NOT come
// back as inline telemetry in the legacy footer. The old window.ssUid inline
// <script> is doubly wrong now -- the strict public CSP (script-src 'self')
// would block it, and strict skins never load footer-scripts.php anyway -- so
// the id rides a hidden data attribute emitted from the registered-assets
// component (core/thomas-uid.php) and the footer stays clean. Both halves are
// asserted: the footer carries no inline telemetry, and the id source is random
// and references nothing that identifies the install.
foreach (['thomas_uid', 'window.ssUid', 'INSERT INTO', '<script>'] as $forbidden) {
    if (str_contains($footer, $forbidden)) {
        throw new RuntimeException("Public footer reintroduced inline telemetry/write behavior: {$forbidden}");
    }
}
$thomasUid = (string)file_get_contents($root . '/core/thomas-uid.php');
if (!str_contains($thomasUid, 'random_bytes')) {
    throw new RuntimeException('The Thomas install id is not generated from random_bytes -- it may be identifying.');
}
foreach (['site_url', 'site_name', 'site_title', 'HTTP_HOST', 'SERVER_NAME', '$_SERVER'] as $ident) {
    if (str_contains($thomasUid, $ident)) {
        throw new RuntimeException("The Thomas install id source references identifying data: {$ident}");
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
