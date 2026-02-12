<?php
/**
 * SnapSmack - Infinite Loader
 * Version: 1.2
 * MASTER DIRECTIVE: Relative Pathing Only.
 * - Matches the database and layout logic of gallery-wall.php 14.1
 */

require_once 'core/db.php';

// 1. FETCH SETTINGS (Required for grid consistency)
try {
    $settings_stmt = $pdo->query("SELECT setting_key, setting_val FROM snap_settings");
    $settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) { $settings = []; }

$wall_rows = isset($settings['wall_rows']) ? (int)$settings['wall_rows'] : 1;
$wall_gap  = isset($settings['wall_gap']) ? (int)$settings['wall_gap'] : 120;

// 2. Layout Math (Must match gallery-wall.php exactly)
$rows_to_render = max(1, min(4, $wall_rows));
$vh_share = 100 / $rows_to_render; 
$gap_adjust = ($wall_gap * ($rows_to_render + 1)) / $rows_to_render;
$tile_style_string = "height: calc({$vh_share}vh - {$gap_adjust}px) !important;";

// 3. Get the offset from the JS fetch request
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$limit = 20; 

// 4. Fetch the next batch
try {
    $stmt = $pdo->prepare("SELECT id, img_title, img_file FROM snap_images 
                           WHERE img_status = 'published' 
                           AND img_date <= NOW() 
                           ORDER BY img_date DESC LIMIT :limit OFFSET :offset");
    
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $batch = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    exit; // Quietly exit if DB fails
}

// 5. Render the tiles for the JS to inject
foreach ($batch as $img) {
    echo '<div class="wall-tile" style="' . $tile_style_string . '" onclick="zoomImage(this)" data-full="' . htmlspecialchars($img['img_file']) . '">';
    echo '  <img src="' . htmlspecialchars($img['img_file']) . '" alt="Smack" loading="lazy">';
    echo '  <div class="tile-meta">';
    // Preserving paragraphs as we did in the main file
    echo      nl2br(htmlspecialchars($img['img_title']));
    echo '  </div>';
    echo '</div>';
}