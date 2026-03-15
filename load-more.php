<?php
/**
 * SNAPSMACK - AJAX infinite loader for floating gallery
 * Alpha v0.7.4
 *
 * Backend handler that returns HTML fragments for additional image tiles.
 * Matches layout settings from gallery-wall.php to ensure loaded tiles render correctly.
 */

require_once 'core/db.php';

// --- SETTINGS SYNCHRONIZATION ---
// Loads layout variables to ensure injected tiles match initial page load geometry
try {
    $settings_stmt = $pdo->query("SELECT setting_key, setting_val FROM snap_settings");
    $settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) {
    $settings = [];
}

$wall_rows = isset($settings['wall_rows']) ? (int)$settings['wall_rows'] : 1;
$wall_gap  = isset($settings['wall_gap']) ? (int)$settings['wall_gap'] : 120;

// --- LAYOUT GEOMETRY ---
// Must remain identical to gallery-wall.php to maintain grid integrity when appending new tiles
$rows_to_render = max(1, min(4, $wall_rows));
$vh_share = 100 / $rows_to_render;
$gap_adjust = ($wall_gap * ($rows_to_render + 1)) / $rows_to_render;
$tile_style_string = "height: calc({$vh_share}vh - {$gap_adjust}px) !important;";

// --- REQUEST PARAMETERS ---
// The offset represents how many images are already loaded on the client
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$limit = 20;

// --- IMAGE BATCH QUERY ---
// Uses PHP-generated timestamp (timezone set in core/db.php) for consistent filtering
$now_local = date('Y-m-d H:i:s');

try {
    $stmt = $pdo->prepare("SELECT id, img_title, img_file FROM snap_images
                           WHERE img_status = 'published'
                           AND img_date <= :now_local
                           ORDER BY img_date DESC LIMIT :limit OFFSET :offset");

    $stmt->bindValue(':now_local', $now_local, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $batch = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Fail silently to avoid breaking the front-end JavaScript listener
    exit;
}

// --- TILE RENDERING ---
// Outputs HTML fragments. JavaScript appends these to the wall canvas.
foreach ($batch as $img) {
    echo '<div class="wall-tile" style="' . $tile_style_string . '" onclick="zoomImage(this)" data-full="' . htmlspecialchars($img['img_file']) . '">';
    echo '  <img src="' . htmlspecialchars($img['img_file']) . '" alt="Smack" loading="lazy">';
    echo '  <div class="tile-meta">';
    // Renders title with newline support
    echo      nl2br(htmlspecialchars($img['img_title']));
    echo '  </div>';
    echo '</div>';
}
