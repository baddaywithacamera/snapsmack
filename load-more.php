<?php
/**
 * SNAPSMACK - AJAX Infinite Loader.
 * Backend handler for the gallery wall's lazy-loading functionality.
 * Returns HTML fragments for additional image tiles based on a requested offset.
 * Git Version Official Alpha 0.5
 */

require_once 'core/db.php';

// --- SETTINGS SYNCHRONIZATION ---
// Loads layout variables to ensure injected tiles match the initial page load.
try {
    $settings_stmt = $pdo->query("SELECT setting_key, setting_val FROM snap_settings");
    $settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) { 
    $settings = []; 
}

$wall_rows = isset($settings['wall_rows']) ? (int)$settings['wall_rows'] : 1;
$wall_gap  = isset($settings['wall_gap']) ? (int)$settings['wall_gap'] : 120;

// --- LAYOUT GEOMETRY MATH ---
// This logic must remain identical to gallery-wall.php to maintain grid integrity.
$rows_to_render = max(1, min(4, $wall_rows));
$vh_share = 100 / $rows_to_render; 
$gap_adjust = ($wall_gap * ($rows_to_render + 1)) / $rows_to_render;
$tile_style_string = "height: calc({$vh_share}vh - {$gap_adjust}px) !important;";

// --- REQUEST HANDLING ---
// The 'offset' represents the number of images already displayed on the client side.
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$limit = 20; 

// --- BATCH FETCH ---
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
    // Fail silently on database errors to avoid breaking the front-end JS listener.
    exit; 
}

// --- FRAGMENT RENDERING ---
// Outputs the HTML for the wall tiles. wall-engine.js receives this and appends it to the canvas.
foreach ($batch as $img) {
    echo '<div class="wall-tile" style="' . $tile_style_string . '" onclick="zoomImage(this)" data-full="' . htmlspecialchars($img['img_file']) . '">';
    echo '  <img src="' . htmlspecialchars($img['img_file']) . '" alt="Smack" loading="lazy">';
    echo '  <div class="tile-meta">';
    // Renders the image title with line-break support.
    echo      nl2br(htmlspecialchars($img['img_title']));
    echo '  </div>';
    echo '</div>';
}