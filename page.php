<?php
/**
 * SNAPSMACK - Static page renderer
 * Alpha v0.7.3
 *
 * Loads and displays a single static page by slug from the snap_pages table.
 * Redirects to archive if the requested page does not exist or is inactive.
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/parser.php';
require_once __DIR__ . '/core/skin-settings.php';

// --- INITIALIZATION ---
$settings = [];
$site_name = 'ISWA.CA';
$active_skin = 'smackdown';
$slug = isset($_GET['slug']) ? $_GET['slug'] : 'about';

try {
    $snapsmack = new SnapSmack($pdo);

    // --- SETTINGS LOADING ---
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

    // Define BASE_URL from database. Source of truth for site URL.
    if (!defined('BASE_URL')) {
        $db_url = $settings['site_url'] ?? 'https://example.com/';
        define('BASE_URL', rtrim($db_url, '/') . '/');
    }

    $active_skin = $settings['active_skin'] ?? 'smackdown';
    $site_name = $settings['site_name'] ?? $site_name;

    // Force Pocket Rocket on mobile devices (phones only, not tablets)
    if (snapsmack_is_mobile() && is_dir(__DIR__ . '/skins/' . SNAPSMACK_MOBILE_SKIN)) {
        $active_skin = SNAPSMACK_MOBILE_SKIN;
    }

    // Overlay skin-scoped settings so each skin retains its own customizations
    snapsmack_apply_skin_settings($settings, $active_skin);

    // --- PAGE LOOKUP ---
    $page_stmt = $pdo->prepare("SELECT * FROM snap_pages WHERE slug = ? AND is_active = 1 LIMIT 1");
    $page_stmt->execute([$slug]);
    $page_data = $page_stmt->fetch();

    if (!$page_data) {
        // Safe redirect to archive if page does not exist or is inactive
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

<body class="static-transmission">
    <div id="page-wrapper">

        <div id="scroll-stage">

            <?php
            // Header is placed inside scroll-stage so it scrolls with page content
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
                    // Render page content using the parser. Content is stored in the database.
                    if (!empty($page_data['content'])) {
                        echo $snapsmack->parseContent($page_data['content']);
                    } else {
                        echo "<p class='dim'>No content signal found for this sector.</p>";
                    }
                    ?>
                </div>
            </div>

            <?php
            // Footer is placed inside scroll-stage so it scrolls with page content
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
