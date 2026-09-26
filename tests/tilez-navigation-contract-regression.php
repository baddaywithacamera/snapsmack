<?php
/**
 * Regression: TILEZ keeps its menu and adds SCROLL's round quick links.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
$header = file_get_contents(__DIR__ . '/../skins/tilez/skin-header.php');
$css = file_get_contents(__DIR__ . '/../skins/tilez/style.css');

$checks = [
    'desktop menu remains rendered' => str_contains($header, '<ul class="main-menu">'),
    'round quick navigation remains rendered' => str_contains($header, 'class="tilez-icon-nav"'),
    'SCROLL home icon is present' => str_contains($header, 'M3 11.5 12 4l9 7.5'),
    'SCROLL about icon is present' => str_contains($header, 'title="About"'),
    'SCROLL blogroll icon is present' => str_contains($header, 'title="Blogroll"'),
    'SCROLL search control is present' => str_contains($header, 'class="tilez-nav-search"'),
    'desktop menu is not forcibly hidden' => !str_contains($css, '.navigation .main-menu,.tilez-icon-nav { display:none !important; }'),
    'labelled replacement menu is absent' => !str_contains($header, 'nav-toggle-label'),
];

$failed = array_keys(array_filter($checks, static fn($ok) => !$ok));
if ($failed) {
    fwrite(STDERR, 'FAIL: ' . implode('; ', $failed) . PHP_EOL);
    exit(1);
}
echo "OK: TILEZ retains its menu and adds SCROLL quick-navigation icons." . PHP_EOL;

// ===== SNAPSMACK EOF =====
