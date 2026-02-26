<?php
/**
 * SNAPSMACK - Static page engine.
 * Renders database-driven content for static pages using the current skin.
 * Supports hero assets, dynamic content parsing, and layout consistency.
 * Git Version Official Alpha 0.5
 */

// Basic error reporting for development and debugging.
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Load core environment and content parsing dependencies.
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/parser.php';

// --- INITIALIZE SCOPE ---
// Setup default variables to prevent errors if settings are missing.
$settings = [];
$site_name = 'ISWA.CA';
$active_skin = 'smackdown';
$slug = isset($_GET['slug']) ? $_GET['slug'] : 'about';

try {
    $snapsmack = new SnapSmack($pdo);

    // Fetch global site configuration.
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Define the base URL for the site, ensuring a consistent trailing slash.
    if (!defined('BASE_URL')) {
        $db_url = $settings['site_url'] ?? 'https://iswa.ca/';
        define('BASE_URL', rtrim($db_url, '/') . '/'); 
    }

    $active_skin = $settings['active_skin'] ?? 'smackdown';
    $site_name = $settings['site_name'] ?? $site_name;

    // --- PAGE DATA FETCH ---
    // Retrieve the specific page content based on the provided slug.
    $page_stmt = $pdo->prepare("SELECT * FROM snap_pages WHERE slug = ? AND is_active = 1 LIMIT 1");
    $page_stmt->execute([$slug]);
    $page_data = $page_stmt->fetch();

    if (!$page_data) {
        // Redirect to the archive browser if the requested page is missing or disabled.
        header("Location: " . BASE_URL . "archive.php");
        exit;
    }

} catch (Exception $e) {
    // Fail-safe error display for database or core initialization issues.
    die("<div style='background:#300;color:#f99;padding:20px;border:1px solid red;font-family:monospace;'><h3>PAGE_TRANSMISSION_ERROR</h3>" . $e->getMessage() . "</div>");
}

$page_title = htmlspecialchars($page_data['title']);
$skin_path  = 'skins/' . $active_skin;

// Include the skin-specific metadata template.
if (file_exists(__DIR__ . '/' . $skin_path . '/meta.php')) {
    include __DIR__ . '/' . $skin_path . '/meta.php';
}
?>

<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/public-facing.css?v=<?php echo time(); ?>">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>skins/<?php echo $active_skin; ?>/style.css?v=<?php echo time(); ?>">

<body class="static-transmission">
    <div id="page-wrapper">
        
        <div id="scroll-stage">
            
            <?php 
            // Header is included inside the scroll stage to allow natural page scrolling.
            $header_file = __DIR__ . '/' . $skin_path . '/header.php';
            if (file_exists($header_file)) {
                include $header_file;
            } else {
                include __DIR__ . '/core/header.php';
            }
            ?>

            <?php if (!empty($page_data['image_asset'])): ?>
                <div id="photobox" class="page-hero">
                    <div class="main-photo">
                        <img src="<?php echo BASE_URL . ltrim($page_data['image_asset'], '/'); ?>" 
                             class="post-image" 
                             alt="<?php echo $page_title; ?>">
                    </div>
                </div>
            <?php endif; ?>

            <div class="static-content">
                <h1 class="static-page-title"><?php echo $page_title; ?></h1>
                
                <div class="description">
                    <?php 
                    // Content Rendering: Processes raw content through the SnapSmack parser.
                    if (!empty($page_data['content'])) {
                        echo $snapsmack->parseContent($page_data['content']); 
                    } else {
                        echo "<p class='dim'>No content signal found for this sector.</p>";
                    }
                    ?>
                </div>
            </div> 

            <?php 
            // Footer inclusion inside the scroll stage.
            $footer_file = __DIR__ . '/' . $skin_path . '/footer.php';
            if (file_exists($footer_file)) {
                include $footer_file; 
            }
            ?>
        </div>
    </div>

    <?php 
    // Load skin-specific footer scripts.
    $scripts_file = __DIR__ . '/' . $skin_path . '/footer-scripts.php';
    if (file_exists($scripts_file)) {
        include $scripts_file;
    }
    ?>
</body>
</html>