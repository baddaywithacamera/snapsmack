<?php
// SNAPSMACK_EOF_HEADER: last non-empty line is the canonical EOF marker.
declare(strict_types=1);
require_once __DIR__ . '/../core/skin-presentation.php';
$manifest = json_decode(file_get_contents(__DIR__ . '/../skins/new-horizon/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
$options = snapsmack_declared_skin_options([], 'new-horizon');
$defaultCss = snapsmack_declared_skin_style('new-horizon', $options);
if (str_contains($defaultCss, '--nh-body-dark:') || str_contains($defaultCss, '--nh-heading-light:')) throw new RuntimeException('Unset overrides alter existing shades');
$checks = 1;
foreach (['heading', 'body', 'footer'] as $role) {
    foreach (['dark', 'light'] as $variant) {
        $key = 'nh_' . $role . '_colour_' . $variant;
        $changed = snapsmack_declared_skin_options([$key => '#808080'], 'new-horizon');
        $css = snapsmack_declared_skin_style('new-horizon', $changed);
        if (!str_contains($css, '--nh-' . $role . '-' . $variant . ':#808080')) throw new RuntimeException('Override missing: ' . $key);
        $reset = snapsmack_declared_skin_options([$key => $manifest['options'][$key]['default']], 'new-horizon');
        if (str_contains(snapsmack_declared_skin_style('new-horizon', $reset), '--nh-' . $role . '-' . $variant . ':')) throw new RuntimeException('Reset failed');
        $invalid = snapsmack_declared_skin_options([$key => '#fff;display:none'], 'new-horizon');
        if ($invalid[$key] !== $manifest['options'][$key]['default']) throw new RuntimeException('Invalid colour accepted');
        $checks += 3;
    }
}
foreach (['header_title_color', 'tagline_color'] as $key) {
    if ($manifest['options'][$key]['section'] !== 'TYPOGRAPHY') throw new RuntimeException('Control not in Typography');
    $checks++;
}
if (isset($argv[1])) {
    if (!is_dir($argv[1])) mkdir($argv[1], 0777, true);
    foreach (['dark','light'] as $variant) foreach (['default','grey'] as $state) {
        $settings = [];
        if ($state === 'grey') foreach (['heading','body','footer'] as $role) $settings['nh_'.$role.'_colour_'.$variant] = '#808080';
        $css = snapsmack_declared_skin_style('new-horizon', snapsmack_declared_skin_options($settings, 'new-horizon'));
        $css = file_get_contents(__DIR__.'/../skins/new-horizon/variant-'.$variant.'.css').file_get_contents(__DIR__.'/../skins/new-horizon/style.css').$css;
        file_put_contents($argv[1].'/'.$variant.'-'.$state.'.html', '<!doctype html><meta charset="utf-8"><style>'.$css.'</style><body class="static-transmission"><main class="static-content"><h1 class="static-page-title">Heading</h1><p id="body">Body text</p><div class="description">Description text</div></main><footer id="system-footer"><div class="inside"><p id="sig-text">Footer text</p></div></footer>');
    }
}
echo "NH colour options: $checks checks PASS. Admin save/reload and installed acceptance NOT CHECKED.\n";
// ===== SNAPSMACK EOF =====
