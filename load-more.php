<?php
/**
 * SNAPSMACK - AJAX infinite loader for floating gallery
 *
 * Backend handler that returns HTML fragments for additional image tiles.
 * Matches layout settings from gallery-wall.php to ensure loaded tiles render correctly.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
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

// --- LAYOUT GEOMETRY ---
// Grid handles row sizing via CSS variables; no per-tile height needed.

// --- REQUEST PARAMETERS ---
// The offset represents how many images are already loaded on the client
$offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;
$limit = 20;

// --- IMAGE BATCH QUERY ---
// Uses PHP-generated timestamp (timezone set in core/db.php) for consistent filtering
$now_local = date('Y-m-d H:i:s');

try {
    $stmt = $pdo->prepare("SELECT id, img_title, img_file, img_thumb_aspect FROM snap_images
                           WHERE img_status = 'published'
                           AND img_date <= :now_local
                           ORDER BY sort_order ASC, img_date DESC LIMIT :limit OFFSET :offset");

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
    $thumb = !empty($img['img_thumb_aspect']) ? $img['img_thumb_aspect'] : $img['img_file'];
    echo '<div class="wall-tile" data-full="' . htmlspecialchars($img['img_file']) . '">';
    echo '  <img src="' . htmlspecialchars($thumb) . '" alt="Smack" loading="eager" decoding="async">';
    echo '</div>';
}
// ===== SNAPSMACK EOF =====
