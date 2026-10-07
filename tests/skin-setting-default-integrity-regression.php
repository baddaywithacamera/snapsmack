<?php
require_once dirname(__DIR__) . '/core/skin-settings.php';

$fail = static function (string $message): void {
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
};

// A legacy bare value can belong to another skin.  GALLERIA must recover its
// own declared default until it has an explicitly scoped choice.
$settings = [
    'active_skin' => 'galleria',
    'htbs_title_font' => 'Libre Baskerville',
];
snapsmack_apply_skin_settings($settings, 'galleria');
if (($settings['htbs_title_font'] ?? null) !== 'Georgia') {
    $fail('A legacy bare font leaked across the GALLERIA skin boundary.');
}

// An unrelated explicit skin-scoped choice remains authoritative.
$settings['galleria__htbs_title_font'] = 'Playfair Display';
snapsmack_apply_skin_settings($settings, 'galleria');
if (($settings['htbs_title_font'] ?? null) !== 'Playfair Display') {
    $fail('An explicit GALLERIA font choice was not preserved.');
}

$galleriaManifest = json_decode(
    file_get_contents(dirname(__DIR__) . '/skins/galleria/manifest.json'),
    true
);
if (($galleriaManifest['options']['header_text_transform']['default'] ?? null) !== 'none') {
    $fail('GALLERIA must preserve the entered site-title case by default.');
}

// The scoped values written by the broken cross-skin picker are not genuine
// customizations.  Exact known leak combinations recover the skin defaults,
// while unrelated explicit choices above remain authoritative.
$leaked = [
    'galleria__htbs_title_font' => 'Libre Baskerville',
];
snapsmack_apply_skin_settings($leaked, 'galleria');
if (($leaked['htbs_title_font'] ?? null) !== 'Georgia') {
    $fail('Known scoped GALLERIA masthead-font leak did not recover Georgia.');
}

$grit = ['true-grit__header_font_family' => 'Playfair Display'];
snapsmack_apply_skin_settings($grit, 'true-grit');
if (($grit['header_font_family'] ?? null) !== 'Raleway') {
    $fail('Known TRUE GRIT title-font leak did not recover Raleway.');
}

// Global-only settings are never replaced by a manifest option/default pass.
if (($settings['active_skin'] ?? null) !== 'galleria') {
    $fail('The skin settings overlay changed a global-only setting.');
}

$admin = file_get_contents(dirname(__DIR__) . '/smack-skin.php');
if (strpos($admin, "!array_key_exists((string)\$val, \$o['options'])") === false
    || strpos($admin, '(skin default)') === false) {
    $fail('The skin picker cannot represent a declared default missing from its options.');
}

echo "skin setting default integrity regression: ok\n";
