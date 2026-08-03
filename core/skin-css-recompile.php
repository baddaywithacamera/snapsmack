<?php
/**
 * SNAPSMACK — Isolated public skin-CSS recompiler.
 *
 * Regenerates the `/* SKIN_START *​/ … /* SKIN_END *​/` block inside the
 * `custom_css_public` settings blob from the CURRENT database state + the active
 * skin's manifest, then flushes the page cache. Also rebuilds the Google-Font
 * injection blob for any active font-family selections.
 *
 * WHY THIS EXISTS (read before touching):
 *   The canonical compile lives inline in smack-skin.php's save handler
 *   (~lines 516-658). Admin pages OTHER than "Smooth Your Skin" (e.g. the Solo
 *   Image Appearance panel) can now own a subset of a skin's controls via the
 *   per-option `admin_page` key. When such a page saves, the values land in
 *   snap_settings but the compiled CSS blob is stale until the next Smooth-Your-
 *   Skin save — so the controls would silently do nothing.
 *
 *   This function is that page's OWN copy of the compile. It is deliberately a
 *   faithful DUPLICATE of smack-skin.php's logic, NOT a shared extraction:
 *   smack-skin.php — the primary, most-exercised editing path — is left
 *   completely untouched, so a bug here can never break the main skin compile.
 *   It reads ALL manifest options (ignoring admin_page, exactly like the
 *   canonical compile) so it regenerates the entire SKIN block consistently no
 *   matter which page triggered the save.
 *
 *   If you change the compile in smack-skin.php, mirror it here (and vice-versa).
 *   Unifying the two into one shared function is a fine future cleanup — it was
 *   consciously deferred to keep the primary path's risk at zero.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

require_once __DIR__ . '/skin-manifest.php';   // load_skin_manifest()
require_once __DIR__ . '/skin-settings.php';   // snapsmack_apply_skin_settings()

/**
 * Recompile the public skin CSS block + font injection for $active_skin from the
 * current snap_settings state. Safe to call after any admin page saves skin
 * option values. No-op-safe: bails quietly if the skin/manifest can't load.
 */
