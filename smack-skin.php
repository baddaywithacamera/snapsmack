<?php
/**
 * SNAPSMACK - Skin Administration & Gallery
 *
 * Two-tab interface for managing skins:
 *
 *   CUSTOMIZE — Configures active theme-specific options and CSS generation.
 *               Manages color schemes, fonts, and other skin-level customizations.
 *
 *   GALLERY   — Browse the remote skin registry, install new skins, update
 *               existing ones, or remove skins you no longer need. Development
 *               skins and mobile-only skins are hidden from the gallery entirely.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */




require_once 'core/auth-smack.php';
require_once 'core/skin-registry.php';
require_once 'core/skin-settings.php';

// --- SETTINGS BOOTSTRAP ---
// Must load BEFORE skin discovery so active_skin is available.
if (!isset($settings)) {
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
}

// --- TAB ROUTING ---
// Determine which tab is active: 'customize' (default) or 'gallery'
$active_tab = $_GET['tab'] ?? 'customize';
if (!in_array($active_tab, ['customize', 'gallery', 'mobile'])) $active_tab = 'customize';

// Initialise here so modal-building code at the bottom of the page
// always has a valid array regardless of which tab is active.
$local_skins = [];

// --- PROTECTED SKINS (cannot be removed) ---
// No skins are hard-protected — all can be reinstalled from the gallery.
$protected_skins = [];

// --- GALLERY ACTION HANDLERS ---
// Process install/remove requests before any output is sent.
$gallery_msg = '';
$gallery_err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gallery_action'])) {

    // CSRF check: reuse session token
    if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $gallery_err = 'Invalid security token. Please reload the page.';
    } else {
        $action = $_POST['gallery_action'];
        $slug   = $_POST['skin_slug'] ?? '';
        $active = $settings['active_skin'] ?? '';

        if ($action === 'install' || $action === 'update') {
            $download_url = $_POST['download_url'] ?? '';
            $signature    = $_POST['signature'] ?? '';
            require_once __DIR__ . '/core/release-pubkey.php';
            $public_key   = defined('SNAPSMACK_RELEASE_PUBKEY') ? SNAPSMACK_RELEASE_PUBKEY : '';

            if (empty($download_url)) {
                $gallery_err = 'No download URL provided for this skin.';
            } else {
                $result = skin_registry_install($slug, $download_url, $signature, $public_key);
                if ($result['success']) {
                    skin_registry_clear_cache();
                    $gallery_msg = $result['message'];
                } else {
                    $gallery_err = $result['message'];
                }
            }
        } elseif ($action === 'remove') {
            if (in_array($slug, $protected_skins, true)) {
                $gallery_err = 'This skin is required by SnapSmack and cannot be removed.';
                $result = ['success' => false];
            } else {
                $result = skin_registry_remove($slug, $active);
            }
            if ($result['success']) {
                skin_registry_clear_cache();
                $gallery_msg = $result['message'];
            } else {
                $gallery_err = $result['message'];
            }
        } elseif ($action === 'activate') {
            // Activate an already-installed skin straight from the gallery.
            $slug     = preg_replace('/[^a-z0-9_\-]/', '', $slug);
            $skin_dir = __DIR__ . '/skins/' . $slug;
            if ($slug === '' || !is_dir($skin_dir) || !is_file($skin_dir . '/manifest.json')) {
                $gallery_err = 'That skin is not installed.';
            } else {
                $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('active_skin', ?) ON DUPLICATE KEY UPDATE setting_val = ?")
                    ->execute([$slug, $slug]);
                $gallery_msg = 'Skin "' . $slug . '" is now active.';

                // Keep site_mode in lockstep with the activated skin — same rule
                // as the Customize-tab activation: a single-mode skin whose mode
                // differs from the current site_mode switches the site to it.
                $_man   = load_skin_manifest($slug);
                $_modes = (is_array($_man) && isset($_man['modes']) && is_array($_man['modes']))
                          ? array_values($_man['modes']) : [];
                if (count($_modes) === 1 && in_array($_modes[0], ['photoblog', 'carousel', 'smacktalk'], true)) {
                    $_cur = (string)($pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key='site_mode' LIMIT 1")->fetchColumn() ?: 'photoblog');
                    if ($_modes[0] !== $_cur) {
                        $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('site_mode', ?) ON DUPLICATE KEY UPDATE setting_val = ?")
                            ->execute([$_modes[0], $_modes[0]]);
                        $_lbl = ['photoblog' => 'SmackOneOut', 'carousel' => 'GramOfSmack', 'smacktalk' => 'SmackTalk'];
                        $gallery_msg .= ' Site mode switched to ' . ($_lbl[$_modes[0]] ?? $_modes[0]) . ' to match it.';
                    }
                }

                // Flush the page cache so the new skin shows immediately.
                if (is_file(__DIR__ . '/core/page-cache.php')) {
                    require_once __DIR__ . '/core/page-cache.php';
                    if (function_exists('page_cache_purge_all')) page_cache_purge_all();
                }
            }
        }
    }

    // Redirect to avoid form resubmission
    if (!empty($gallery_msg)) {
        $_SESSION['gallery_flash'] = $gallery_msg;
        header("Location: smack-skin.php?tab=gallery");
        exit;
    }
}

// Pick up flash messages from redirects
if (isset($_SESSION['gallery_flash'])) {
    $gallery_msg = $_SESSION['gallery_flash'];
    unset($_SESSION['gallery_flash']);
}

// Generate CSRF token for gallery forms
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- 1. LOAD GLOBAL INVENTORY ---
// Pulls the master list of available scripts and engines.
$global_inventory = (function() { return include 'core/manifest-inventory.php'; })();

// --- 2. PUBLIC SKIN DISCOVERY ---
// Scans the skins directory for valid manifests to populate the selector.
// Skins are filtered by site_mode so only compatible skins appear:
//   SMACKONEOUT (photoblog)  → skins that are neither carousel nor smacktalk
//   GRAMOFSMACK (carousel)   → skins with features.carousel = true  (e.g. The Grid)
//   SMACKTALK                → skins with 'smacktalk' in manifest modes[]  (e.g. Alfred)
//   Mobile (Photogram)       → excluded entirely; forced automatically on phones
$site_mode        = $settings['site_mode'] ?? 'photoblog';
$is_carousel      = ($site_mode === 'carousel');
$is_smacktalk     = ($site_mode === 'smacktalk');

$skin_dirs       = array_filter(glob('skins/*'), 'is_dir');
$available_skins = [];
foreach ($skin_dirs as $dir) {
    $slug = basename($dir);
    // Mobile skin is forced automatically on phones; never in the admin selector.
    if (defined('SNAPSMACK_MOBILE_SKIN') && $slug === SNAPSMACK_MOBILE_SKIN) continue;
    if (file_exists($dir . '/manifest.json')) {
        $temp = snapsmack_load_manifest($dir . '/manifest.json');
        // Development skins are not selectable in the admin skin picker
        if (($temp['status'] ?? 'stable') === 'development') continue;
        // Mode filter
        $skin_is_carousel   = !empty($temp['features']['carousel']);
        $skin_is_smacktalk  = in_array('smacktalk', $temp['modes'] ?? []);
        if ($is_carousel  && !$skin_is_carousel)                    continue; // GramOfSmack: carousel only
        if ($is_smacktalk && !$skin_is_smacktalk)                   continue; // SmackTalk: smacktalk only
        if (!$is_carousel && !$is_smacktalk
            && ($skin_is_carousel || $skin_is_smacktalk))           continue; // SmackOneOut: exclude both
        $available_skins[$slug] = $temp['name'] ?? ucfirst($slug);
    }
}

// If the mode filter wiped out all skins (e.g. carousel site with no carousel skin
// installed yet), fall back to unfiltered so the admin page stays accessible.
if (empty($available_skins)) {
    foreach ($skin_dirs as $dir) {
        $slug = basename($dir);
        if (defined('SNAPSMACK_MOBILE_SKIN') && $slug === SNAPSMACK_MOBILE_SKIN) continue;
        if (file_exists($dir . '/manifest.json')) {
            $temp = snapsmack_load_manifest($dir . '/manifest.json');
            if (($temp['status'] ?? 'stable') === 'development') continue;
            $available_skins[$slug] = $temp['name'] ?? ucfirst($slug);
        }
    }
}

$current_db_active = $settings['active_skin'] ?? array_key_first($available_skins);
$target_skin       = $_GET['s'] ?? $current_db_active;
if (!isset($available_skins[$target_skin])) $target_skin = array_key_first($available_skins);
if ($target_skin) snapsmack_apply_skin_settings($settings, $target_skin);
$manifest = load_skin_manifest($target_skin);

// --- 3. ENGINE RESOLUTION ---
// Identify which engines the selected skin requires based on its manifest.
$required_engines = $manifest['require_scripts'] ?? [];

$resolved_engines = [];
foreach ($required_engines as $engine_key) {
    if (isset($global_inventory['scripts'][$engine_key])) {
        $resolved_engines[$engine_key] = $global_inventory['scripts'][$engine_key];
    }
}

