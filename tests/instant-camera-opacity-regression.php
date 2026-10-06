<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/core/skin-presentation.php';

foreach ([0, 50, 100] as $opacity) {
    $presentation = snapsmack_skin_presentation([
        'ic_scrim' => (string)max(10, min(90, $opacity)),
        'ic_nav_opacity' => (string)$opacity,
        'nav_dropdown_opacity' => (string)$opacity,
    ], 'instant-camera');
    $style = (string)$presentation['style'];
    $expectedScrim = number_format(max(10, min(90, $opacity)) / 100, 2, '.', '');
    if (!str_contains($style, '--ic-scrim:' . $expectedScrim)) {
        throw new RuntimeException("Scrim control failed at {$opacity}.");
    }
    $expectedNav = $opacity === 0 ? '--ic-nav-bg:transparent' : 'rgba(255,255,255,' . number_format($opacity / 100, 2, '.', '') . ')';
    if (!str_contains($style, $expectedNav)) {
        throw new RuntimeException("Navbar opacity control failed at {$opacity}.");
    }
    $expectedDropdown = 'rgba(0,0,0,' . number_format($opacity / 100, 2, '.', '') . ')';
    if (!str_contains($style, '--nav-dropdown-bg:' . $expectedDropdown)
        || !str_contains($style, 'background:var(--nav-dropdown-bg)')) {
        throw new RuntimeException("Dropdown opacity control failed at {$opacity}.");
    }
}

$layout = (string)file_get_contents(dirname(__DIR__) . '/skins/instant-camera/layout.php');
$css = (string)file_get_contents(dirname(__DIR__) . '/skins/instant-camera/style.css');
$manifest = json_decode((string)file_get_contents(dirname(__DIR__) . '/skins/instant-camera/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
if (!str_contains($layout, 'class="ic-scrim"')) throw new RuntimeException('Scrim layer is absent from the owned layout.');
if (($manifest['version'] ?? '') !== '1.0.41') throw new RuntimeException('INSTANT CAMERA release is not sequentially versioned at 1.0.41.');
if (($manifest['options']['ic_post_viewer_backdrop_opacity']['default'] ?? null) !== '0') throw new RuntimeException('Post viewer must default to the unobscured archive.');
foreach (['class="tg-post-ig-image"', 'class="tg-post-ig-info"', 'class="tg-post-ig-header"', 'class="tg-post-ig-body"', 'class="tg-post-ig-actions"'] as $legacyViewerHook) {
    if (!str_contains($layout, $legacyViewerHook)) throw new RuntimeException("Original post viewer hook is missing: {$legacyViewerHook}");
}
if (!str_contains($layout, 'data-autoopen="1"')) throw new RuntimeException('Direct photograph routes no longer reconnect to the original modal viewer.');
if (!preg_match('/\.tg-modal-frame\s*\{[^}]*height:\s*min\(92vh,\s*900px\)/s', $css)
    || !preg_match('/\.tg-post-ig\s*\{[^}]*display:\s*flex[^}]*height:\s*100dvh/s', $css)
    || !preg_match('/\.tg-post-ig-info\s*\{[^}]*flex:\s*0 0 335px/s', $css)) {
    throw new RuntimeException('Original centered print-and-sidebar viewer geometry changed.');
}
if (!preg_match('/\.ic-bg\s*\{[^}]*z-index:\s*0/s', $css)
    || !preg_match('/\.ic-scrim\s*\{[^}]*z-index:\s*1[^}]*opacity:\s*var\(--ic-scrim/s', $css)
    || !preg_match('/\.ic-panel\s*\{[^}]*z-index:\s*2/s', $css)
    || !preg_match('/\.tg-content-wrap\s*\{[^}]*z-index:\s*3/s', $css)) {
    throw new RuntimeException('Scrim is not visibly stacked between the background and content.');
}
if (!preg_match('/\.tg-sticky-nav,\s*\.tg-sticky-nav\.profile-hidden\s*\{\s*background:\s*transparent/s', $css)) {
    throw new RuntimeException('Navbar no longer shares the translucent content plane.');
}

echo "Instant Camera opacity regression: PASS\n";
