<?php
/**
 * SNAPSMACK - Archive browser.
 * Provides a filtered or global view of all published images.
 * Supports filtering by category or album via URL parameters.
 * Git Version Official Alpha 0.5
 */

// Basic error reporting for development and debugging.
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Load core dependencies.
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/parser.php';

// Initialize default environment variables to prevent crashes if settings are missing.
$settings = [];
$site_name = 'ISWA.CA';
$active_skin = 'smackdown';

try {
    $snapsmack = new SnapSmack($pdo);

    // Load global site settings from the database.
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Define the base URL for the site, ensuring a consistent trailing slash.
    if (!defined('BASE_URL')) {
        $db_url = $settings['site_url'] ?? 'https://iswa.ca/';
        define('BASE_URL', rtrim($db_url, '/') . '/'); 
    }

    $active_skin = $settings['active_skin'] ?? 'smackdown';
    $site_name = $settings['site_name'] ?? $site_name;

    // Capture category or album filters from the URL.
    $cat_filter   = isset($_GET['cat']) ? (int)$_GET['cat'] : null;
    $album_filter = isset($_GET['album']) ? (int)$_GET['album'] : null;

    // --- DATA QUERY CONSTRUCTION ---
    // Builds the SQL query dynamically based on whether the user is filtering by category or album.
    $sql = "SELECT i.* FROM snap_images i ";
    $where_clauses = ["i.img_status = 'published'", "i.img_date <= NOW()"];
    $params = [];

    if ($cat_filter) {
        $sql .= "INNER JOIN snap_image_cat_map c ON i.id = c.image_id ";
        $where_clauses[] = "c.cat_id = ?";
        $params[] = $cat_filter;
    } elseif ($album_filter) {
        $sql .= "INNER JOIN snap_image_album_map a ON i.id = a.image_id ";
        $where_clauses[] = "a.album_id = ?";
        $params[] = $album_filter;
    }

    $sql .= " WHERE " . implode(" AND ", $where_clauses);
    $sql .= " ORDER BY i.img_date DESC, i.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $images = $stmt->fetchAll();

    // Fetch lists for the filter dropdown menus.
    $all_cats   = $pdo->query("SELECT * FROM snap_categories ORDER BY cat_name ASC")->fetchAll();
    $all_albums = $pdo->query("SELECT * FROM snap_albums ORDER BY album_name ASC")->fetchAll();

} catch (Exception $e) {
    // Basic fail-safe if the database or core fails to initialize.
    die("<div style='background:#300;color:#f99;padding:20px;border:1px solid red;font-family:monospace;'><h3>ARCHIVE_TRANSMISSION_ERROR</h3>" . $e->getMessage() . "</div>");
}

$page_title = "Archive";
$skin_path  = 'skins/' . $active_skin;

// Include the skin's meta template if it exists.
if (file_exists(__DIR__ . '/' . $skin_path . '/meta.php')) {
    include __DIR__ . '/' . $skin_path . '/meta.php';
}
?>

<body class="archive-page">
    <div id="page-wrapper">
        
        <?php 
        // Load the header from the active skin, or fall back to the core header.
        $header_file = __DIR__ . '/' . $skin_path . '/header.php';
        include (file_exists($header_file)) ? $header_file : __DIR__ . '/core/header.php';
        ?>

        <div id="infobox">
            <div class="nav-links">
                <div class="center">
                    <a href="archive.php" class="<?php echo !$cat_filter && !$album_filter ? 'active' : 'inactive'; ?>">
                        [ SHOW ALL ]
                    </a>
                    <span class="sep">/</span>

                    <div class="filter-group">
                        <label class="dim">REGISTRY:</label>
                        <select onchange="location = this.value;">
                            <option value="archive.php">-- ALL CATEGORIES --</option>
                            <?php foreach($all_cats as $c): ?>
                                <option value="?cat=<?php echo $c['id']; ?>" <?php echo $cat_filter == $c['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($c['cat_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <span class="sep">|</span>

                    <div class="filter-group">
                        <label class="dim">ALBUMS:</label>
                        <select onchange="location = this.value;">
                            <option value="archive.php">-- ALL ALBUMS --</option>
                            <?php foreach($all_albums as $a): ?>
                                <option value="?album=<?php echo $a['id']; ?>" <?php echo $album_filter == $a['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($a['album_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div id="scroll-stage">
            <div id="browse-grid" style="--grid-cols: <?php echo htmlspecialchars($settings['browse_cols'] ?? 4); ?>; --thumb-width: <?php echo htmlspecialchars($settings['thumb_size'] ?? 200); ?>px;">
                <?php if ($images): ?>
                    <?php foreach ($images as $img): ?>
                        <div class="thumb-container">
                            <?php 
                                // Construct the permanent link to the post using its slug.
                                $link = BASE_URL . htmlspecialchars($img['img_slug']);
                                
                                // Determine the path to the thumbnail.
                                // Logic assumes thumbnails are stored in a 'thumbs' subfolder within the image directory.
                                $full_img_path = ltrim($img['img_file'], '/');
                                $filename = basename($full_img_path);
                                $folder = str_replace($filename, '', $full_img_path);
                                
                                $thumb_url = BASE_URL . $folder . 'thumbs/t_' . $filename;
                            ?>
                            <a href="<?php echo $link; ?>" class="thumb-link" title="<?php echo htmlspecialchars($img['img_title']); ?>">
                                <img src="<?php echo $thumb_url; ?>" alt="<?php echo htmlspecialchars($img['img_title']); ?>" loading="lazy">
                            </a>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-sector-msg">NO SIGNALS RECORDED IN THIS SECTOR.</div>
                <?php endif; ?>
            </div>

            <?php 
            // Load the skin's footer if present.
            $footer_file = __DIR__ . '/' . $skin_path . '/footer.php';
            if (file_exists($footer_file)) include $footer_file; 
            ?>
        </div>
    </div>

    <div id="hud" class="hud-msg"></div>

    <?php 
    // Inject any user-defined tracking or analytics scripts from the database.
    if (!empty($settings['footer_injection_scripts'])): 
        echo $settings['footer_injection_scripts']; 
    endif; 
    ?>
</body>
</html>