// --- MOBILE-SKIN AVATAR SAVE (Mobile tab; dedicated — never changes the active desktop skin) ---
if (isset($_POST['save_mobile_avatar'])) {
    $_mob_slug = preg_replace('/[^a-z0-9_\-]/', '', $_POST['mobile_skin_slug'] ?? '');
    $_is_mobile = false;
    if ($_mob_slug && skin_manifest_exists($_mob_slug)) {
        $_mm = load_skin_manifest($_mob_slug);
        $_is_mobile = is_array($_mm) && !empty($_mm['features']['mobile_only']);
    }
    if ($_is_mobile && !empty($_FILES['mobile_avatar']['name']) && ($_FILES['mobile_avatar']['error'] ?? 1) === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/uploads/skin-avatars/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
        $ext = strtolower(pathinfo($_FILES['mobile_avatar']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
            $filename = $_mob_slug . '--skin_avatar.' . $ext;
            if (move_uploaded_file($_FILES['mobile_avatar']['tmp_name'], $upload_dir . $filename)) {
                $rel = 'uploads/skin-avatars/' . $filename;
                $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?")
                    ->execute([$_mob_slug . '__skin_avatar', $rel, $rel]);
            }
        }
    }
    header('Location: smack-skin.php?tab=mobile');
    exit;
}

// --- 4. SAVE HANDLER (Customize tab) ---
// INSTANT CAMERA — AI-assisted aspect-ratio detection (spec §2.3).
// Server-side: 3 scans → configured vision AI → averaged width:height → writes
// the skin's Custom format. The API key never reaches the browser.
if (isset($_POST['ic_aspect_detect'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        $_SESSION['gallery_flash'] = 'Security check failed — please try again.';
        header('Location: smack-skin.php?s=instant-camera'); exit;
    }
    // Measure the aspect ratio straight from the uploaded sample's pixel
    // dimensions — exact, free, instant. A digital/faux frame's pixels ARE its
    // ratio (e.g. VNTG runs a touch off the standard Polaroid spec), so reading
    // getimagesize() beats both a hardcoded standard and AI estimation. AI vision
    // was only ever needed to measure physical print scans — dropped.
    $tmp = '';
    if (!empty($_FILES['ic_scan']['tmp_name'])) {
        $tn = $_FILES['ic_scan']['tmp_name'];
        $en = $_FILES['ic_scan']['error'];
        if (is_array($tn)) {
            foreach ($tn as $i => $t) { if (($en[$i] ?? 1) === UPLOAD_ERR_OK) { $tmp = $t; break; } }
        } elseif (($en ?? 1) === UPLOAD_ERR_OK) {
            $tmp = $tn;
        }
    }
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        $_SESSION['gallery_flash'] = 'Upload one sample image to measure the aspect ratio.';
    } else {
        $dim = @getimagesize($tmp);
        if (!$dim || (int)$dim[0] < 1 || (int)$dim[1] < 1) {
            $_SESSION['gallery_flash'] = 'Could not read that image — use a JPEG, PNG or WebP.';
        } else {
            $w = (int)$dim[0]; $h = (int)$dim[1];
            $gcd = function ($a, $b) { while ($b) { [$a, $b] = [$b, $a % $b]; } return $a ?: 1; };
            $d = $gcd($w, $h); $rw = (int)($w / $d); $rh = (int)($h / $d);
            foreach (['ic_format' => 'custom', 'ic_custom_ratio' => "$rw:$rh"] as $k => $v) {
                $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?")
                    ->execute(["instant-camera__$k", $v, $v]);
            }
            require_once __DIR__ . '/core/page-cache.php';
            page_cache_purge_all();
            $_SESSION['gallery_flash'] = "Aspect ratio measured: {$rw}:{$rh} (from {$w}×{$h}px). Print format set to Custom.";
        }
    }
    header('Location: smack-skin.php?s=instant-camera&msg=updated'); exit;
}

// --- RESET PARADE BACKGROUND TUNING (flag + fireworks) to manifest defaults ---
// Deletes ONLY the animated-background tuning keys so the manifest defaults take
// over (snapsmack_apply_skin_settings falls back to them at render). Leaves the
// chosen background MODE, flag palette, colours, glow and nav settings intact.
// Exists because there was no reset control and the 1.0.3 slider rescale left
// old saved values misread (sparse straight-line fireworks).
if (isset($_POST['reset_pa_bg'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        exit('Invalid CSRF token.');
    }
    $reset_skin = preg_replace('/[^a-z0-9_\-]/', '', $_POST['active_skin_target'] ?? 'parade');
    $reset_opts = [
        // Fireworks tuning
        'pa_rate', 'pa_explode', 'pa_intensity', 'pa_soft',
        'pa_spread', 'pa_launch', 'pa_streamer',
        // Waving-flag tuning
        'pa_flag_speed', 'pa_flag_amplitude', 'pa_flag_opacity',
    ];
    $reset_keys = array_map(fn($k) => $reset_skin . '__' . $k, $reset_opts);
    $ph = implode(',', array_fill(0, count($reset_keys), '?'));
    $pdo->prepare("DELETE FROM snap_settings WHERE setting_key IN ($ph)")->execute($reset_keys);

    require_once __DIR__ . '/core/page-cache.php';
    page_cache_purge_all();

    $_SESSION['gallery_flash'] = 'Flag & Fireworks settings reset to defaults.';
    header('Location: smack-skin.php?s=' . urlencode($reset_skin) . '&msg=reset');
    exit;
}

if (isset($_POST['save_skin_settings'])) {

    // 4a. Persistence: Save individual skin and engine control values.
    //     Skin option keys are stored with a skin prefix (e.g. "galleria__htbs_wall_color")
    //     so each skin retains its own customizations independently.
    //     Engine control keys (non-htbs_) are saved bare — they're global.
    $save_skin = $_POST['active_skin_target'] ?? $target_skin;
    if (isset($_POST['skin_opt'])) {
        foreach ($_POST['skin_opt'] as $s_key => $s_val) {
            $scoped_key = $save_skin . '__' . $s_key;
            $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?")
                ->execute([$scoped_key, $s_val, $s_val]);
        }
    }

    // MOBILE SKIN: the compiler further down (4c) only compiles the ACTIVE
    // skin's options into custom_css_public — the mobile-only skin is never
    // active, so its options compile into custom_css_mobile instead. Doing it
    // here makes a settings save take effect on phones immediately.
    if (defined('SNAPSMACK_MOBILE_SKIN') && $save_skin === SNAPSMACK_MOBILE_SKIN) {
        require_once __DIR__ . '/core/skin-compile-mobile.php';
        try { snapsmack_compile_mobile_css($pdo); } catch (Throwable $e) { /* non-fatal */ }
    }

    // Handle type:'image' skin options — uploaded files override the hidden input value.
    if (!empty($_FILES['skin_img_opt']['name'])) {
        $upload_dir = __DIR__ . '/uploads/skin-avatars/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        $allowed_exts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

        // Pull this skin's option meta so we can honour per-field minimum
        // dimensions (e.g. The Grid treatment image requires >= 1920x1080).
        $_img_opts = [];
        $_mf_path  = __DIR__ . '/skins/' . $save_skin . '/manifest.json';
        if (is_file($_mf_path)) {
            $_mf = snapsmack_load_manifest($_mf_path);
            $_img_opts = (is_array($_mf) && isset($_mf['options'])) ? $_mf['options'] : [];
        }
        $_img_rejects = [];

        foreach ($_FILES['skin_img_opt']['name'] as $img_key => $orig_name) {
            if (empty($orig_name) || $_FILES['skin_img_opt']['error'][$img_key] !== UPLOAD_ERR_OK) continue;
            $img_key_clean = preg_replace('/[^a-z0-9_\-]/', '', $img_key);
            if (!$img_key_clean) continue;
            $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed_exts)) continue;

            // Enforce minimum dimensions when the manifest declares them.
            $min_w = (int)($_img_opts[$img_key]['min_width']  ?? 0);
            $min_h = (int)($_img_opts[$img_key]['min_height'] ?? 0);
            if ($min_w > 0 || $min_h > 0) {
                $dim = @getimagesize($_FILES['skin_img_opt']['tmp_name'][$img_key]);
                if (!$dim || $dim[0] < $min_w || $dim[1] < $min_h) {
                    $_img_rejects[] = ($_img_opts[$img_key]['label'] ?? $img_key)
                        . ' needs at least ' . $min_w . '×' . $min_h . 'px'
                        . ($dim ? ' (got ' . (int)$dim[0] . '×' . (int)$dim[1] . 'px)' : '');
                    continue;
                }
            }

            $filename = $save_skin . '--' . $img_key_clean . '.' . $ext;
            $target   = $upload_dir . $filename;
            if (move_uploaded_file($_FILES['skin_img_opt']['tmp_name'][$img_key], $target)) {
                $rel_path   = 'uploads/skin-avatars/' . $filename;
                $scoped_key = $save_skin . '__' . $img_key_clean;
                $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_val = ?")
                    ->execute([$scoped_key, $rel_path, $rel_path]);
            }
        }

        if (!empty($_img_rejects)) {
            $_SESSION['gallery_flash'] = 'Image not saved — ' . implode('; ', $_img_rejects) . '.';
        }
    }

    // 4a-ii. Variant persistence (skin palette, if manifest declares variants).
    if (isset($_POST['active_skin_variant'])) {
        $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('active_skin_variant', ?) ON DUPLICATE KEY UPDATE setting_val = ?")
            ->execute([$_POST['active_skin_variant'], $_POST['active_skin_variant']]);
    }

    $active_skin = $_POST['active_skin_target'] ?? $target_skin;
    $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('active_skin', ?) ON DUPLICATE KEY UPDATE setting_val = ?")
        ->execute([$active_skin, $active_skin]);

    // Keep site_mode in lockstep with the activated skin. Each skin declares the
    // mode(s) it's built for in its manifest ('modes'). When a skin supports
    // exactly ONE mode and that differs from the current site_mode, switch the
    // site to it — otherwise a gram skin (The Grid) can sit on a photoblog-mode
    // install and the poster routing / calendar / masthead all behave wrong
    // (the unzucked.ca "solo poster on a Grid site" bug). Multi-mode or
    // mode-agnostic skins are left untouched. Change is surfaced via the flash.
    $_sk_slug  = preg_replace('/[^a-z0-9_\-]/', '', $active_skin);
    $_sk_mpath = __DIR__ . '/skins/' . $_sk_slug . '/manifest.json';
    if (is_file($_sk_mpath)) {
        $_sk_man   = snapsmack_load_manifest($_sk_mpath);
        $_sk_modes = (is_array($_sk_man) && isset($_sk_man['modes']) && is_array($_sk_man['modes']))
                     ? array_values($_sk_man['modes']) : [];
        if (count($_sk_modes) === 1 && in_array($_sk_modes[0], ['photoblog', 'carousel', 'smacktalk'], true)) {
            $_cur_mode = (string)($pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key='site_mode' LIMIT 1")->fetchColumn() ?: 'photoblog');
            if ($_sk_modes[0] !== $_cur_mode) {
                $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('site_mode', ?) ON DUPLICATE KEY UPDATE setting_val = ?")
                    ->execute([$_sk_modes[0], $_sk_modes[0]]);
                $_mode_labels = ['photoblog' => 'SmackOneOut', 'carousel' => 'GramOfSmack', 'smacktalk' => 'SmackTalk'];
                $_SESSION['gallery_flash'] = 'Site mode switched to ' . ($_mode_labels[$_sk_modes[0]] ?? $_sk_modes[0]) . ' to match this skin.';
            }
        }
    }

    // 4b. Refresh local settings cache for CSS compilation AND form display.
    // Both $all_settings (used by compiler) and $settings (used by form rendering)
    // must reflect the just-saved values. Without this, the form shows stale values
    // after save, and a second save would silently revert changes.
    $all_settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    snapsmack_apply_skin_settings($all_settings, $active_skin);
    $settings = $all_settings;

    // 4b-i. Flush the page cache — skin appearance changes must be visible
    // immediately on the front end, not after the next cache TTL expires.
    require_once __DIR__ . '/core/page-cache.php';
    page_cache_purge_all();

    // 4c. Public CSS Compilation.
    $generated_public = "/* SKIN_START */\n";

    // Map manifest options to CSS properties or custom payloads.
    foreach ($manifest['options'] as $key => $meta) {
        $val = ($all_settings[$key] ?? '') !== '' ? $all_settings[$key] : ($meta['default'] ?? '');
        $prop = $meta['property'] ?? '';

        // Skip options with no CSS property — handled by PHP (e.g. bevel style, wood grain)
        if ($prop === '') {
            continue;
        }

        // Skip options with no resolved value (empty default, nothing in DB).
        // This lets manifest options that target CSS variables use an empty
        // default so the skin's style.css fallback (e.g. var(--page-bg, var(--bg-primary)))
        // remains in control until the admin explicitly sets a value.
        if ($val === '') {
            continue;
        }

        // Skip data-attributes — read by JS engines, not CSS
        if (strpos($prop, 'data-') === 0) {
            continue;
        }

        // Custom properties (custom-framing, custom-cols) — only emit the css block
        if (strpos($prop, 'custom-') === 0) {
            if ($meta['type'] === 'select' && isset($meta['options'][$val]['css'])) {
                $generated_public .= "{$meta['selector']} {$meta['options'][$val]['css']}\n";
            }
            continue;
        }

        if ($meta['type'] === 'select' && isset($meta['options'][$val]['css'])) {
            $generated_public .= "{$meta['selector']} {$meta['options'][$val]['css']}\n";
        } elseif ($prop === 'font-family') {
            // Smart fallback: monospace stack for DotMatrix/mono fonts, sans-serif for others
            $fallback = 'sans-serif';
            if (stripos($val, 'DotMatrix') !== false || stripos($val, 'Mono') !== false
                || stripos($val, 'Courier') !== false || stripos($val, 'Tiny5') !== false
                || stripos($val, 'Anonymous') !== false) {
                $fallback = "'Courier New', monospace";
            }
            $generated_public .= "{$meta['selector']} { font-family: \"{$val}\", {$fallback}; }\n";
            // Companion font-size: always emit for every font picker. The 'size' key
            // in the manifest provides custom min/max/default; falls back to generic
            // defaults so all skins get a size slider without any manifest changes.
            if (!empty($meta['selector'])) {
                $sz_key = $key . '_size';
                $sz_val = ($all_settings[$sz_key] ?? '') !== '' ? $all_settings[$sz_key] : ($meta['size']['default'] ?? '1.0');
                $generated_public .= "{$meta['selector']} { font-size: {$sz_val}rem; }\n";
            }
        } elseif ($meta['type'] === 'range' || $meta['type'] === 'number' || $meta['type'] === 'range_numeric') {
            // Use manifest unit if declared; default to px for standard CSS properties,
            // empty for CSS custom properties (unless manifest explicitly sets a unit).
            if (isset($meta['unit'])) {
                $unit = $meta['unit'];
            } else {
                $unit = (substr($prop, 0, 2) === '--') ? '' : 'px';
            }
            // Handle comma-separated properties (e.g. 'padding-left, padding-right')
            $props = array_map('trim', explode(',', $prop));
            $declarations = [];
            foreach ($props as $p) {
                $declarations[] = "{$p}: {$val}{$unit}";
            }
            $generated_public .= "{$meta['selector']} { " . implode('; ', $declarations) . "; }\n";
        } else {
            // Same split for generic properties
            $props = array_map('trim', explode(',', $prop));
            $declarations = [];
            foreach ($props as $p) {
                $declarations[] = "{$p}: {$val}";
            }
            $generated_public .= "{$meta['selector']} { " . implode('; ', $declarations) . "; }\n";
        }
    }

    // Process Engine-specific CSS variables (e.g. Glitch Engine).
    foreach ($resolved_engines as $engine_key => $engine) {
        if (!empty($engine['controls'])) {
            $generated_public .= "/* ENGINE: {$engine_key} */\n";
            foreach ($engine['controls'] as $ctrl_key => $ctrl) {
                $val  = ($all_settings[$ctrl_key] ?? '') !== '' ? $all_settings[$ctrl_key] : ($ctrl['default'] ?? '');

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

    // Surgical Update: Replace only the SKIN block within the public CSS blob.
    $existing_blob  = $all_settings['custom_css_public'] ?? '';
    $skin_pattern   = '/\/\* SKIN_START \*\/.*?\/\* SKIN_END \*\//s';
    $final_public   = preg_match($skin_pattern, $existing_blob)
        ? preg_replace($skin_pattern, $generated_public, $existing_blob)
        : $generated_public . "\n\n" . trim($existing_blob);

    $pdo->prepare("REPLACE INTO snap_settings (setting_key, setting_val) VALUES ('custom_css_public', ?)")
        ->execute([$final_public]);

    // 4d. ENGINE HANDSHAKE: Build the script injection block.
    $v         = time();
    $injection = '';

    // 4d-i. Google Font CDN links for any active font-family selections
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
        if (!empty($google_needed)) {
            $families = [];
            foreach (array_keys($google_needed) as $fam) {
                $families[] = str_replace(' ', '+', $fam) . ':wght@400;700';
            }
            $gf_url = 'https://fonts.googleapis.com/css2?' . implode('&', array_map(fn($f) => "family={$f}", $families)) . '&display=swap';
            $injection .= '<link rel="stylesheet" href="' . htmlspecialchars($gf_url) . '">' . "\n";
        }
    }

    // 4d-ii. Engine scripts are loaded by skin-footer.php at runtime via
    //        the manifest require_scripts[] list. They must NOT be duplicated
    //        here — only the Google Font CDN link belongs in the injection blob.

    $pdo->prepare("REPLACE INTO snap_settings (setting_key, setting_val) VALUES ('footer_injection_scripts', ?)")
        ->execute([$injection]);

    // 4e. Admin Styling: Injected from manifest if defined.
    if (isset($manifest['admin_styling'])) {
        $generated_admin = "/* SKIN_START */\n" . trim($manifest['admin_styling']) . "\n/* SKIN_END */";
        $pdo->prepare("INSERT INTO snap_settings (setting_key, setting_val) VALUES ('custom_css_admin', ?) ON DUPLICATE KEY UPDATE setting_val = ?")
            ->execute([$generated_admin, $generated_admin]);
    }

    // 4f. Asset sync — fetch any fonts or JS engines missing from disk.
    // Runs silently; result appended to flash if anything was fetched or failed.
    require_once 'core/asset-sync.php';
    $sync_msg = asset_sync_run();
    if ($sync_msg !== null) {
        $_SESSION['gallery_flash'] = 'Assets: ' . $sync_msg;
    }

    header("Location: smack-skin.php?s={$active_skin}&msg=updated");
    exit;
}

$page_title = "SKIN ADMIN";
include 'core/admin-header.php';
include 'core/sidebar.php';

// --- LOCAL FONT @FONT-FACE DECLARATIONS ---
// Needed so font previews and dropdown option styles render local TTF fonts.
$global_inventory = include 'core/manifest-inventory.php';
$local_fonts = $global_inventory['local_fonts'] ?? [];
if (!empty($local_fonts)) {
    echo '<style id="snapsmack-admin-local-fonts">' . "\n";
    foreach ($local_fonts as $family => $font) {
        $file_url = BASE_URL . ltrim($font['file'], '/');
        $format   = $font['format'] ?? 'truetype';
        $weight   = $font['weight'] ?? 'normal';
        $style    = $font['style'] ?? 'normal';
        echo "@font-face { font-family: '{$family}'; src: url('{$file_url}') format('{$format}'); font-weight: {$weight}; font-style: {$style}; font-display: swap; }\n";
    }
    echo '</style>' . "\n";
}

// --- GOOGLE FONT PREVIEWS ---
// Load all Google Fonts from the inventory so they render in the font picker
// dropdown options. Single CDN request, admin-only page — no performance concern.
$google_families = $global_inventory['fonts'] ?? [];
if (!empty($google_families)) {
    $gf_parts = [];
    foreach (array_keys($google_families) as $fam) {
        $gf_parts[] = 'family=' . str_replace(' ', '+', $fam) . ':wght@400;700';
    }
    $gf_url = 'https://fonts.googleapis.com/css2?' . implode('&', $gf_parts) . '&display=swap';
    echo '<link rel="stylesheet" href="' . htmlspecialchars($gf_url) . '">' . "\n";
}
?>

<style>
/* --- SKIN PAGE: TAB NAVIGATION --- */
.skin-tabs {
    display: flex;
    gap: 0;
    margin-bottom: 24px;
    border-bottom: 1px solid rgba(128,128,128,0.3);
}
.skin-tab {
    padding: 10px 24px;
    font-size: 0.8rem;
    font-weight: 700;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: inherit;
    opacity: 0.5;
    text-decoration: none;
    border-bottom: 2px solid transparent;
    transition: color 0.2s, border-color 0.2s, opacity 0.2s;
}
.skin-tab:hover { opacity: 0.75; }
.skin-tab.active {
    opacity: 1;
    font-weight: 900;
    border-bottom-color: currentColor;
}

/* --- GALLERY: Skin cards grid --- */
.gallery-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 20px;
    margin-top: 20px;
}
.skin-card {
    background: rgba(128,128,128,0.06);
    border: 1px solid rgba(128,128,128,0.25);
    border-radius: 4px;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    transition: border-color 0.2s;
}
.skin-card:hover {
    border-color: rgba(128,128,128,0.5);
}
.skin-card-screenshot {
    width: 100%;
    height: 180px;
    background: rgba(128,128,128,0.08);
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    position: relative;
}
.skin-card-screenshot img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: none;
}
.skin-card-screenshot img.ss-active { display: block; }
.skin-card-screenshot .no-preview {
    opacity: 0.35;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 2px;
}
.ss-dots {
    position: absolute;
    bottom: 6px;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    gap: 5px;
    z-index: 2;
}
.ss-dot {
    width: 7px; height: 7px;
    border-radius: 50%;
    background: rgba(255,255,255,0.4);
    border: 1px solid rgba(0,0,0,0.3);
    cursor: pointer;
    transition: background .15s;
}
.ss-dot.active { background: rgba(255,255,255,0.9); }
.ss-nav {
    position: absolute;
    top: 0; bottom: 0;
    width: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    opacity: 0;
    transition: opacity .15s;
    z-index: 2;
    font-size: 18px;
    color: #fff;
    text-shadow: 0 1px 3px rgba(0,0,0,0.6);
    user-select: none;
}
.skin-card-screenshot:hover .ss-nav { opacity: 1; }
.ss-nav-prev { left: 0; }
.ss-nav-next { right: 0; }
.ss-label {
    position: absolute;
    top: 6px; left: 8px;
    font-size: 0.55rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    background: rgba(0,0,0,0.55);
    color: #fff;
    padding: 2px 6px;
    border-radius: 3px;
    z-index: 2;
    display: none;
}
.skin-card-screenshot:hover .ss-label { display: block; }
.skin-card-body {
    padding: 16px;
    flex: 1;
    display: flex;
    flex-direction: column;
}
.skin-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 8px;
}
.skin-card-name {
    font-size: 0.95rem;
    font-weight: 700;
    color: inherit;
    text-transform: uppercase;
    letter-spacing: 1px;
}
.skin-card-version {
    font-size: 0.7rem;
    opacity: 0.5;
    font-family: monospace;
}
.skin-card-desc {
    font-size: 0.8rem;
    opacity: 0.6;
    line-height: 1.5;
    margin-bottom: 12px;
    flex: 1;
}
.skin-card-meta {
    font-size: 0.7rem;
    opacity: 0.4;
    margin-bottom: 12px;
}
.skin-card-features {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-bottom: 12px;
}
.feature-tag {
    font-size: 0.65rem;
    padding: 2px 8px;
    border-radius: 3px;
    text-transform: uppercase;
    letter-spacing: 1px;
    font-weight: 600;
}
.feature-tag.wall    { background: rgba(128,128,128,0.1); color: inherit; opacity: 0.7; border: 1px solid rgba(128,128,128,0.3); }
.feature-tag.no-wall { background: rgba(128,128,128,0.06); color: inherit; opacity: 0.5; border: 1px solid rgba(128,128,128,0.2); }
.feature-tag.layout  { background: rgba(128,128,128,0.1); color: inherit; opacity: 0.7; border: 1px solid rgba(128,128,128,0.3); }

