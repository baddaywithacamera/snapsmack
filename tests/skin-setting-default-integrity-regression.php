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

$squareManifest = json_decode(
    file_get_contents(dirname(__DIR__) . '/skins/hip-to-be-square/manifest.json'),
    true
);
if (($squareManifest['options']['header_text_transform']['default'] ?? null) !== 'none') {
    $fail('SQUARED STRAIGHT must preserve the entered site-title case by default.');
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

// JIVE TURKEY's original settings are unambiguously skin-owned by their jt_
// prefix. The scoping migration must not replace a saved white solo surface
// (or its related scrim/card controls) with the black manifest defaults.
$jive = [
    'jt_post_bg_color' => '#ffffff',
    'jt_solo_scrim_color' => '#ffffff',
    'jt_solo_scrim_opacity' => '0',
    'jt_solo_card_color' => '#ffffff',
];
snapsmack_apply_skin_settings($jive, 'jive-turkey');
foreach (['jt_post_bg_color', 'jt_solo_scrim_color', 'jt_solo_scrim_opacity', 'jt_solo_card_color'] as $key) {
    if (($jive[$key] ?? null) !== ([
        'jt_post_bg_color' => '#ffffff',
        'jt_solo_scrim_color' => '#ffffff',
        'jt_solo_scrim_opacity' => '0',
        'jt_solo_card_color' => '#ffffff',
    ][$key])) {
        $fail('JIVE TURKEY lost a pre-scope skin-owned presentation value: ' . $key);
    }
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

// Saving must not depend on the submit button being included in the request.
// Keyboard submission, browser accessibility paths, and assisted controls can
// submit a valid form without serializing the button name.  The intent and CSRF
// proof therefore belong to the form itself.
if (strpos($admin, '<input type="hidden" name="save_skin_settings" value="1">') === false) {
    $fail('Skin Admin still gates persistence on a serialized submit button.');
}
if (strpos($admin, '<button type="submit" name="save_skin_settings"') !== false) {
    $fail('Skin Admin still duplicates save intent on the submit button.');
}
if (strpos($admin, "hash_equals(\$_SESSION['csrf_token'] ?? '', (string)\$_POST['csrf_token'])") === false) {
    $fail('Skin Admin save persistence is missing its CSRF verification.');
}

// Explicit case selections remain authoritative after the scoped settings
// overlay; the manifest default must never replace a submitted saved value.
$caseSettings = ['galleria__header_text_transform' => 'lowercase'];
snapsmack_apply_skin_settings($caseSettings, 'galleria');
if (($caseSettings['header_text_transform'] ?? null) !== 'lowercase') {
    $fail('GALLERIA lost an explicit lowercase site-title choice on reload.');
}

echo "skin setting default integrity regression: ok\n";
