<?php
/**
 * SnapSmack - Gallery Wall
 * Version: 15.0 - Skin Integration
 * -------------------------------------------------------------------------
 * Desktop-only 3D wall experience. Integrates with skin system for
 * typography, colours, and shadow settings via manifest options.
 * Redirects to archive if: skin doesn't support wall, admin disabled it,
 * or user is on a mobile device (JS gate).
 * -------------------------------------------------------------------------
 */

require_once 'core/db.php';

// 1. FETCH SETTINGS
try {
    $settings_stmt = $pdo->query("SELECT setting_key, setting_val FROM snap_settings");
    $settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) { $settings = []; }

// 2. DEFINE BASE_URL (normally set by core/auth.php or skin headers, but
//    gallery-wall is standalone so we bootstrap it here before any includes)
if (!defined('BASE_URL')) {
    $db_defined_url = $settings['site_url'] ?? '/';
    $final_base = rtrim($db_defined_url, '/') . '/';
    define('BASE_URL', $final_base);
}

// 3. SKIN MANIFEST — Check wall support
$active_skin = $settings['active_skin'] ?? '';
$manifest = [];
if ($active_skin && file_exists("skins/{$active_skin}/manifest.php")) {
    $manifest = include "skins/{$active_skin}/manifest.php";
}

$supports_wall = !empty($manifest['features']['supports_wall']);
$wall_enabled  = ($settings['show_wall_link'] ?? '1') === '1';

// Gate: Redirect if skin doesn't support wall or admin disabled it
if (!$supports_wall || !$wall_enabled) {
    header("Location: archive.php");
    exit;
}

// 4. PULL FONT INVENTORY (replaces hardcoded font map)
$inventory = include 'core/manifest-inventory.php';
$fonts     = $inventory['fonts'] ?? [];

// 5. WALL SETTINGS (from skin manifest options, saved via smack-skin.php)
$wall_friction   = (float)($settings['wall_friction']   ?? 0.96);
$wall_dragweight = (float)($settings['wall_dragweight'] ?? 2.5);
$wall_theme      = $settings['wall_theme']              ?? '#000000';
$pinch_power     = (int)($settings['pinch_sensitivity'] ?? 30);
$wall_limit      = (int)($settings['wall_limit']        ?? 100);
$wall_rows       = max(1, min(4, (int)($settings['wall_rows'] ?? 1)));
$wall_gap        = (int)($settings['wall_gap']          ?? 120);

// Typography
$font_ref   = $settings['wall_font_ref']   ?? 'Playfair Display';
$font_css   = "'{$font_ref}', sans-serif";

// Shadow engine
$shad_color = $settings['wall_shadow_color']     ?? '#000000';
$intensity  = $settings['wall_shadow_intensity']  ?? 'heavy';

switch ($intensity) {
    case 'none':  $shadow_css = 'none'; break;
    case 'light': $shadow_css = "0 1px 3px {$shad_color}"; break;
    case 'heavy':
    default:      $shadow_css = "0 0 10px {$shad_color}, 0 0 20px {$shad_color}, 0 4px 8px {$shad_color}"; break;
}

$text_color = $settings['wall_text_color'] ?? '#808080';

// 6. LAYOUT MATH
$vh_share   = 100 / $wall_rows;
$gap_adjust = ($wall_gap * ($wall_rows + 1)) / $wall_rows;
$tile_style = "height: calc({$vh_share}vh - {$gap_adjust}px) !important;";

// 7. IMAGE COUNT & FETCH
try {
    $count_stmt   = $pdo->query("SELECT COUNT(*) FROM snap_images WHERE img_status = 'published' AND img_date <= NOW()");
    $total_images = (int)$count_stmt->fetchColumn();
} catch (Exception $e) { $total_images = 0; }

try {
    $stmt = $pdo->prepare("SELECT id, img_title, img_file FROM snap_images 
                           WHERE img_status = 'published' 
                           AND img_date <= NOW() 
                           ORDER BY img_date DESC LIMIT :limit");
    $stmt->bindValue(':limit', (int)$wall_limit, PDO::PARAM_INT);
    $stmt->execute();
    $images = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $images = []; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php
    // Core meta: SEO, dynamic CSS blob (SKIN_START/SKIN_END), public-facing.css
    include 'core/meta.php';
    ?>

    <title>Gallery Wall | <?php echo htmlspecialchars($settings['site_name'] ?? 'SnapSmack'); ?></title>

    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/ss-engine-wall.css?v=<?php echo time(); ?>">

    <style>
        :root {
            --wall-bg: <?php echo htmlspecialchars($wall_theme); ?>;
            --wall-gap: <?php echo $wall_gap; ?>px;
            --wall-font: <?php echo $font_css; ?>;
            --wall-text: <?php echo htmlspecialchars($text_color); ?>;
            --wall-shadow-string: <?php echo $shadow_css; ?>;
        }

        /* Strip layout overrides */
        .wall-canvas { 
            display: flex; 
            flex-direction: column; 
            flex-wrap: wrap; 
            align-content: flex-start; 
            height: 100vh; 
            padding-left: var(--wall-gap); 
        }

        .wall-tile { 
            margin-right: var(--wall-gap); 
            margin-bottom: var(--wall-gap); 
        }

        .tile-meta {
            visibility: <?php echo (($settings['show_titles'] ?? '1') == '1') ? 'visible' : 'hidden'; ?>;
            position: absolute;
            bottom: -60px;
            left: 50%;
            transform: translateX(-50%);
            width: max-content;
            text-align: center;
            font-family: var(--wall-font);
            color: var(--wall-text);
            text-shadow: var(--wall-shadow-string);
            opacity: 0; 
            transition: opacity 0.3s ease;
            pointer-events: none;
            white-space: nowrap;
        }

        .wall-tile:hover .tile-meta, 
        .wall-tile.is-centered .tile-meta { 
            opacity: 1; 
        }
    </style>

    <script>
    // MOBILE GATE: Redirect touch/small-screen users to archive
    if (window.innerWidth < 768 || ('ontouchstart' in window && window.innerWidth < 1024)) {
        window.location.replace('archive.php');
    }
    </script>
</head>
<body class="is-wall">

<script>
    window.WALL_CONFIG = {
        friction: <?php echo $wall_friction; ?>,
        dragWeight: <?php echo $wall_dragweight; ?>,
        pinchPower: <?php echo $pinch_power; ?>,
        totalImages: <?php echo $total_images; ?>,
        initialLimit: <?php echo $wall_limit; ?>
    };
</script>

<div class="wall-viewport">
    <div class="wall-canvas" id="wall-canvas">
        <?php foreach ($images as $img): ?>
            <div class="wall-tile" style="<?php echo $tile_style; ?>" onclick="zoomImage(this)" data-full="<?php echo htmlspecialchars($img['img_file']); ?>">
                <img src="<?php echo htmlspecialchars($img['img_file']); ?>" alt="Smack" loading="lazy">
                <div class="tile-meta">
                    <?php 
                        $words = explode(' ', htmlspecialchars($img['img_title']));
                        foreach ($words as $word) {
                            if (!empty($word)) echo "<span>" . ucfirst(strtolower($word)) . "</span> ";
                        }
                    ?>
                </div>
            </div>
        <?php endforeach; ?>
        <div id="wall-sentinel" style="width: 1px; height: 1px; pointer-events: none;"></div>
    </div>
</div>

<div id="zoom-layer"></div>
<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-wall.js?v=<?php echo time(); ?>"></script>
<?php include __DIR__ . '/core/footer-scripts.php'; ?>
</body>
</html>
