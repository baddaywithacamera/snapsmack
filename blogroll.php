<?php
/**
 * SNAPSMACK - Public blogroll network
 * Alpha v0.7.4
 *
 * Renders a categorized list of external peer links. Inherits static page styling
 * and respects the skin system for headers and footers.
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/skin-settings.php';

// --- INITIALIZATION ---
// Initialize defaults to prevent crashes if settings are missing
$settings    = [];
$site_name   = 'SNAPSMACK';
$active_skin = 'smackdown';

try {
    // --- SETTINGS LOADING ---
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

    // Define BASE_URL with trailing slash for consistent asset loading
    if (!defined('BASE_URL')) {
        $db_url = $settings['site_url'] ?? '/';
        define('BASE_URL', rtrim($db_url, '/') . '/');
    }

    $active_skin = $settings['active_skin'] ?? 'smackdown';
    $site_name   = $settings['site_name'] ?? $site_name;

    // Force Pocket Rocket on mobile devices (phones only, not tablets)
    if (snapsmack_is_mobile() && is_dir(__DIR__ . '/skins/' . SNAPSMACK_MOBILE_SKIN)) {
        $active_skin = SNAPSMACK_MOBILE_SKIN;
    }

    // Overlay skin-scoped settings so each skin retains its own customizations
    snapsmack_apply_skin_settings($settings, $active_skin);

    // --- ACCESS CONTROL ---
    // Redirect to home if blogroll feature is disabled
    if (($settings['blogroll_enabled'] ?? '1') == '0') {
        header("Location: " . (defined('BASE_URL') ? BASE_URL : '/'));
        exit;
    }

    // --- PEER LOOKUP ---
    // Fetch all peer links with their category names for display grouping
    $peers = $pdo->query(
        "SELECT b.*, c.cat_name
         FROM snap_blogroll b
         LEFT JOIN snap_blogroll_cats c ON b.cat_id = c.id
         ORDER BY c.cat_name ASC, b.peer_name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    // Fail-safe error display for database or connection issues
    die("<div style='background:#300;color:#f99;padding:20px;border:1px solid red;font-family:monospace;'><h3>BLOGROLL_TRANSMISSION_ERROR</h3>" . $e->getMessage() . "</div>");
}

$skin_path = 'skins/' . $active_skin;

// Include skin meta template if available
if (file_exists(__DIR__ . '/' . $skin_path . '/skin-meta.php')) {
    include __DIR__ . '/' . $skin_path . '/skin-meta.php';
}
?>

<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/public-facing.css?v=<?php echo time(); ?>">

<body class="static-transmission is-blogroll">
    <div id="page-wrapper">
        <div id="scroll-stage">

            <?php
            // Load header from active skin or fall back to core default
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
                    // --- PEER GROUPING ---
                    // Organize peers into an associative array keyed by category name
                    $grouped = [];
                    foreach ($peers as $p) {
                        $cat = $p['cat_name'] ?: 'UNCATEGORIZED';
                        $grouped[$cat][] = $p;
                    }

                    // Render each category block
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
            // Load skin footer if present
            $footer_file = __DIR__ . '/' . $skin_path . '/skin-footer.php';
            if (file_exists($footer_file)) {
                include $footer_file;
            }
            ?>

        </div></div>
    <?php include __DIR__ . '/core/footer-scripts.php'; ?>
</body>
</html>
