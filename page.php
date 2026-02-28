<?php
/**
 * SnapSmack - Static Page Engine
 * Version: PRO-6.2 - Layout Restoration
 * MASTER DIRECTIVE: Full file return. Standardized junctions & scope safety.
 * - FIXED: Body class restored to 'static-transmission' to engage style.css rules.
 * - FIXED: Header moved INSIDE #scroll-stage so it scrolls with content.
 * - FIXED: CSS Cache Buster on style.css to force updates.
 */

// 1. Error Reporting (Safety Valve)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 2. Bootstrap Environment
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/parser.php';

// INITIALIZE SCOPE
$settings = [];
$site_name = 'ISWA.CA';
$active_skin = 'smackdown';
$slug = isset($_GET['slug']) ? $_GET['slug'] : 'about';

try {
    $snapsmack = new SnapSmack($pdo);

    // Fetch Global Settings
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // THE PENCIL: Define BASE_URL (Source of Truth from DB)
    if (!defined('BASE_URL')) {
        $db_url = $settings['site_url'] ?? 'https://iswa.ca/';
        define('BASE_URL', rtrim($db_url, '/') . '/'); 
    }

    $active_skin = $settings['active_skin'] ?? 'smackdown';
    $site_name = $settings['site_name'] ?? $site_name;

    // 3. Fetch Specific Page Data
    $page_stmt = $pdo->prepare("SELECT * FROM snap_pages WHERE slug = ? AND is_active = 1 LIMIT 1");
    $page_stmt->execute([$slug]);
    $page_data = $page_stmt->fetch();

    if (!$page_data) {
        // Safe redirect to archive if slug is non-existent
        header("Location: " . BASE_URL . "archive.php");
        exit;
    }

} catch (Exception $e) {
    die("<div style='background:#300;color:#f99;padding:20px;border:1px solid red;font-family:monospace;'><h3>PAGE_TRANSMISSION_ERROR</h3>" . $e->getMessage() . "</div>");
}

$page_title = htmlspecialchars($page_data['title']);
$skin_path  = 'skins/' . $active_skin;

if (file_exists(__DIR__ . '/' . $skin_path . '/skin-meta.php')) {
    include __DIR__ . '/' . $skin_path . '/skin-meta.php';
}
?>

<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/public-facing.css?v=<?php echo time(); ?>">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>skins/<?php echo $active_skin; ?>/style.css?v=<?php echo time(); ?>">

<body class="static-transmission">
    <div id="page-wrapper">
        
        <div id="scroll-stage">
            
            <?php 
            // FIXED: Header moved INSIDE scroll-stage so it scrolls with the page
            $header_file = __DIR__ . '/' . $skin_path . '/skin-header.php';
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
                    /**
                     * THE RENDER LOGIC
                     * If content is missing here, check the Database 'content' column.
                     */
                    if (!empty($page_data['content'])) {
                        echo $snapsmack->parseContent($page_data['content']); 
                    } else {
                        echo "<p class='dim'>No content signal found for this sector.</p>";
                    }
                    ?>
                </div>
            </div> 

            <?php 
            // FOOTER JUNCTION (Inside scroll-stage)
            $footer_file = __DIR__ . '/' . $skin_path . '/skin-footer.php';
            if (file_exists($footer_file)) {
                include $footer_file; 
            }
            ?>
        </div>
    </div>

    <?php include __DIR__ . '/core/footer-scripts.php'; ?>
</body>
</html>