/* --- STATUS BADGES --- */
.status-badge {
    display: inline-block;
    font-size: 0.6rem;
    font-weight: 700;
    letter-spacing: 1px;
    text-transform: uppercase;
    padding: 2px 8px;
    border-radius: 3px;
}
.status-badge.stable     { background: rgba(128,128,128,0.1); color: inherit; opacity: 0.8; border: 1px solid rgba(128,128,128,0.3); }
.status-badge.beta       { background: rgba(128,128,128,0.08); color: inherit; opacity: 0.65; border: 1px solid rgba(128,128,128,0.25); }
.status-badge.development { background: rgba(128,128,128,0.06); color: inherit; opacity: 0.5; border: 1px solid rgba(128,128,128,0.2); }

/* Installed badge */
.installed-badge {
    display: inline-block;
    font-size: 0.6rem;
    font-weight: 700;
    letter-spacing: 1px;
    text-transform: uppercase;
    padding: 2px 8px;
    border-radius: 3px;
    background: rgba(128,128,128,0.1);
    color: inherit;
    opacity: 0.7;
    border: 1px solid rgba(128,128,128,0.3);
}
.active-badge {
    background: rgba(128,128,128,0.15);
    opacity: 0.9;
    font-weight: 900;
    border: 1px solid rgba(128,128,128,0.4);
}

/* --- GALLERY BUTTONS --- */
.skin-card-actions {
    display: flex;
    gap: 8px;
    margin-top: auto;
}
.skin-card-actions form { margin: 0; }
.gallery-btn {
    display: inline-block;
    padding: 6px 14px;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    border: none;
    border-radius: 3px;
    cursor: pointer;
    transition: opacity 0.2s;
}
.gallery-btn:hover { opacity: 0.8; }
.gallery-btn.install  { background: rgba(128,128,128,0.25); color: inherit; font-weight: 900; }
.gallery-btn.update   { background: rgba(128,128,128,0.2); color: inherit; }
.gallery-btn.remove   { background: rgba(128,128,128,0.08); color: inherit; opacity: 0.5; border: 1px solid rgba(128,128,128,0.2); }
.gallery-btn.reinstall { background: rgba(128,128,128,0.18); color: inherit; border: 1px solid rgba(128,128,128,0.45); }
.gallery-btn.disabled {
    background: rgba(128,128,128,0.05);
    color: inherit;
    opacity: 0.3;
    cursor: not-allowed;
    border: 1px solid rgba(128,128,128,0.15);
}

/* --- GALLERY ERROR/SUCCESS --- */
.gallery-alert {
    padding: 10px 16px;
    margin-bottom: 16px;
    border-radius: 3px;
    font-size: 0.8rem;
}
.gallery-alert.success { background: rgba(128,128,128,0.1); color: inherit; border: 1px solid rgba(128,128,128,0.3); }
.gallery-alert.error   { background: rgba(128,128,128,0.06); color: inherit; opacity: 0.8; border: 1px solid rgba(128,128,128,0.2); }

