<?php
/**
 * SnapSmack - Archive Wall
 * Version: 14.7 - Hotkey Sync
 * MASTER DIRECTIVE: Full file return. 
 * - Fixed: Changed 'show_titles' logic to use visibility so hotkey '1' can override it.
 * - Fixed: Removed redundant CSS already handled in wall-engine.css.
 * - Kept: Signature word-span title logic and physics config.
 */

require_once 'core/db.php'; 

// 1. FETCH SETTINGS
try {
    $settings_stmt = $pdo->query("SELECT setting_key, setting_val FROM snap_settings");
    $settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) { $settings = []; }

// Fallbacks
$wall_rows       = isset($settings['wall_rows']) ? (int)$settings['wall_rows'] : 1;
$wall_gap        = isset($settings['wall_gap']) ? (int)$settings['wall_gap'] : 120;
$wall_friction   = $settings['wall_friction']   ?? 0.96;
$wall_dragweight = $settings['wall_dragweight'] ?? 2.5;
$wall_theme      = $settings['wall_theme']      ?? '#000000';
$pinch_power     = $settings['pinch_sensitivity'] ?? 30;
$wall_limit      = $settings['wall_limit']      ?? 100;

// --- MAX COUNT DISCOVERY ---
try {
    $count_stmt = $pdo->query("SELECT COUNT(*) FROM snap_images WHERE img_status = 'published' AND img_date <= NOW()");
    $total_images = (int)$count_stmt->fetchColumn();
} catch (Exception $e) { $total_images = 0; }

// --- TYPOGRAPHY & SHADOW ENGINE ---
$font_map = [
    'Playfair Display'   => "'Playfair Display', serif",
    'Cinzel'             => "'Cinzel', serif",
    'Cormorant Garamond' => "'Cormorant Garamond', serif",
    'Montserrat'         => "'Montserrat', sans-serif",
    'Lato'               => "'Lato', sans-serif",
    'Courier Prime'      => "'Courier Prime', monospace"
];

$font_ref = $settings['wall_font_ref'] ?? 'Playfair Display';
$font_css = $font_map[$font_ref] ?? $font_map['Playfair Display'];
$text_color = $settings['wall_text_color'] ?? '#808080';
$shad_color = $settings['wall_shadow_color'] ?? '#000000';
$intensity  = $settings['wall_shadow_intensity'] ?? 'heavy';

switch ($intensity) {
    case 'none':  $shadow_css = 'none'; break;
    case 'light': $shadow_css = "0 1px 3px $shad_color"; break;
    case 'heavy':
    default:      $shadow_css = "0 0 10px $shad_color, 0 0 20px $shad_color, 0 4px 8px $shad_color"; break;
}

// 4. Layout Math
$rows_to_render = max(1, min(4, $wall_rows));
$vh_share = 100 / $rows_to_render; 
$gap_adjust = ($wall_gap * ($rows_to_render + 1)) / $rows_to_render;
$tile_style_string = "height: calc({$vh_share}vh - {$gap_adjust}px) !important;";

// 5. FETCH IMAGES
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
    <meta charset="UTF-8">
    <title>Gallery View | <?php echo htmlspecialchars($settings['site_name'] ?? 'SnapSmack'); ?></title>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=Cinzel:wght@400;700&family=Cormorant+Garamond:wght@400;700&family=Montserrat:wght@300;400;700&family=Lato:wght@300;400&family=Courier+Prime&display=swap">
    <link rel="stylesheet" href="assets/css/ss-engine-wall.css">
    <style>
        :root {
            --wall-bg: <?php echo htmlspecialchars($wall_theme); ?>;
            --wall-gap: <?php echo $wall_gap; ?>px;
            --wall-font: <?php echo $font_css; ?>;
            --wall-text: <?php echo htmlspecialchars($text_color); ?>;
            --wall-shadow-string: <?php echo $shadow_css; ?>;
        }

        /* Essential layout overrides for the 'Strips' view */
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
            /* Hotkey '1' toggles visibility. We use this instead of display:none */
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
</head>
<body>
<script>
    window.WALL_CONFIG = {
        friction: <?php echo (float)$wall_friction; ?>,
        dragWeight: <?php echo (float)$wall_dragweight; ?>,
        pinchPower: <?php echo (int)$pinch_power; ?>,
        totalImages: <?php echo $total_images; ?>,
        initialLimit: <?php echo (int)$wall_limit; ?>
    };
</script>
<div class="wall-viewport">
    <div class="wall-canvas" id="wall-canvas">
        <?php foreach ($images as $img): ?>
            <div class="wall-tile" style="<?php echo $tile_style_string; ?>" onclick="zoomImage(this)" data-full="<?php echo htmlspecialchars($img['img_file']); ?>">
                <img src="<?php echo htmlspecialchars($img['img_file']); ?>" alt="Smack" loading="lazy">
                <div class="tile-meta">
                    <?php 
                        $words = explode(' ', htmlspecialchars($img['img_title']));
                        foreach ($words as $word) {
                            if(!empty($word)) echo "<span>" . ucfirst(strtolower($word)) . "</span> ";
                        }
                    ?>
                </div>
            </div>
        <?php endforeach; ?>
        <div id="wall-sentinel" style="width: 1px; height: 1px; pointer-events: none;"></div>
    </div>
</div>
<div id="zoom-layer"></div>
<script src="assets/js/ss-engine-wall.js"></script>
<?php include __DIR__ . '/core/footer-scripts.php'; ?>
</body>
</html>