function snapsmack_recompile_public_skin_css(PDO $pdo, string $active_skin): void
{
    $active_skin = trim($active_skin);
    if ($active_skin === '' || !function_exists('load_skin_manifest')) {
        return;
    }

    $manifest = load_skin_manifest($active_skin);
    if (!is_array($manifest) || empty($manifest['options'])) {
        return;
    }

    // Fresh settings snapshot (includes the values the calling page just saved),
    // with the skin's own defaults layered in the same way the front end sees them.
    $all_settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")
                        ->fetchAll(PDO::FETCH_KEY_PAIR);
    if (function_exists('snapsmack_apply_skin_settings')) {
        snapsmack_apply_skin_settings($all_settings, $active_skin);
    }

    $global_inventory = (function () { return include __DIR__ . '/manifest-inventory.php'; })();
    if (!is_array($global_inventory)) $global_inventory = [];

    // Engine resolution (mirrors smack-skin.php §3).
    $required_engines = $manifest['require_scripts'] ?? [];
    $resolved_engines = [];
    foreach ($required_engines as $engine_key) {
        if (isset($global_inventory['scripts'][$engine_key])) {
            $resolved_engines[$engine_key] = $global_inventory['scripts'][$engine_key];
        }
    }

    // --- Public CSS compilation (mirrors smack-skin.php §4c) ---
    $generated_public = "/* SKIN_START */\n";

    foreach ($manifest['options'] as $key => $meta) {
        $val  = ($all_settings[$key] ?? '') !== '' ? $all_settings[$key] : ($meta['default'] ?? '');
        $prop = $meta['property'] ?? '';

        if ($prop === '')                    continue; // handled in PHP, not CSS
        if ($val === '')                     continue; // empty → skin style.css fallback wins
        if (strpos($prop, 'data-') === 0)    continue; // data-attrs read by JS, not CSS

        if (strpos($prop, 'custom-') === 0) {
            if ($meta['type'] === 'select' && isset($meta['options'][$val]['css'])) {
                $generated_public .= "{$meta['selector']} {$meta['options'][$val]['css']}\n";
            }
            continue;
        }

        if ($meta['type'] === 'select' && isset($meta['options'][$val]['css'])) {
            $generated_public .= "{$meta['selector']} {$meta['options'][$val]['css']}\n";
        } elseif ($prop === 'font-family') {
            $fallback = 'sans-serif';
            if (stripos($val, 'DotMatrix') !== false || stripos($val, 'Mono') !== false
                || stripos($val, 'Courier') !== false || stripos($val, 'Tiny5') !== false
                || stripos($val, 'Anonymous') !== false) {
                $fallback = "'Courier New', monospace";
            }
            $generated_public .= "{$meta['selector']} { font-family: \"{$val}\", {$fallback}; }\n";
            if (!empty($meta['selector']) && empty($meta['no_size_slider'])) {
                $sz_key = $key . '_size';
                $sz_val = ($all_settings[$sz_key] ?? '') !== '' ? $all_settings[$sz_key] : ($meta['size']['default'] ?? '1.0');
                $generated_public .= "{$meta['selector']} { font-size: {$sz_val}rem; }\n";
            }
        } elseif ($meta['type'] === 'range' || $meta['type'] === 'number' || $meta['type'] === 'range_numeric') {
            if (isset($meta['unit'])) {
                $unit = $meta['unit'];
            } else {
                $unit = (substr($prop, 0, 2) === '--') ? '' : 'px';
            }
            $props = array_map('trim', explode(',', $prop));
            $declarations = [];
            foreach ($props as $p) {
                $declarations[] = "{$p}: {$val}{$unit}";
            }
            $generated_public .= "{$meta['selector']} { " . implode('; ', $declarations) . "; }\n";
        } else {
            $props = array_map('trim', explode(',', $prop));
            $declarations = [];
            foreach ($props as $p) {
                $declarations[] = "{$p}: {$val}";
            }
            $generated_public .= "{$meta['selector']} { " . implode('; ', $declarations) . "; }\n";
        }
    }

    // Engine-specific CSS variables (mirrors smack-skin.php).
    foreach ($resolved_engines as $engine_key => $engine) {
        if (!empty($engine['controls'])) {
            $generated_public .= "/* ENGINE: {$engine_key} */\n";
            foreach ($engine['controls'] as $ctrl_key => $ctrl) {
                $val = ($all_settings[$ctrl_key] ?? '') !== '' ? $all_settings[$ctrl_key] : ($ctrl['default'] ?? '');
                if ($engine_key === 'smack-glitch') {
                    if ($ctrl_key === 'glitch_enabled') {
                        $generated_public .= ".post-image { --glitch-enabled: {$val}; }\n";
                    } elseif ($ctrl_key === 'glitch_intensity') {
                        $generated_public .= ".post-image { --glitch-intensity: {$val}px; }\n";
                    } elseif ($ctrl_key === 'glitch_speed') {
                        $generated_public .= ".post-image { --glitch-ms: {$val}ms; }\n";
                    }
                }
            }
        }
    }

    $generated_public .= "/* SKIN_END */";

    // Surgical update: replace only the SKIN block within the public CSS blob.
    $existing_blob = $all_settings['custom_css_public'] ?? '';
    $skin_pattern  = '/\/\* SKIN_START \*\/.*?\/\* SKIN_END \*\//s';
    $final_public  = preg_match($skin_pattern, $existing_blob)
        ? preg_replace($skin_pattern, $generated_public, $existing_blob)
        : $generated_public . "\n\n" . trim($existing_blob);

    $pdo->prepare("REPLACE INTO snap_settings (setting_key, setting_val) VALUES ('custom_css_public', ?)")
        ->execute([$final_public]);

    // Google-Font CDN links for any active font-family selections (mirrors §4d-i).
    $google_catalog = $global_inventory['fonts'] ?? [];
    if (!empty($google_catalog)) {
        $google_needed = [];
        foreach ($manifest['options'] as $opt_key => $opt_meta) {
            if (($opt_meta['property'] ?? '') === 'font-family' || !empty($opt_meta['is_font'])) {
                $active_val = ($all_settings[$opt_key] ?? '') !== '' ? $all_settings[$opt_key] : ($opt_meta['default'] ?? '');
                if ($active_val !== '' && isset($google_catalog[$active_val])) {
                    $google_needed[$active_val] = true;
                }
            }
        }
        $injection = '';
        if (!empty($google_needed)) {
            $families = [];
            foreach (array_keys($google_needed) as $fam) {
                $families[] = str_replace(' ', '+', $fam) . ':wght@400;700';
            }
            $gf_url = 'https://fonts.googleapis.com/css2?' . implode('&', array_map(fn($f) => "family={$f}", $families)) . '&display=swap';
            $injection .= '<link rel="stylesheet" href="' . htmlspecialchars($gf_url) . '">' . "\n";
        }
        // Only rewrite the font-injection blob when THIS skin actually declares
        // fonts, so we never stomp another skin's injection with an empty string.
        $pdo->prepare("REPLACE INTO snap_settings (setting_key, setting_val) VALUES ('footer_injection_scripts', ?)")
            ->execute([$injection]);
    }

    // Flush the page cache so the change is visible immediately (mirrors §4b-i).
    $pc = __DIR__ . '/page-cache.php';
    if (is_file($pc)) {
        require_once $pc;
        if (function_exists('page_cache_purge_all')) {
            page_cache_purge_all();
        }
    }
}
// ===== SNAPSMACK EOF =====
