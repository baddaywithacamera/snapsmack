<?php
declare(strict_types=1);

// SNAPSMACK_EOF_HEADER
// Last non-empty line must be: // ===== SNAPSMACK EOF =====

/**
 * Show where a skin setting's value comes from, and where it stops.
 *
 *     php tools/skin-setting-trace.php                       # the active skin
 *     php tools/skin-setting-trace.php 50-shades-of-noah-grey
 *     php tools/skin-setting-trace.php 50-shades-of-noah-grey static_body_font
 *
 * Why this file exists. A skin control can look saved in Skin Admin and still
 * arrive on the page as the manifest default, and nothing in between says so.
 * Every option travels the same four steps and each one can drop it:
 *
 *   1. the scoped row   <skin>__<key>    written by Skin Admin on save
 *   2. the bare row     <key>            a pre-scoping leftover, or a global
 *   3. the overlay      snapsmack_apply_skin_settings() picks one, or the
 *                       manifest default when there is no scoped row
 *   4. the page         snapsmack_declared_skin_options() validates the value
 *                       against the manifest and falls back to the default
 *                       when it does not match
 *
 * Reading rows and printing them is all this does. It writes nothing, changes
 * nothing, and needs no arguments to be useful. Run it on the site's own box.
 */

require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/skin-settings.php';
require_once __DIR__ . '/../core/skin-presentation.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$settings = $pdo->query('SELECT setting_key, setting_val FROM snap_settings')
                ->fetchAll(PDO::FETCH_KEY_PAIR);

$slug = $argv[1] ?? (string)($settings['active_skin'] ?? '');
$slug = preg_replace('/[^a-z0-9-]/', '', strtolower($slug));
$only = $argv[2] ?? '';

$path = dirname(__DIR__) . '/skins/' . $slug . '/manifest.json';
if ($slug === '' || !is_file($path)) {
    fwrite(STDERR, "No manifest for skin '" . $slug . "'.\n");
    exit(1);
}
$manifest = json_decode((string)file_get_contents($path), true);
$options  = is_array($manifest['options'] ?? null) ? $manifest['options'] : [];

$raw = $settings;                                    // straight from the table
$overlaid = $settings;
snapsmack_apply_skin_settings($overlaid, $slug);     // step 3
$resolved = snapsmack_declared_skin_options($overlaid, $slug); // step 4

printf("Skin: %s (manifest version %s)\n", $slug, (string)($manifest['version'] ?? '?'));
printf("Active skin in the database: %s\n\n", (string)($settings['active_skin'] ?? '(none)'));

$lost = [];
$printed = 0;
foreach ($options as $key => $meta) {
    if (!is_array($meta)) continue;
    if ($only !== '' && $key !== $only) continue;

    $scoped  = array_key_exists($slug . '__' . $key, $raw) ? (string)$raw[$slug . '__' . $key] : null;
    $bare    = array_key_exists($key, $raw) ? (string)$raw[$key] : null;
    $default = array_key_exists('default', $meta) ? (string)$meta['default'] : '';
    $page    = array_key_exists($key, $resolved) ? (string)$resolved[$key] : '(not sent to the page)';

    // The interesting case: a value was stored somewhere and the page still
    // shows the default. That is the shape of a control that looks saved.
    $stored = $scoped ?? $bare;
    $interesting = $stored !== null && $stored !== '' && $page === $default && $stored !== $default;
    if ($interesting) $lost[] = $key;

    if ($only === '' && !$interesting) continue;
    $printed++;
    printf("%s\n", $key);
    printf("    scoped row  %s__%s = %s\n", $slug, $key, $scoped === null ? '(no row)' : "'" . $scoped . "'");
    printf("    bare row    %s = %s\n", $key, $bare === null ? '(no row)' : "'" . $bare . "'");
    printf("    manifest default            = '%s'\n", $default);
    printf("    what the page actually gets = '%s'%s\n\n", $page,
        $interesting ? '   <-- the stored value never arrived' : '');
}

// A font control also carries a size slider named <key>_size. The slider is
// written on save like any other control, but nothing reads it unless the
// manifest declares it as an option of its own, so the size silently stays at
// whatever the stylesheet says.
$sizeless = [];
foreach ($options as $key => $meta) {
    if (!is_array($meta)) continue;
    $isFont = (($meta['property'] ?? '') === 'font-family') || !empty($meta['is_font']);
    if (!$isFont || !empty($meta['no_size_slider'])) continue;
    $sizeKey = (string)($meta['sz_key_override'] ?? ($key . '_size'));
    if (!array_key_exists($sizeKey, $options)) {
        $stored = $raw[$slug . '__' . $sizeKey] ?? $raw[$sizeKey] ?? null;
        $sizeless[] = $sizeKey . ($stored === null ? ' (never set)' : " (you set '" . $stored . "')");
    }
}

if ($only === '') {
    if ($printed === 0) {
        print("Every stored value reaches the page. Nothing is being dropped.\n\n");
    } else {
        printf("%d control%s stored a value the page never receives: %s\n\n",
            count($lost), count($lost) === 1 ? '' : 's', implode(', ', $lost));
    }
    if ($sizeless) {
        print("Font size sliders this skin shows but nothing reads:\n");
        foreach ($sizeless as $s) print('    ' . $s . "\n");
        print("  The slider saves. No code reads it, because the manifest does not\n");
        print("  declare it as an option, so the size stays as the stylesheet set it.\n\n");
    }
}
exit(0);
// ===== SNAPSMACK EOF =====