/* --- REGISTRY INFO --- */
.registry-info {
    font-size: 0.75rem;
    color: #555;
    margin-bottom: 16px;
}
.registry-info a { text-decoration: underline; color: #e8a020; opacity: 1; font-weight: 600; }
</style>

<div class="main">
    <div class="header-row">
        <h2>SKIN ADMIN</h2>
    </div>

    <!-- ============================================================
         TAB NAVIGATION
         ============================================================ -->
    <div class="skin-tabs">
        <a href="smack-skin.php?tab=customize&s=<?php echo urlencode($target_skin); ?>"
           class="skin-tab <?php echo ($active_tab === 'customize') ? 'active' : ''; ?>">
            CUSTOMIZE
        </a>
        <a href="smack-skin.php?tab=mobile"
           class="skin-tab <?php echo ($active_tab === 'mobile') ? 'active' : ''; ?>">
            MOBILE
        </a>
        <a href="smack-skin.php?tab=gallery"
           class="skin-tab <?php echo ($active_tab === 'gallery') ? 'active' : ''; ?>">
            GALLERY
        </a>
    </div>

<?php if ($active_tab === 'customize'): ?>
    <!-- ============================================================
         TAB 1: CUSTOMIZE (existing skin customization UI)
         ============================================================ -->

    <div class="box appearance-controller">
        <div class="skin-meta-wrap">
            <div class="theme-title-display">
                <?php echo strtoupper(htmlspecialchars($manifest['name'])); ?>
                <span class="theme-version-tag">v<?php echo htmlspecialchars($manifest['version']); ?></span>
                <?php if (!empty($manifest['status'])): ?>
                    <span class="status-badge <?php echo htmlspecialchars($manifest['status']); ?>">
                        <?php echo strtoupper(htmlspecialchars($manifest['status'])); ?>
                    </span>
                <?php endif; ?>
            </div>
            <p class="skin-desc-text"><?php echo htmlspecialchars($manifest['description']); ?></p>
            <div class="dim">
                BY <?php echo strtoupper(htmlspecialchars($manifest['author'])); ?>
                <?php if (!empty($manifest['support'])): ?>
                    | <a href="mailto:<?php echo htmlspecialchars($manifest['support']); ?>" class="support-link">SUPPORT</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="skin-selector-wrap">
            <form method="GET">
                <input type="hidden" name="tab" value="customize">
                <label>SKIN SELECTOR</label>
                <select name="s" onchange="this.form.submit()">
                    <?php foreach ($available_skins as $slug => $name): ?>
                        <option value="<?php echo $slug; ?>" <?php echo ($target_skin == $slug) ? 'selected' : ''; ?>>
                            <?php echo strtoupper($name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success">> SKIN ARCHITECTURE CALIBRATED</div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="active_skin_target" value="<?php echo $target_skin; ?>">

        <div id="smack-skin-config-wrap">

            <?php
            // --- UNIVERSAL PROFILE AVATAR (every skin, any install mode) ---
            // Stored scoped as "<skin>__skin_avatar"; the generic image-upload
            // handler saves it without needing a manifest entry. Photogram (mobile)
            // inherits the active desktop skin's avatar from this key. Rendered for
            // every skin EXCEPT ones that already declare their own skin_avatar
            // option (e.g. The Grid), to avoid a duplicate control.
            if (!isset($manifest['options']['skin_avatar'])):
                $_av_val = $settings[$target_skin . '__skin_avatar'] ?? '';
                $_av_url = $_av_val ? BASE_URL . ltrim($_av_val, '/') : '';
            ?>
            <div class="box">
                <h3>PROFILE AVATAR</h3>
                <div class="dash-grid">
                    <div class="lens-input-wrapper">
                        <input type="hidden" name="skin_opt[skin_avatar]" value="<?php echo htmlspecialchars($_av_val); ?>">
                        <?php if ($_av_url): ?>
                        <div style="margin-bottom:8px;">
                            <img src="<?php echo htmlspecialchars($_av_url); ?>" style="width:72px;height:72px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.15);display:block;">
                        </div>
                        <?php endif; ?>
                        <div class="file-upload-wrapper" onclick="document.getElementById('skinimg-skin_avatar').click()">
                            <div class="file-custom-btn">UPLOAD</div>
                            <div class="file-name-display" id="skinimg-name-skin_avatar"><?php echo $_av_val ? 'CURRENT' : 'SELECT FILE'; ?></div>
                        </div>
                        <input type="file" id="skinimg-skin_avatar" name="skin_img_opt[skin_avatar]"
                               accept="image/jpeg,image/png,image/webp,image/gif" style="display:none;"
                               onchange="document.getElementById('skinimg-name-skin_avatar').innerText = (this.files[0] ? this.files[0].name : 'SELECT FILE')">
                        <p class="dim field-hint" style="margin-top:4px;">SQUARE IMAGE. PROFILE AVATAR &mdash; ALSO USED BY THE PHOTOGRAM MOBILE VIEW.</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php
            // --- COLOUR VARIANT: Only if manifest declares variants ---
            if (!empty($manifest['variants'])):
                $current_variant = $settings['active_skin_variant'] ?? ($manifest['default_variant'] ?? array_key_first($manifest['variants']));
            ?>
            <div class="box">
                <h3>COLOUR VARIANT</h3>
                <div class="dash-grid">
                    <div class="lens-input-wrapper">
                        <label>SKIN PALETTE</label>
                        <select name="active_skin_variant">
                            <?php foreach ($manifest['variants'] as $v_key => $v_label): ?>
                                <option value="<?php echo htmlspecialchars($v_key); ?>" <?php echo ($current_variant === $v_key) ? 'selected' : ''; ?>>
                                    <?php echo strtoupper(htmlspecialchars($v_label)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="dim field-hint">COLOUR PALETTE FOR THE ACTIVE SKIN. GEOMETRY AND LAYOUT ARE UNCHANGED.</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php
            // --- SKIN OPTIONS: Grouped by manifest sections ---
            $sec = [];
            foreach ($manifest['options'] as $k => $o) {
                if (($o['admin_page'] ?? 'skin') !== 'skin') continue; // handled by another admin page
                $sec[$o['section'] ?? 'GENERAL'][] = ['key' => $k, 'meta' => $o];
            }
            foreach ($sec as $title => $opts):
            ?>
                <div class="box">
                    <h3><?php echo $title; ?></h3>
                    <div class="dash-grid">
                    <?php foreach ($opts as $item):
                        $k   = $item['key'];
                        $o   = $item['meta'];
                        if ($o['type'] === 'spacer') { echo '<div class="lens-input-wrapper"></div>'; continue; }
                        $val = ($settings[$k] ?? '') !== '' ? $settings[$k] : $o['default'];
                        // Context-sensitive controls: a manifest 'show_when' =>
                        // ['<other_key>' => '<value>'] hides this wrapper unless that
                        // control currently equals the value (toggled live by the
                        // script below). Reusable across all skins.
                        $show_attr = '';
                        if (!empty($o['show_when']) && is_array($o['show_when'])) {
                            $sw_key = (string) array_key_first($o['show_when']);
                            $sw_val = (string) $o['show_when'][$sw_key];
                            $show_attr = ' data-show-when="' . htmlspecialchars($sw_key)
                                       . '" data-show-eq="' . htmlspecialchars($sw_val) . '"';
                        }
                        ?>
                        <div class="lens-input-wrapper"<?php echo $show_attr; ?>>
                            <label><?php echo strtoupper($o['label']); ?></label>
                            <?php if ($o['type'] === 'color' && !empty($o['is_greyscale'])): ?>
                                <?php
                                $grey_swatches = ['#000000','#2a2a2a','#555555','#808080','#aaaaaa','#d4d4d4','#ffffff'];
                                $grey_safe     = htmlspecialchars($val ?: '#808080');
                                ?>
                                <div class="grey-picker-container">
                                    <input type="hidden" name="skin_opt[<?php echo $k; ?>]"
                                           id="gp-<?php echo $k; ?>"
                                           value="<?php echo $grey_safe; ?>">
                                    <div class="grey-swatches">
                                        <?php foreach ($grey_swatches as $sw): ?>
                                        <button type="button"
                                                class="grey-swatch <?php echo strtolower($val) === strtolower($sw) ? 'selected' : ''; ?>"
                                                style="background:<?php echo $sw; ?>;"
                                                data-val="<?php echo $sw; ?>"
                                                data-target="gp-<?php echo $k; ?>"
                                                title="<?php echo strtoupper($sw); ?>"
                                                onclick="(function(b){
                                                    var inp=document.getElementById(b.dataset.target);
                                                    inp.value=b.dataset.val;
                                                    b.closest('.grey-swatches').querySelectorAll('.grey-swatch').forEach(function(s){s.classList.remove('selected');});
                                                    b.classList.add('selected');
                                                })(this)">
                                        </button>
                                        <?php endforeach; ?>
                                    </div>
                                    <span class="hex-display" style="font-size:0.75rem;opacity:0.5;"><?php echo strtoupper($grey_safe); ?></span>
                                </div>
                            <?php elseif ($o['type'] === 'color'): ?>
                                <div class="color-picker-container">
                                    <input type="color" name="skin_opt[<?php echo $k; ?>]" value="<?php echo htmlspecialchars($val); ?>">
                                    <span class="hex-display"><?php echo strtoupper(htmlspecialchars($val)); ?></span>
                                </div>
                            <?php elseif ($o['type'] === 'range'):
                                $display_unit = strtoupper($o['unit'] ?? 'px');
                            ?>
                                <div class="range-wrapper">
                                    <input type="range" name="skin_opt[<?php echo $k; ?>]"
                                        min="<?php echo $o['min']; ?>" max="<?php echo $o['max']; ?>"
                                        step="<?php echo $o['step'] ?? '1'; ?>"
                                        value="<?php echo htmlspecialchars($val); ?>"
                                        oninput="this.nextElementSibling.innerText = this.value + '<?php echo $display_unit; ?>'">
                                    <span class="active-val"><?php echo strtoupper(htmlspecialchars($val)); ?><?php echo $display_unit; ?></span>
                                </div>
                            <?php elseif ($o['type'] === 'range_numeric'):
                                $rn_unit      = $o['unit'] ?? 'px';
                                $rn_slider_id = 'rns-' . $k;
                                $rn_num_id    = 'rnn-' . $k;
                                $rn_val       = (int)$val;
                            ?>
                                <div class="range-numeric-wrapper" style="display:flex;align-items:center;gap:8px;">
                                    <input type="range" id="<?php echo $rn_slider_id; ?>"
                                        min="<?php echo (int)$o['min']; ?>"
                                        max="<?php echo (int)$o['max']; ?>"
                                        step="<?php echo $o['step'] ?? '1'; ?>"
                                        value="<?php echo $rn_val; ?>"
                                        style="flex:1"
                                        oninput="document.getElementById('<?php echo $rn_num_id; ?>').value=this.value">
                                    <input type="number" id="<?php echo $rn_num_id; ?>" name="skin_opt[<?php echo $k; ?>]"
                                        min="<?php echo (int)$o['min']; ?>"
                                        max="<?php echo (int)$o['max']; ?>"
                                        step="<?php echo $o['step'] ?? '1'; ?>"
                                        value="<?php echo $rn_val; ?>"
                                        style="width:5em;text-align:right;"
                                        oninput="document.getElementById('<?php echo $rn_slider_id; ?>').value=this.value">
                                    <span style="opacity:0.6;font-size:0.8em;"><?php echo htmlspecialchars($rn_unit); ?></span>
                                </div>
                            <?php elseif ($o['type'] === 'select'): ?>
                                <?php $is_font = (($o['property'] ?? '') === 'font-family') || !empty($o['is_font']); ?>
                                <select name="skin_opt[<?php echo $k; ?>]"
                                    <?php if ($is_font): ?>data-font-preview="1"<?php endif; ?>>
                                    <?php foreach ($o['options'] as $sv => $sl): ?>
                                        <option value="<?php echo $sv; ?>"
                                            <?php echo ($val == $sv) ? 'selected' : ''; ?>
                                            <?php if ($is_font): ?>style="font-family: '<?php echo $sv; ?>', sans-serif;"<?php endif; ?>>
                                            <?php echo is_array($sl) ? $sl['label'] : $sl; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($is_font): ?>
                                    <div class="font-preview" style="margin-top:8px; padding:10px 14px; background:rgba(255,255,255,0.05); border:1px solid rgba(255,255,255,0.1); border-radius:3px;">
                                        <span class="font-preview-text" style="font-family: '<?php echo htmlspecialchars($val); ?>', sans-serif; font-size:18px; letter-spacing:0.5px;">
                                            <?php echo htmlspecialchars($val); ?>
                                        </span>
                                        <span style="display:block; font-family: '<?php echo htmlspecialchars($val); ?>', sans-serif; font-size:13px; opacity:0.5; margin-top:4px;" class="font-preview-text">
                                            The quick brown fox jumps over the lazy dog
                                        </span>
                                    </div>
                                    <?php if (empty($o['no_size_slider'])): ?>
                                    <?php
                                        $sz_key  = $o['sz_key_override'] ?? ($k . '_size');
                                        $sz      = $o['size'] ?? [];
                                        $sz_unit = strtoupper($sz['unit'] ?? 'REM');
                                        $sz_val  = ($settings[$sz_key] ?? '') !== '' ? $settings[$sz_key] : ($sz['default'] ?? '1.0');
                                    ?>
                                    <div style="margin-top:12px;">
                                        <label style="display:block; font-size:0.7rem; letter-spacing:1.5px; text-transform:uppercase; opacity:0.5; margin-bottom:6px;">Font Size (<?php echo strtolower($sz_unit); ?>)</label>
                                        <div class="range-wrapper">
                                            <input type="range" name="skin_opt[<?php echo $sz_key; ?>]"
                                                min="<?php echo $sz['min'] ?? '0.7'; ?>"
                                                max="<?php echo $sz['max'] ?? '2.0'; ?>"
                                                step="<?php echo $sz['step'] ?? '0.05'; ?>"
                                                value="<?php echo htmlspecialchars($sz_val); ?>"
                                                oninput="this.nextElementSibling.innerText = this.value + '<?php echo $sz_unit; ?>'">
                                            <span class="active-val"><?php echo htmlspecialchars($sz_val); ?><?php echo $sz_unit; ?></span>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php elseif ($o['type'] === 'image'): ?>
                                <?php
                                /* Existing path preserved via hidden input.
                                   If a file is uploaded the POST handler above overwrites it in DB. */
                                $img_preview_url = $val ? BASE_URL . ltrim($val, '/') : '';
                                $img_accept      = $o['accept'] ?? 'image/jpeg,image/png,image/webp,image/gif';
                                ?>
                                <input type="hidden" name="skin_opt[<?php echo $k; ?>]" value="<?php echo htmlspecialchars($val); ?>">
                                <?php if ($img_preview_url): ?>
                                <div style="margin-bottom:8px;">
                                    <img src="<?php echo htmlspecialchars($img_preview_url); ?>"
                                         style="width:72px;height:72px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.15);display:block;">
                                </div>
                                <?php endif; ?>
                                <div class="file-upload-wrapper" onclick="document.getElementById('skinimg-<?php echo $k; ?>').click()">
                                    <div class="file-custom-btn">UPLOAD</div>
                                    <div class="file-name-display" id="skinimg-name-<?php echo $k; ?>"><?php echo $val ? 'CURRENT' : 'SELECT FILE'; ?></div>
                                </div>
                                <input type="file" id="skinimg-<?php echo $k; ?>" name="skin_img_opt[<?php echo $k; ?>]"
                                       accept="<?php echo htmlspecialchars($img_accept); ?>"
                                       style="display:none;"
                                       onchange="document.getElementById('skinimg-name-<?php echo $k; ?>').innerText = (this.files[0] ? this.files[0].name : 'SELECT FILE')">
                                <?php if (!empty($o['hint'])): ?>
                                <p class="dim field-hint" style="margin-top:4px;"><?php echo htmlspecialchars(strtoupper($o['hint'])); ?></p>
                                <?php endif; ?>
                            <?php else: ?>
                                <input type="text" name="skin_opt[<?php echo $k; ?>]" value="<?php echo htmlspecialchars($val); ?>">
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <script>
            /* Context-sensitive skin controls: show a [data-show-when] wrapper only
               while the named control equals data-show-eq, and hide a whole section
               box when all of its conditional controls are hidden. Reusable across
               skins (e.g. PARADE fireworks vs waving-flag controls). */
            (function () {
                function applyShowWhen() {
                    document.querySelectorAll('[data-show-when]').forEach(function (el) {
                        var key  = el.getAttribute('data-show-when');
                        var eq   = el.getAttribute('data-show-eq');
                        var ctrl = document.querySelector('[name="skin_opt[' + key + ']"]');
                        if (!ctrl) return;
                        el.style.display = (String(ctrl.value) === eq) ? '' : 'none';
                    });
                    document.querySelectorAll('.box').forEach(function (box) {
                        if (!box.querySelector('[data-show-when]')) return; // only auto-manage conditional boxes
                        var wraps = box.querySelectorAll('.lens-input-wrapper');
                        var anyVisible = Array.prototype.some.call(wraps, function (w) {
                            return w.style.display !== 'none';
                        });
                        box.style.display = anyVisible ? '' : 'none';
                    });
                }
                document.addEventListener('change', function (e) {
                    if (e.target && e.target.name && e.target.name.indexOf('skin_opt[') === 0) applyShowWhen();
                });
                if (document.readyState !== 'loading') applyShowWhen();
                else document.addEventListener('DOMContentLoaded', applyShowWhen);
            })();
            </script>

            <?php
            // --- ENGINE CONTROLS: One box per engine that exposes settings ---
            // Engines with admin_page=>'archive' are rendered on smack-appearance-archive.php instead.
            foreach ($resolved_engines as $engine_key => $engine):
                if (empty($engine['has_settings']) || empty($engine['controls'])) continue;
                if (($engine['admin_page'] ?? 'skin') !== 'skin') continue;
                $engine_label = strtoupper($engine['label'] ?? $engine_key);
            ?>
            <div class="box">
                <h3><?php echo htmlspecialchars($engine_label); ?> SETTINGS</h3>
                <div class="dash-grid">
                <?php foreach ($engine['controls'] as $k => $o):
                    $val = ($settings[$k] ?? '') !== '' ? $settings[$k] : ($o['default'] ?? '');
                ?>
                    <div class="lens-input-wrapper">
                        <label><?php echo strtoupper(htmlspecialchars($o['label'] ?? $k)); ?></label>
                        <?php if (($o['type'] ?? '') === 'range'):
                            $step = $o['step'] ?? '1';
                            $display_unit2 = strtoupper($o['unit'] ?? 'px');
                        ?>
                            <div class="range-wrapper">
                                <input type="range" name="skin_opt[<?php echo htmlspecialchars($k); ?>]"
                                    min="<?php echo htmlspecialchars($o['min'] ?? 0); ?>"
                                    max="<?php echo htmlspecialchars($o['max'] ?? 100); ?>"
                                    step="<?php echo htmlspecialchars($step); ?>"
                                    value="<?php echo htmlspecialchars($val); ?>"
                                    oninput="this.nextElementSibling.innerText = this.value + '<?php echo $display_unit2; ?>'">
                                <span class="active-val"><?php echo htmlspecialchars($val); ?><?php echo $display_unit2; ?></span>
                            </div>
                        <?php elseif (($o['type'] ?? '') === 'select'): ?>
                            <select name="skin_opt[<?php echo htmlspecialchars($k); ?>]">
                                <?php foreach (($o['options'] ?? []) as $opt_val => $opt_label): ?>
                                    <option value="<?php echo htmlspecialchars($opt_val); ?>" <?php echo ($val == $opt_val) ? 'selected' : ''; ?>>
                                        <?php echo strtoupper(htmlspecialchars($opt_label)); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="text" name="skin_opt[<?php echo htmlspecialchars($k); ?>]" value="<?php echo htmlspecialchars($val); ?>">
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>

        </div>

        <div class="form-action-row">
            <button type="submit" name="save_skin_settings" class="master-update-btn">SAVE SKIN SPECIFIC CALIBRATION</button>
        </div>
    </form>

    <?php if (($target_skin ?? '') === 'parade'): ?>
    <!-- PARADE — reset the animated-background tuning (flag + fireworks) to the
         manifest defaults. Keeps the chosen mode, palette, colours, glow, nav. -->
    <form method="post" action="smack-skin.php?s=parade" style="margin-top:12px;"
          onsubmit="return confirm('Reset PARADE Flag &amp; Fireworks settings to their defaults?\n\nYour background mode, flag palette, colours, glow and nav settings are kept.');">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
        <input type="hidden" name="active_skin_target" value="parade">
        <button type="submit" name="reset_pa_bg" class="master-update-btn">RESET FLAG &amp; FIREWORKS TO DEFAULTS</button>
    </form>
    <?php endif; ?>

    <?php if (($target_skin ?? '') === 'instant-camera'): ?>
    <!-- INSTANT CAMERA — measure a custom aspect ratio from one sample image
         (getimagesize → exact W:H). Own multipart form (forms can't nest);
         posts to the ic_aspect_detect handler above. -->
    <div class="box">
        <h3>MEASURE ASPECT RATIO</h3>
        <p class="dim field-hint">Using a faux or digital instant-film format that's a touch off-spec (e.g. VNTG)? Upload <strong>one</strong> sample image — the exact width:height ratio is read straight from its pixels and saved as your Custom format. Instant, no AI, no cost. For the standard formats above, just pick one.</p>
        <form method="POST" enctype="multipart/form-data" id="ic-aspect-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
            <div class="dash-grid">
                <div class="lens-input-wrapper">
                    <label>SAMPLE IMAGE</label>
                    <input type="file" id="ic-scan-input" name="ic_scan" accept="image/jpeg,image/png,image/webp">
                    <div id="ic-scan-preview" style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;"></div>
                    <div id="ic-scan-progress" style="display:none;height:6px;background:rgba(127,127,127,.25);border-radius:3px;margin-top:10px;overflow:hidden;">
                        <div id="ic-scan-bar" style="height:100%;width:0;background:var(--accent,#39FF14);transition:width .15s;"></div>
                    </div>
                </div>
            </div>
            <div class="form-action-row">
                <button type="submit" name="ic_aspect_detect" class="master-update-btn">MEASURE ASPECT RATIO</button>
            </div>
        </form>
        <script src="<?php echo BASE_URL; ?>assets/js/ss-engine-scan-upload.js?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>"></script>
    </div>
    <?php endif; ?>

<?php elseif ($active_tab === 'mobile'): ?>
    <!-- ============================================================
         TAB 3: MOBILE (mobile-only skins — auto-served to phones,
         hidden from the gallery, configured here)
         ============================================================ -->
    <?php
    // Enumerate every mobile-only skin (manifest features.mobile_only === true).
    // Photogram today; Telegram + others appear automatically when installed.
    $mobile_skins = [];
    foreach (glob('skins/*', GLOB_ONLYDIR) as $_md) {
        $_ms = basename($_md);
        if (!is_file($_md . '/manifest.json')) continue;
        $_mm = snapsmack_load_manifest($_md . '/manifest.json');
        if (is_array($_mm) && !empty($_mm['features']['mobile_only'])) {
            $mobile_skins[$_ms] = $_mm['name'] ?? ucfirst($_ms);
        }
    }
    ?>
    <div class="box">
        <h3>MOBILE SKINS</h3>
        <p class="dim field-hint">MOBILE-ONLY SKINS ARE SERVED AUTOMATICALLY ON PHONES AND ARE HIDDEN FROM THE GALLERY. SET EACH ONE'S PROFILE AVATAR HERE. MORE MOBILE OPTIONS COMING.</p>
    </div>
    <?php if (empty($mobile_skins)): ?>
        <div class="box"><p class="dim">No mobile-only skins are installed.</p></div>
    <?php else: foreach ($mobile_skins as $_ms => $_mname):
        $_mav     = $settings[$_ms . '__skin_avatar'] ?? '';
        $_mav_url = $_mav ? BASE_URL . ltrim($_mav, '/') : '';
    ?>
        <div class="box">
            <h3><?php echo strtoupper(htmlspecialchars($_mname)); ?></h3>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="mobile_skin_slug" value="<?php echo htmlspecialchars($_ms); ?>">
                <div class="dash-grid">
                    <div class="lens-input-wrapper">
                        <label>PROFILE AVATAR</label>
                        <?php if ($_mav_url): ?>
                        <div style="margin-bottom:8px;">
                            <img src="<?php echo htmlspecialchars($_mav_url); ?>" style="width:72px;height:72px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.15);display:block;">
                        </div>
                        <?php endif; ?>
                        <div class="file-upload-wrapper" onclick="document.getElementById('mob-av-<?php echo $_ms; ?>').click()">
                            <div class="file-custom-btn">UPLOAD</div>
                            <div class="file-name-display" id="mob-av-name-<?php echo $_ms; ?>"><?php echo $_mav ? 'CURRENT' : 'SELECT FILE'; ?></div>
                        </div>
                        <input type="file" id="mob-av-<?php echo $_ms; ?>" name="mobile_avatar"
                               accept="image/jpeg,image/png,image/webp,image/gif" style="display:none;"
                               onchange="document.getElementById('mob-av-name-<?php echo $_ms; ?>').innerText = (this.files[0] ? this.files[0].name : 'SELECT FILE')">
                        <p class="dim field-hint" style="margin-top:4px;">SQUARE IMAGE. SHOWN AT THE TOP OF THE FEED AND PROFILE.</p>
                    </div>
                </div>
                <button type="submit" name="save_mobile_avatar" class="master-update-btn">SAVE <?php echo strtoupper(htmlspecialchars($_mname)); ?> AVATAR</button>
            </form>
        </div>
    <?php endforeach; endif; ?>

<?php elseif ($active_tab === 'gallery'): ?>
    <!-- ============================================================
         TAB 2: GALLERY (browse, install, update, remove skins)
         ============================================================ -->

    <?php if (!empty($gallery_msg)): ?>
        <div class="gallery-alert success">> <?php echo htmlspecialchars($gallery_msg); ?></div>
    <?php endif; ?>
    <?php if (!empty($gallery_err)): ?>
        <div class="gallery-alert error">> <?php echo htmlspecialchars($gallery_err); ?></div>
    <?php endif; ?>

    <?php
    // ── Screenshot discovery helper ────────────────────────────────────
    // Returns array of ['file'=>absolute_url, 'label'=>'Archive'|etc.]
    // for all screenshot-*.png files in a skin directory.
    // Uses SKINS_DIR (absolute filesystem path) for reliable file_exists checks
    // regardless of PHP's working directory. Returns BASE_URL-prefixed URLs so
    // links work in subdirectory installs.
    // Falls back to screenshot.png for legacy skins with a single shot.
    function skin_screenshots(string $slug): array {
        $abs_dir = SKINS_DIR . '/' . $slug;   // absolute path for file_exists
        $url_dir = BASE_URL . "skins/{$slug}"; // absolute URL for img src
        $shots   = [];
        $names   = [
            'screenshot-landing.png' => 'Landing',
            'screenshot-archive.png' => 'Archive',
            'screenshot-page.png'    => 'Text Page',
        ];
        foreach ($names as $file => $label) {
            if (file_exists("{$abs_dir}/{$file}")) {
                $shots[] = ['file' => "{$url_dir}/{$file}", 'label' => $label];
            }
        }
        // Fallback: single screenshot.png (legacy)
        if (empty($shots) && file_exists("{$abs_dir}/screenshot.png")) {
            $shots[] = ['file' => "{$url_dir}/screenshot.png", 'label' => 'Preview'];
        }
        return $shots;
    }

    // Renders the screenshot carousel HTML for a skin card.
    // $remote_screenshots: array of ['src'=>url, 'label'=>label] from the registry,
    //   used as fallback when the skin is not locally installed.
    // $remote_screenshot:  single URL string, legacy fallback (used when $remote_screenshots absent).
    function render_skin_screenshots(string $slug, string $skin_name, ?string $remote_screenshot = null, array $remote_screenshots = []): void {
        $shots = skin_screenshots($slug);
        if (!empty($shots)): ?>
            <?php foreach ($shots as $i => $s): ?>
                <img src="<?php echo htmlspecialchars($s['file']); ?>"
                     alt="<?php echo htmlspecialchars($skin_name . ' — ' . $s['label']); ?>"
                     class="<?php echo $i === 0 ? 'ss-active' : ''; ?>"
                     data-label="<?php echo htmlspecialchars($s['label']); ?>"
                     loading="lazy"
                     onerror="this.style.display='none';">
            <?php endforeach; ?>
            <?php if (count($shots) > 1): ?>
                <span class="ss-label"><?php echo htmlspecialchars($shots[0]['label']); ?></span>
                <span class="ss-nav ss-nav-prev" onclick="ssNav(this,-1)">&lsaquo;</span>
                <span class="ss-nav ss-nav-next" onclick="ssNav(this,1)">&rsaquo;</span>
                <span class="ss-dots">
                    <?php foreach ($shots as $i => $s): ?>
                        <span class="ss-dot<?php echo $i === 0 ? ' active' : ''; ?>" onclick="ssGo(this,<?php echo $i; ?>)"></span>
                    <?php endforeach; ?>
                </span>
            <?php endif; ?>
        <?php elseif (!empty($remote_screenshots)): ?>
            <?php foreach ($remote_screenshots as $i => $s): ?>
                <img src="<?php echo htmlspecialchars($s['src']); ?>"
                     alt="<?php echo htmlspecialchars($skin_name . ' — ' . $s['label']); ?>"
                     class="<?php echo $i === 0 ? 'ss-active' : ''; ?>"
                     data-label="<?php echo htmlspecialchars($s['label']); ?>"
                     loading="lazy"
                     onerror="this.style.display='none'; this.parentElement.querySelector('.no-preview') && (this.parentElement.querySelector('.no-preview').style.display='block');">
            <?php endforeach; ?>
            <?php if (count($remote_screenshots) > 1): ?>
                <span class="ss-label"><?php echo htmlspecialchars($remote_screenshots[0]['label']); ?></span>
                <span class="ss-nav ss-nav-prev" onclick="ssNav(this,-1)">&lsaquo;</span>
                <span class="ss-nav ss-nav-next" onclick="ssNav(this,1)">&rsaquo;</span>
                <span class="ss-dots">
                    <?php foreach ($remote_screenshots as $i => $s): ?>
                        <span class="ss-dot<?php echo $i === 0 ? ' active' : ''; ?>" onclick="ssGo(this,<?php echo $i; ?>)"></span>
                    <?php endforeach; ?>
                </span>
            <?php endif; ?>
        <?php elseif ($remote_screenshot): ?>
            <img src="<?php echo htmlspecialchars($remote_screenshot); ?>"
                 alt="<?php echo htmlspecialchars($skin_name); ?>"
                 class="ss-active"
                 loading="lazy"
                 onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
            <span class="no-preview d-none">PREVIEW UNAVAILABLE</span>
        <?php else: ?>
            <span class="no-preview">NO PREVIEW</span>
        <?php endif;
    }

    // Fetch registry and local skin data
    $registry_url = SKIN_REGISTRY_DEFAULT_URL;
    $registry     = skin_registry_fetch($registry_url);
    $local_skins  = skin_registry_local();

    if (isset($registry['error'])):
    ?>
        <div class="box">
            <h3>SKIN REGISTRY</h3>
            <div class="gallery-alert error">> <?php echo htmlspecialchars($registry['error']); ?></div>
            <p class="dim mt-10">
                REGISTRY URL: <span class="dim"><?php echo htmlspecialchars($registry_url); ?></span>
            </p>

            <!-- Fallback: show locally installed skins only -->
            <h3 class="mt-24">INSTALLED SKINS</h3>
            <div class="gallery-grid">
                <?php foreach ($local_skins as $slug => $skin):
                    // Mobile skin is auto-assigned — never user-selectable or visible
                    if (defined('SNAPSMACK_MOBILE_SKIN') && SNAPSMACK_MOBILE_SKIN !== '' && $slug === SNAPSMACK_MOBILE_SKIN) continue;
                    // Mobile-only skins (e.g. photogram) are never shown in the gallery
                    if (!empty($skin['features']['mobile_only'])) continue;
                    // Development skins are not shown in the gallery
                    if (($skin['status'] ?? 'stable') === 'development') continue;
                    // Mode filter: only show skins matching the site's install mode
                    // (same three-mode rule as the main gallery path above).
                    $skin_carousel  = !empty($skin['features']['carousel']);
                    $skin_smacktalk = in_array('smacktalk', $skin['modes'] ?? []);
                    if ($is_carousel  && !$skin_carousel)  continue;
                    if ($is_smacktalk && !$skin_smacktalk) continue;
                    if (!$is_carousel && !$is_smacktalk && ($skin_carousel || $skin_smacktalk)) continue;
                ?>
                    <div class="skin-card" style="cursor:pointer;" onclick="openSkinModal('<?php echo htmlspecialchars($slug); ?>')">
                        <div class="skin-card-screenshot">
                            <?php render_skin_screenshots($slug, $skin['name']); ?>
                        </div>
                        <div class="skin-card-body">
                            <div class="skin-card-header">
                                <span class="skin-card-name"><?php echo htmlspecialchars($skin['name']); ?></span>
                                <span class="skin-card-version">v<?php echo htmlspecialchars($skin['version']); ?></span>
                            </div>
                            <div class="mb-8">
                                <span class="installed-badge <?php echo ($current_db_active === $slug) ? 'active-badge' : ''; ?>">
                                    <?php echo ($current_db_active === $slug) ? 'ACTIVE' : 'INSTALLED'; ?>
                                </span>
                                <span class="status-badge <?php echo htmlspecialchars($skin['status']); ?>">
                                    <?php echo strtoupper($skin['status']); ?>
                                </span>
                            </div>
                            <div class="skin-card-desc"><?php echo htmlspecialchars($skin['description']); ?></div>
                            <div class="skin-card-meta">BY <?php echo strtoupper(htmlspecialchars($skin['author'])); ?></div>
                            <?php if ($current_db_active !== $slug && !in_array($slug, $protected_skins, true)): ?>
                                <div class="skin-card-actions">
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="gallery_action" value="remove">
                                        <input type="hidden" name="skin_slug" value="<?php echo htmlspecialchars($slug); ?>">
                                        <button type="submit" class="gallery-btn remove"
                                                onclick="return confirm('Remove skin \'<?php echo htmlspecialchars($skin['name']); ?>\'? This deletes the skin directory.');">
                                            REMOVE
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    <?php else: ?>
        <?php
        // Registry loaded successfully — compare with local
        $gallery_skins = skin_registry_compare($registry, $local_skins);
        ?>
        <div class="registry-info">
            REGISTRY: <?php echo htmlspecialchars($registry_url); ?>
            &nbsp;|&nbsp;
            <?php echo count($gallery_skins); ?> SKIN<?php echo count($gallery_skins) !== 1 ? 'S' : ''; ?> AVAILABLE
            &nbsp;|&nbsp;
            <a href="smack-skin.php?tab=gallery&refresh=1">REFRESH</a>
        </div>

        <?php
        // Handle manual cache refresh
        if (isset($_GET['refresh'])) {
            skin_registry_clear_cache();
            header("Location: smack-skin.php?tab=gallery");
            exit;
        }
        ?>

        <div class="gallery-grid">
            <?php foreach ($gallery_skins as $slug => $skin):
                // Mobile skin is auto-assigned — never user-selectable or visible
                if (defined('SNAPSMACK_MOBILE_SKIN') && SNAPSMACK_MOBILE_SKIN !== '' && $slug === SNAPSMACK_MOBILE_SKIN) continue;
                // Mobile-only skins (e.g. photogram) are never shown in the gallery
                if (!empty($skin['features']['mobile_only'])) continue;
                // Development skins are not shown in the gallery
                if (($skin['status'] ?? 'stable') === 'development') continue;
                // Mode filter: only show skins matching the site's install mode.
                // Mirrors the Customize selector — carousel sites see carousel skins;
                // SMACKTALK sites see only skins declaring 'smacktalk' in modes[]
                // (Alfred, the sole SMACKTALK skin); photoblog sees neither.
                // (Registry entries carry modes[] from the Skin Packager.)
                $skin_carousel  = !empty($skin['features']['carousel']);
                $skin_smacktalk = in_array('smacktalk', $skin['modes'] ?? []);
                if ($is_carousel  && !$skin_carousel)  continue;
                if ($is_smacktalk && !$skin_smacktalk) continue;
                if (!$is_carousel && !$is_smacktalk && ($skin_carousel || $skin_smacktalk)) continue;
            ?>
                <div class="skin-card" style="cursor:pointer;" onclick="openSkinModal('<?php echo htmlspecialchars($slug); ?>')">
                    <!-- Screenshot(s) -->
                    <div class="skin-card-screenshot">
                        <?php render_skin_screenshots($slug, $skin['name'] ?? $slug, $skin['screenshot'] ?? null, $skin['screenshots'] ?? []); ?>
                    </div>

                    <!-- Body -->
                    <div class="skin-card-body">
                        <div class="skin-card-header">
                            <span class="skin-card-name"><?php echo htmlspecialchars($skin['name'] ?? $slug); ?></span>
                            <span class="skin-card-version">v<?php echo htmlspecialchars($skin['version'] ?? '?'); ?></span>
                        </div>

                        <!-- Status + Installed badges -->
                        <div class="skin-badge-row">
                            <span class="status-badge <?php echo htmlspecialchars($skin['status'] ?? 'stable'); ?>">
                                <?php echo strtoupper($skin['status'] ?? 'STABLE'); ?>
                            </span>
                            <?php if ($skin['installed']): ?>
                                <span class="installed-badge <?php echo ($current_db_active === $slug) ? 'active-badge' : ''; ?>">
                                    <?php echo ($current_db_active === $slug) ? 'ACTIVE' : 'INSTALLED'; ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($skin['update_available']): ?>
                                <span class="status-badge beta">UPDATE: v<?php echo htmlspecialchars($skin['version']); ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="skin-card-desc"><?php echo htmlspecialchars($skin['description'] ?? ''); ?></div>

                        <div class="skin-card-meta">
                            BY <?php echo strtoupper(htmlspecialchars($skin['author'] ?? 'Unknown')); ?>
                            <?php if (!empty($skin['download_size'])): ?>
                                &nbsp;|&nbsp; <?php echo round($skin['download_size'] / 1024); ?>KB
                            <?php endif; ?>
                        </div>

                        <!-- Feature tags -->
                        <?php if (!empty($skin['features'])): ?>
                            <div class="skin-card-features">
                                <?php if (!empty($skin['features']['supports_wall'])): ?>
                                    <span class="feature-tag wall">FLOAT</span>
                                <?php else: ?>
                                    <span class="feature-tag no-wall">NO FLOAT</span>
                                <?php endif; ?>
                                <?php foreach (($skin['features']['archive_layouts'] ?? []) as $layout): ?>
                                    <span class="feature-tag layout"><?php echo strtoupper(htmlspecialchars($layout)); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Action buttons -->
                        <div class="skin-card-actions">
                            <?php if ($skin['status'] === 'development'): ?>
                                <!-- Development skins: visible but not installable -->
                                <button class="gallery-btn disabled" disabled title="This skin is under development and cannot be installed yet.">
                                    UNDER DEVELOPMENT
                                </button>

                            <?php elseif ($skin['update_available']): ?>
                                <!-- Update available -->
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="gallery_action" value="update">
                                    <input type="hidden" name="skin_slug" value="<?php echo htmlspecialchars($slug); ?>">
                                    <input type="hidden" name="download_url" value="<?php echo htmlspecialchars($skin['download_url'] ?? ''); ?>">
                                    <input type="hidden" name="signature" value="<?php echo htmlspecialchars($skin['signature'] ?? ''); ?>">
                                    <button type="submit" class="gallery-btn update"
                                            onclick="return confirm('Update \'<?php echo htmlspecialchars($skin['name']); ?>\' from v<?php echo htmlspecialchars($skin['local_version']); ?> to v<?php echo htmlspecialchars($skin['version']); ?>?');">
                                        UPDATE TO v<?php echo strtoupper(htmlspecialchars($skin['version'])); ?>
                                    </button>
                                </form>

                            <?php elseif (!$skin['installed']): ?>
                                <!-- Not installed: offer install -->
                                <?php if (!empty($skin['download_url'])): ?>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                        <input type="hidden" name="gallery_action" value="install">
                                        <input type="hidden" name="skin_slug" value="<?php echo htmlspecialchars($slug); ?>">
                                        <input type="hidden" name="download_url" value="<?php echo htmlspecialchars($skin['download_url']); ?>">
                                        <input type="hidden" name="signature" value="<?php echo htmlspecialchars($skin['signature'] ?? ''); ?>">
                                        <button type="submit" class="gallery-btn install">INSTALL</button>
                                    </form>
                                <?php else: ?>
                                    <button class="gallery-btn disabled" disabled>NO DOWNLOAD</button>
                                <?php endif; ?>

                            <?php else: ?>
                                <!-- Installed and up to date -->
                                <span class="skin-status-current">
                                    UP TO DATE
                                </span>
                                <?php if (!empty($skin['download_url'])): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="gallery_action" value="install">
                                    <input type="hidden" name="skin_slug" value="<?php echo htmlspecialchars($slug); ?>">
                                    <input type="hidden" name="download_url" value="<?php echo htmlspecialchars($skin['download_url']); ?>">
                                    <input type="hidden" name="signature" value="<?php echo htmlspecialchars($skin['signature'] ?? ''); ?>">
                                    <button type="submit" class="gallery-btn reinstall">REINSTALL</button>
                                </form>
                                <?php else: ?>
                                <button class="gallery-btn reinstall" disabled title="No download URL available">REINSTALL</button>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if ($skin['installed'] && $current_db_active !== $slug): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="gallery_action" value="activate">
                                    <input type="hidden" name="skin_slug" value="<?php echo htmlspecialchars($slug); ?>">
                                    <button type="submit" class="gallery-btn activate">ACTIVATE</button>
                                </form>
                            <?php endif; ?>

                            <?php if ($skin['installed'] && $current_db_active !== $slug && !in_array($slug, $protected_skins, true)): ?>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="gallery_action" value="remove">
                                    <input type="hidden" name="skin_slug" value="<?php echo htmlspecialchars($slug); ?>">
                                    <button type="submit" class="gallery-btn remove"
                                            onclick="return confirm('Remove skin \'<?php echo htmlspecialchars($skin['name']); ?>\'? This deletes the entire skin directory.');">
                                        REMOVE
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php
            // Show locally installed skins that are NOT in the registry
            // (custom/local-only skins), filtered by site mode
            foreach ($local_skins as $slug => $skin):
                if (isset($gallery_skins[$slug])) continue;
                // Mobile skin is auto-assigned — never user-selectable or visible
                if (defined('SNAPSMACK_MOBILE_SKIN') && SNAPSMACK_MOBILE_SKIN !== '' && $slug === SNAPSMACK_MOBILE_SKIN) continue;
                // Mobile-only skins (e.g. photogram) are never shown in the gallery
                if (!empty($skin['features']['mobile_only'])) continue;
                // Development skins are not shown in the gallery
                if (($skin['status'] ?? 'stable') === 'development') continue;
                $skin_carousel = !empty($skin['features']['carousel']);
                if ($skin_carousel !== $is_carousel) continue;
            ?>
                <div class="skin-card" style="cursor:pointer;" onclick="openSkinModal('<?php echo htmlspecialchars($slug); ?>')">
                    <div class="skin-card-screenshot">
                        <?php render_skin_screenshots($slug, $skin['name']); ?>
                    </div>
                    <div class="skin-card-body">
                        <div class="skin-card-header">
                            <span class="skin-card-name"><?php echo htmlspecialchars($skin['name']); ?></span>
                            <span class="skin-card-version">v<?php echo htmlspecialchars($skin['version']); ?></span>
                        </div>
                        <div class="skin-badge-row">
                            <span class="status-badge <?php echo htmlspecialchars($skin['status']); ?>">
                                <?php echo strtoupper($skin['status']); ?>
                            </span>
                            <span class="installed-badge <?php echo ($current_db_active === $slug) ? 'active-badge' : ''; ?>">
                                <?php echo ($current_db_active === $slug) ? 'ACTIVE' : 'INSTALLED'; ?>
                            </span>
                            <span class="badge-local-only">LOCAL ONLY</span>
                        </div>
                        <div class="skin-card-desc"><?php echo htmlspecialchars($skin['description']); ?></div>
                        <div class="skin-card-meta">BY <?php echo strtoupper(htmlspecialchars($skin['author'])); ?></div>
                        <?php if ($current_db_active !== $slug && !in_array($slug, $protected_skins, true)): ?>
                            <div class="skin-card-actions">
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                    <input type="hidden" name="gallery_action" value="remove">
                                    <input type="hidden" name="skin_slug" value="<?php echo htmlspecialchars($slug); ?>">
                                    <button type="submit" class="gallery-btn remove"
                                            onclick="return confirm('Remove skin \'<?php echo htmlspecialchars($skin['name']); ?>\'?');">
                                        REMOVE
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php
        // ── Cleanup: remove installed skins the mode filter hides ───────────────
        // The gallery above hides skins that don't fit this site's mode (carousel
        // vs photoblog), plus mobile-only and development skins — which ALSO hid
        // their REMOVE button, so an installed skin you can't use here was stuck on
        // disk with no way to delete it through the CMS. List every such on-disk
        // skin so it can be removed. Active skin + the auto-assigned mobile skin +
        // protected skins are never listed.
        $cleanup_skins = [];
        foreach ($local_skins as $slug => $skin) {
            if ($slug === $current_db_active) continue;
            if (defined('SNAPSMACK_MOBILE_SKIN') && SNAPSMACK_MOBILE_SKIN !== '' && $slug === SNAPSMACK_MOBILE_SKIN) continue;
            if (in_array($slug, $protected_skins, true)) continue;
            $carousel = !empty($skin['features']['carousel']);
            $hidden = !empty($skin['features']['mobile_only'])
                   || (($skin['status'] ?? 'stable') === 'development')
                   || ($carousel !== $is_carousel);
            if ($hidden) $cleanup_skins[$slug] = $skin;
        }
        ?>
        <?php if (!empty($cleanup_skins)): ?>
        <div style="margin-top:28px; padding-top:18px; border-top:1px solid var(--border,#333);">
            <h3 style="margin:0 0 6px; font-size:0.95rem;">INSTALLED, BUT NOT USABLE IN THIS MODE</h3>
            <p style="margin:0 0 14px; font-size:0.85rem; color:var(--text-muted,#888);">
                These skins are on disk but don't fit this site's current mode (or are mobile-only / development),
                so they're hidden from the gallery above. Remove any you don't need — an idle skin is a live
                <code>manifest.json</code> sitting on disk.
            </p>
            <div style="display:flex; flex-direction:column; gap:8px;">
            <?php foreach ($cleanup_skins as $slug => $skin): ?>
                <div style="display:flex; align-items:center; gap:12px; padding:8px 12px; border:1px solid var(--border,#2a2a2a); border-radius:6px;">
                    <span style="font-weight:600; min-width:160px;"><?php echo htmlspecialchars($skin['name'] ?? $slug); ?></span>
                    <span style="color:var(--text-muted,#888); font-size:0.82rem; flex:1;"><?php echo htmlspecialchars($slug); ?> &middot; v<?php echo htmlspecialchars($skin['version'] ?? '?'); ?></span>
                    <form method="POST" style="margin:0;">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="gallery_action" value="remove">
                        <input type="hidden" name="skin_slug" value="<?php echo htmlspecialchars($slug); ?>">
                        <button type="submit" class="gallery-btn remove"
                                onclick="return confirm('Remove skin \'<?php echo htmlspecialchars($skin['name'] ?? $slug); ?>\'? This deletes the skin directory.');">
                            REMOVE
                        </button>
                    </form>
                </div>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>

<?php endif; ?>
</div>

<!-- ============================================================
     SKIN DETAIL MODAL
     ============================================================ -->
<div id="skin-modal-overlay" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:9999;overflow-y:auto;padding:40px 20px;" onclick="if(event.target===this)closeSkinModal()">
    <div id="skin-modal" style="max-width:960px;margin:0 auto;background:var(--bg-primary, #1a1a1a);border:1px solid var(--border, #333);border-radius:8px;overflow:hidden;box-shadow:0 16px 64px rgba(0,0,0,0.5);">
        <!-- Header -->
        <div id="sm-header" style="padding:20px 24px;border-bottom:1px solid var(--border, #333);display:flex;justify-content:space-between;align-items:center;">
            <div>
                <span id="sm-name" style="font-size:1.3rem;font-weight:700;"></span>
                <span id="sm-version" style="font-size:0.85rem;opacity:0.5;margin-left:8px;"></span>
            </div>
            <button onclick="closeSkinModal()" style="background:none;border:none;color:var(--text-dim, #888);font-size:1.5rem;cursor:pointer;padding:4px 8px;">&times;</button>
        </div>

        <!-- Screenshots side by side -->
        <div id="sm-screenshots" style="display:flex;gap:4px;background:#000;"></div>

        <!-- Body -->
        <div style="padding:24px;">
            <!-- Status badges -->
            <div id="sm-badges" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:16px;"></div>

            <!-- Description -->
            <div id="sm-desc" style="line-height:1.6;margin-bottom:20px;color:var(--text-secondary, #aaa);"></div>

            <!-- Author + Demo -->
            <div id="sm-meta" style="font-size:0.8rem;text-transform:uppercase;letter-spacing:1px;color:var(--text-dim, #666);margin-bottom:20px;"></div>

            <!-- Capabilities grid -->
            <div style="margin-bottom:20px;">
                <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:1px;color:var(--text-dim, #666);margin-bottom:8px;">CAPABILITIES</div>
                <div id="sm-caps" style="display:flex;flex-wrap:wrap;gap:6px;"></div>
            </div>

            <!-- Actions -->
            <div id="sm-actions" style="display:flex;gap:12px;justify-content:flex-end;padding-top:16px;border-top:1px solid var(--border, #333);"></div>
        </div>
    </div>
</div>

<?php
// Build a JSON map of all skin data for the modal JS
$modal_skins = [];

// From registry + local comparison
if (isset($gallery_skins)) {
    foreach ($gallery_skins as $slug => $skin) {
        $shots = skin_screenshots($slug);
        $screenshots = [];
        foreach ($shots as $s) {
            $screenshots[] = ['src' => $s['file'], 'label' => $s['label']];
        }
        // Fallback: use registry's screenshots array (multiple) if skin not installed locally
        if (empty($screenshots) && !empty($skin['screenshots'])) {
            $screenshots = $skin['screenshots'];
        }
        // Final fallback: single legacy screenshot URL from registry
        if (empty($screenshots) && !empty($skin['screenshot'])) {
            $screenshots[] = ['src' => $skin['screenshot'], 'label' => 'Preview'];
        }

        // Load features from local manifest if installed
        $features = $skin['features'] ?? [];
        if ($skin['installed'] && skin_manifest_exists($slug)) {
            $m = load_skin_manifest($slug);
            $features = $m['features'] ?? $features;
        }

        $modal_skins[$slug] = [
            'name'        => $skin['name'] ?? $slug,
            'version'     => $skin['version'] ?? '?',
            'author'      => $skin['author'] ?? 'Unknown',
            'description' => $skin['description'] ?? '',
            'status'      => $skin['status'] ?? 'stable',
            'demo_url'    => $skin['demo_url'] ?? '',
            'installed'   => $skin['installed'] ?? false,
            'is_active'   => ($current_db_active === $slug),
            'screenshots' => $screenshots,
            'features'    => $features,
        ];
    }
}

// Add local-only skins not in registry
foreach ($local_skins as $slug => $skin) {
    if (isset($modal_skins[$slug])) continue;
    $shots = skin_screenshots($slug);
    $screenshots = [];
    foreach ($shots as $s) {
        $screenshots[] = ['src' => $s['file'], 'label' => $s['label']];
    }

    $modal_skins[$slug] = [
        'name'        => $skin['name'],
        'version'     => $skin['version'],
        'author'      => $skin['author'],
        'description' => $skin['description'],
        'status'      => $skin['status'],
        'demo_url'    => $skin['demo_url'] ?? '',
        'installed'   => true,
        'is_active'   => ($current_db_active === $slug),
        'screenshots' => $screenshots,
        'features'    => $skin['features'] ?? [],
    ];
}
?>
<script>
var skinModalData = <?php echo json_encode($modal_skins); ?>;

function openSkinModal(slug) {
    var skin = skinModalData[slug];
    if (!skin) return;

    document.getElementById('sm-name').textContent = skin.name;
    document.getElementById('sm-version').textContent = 'v' + skin.version;

    // Screenshots side by side
    var ssHtml = '';
    if (skin.screenshots.length > 0) {
        skin.screenshots.forEach(function(s) {
            ssHtml += '<div style="flex:1;position:relative;aspect-ratio:16/9;overflow:hidden;cursor:pointer;" onclick="openScreenshotLightbox(\'' + s.src.replace(/'/g, "\\'") + '\',\'' + s.label.replace(/'/g, "\\'") + '\')">' +
                '<img src="' + s.src + '" alt="' + s.label + '" style="width:100%;height:100%;object-fit:cover;display:block;" loading="lazy">' +
                '<div style="position:absolute;bottom:0;left:0;right:0;background:rgba(0,0,0,0.6);color:#fff;font-size:0.65rem;text-transform:uppercase;letter-spacing:1px;padding:4px 8px;text-align:center;">' + s.label + '</div>' +
                '</div>';
        });
    } else {
        ssHtml = '<div style="flex:1;aspect-ratio:16/9;display:flex;align-items:center;justify-content:center;color:#555;font-size:0.8rem;text-transform:uppercase;letter-spacing:2px;">No Preview Available</div>';
    }
    document.getElementById('sm-screenshots').innerHTML = ssHtml;

    // Badges
    var badges = '';
    var statusClass = skin.status === 'stable' ? 'color:#4caf50;border-color:#4caf50;' :
                      skin.status === 'beta' ? 'color:#ff9800;border-color:#ff9800;' :
                      'color:#999;border-color:#666;';
    badges += '<span style="font-size:0.7rem;text-transform:uppercase;letter-spacing:1px;padding:2px 8px;border:1px solid;border-radius:3px;' + statusClass + '">' + skin.status.toUpperCase() + '</span>';
    if (skin.is_active) badges += '<span style="font-size:0.7rem;text-transform:uppercase;letter-spacing:1px;padding:2px 8px;border:1px solid;border-radius:3px;color:#4a9eff;border-color:#4a9eff;">ACTIVE</span>';
    else if (skin.installed) badges += '<span style="font-size:0.7rem;text-transform:uppercase;letter-spacing:1px;padding:2px 8px;border:1px solid;border-radius:3px;color:#888;border-color:#555;">INSTALLED</span>';
    document.getElementById('sm-badges').innerHTML = badges;

    // Description
    document.getElementById('sm-desc').textContent = skin.description;

    // Meta
    var meta = 'BY ' + skin.author.toUpperCase();
    if (skin.demo_url) meta += ' | <a href="' + skin.demo_url + '" target="_blank" rel="noopener" style="color:var(--accent, #4a9eff);">LIVE DEMO</a>';
    document.getElementById('sm-meta').innerHTML = meta;

    // Capabilities
    var f = skin.features || {};
    var caps = '';
    function cap(label, val, good) {
        var style = good ? 'background:rgba(76,175,80,0.15);color:#4caf50;border:1px solid rgba(76,175,80,0.3);' :
                           'background:rgba(128,128,128,0.1);color:#888;border:1px solid rgba(128,128,128,0.2);';
        return '<span style="font-size:0.7rem;padding:3px 10px;border-radius:3px;' + style + '">' + label + '</span>';
    }

    caps += cap('LANDING PAGE', f.has_landing, f.has_landing);
    if (f.instagram_mode) caps += cap('INSTAGRAM MODE', true, true);
    if (f.carousel) caps += cap('CAROUSEL', true, true);
    if (f.supports_wall) caps += cap('PHOTO WALL', true, true);

    // Post modes
    var modes = f.post_modes || ['image'];
    modes.forEach(function(m) { caps += cap(m.toUpperCase() + ' POSTS', true, true); });

    // Archive layouts
    (f.archive_layouts || []).forEach(function(l) { caps += cap(l.toUpperCase(), true, true); });

    // Community
    (f.community || []).forEach(function(c) { caps += cap(c.toUpperCase(), true, true); });

    document.getElementById('sm-caps').innerHTML = caps;

    // Actions placeholder (view-only for now)
    document.getElementById('sm-actions').innerHTML = '<button onclick="closeSkinModal()" style="padding:8px 20px;background:var(--bg-secondary, #333);border:1px solid var(--border, #444);border-radius:4px;color:var(--text-primary, #ccc);cursor:pointer;">CLOSE</button>';

    document.getElementById('skin-modal-overlay').style.display = 'block';
    document.addEventListener('keydown', skinModalEsc);
}

function closeSkinModal() {
    document.getElementById('skin-modal-overlay').style.display = 'none';
    document.removeEventListener('keydown', skinModalEsc);
}
function skinModalEsc(e) { if (e.key === 'Escape') closeSkinModal(); }

function openScreenshotLightbox(src, label) {
    var lb = document.getElementById('ss-lightbox');
    if (!lb) {
        lb = document.createElement('div');
        lb.id = 'ss-lightbox';
        lb.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,0.92);display:flex;align-items:center;justify-content:center;cursor:zoom-out;';
        lb.innerHTML = '<img id="ss-lightbox-img" style="max-width:95vw;max-height:95vh;object-fit:contain;display:block;box-shadow:0 0 60px rgba(0,0,0,0.8);" src="" alt="">' +
                       '<div id="ss-lightbox-label" style="position:fixed;bottom:24px;left:50%;transform:translateX(-50%);color:#fff;font-size:0.7rem;text-transform:uppercase;letter-spacing:2px;background:rgba(0,0,0,0.5);padding:4px 14px;border-radius:3px;pointer-events:none;"></div>';
        lb.addEventListener('click', closeScreenshotLightbox);
        document.body.appendChild(lb);
    }
    document.getElementById('ss-lightbox-img').src = src;
    document.getElementById('ss-lightbox-img').alt = label || '';
    document.getElementById('ss-lightbox-label').textContent = label || '';
    lb.style.display = 'flex';
    document.addEventListener('keydown', ssLightboxEsc);
}
function closeScreenshotLightbox() {
    var lb = document.getElementById('ss-lightbox');
    if (lb) lb.style.display = 'none';
    document.removeEventListener('keydown', ssLightboxEsc);
}
function ssLightboxEsc(e) { if (e.key === 'Escape') closeScreenshotLightbox(); }

// Prevent button/form clicks inside skin cards from opening the modal
document.querySelectorAll('.skin-card-actions, .skin-card-actions button, .skin-card-actions form, .ss-nav, .ss-dot').forEach(function(el) {
    el.addEventListener('click', function(e) { e.stopPropagation(); });
});
</script>

<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-font-preview.js?v=<?php echo time(); ?>"></script>
<script>
/* ── Skin screenshot carousel ───────────────────────────────────── */
function ssNav(el, dir) {
    var card  = el.closest('.skin-card-screenshot');
    var imgs  = card.querySelectorAll('img');
    var dots  = card.querySelectorAll('.ss-dot');
    var label = card.querySelector('.ss-label');
    if (imgs.length < 2) return;
    var cur = 0;
    imgs.forEach(function(img, i){ if (img.classList.contains('ss-active')) cur = i; });
    var next = (cur + dir + imgs.length) % imgs.length;
    imgs[cur].classList.remove('ss-active');
    imgs[next].classList.add('ss-active');
    dots.forEach(function(d, i){ d.classList.toggle('active', i === next); });
    if (label) label.textContent = imgs[next].dataset.label || '';
}
function ssGo(el, idx) {
    var card  = el.closest('.skin-card-screenshot');
    var imgs  = card.querySelectorAll('img');
    var dots  = card.querySelectorAll('.ss-dot');
    var label = card.querySelector('.ss-label');
    imgs.forEach(function(img, i){ img.classList.toggle('ss-active', i === idx); });
    dots.forEach(function(d, i){ d.classList.toggle('active', i === idx); });
    if (label) label.textContent = imgs[idx].dataset.label || '';
}
</script>
<?php include 'core/admin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
