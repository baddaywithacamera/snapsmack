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
if (!str_contains($layout, 'class="ic-scrim"')) throw new RuntimeException('Scrim layer is absent from the owned layout.');
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
