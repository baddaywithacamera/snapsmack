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
    if (!str_contains($style, 'var(--nav-dropdown-bg) ' . $opacity . '%,transparent')) {
        throw new RuntimeException("Dropdown opacity control failed at {$opacity}.");
    }
}

$layout = (string)file_get_contents(dirname(__DIR__) . '/skins/instant-camera/layout.php');
$css = (string)file_get_contents(dirname(__DIR__) . '/skins/instant-camera/style.css');
if (!str_contains($layout, 'class="ic-scrim"')) throw new RuntimeException('Scrim layer is absent from the owned layout.');
if (!preg_match('/\.tg-sticky-nav,\s*\.tg-sticky-nav\.profile-hidden\s*\{\s*background:\s*var\(--ic-nav-bg/s', $css)) {
    throw new RuntimeException('Navbar opacity is not applied both before and after sticky activation.');
}

echo "Instant Camera opacity regression: PASS\n";
