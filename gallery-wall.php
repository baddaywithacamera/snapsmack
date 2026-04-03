<?php
/**
 * SNAPSMACK - Floating Gallery 3D experience
 * Alpha v0.7.7
 *
 * Desktop-only interactive 3D gallery. Redirects to archive on mobile devices
 * or if the skin doesn't support the floating gallery feature. Integrates typography,
 * colors, and shadow settings from the active skin manifest.
 */

require_once 'core/db.php';
require_once 'core/skin-settings.php';

// --- SETTINGS LOADING ---
try {
    $settings_stmt = $pdo->query("SELECT setting_key, setting_val FROM snap_settings");
    $settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (Exception $e) { $settings = []; }

// --- BASE URL BOOTSTRAP ---
// Floating Gallery is standalone so it bootstraps BASE_URL here before any includes
if (!defined('BASE_URL')) {
    $db_defined_url = $settings['site_url'] ?? '/';
    $final_base = rtrim($db_defined_url, '/') . '/';
    define('BASE_URL', $final_base);
}

// --- MOBILE REDIRECT ---
// Floating gallery is desktop-only. Phones get redirected to archive.
// constants.php is loaded via core/db.php above.
if (snapsmack_is_mobile()) {
    header("Location: archive.php");
    exit;
}

// --- SKIN MANIFEST & SUPPORT CHECK ---
$active_skin = $settings['active_skin'] ?? '';
snapsmack_apply_skin_settings($settings, $active_skin);
$manifest = [];
if ($active_skin && file_exists("skins/{$active_skin}/manifest.php")) {
    $manifest = include "skins/{$active_skin}/manifest.php";
}

$supports_wall = !empty($manifest['features']['supports_wall']);
$wall_enabled  = ($settings['show_wall_link'] ?? '1') === '1';

// Redirect if skin doesn't support wall or admin disabled it
if (!$supports_wall || !$wall_enabled) {
    header("Location: archive.php");
    exit;
}

// --- FONT INVENTORY ---
$inventory = include 'core/manifest-inventory.php';
$fonts     = $inventory['fonts'] ?? [];

// --- WALL CONFIGURATION ---
// Loads physics, dimensions, and style parameters from settings
$wall_friction   = (float)($settings['wall_friction']   ?? 0.96);
$wall_dragweight = (float)($settings['wall_dragweight'] ?? 2.5);
$wall_theme      = $settings['wall_theme']              ?? '#000000';
$pinch_power     = (int)($settings['pinch_sensitivity'] ?? 30);
$wall_limit      = (int)($settings['wall_limit']        ?? 40);
$wall_rows       = max(1, min(5, (int)($settings['wall_rows'] ?? 2)));
$wall_gap        = (int)($settings['wall_gap']          ?? 24);
$wall_reflect    = ($settings['wall_reflect']           ?? '0') === '1';

// --- LAYOUT CALCULATIONS ---
// Grid rows and gap are set via CSS variables; tiles size themselves via 1fr.
// No per-tile height calculation needed.

// --- IMAGE FETCH ---
// Respects publication date and timestamp filtering (timezone set in core/db.php)
$now_local = date('Y-m-d H:i:s');

try {
    $count_stmt   = $pdo->prepare("SELECT COUNT(*) FROM snap_images WHERE img_status = 'published' AND img_date <= ?");
    $count_stmt->execute([$now_local]);
    $total_images = (int)$count_stmt->fetchColumn();
} catch (Exception $e) { $total_images = 0; }

try {
    $stmt = $pdo->prepare("SELECT id, img_title, img_file, img_thumb_aspect FROM snap_images
                           WHERE img_status = 'published'
                           AND img_date <= :now_local
                           ORDER BY sort_order ASC, img_date DESC LIMIT :limit");
    $stmt->bindValue(':now_local', $now_local, PDO::PARAM_STR);
    $stmt->bindValue(':limit', (int)$wall_limit, PDO::PARAM_INT);
    $stmt->execute();
    $images = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $images = []; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php
    // Core meta: SEO, dynamic CSS from skin, public-facing.css
    include 'core/meta.php';
    ?>

    <title>Floating Gallery | <?php echo htmlspecialchars($settings['site_name'] ?? 'SnapSmack'); ?></title>

    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/ss-engine-wall.css?v=<?php echo time(); ?>">

    <script>
    // Mobile gate: Redirect touch devices or small screens to archive.
    // Runs in <head> before body render as a client-side fallback for the PHP
    // user-agent check (some devices slip through server-side sniffing).
    if (window.innerWidth < 768 || ('ontouchstart' in window && window.innerWidth < 1024)) {
        window.location.replace('archive.php');
    }
    </script>
</head>
<body class="is-wall<?php echo $wall_reflect ? ' wall-reflect' : ''; ?>"
      style="--wall-bg:<?php echo htmlspecialchars($wall_theme); ?>; --wall-gap:<?php echo $wall_gap; ?>px; --wall-rows:<?php echo $wall_rows; ?>;">

<div class="wall-viewport">
    <div class="wall-canvas" id="wall-canvas"
         data-friction="<?php echo $wall_friction; ?>"
         data-drag-weight="<?php echo $wall_dragweight; ?>"
         data-pinch-power="<?php echo $pinch_power; ?>"
         data-total-images="<?php echo $total_images; ?>"
         data-initial-limit="<?php echo $wall_limit; ?>">
        <?php foreach ($images as $img): ?>
            <div class="wall-tile" data-full="<?php echo htmlspecialchars($img['img_file']); ?>">
                <?php $thumb = !empty($img['img_thumb_aspect']) ? $img['img_thumb_aspect'] : $img['img_file']; ?>
                <img src="<?php echo htmlspecialchars($thumb); ?>" alt="Smack" loading="eager" decoding="async">
            </div>
        <?php endforeach; ?>
        <div id="wall-sentinel" class="wall-sentinel"></div>
    </div>
</div>

<div id="zoom-layer"></div>
<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-wall.js?v=<?php echo time(); ?>"></script>
<?php include __DIR__ . '/core/footer-scripts.php'; ?>
</body>
</html>
