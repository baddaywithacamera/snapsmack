<?php
/**
 * SNAPSMACK - Public blogroll.
 * Renders a categorized list of external peer links.
 * Inherits static page styling and supports skin-specific headers and footers.
 * Git Version Official Alpha 0.5
 */

// Basic error reporting for development and debugging.
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Load core database connection.
require_once __DIR__ . '/core/db.php';

// Initialize default environment variables to prevent crashes if settings are missing.
$settings    = [];
$site_name   = 'SNAPSMACK';
$active_skin = 'smackdown';

try {
    // Load global site settings.
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

    // Define the base URL, ensuring a consistent trailing slash for asset loading.
    if (!defined('BASE_URL')) {
        $db_url = $settings['site_url'] ?? '/';
        define('BASE_URL', rtrim($db_url, '/') . '/');
    }

    $active_skin = $settings['active_skin'] ?? 'smackdown';
    $site_name   = $settings['site_name'] ?? $site_name;

    // --- ACCESS CONTROL ---
    // Redirect to home if the blogroll feature has been toggled off in settings.
    if (($settings['blogroll_enabled'] ?? '1') == '0') {
        header("Location: " . (defined('BASE_URL') ? BASE_URL : '/'));
        exit;
    }

    // Fetch all peer links, joining with their respective categories for grouping.
    $peers = $pdo->query(
        "SELECT b.*, c.cat_name 
         FROM snap_blogroll b
         LEFT JOIN snap_blogroll_cats c ON b.cat_id = c.id
         ORDER BY c.cat_name ASC, b.peer_name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    // Fail-safe error display for database or connection issues.
    die("<div style='background:#300;color:#f99;padding:20px;border:1px solid red;font-family:monospace;'><h3>BLOGROLL_TRANSMISSION_ERROR</h3>" . $e->getMessage() . "</div>");
}

$skin_path = 'skins/' . $active_skin;

// Include the skin's meta template if available.
if (file_exists(__DIR__ . '/' . $skin_path . '/skin-meta.php')) {
    include __DIR__ . '/' . $skin_path . '/skin-meta.php';
}
?>

<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/public-facing.css?v=<?php echo time(); ?>">

<body class="static-transmission is-blogroll">
    <div id="page-wrapper">
        <div id="scroll-stage">

            <?php
            // Load the header from the active skin or fall back to the core default.
            $header_file = __DIR__ . '/' . $skin_path . '/skin-header.php';
            if (file_exists($header_file)) {
                include $header_file;
            } else {
                include __DIR__ . '/core/header.php';
            }
            ?>

            <div class="blogroll-canvas">
                <h1 class="static-page-title">THE NETWORK</h1>

                <?php if (empty($peers)): ?>
                    <p class="dim">The network is currently offline. No peers found.</p>

                <?php else:
                    // --- DATA GROUPING ---
                    // Organize peer data into an associative array keyed by category name.
                    $grouped = [];
                    foreach ($peers as $p) {
                        $cat = $p['cat_name'] ?: 'UNCATEGORIZED';
                        $grouped[$cat][] = $p;
                    }

                    // Iterate through each category block.
                    foreach ($grouped as $cat_name => $cat_peers):
                ?>
                    <div class="blogroll-category-block">
                        <h2 class="blogroll-category-heading"><?php echo htmlspecialchars(strtoupper($cat_name)); ?></h2>
                        <div class="blogroll-grid">
                            <?php foreach ($cat_peers as $p): ?>
                                <div class="blogroll-peer">
                                    <div class="blogroll-peer-name">
                                        <a href="<?php echo htmlspecialchars($p['peer_url']); ?>" target="_blank" rel="noopener noreferrer">
                                            <?php echo htmlspecialchars($p['peer_name']); ?>
                                        </a>
                                    </div>
                                    <p class="blogroll-peer-desc"><?php echo htmlspecialchars($p['peer_desc']); ?></p>
                                    <span class="blogroll-peer-url dim"><?php echo htmlspecialchars($p['peer_url']); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                <?php endforeach;
                endif; ?>

            </div><?php
            // Load the skin's footer if present.
            $footer_file = __DIR__ . '/' . $skin_path . '/skin-footer.php';
            if (file_exists($footer_file)) {
                include $footer_file;
            }
            ?>

        </div></div>
    <?php include __DIR__ . '/core/footer-scripts.php'; ?>
</body>
</html>