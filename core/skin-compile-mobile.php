<?php
/**
 * SNAPSMACK — Mobile skin CSS compiler (Photogram)
 *
 * The mobile-only skin is NEVER the active skin, so the smack-skin.php
 * save-time compiler (which only compiles the active skin's options into
 * custom_css_public) never runs for it — its option CSS (tile aspect,
 * borders, colours…) historically never reached the public site AT ALL
 * (the fauxlaroid 823:1000 bug). This module compiles the mobile skin's
 * saved options into the `custom_css_mobile` setting, which core/meta.php
 * emits only when the mobile skin is serving.
 *
 * Option→CSS mapping mirrors smack-skin.php's compiler (4c) minus the
 * engine controls (Photogram has none). If a new option TYPE is added to
 * the main compiler, mirror it here.
 *
 * Compile triggers:
 *   - updater self-heal after a mobile skin install/update (core/updater.php)
 *   - Maintenance FORCE MOBILE SKIN UPDATE (smack-maintenance.php)
 *   - saving the mobile skin's settings in smack-skin.php
 *   - render-time self-heal in core/meta.php when the version stamp is stale
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

/**
 * Compile the mobile skin's options into the custom_css_mobile setting.
 * Values are read from the skin's SCOPED settings (photogram__<key>) —
 * the mobile skin is never active, so it has no unprefixed values.
 *
 * @return bool true when a CSS blob was compiled + stored.
 */
function snapsmack_compile_mobile_css(PDO $pdo): bool
{
    if (!defined('SNAPSMACK_MOBILE_SKIN') || SNAPSMACK_MOBILE_SKIN === '') return false;
    $slug    = SNAPSMACK_MOBILE_SKIN;
    $mf_path = dirname(__DIR__) . '/skins/' . $slug . '/manifest.json';
    if (!is_file($mf_path)) return false;
    $manifest = snapsmack_load_manifest($mf_path);
    if (!is_array($manifest) || empty($manifest['options']) || !is_array($manifest['options'])) return false;

    $all = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")
               ->fetchAll(PDO::FETCH_KEY_PAIR);
    $prefix = $slug . '__';
    $scoped = [];
    foreach ($all as $k => $v) {
        if (strpos($k, $prefix) === 0) $scoped[substr($k, strlen($prefix))] = $v;
    }

    $css = "/* MOBILE_SKIN_START {$slug} */\n";

    foreach ($manifest['options'] as $key => $meta) {
        $val  = ($scoped[$key] ?? '') !== '' ? $scoped[$key] : ($meta['default'] ?? '');
        $prop = $meta['property'] ?? '';

        if ($prop === '') continue;                       // PHP-handled option
        if ($val === '') continue;                        // no value, keep style.css fallback
        if (strpos($prop, 'data-') === 0) continue;       // JS-engine data attribute

        // Custom payload selects — emit the option's css block verbatim.
        if (strpos($prop, 'custom-') === 0) {
            if (($meta['type'] ?? '') === 'select' && isset($meta['options'][$val]['css'])) {
                $css .= "{$meta['selector']} {$meta['options'][$val]['css']}\n";
            }
            continue;
        }

        if (($meta['type'] ?? '') === 'select' && isset($meta['options'][$val]['css'])) {
            $css .= "{$meta['selector']} {$meta['options'][$val]['css']}\n";
        } elseif ($prop === 'font-family') {
            $fallback = 'sans-serif';
            if (stripos($val, 'DotMatrix') !== false || stripos($val, 'Mono') !== false
                || stripos($val, 'Courier') !== false || stripos($val, 'Tiny5') !== false
                || stripos($val, 'Anonymous') !== false) {
                $fallback = "'Courier New', monospace";
            }
            $css .= "{$meta['selector']} { font-family: \"{$val}\", {$fallback}; }\n";
            if (!empty($meta['selector'])) {
                $sz_key = $key . '_size';
                $sz_val = ($scoped[$sz_key] ?? '') !== '' ? $scoped[$sz_key] : ($meta['size']['default'] ?? '1.0');
                $css .= "{$meta['selector']} { font-size: {$sz_val}rem; }\n";
            }
        } elseif (in_array($meta['type'] ?? '', ['range', 'number', 'range_numeric'], true)) {
            if (isset($meta['unit'])) {
                $unit = $meta['unit'];
            } else {
                $unit = (substr($prop, 0, 2) === '--') ? '' : 'px';
            }
            $props = array_map('trim', explode(',', $prop));
            $declarations = [];
            foreach ($props as $p) $declarations[] = "{$p}: {$val}{$unit}";
            $css .= "{$meta['selector']} { " . implode('; ', $declarations) . "; }\n";
        } else {
            $props = array_map('trim', explode(',', $prop));
            $declarations = [];
            foreach ($props as $p) $declarations[] = "{$p}: {$val}";
            $css .= "{$meta['selector']} { " . implode('; ', $declarations) . "; }\n";
        }
    }

    $css .= "/* MOBILE_SKIN_END */";

    // Version stamp drives the render-time self-heal: a skin update changes
    // the stamp target, meta.php notices the mismatch and recompiles.
    $stamp = $slug . '@' . (string)($manifest['version'] ?? '0');
    $up = $pdo->prepare(
        "INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)"
    );
    $up->execute(['custom_css_mobile', $css]);
    $up->execute(['custom_css_mobile_stamp', $stamp]);
    return true;
}

/**
 * The stamp custom_css_mobile SHOULD carry for the currently installed
 * mobile skin — '' when no mobile skin/manifest exists.
 */
function snapsmack_mobile_css_target_stamp(): string
{
    if (!defined('SNAPSMACK_MOBILE_SKIN') || SNAPSMACK_MOBILE_SKIN === '') return '';
    $mf_path = dirname(__DIR__) . '/skins/' . SNAPSMACK_MOBILE_SKIN . '/manifest.json';
    if (!is_file($mf_path)) return '';
    $manifest = snapsmack_load_manifest($mf_path);
    return SNAPSMACK_MOBILE_SKIN . '@' . (string)(is_array($manifest) ? ($manifest['version'] ?? '0') : '0');
}
// ===== SNAPSMACK EOF =====
