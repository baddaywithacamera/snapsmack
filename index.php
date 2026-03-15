<?php
/**
 * SNAPSMACK - Main public controller that handles image display and navigation
 * Alpha v0.7.3a
 *
 * Routes requests to images by slug, loads the active skin template, and
 * manages navigation between published images with proper timestamp filtering.
 *
 * Supports homepage_mode setting:
 *   - 'latest_post'  (default) — shows latest published image via skin landing/layout
 *   - 'static_page'           — renders a chosen static page as homepage; blog moves to blog.php
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/parser.php';
require_once __DIR__ . '/core/skin-settings.php';

// --- INITIALIZATION ---
$settings = [];
$site_name = 'ISWA.CA';
$active_skin = 'new-horizon';
$prev_slug = $next_slug = $first_slug = $last_slug = "";
$comment_count = 0;

try {
    $snapsmack = new SnapSmack($pdo);

    // --- SETTINGS LOADING ---
    $settings_stmt = $pdo->query("SELECT setting_key, setting_val FROM snap_settings");
    $settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Define BASE_URL from settings or fallback. Ensures trailing slash for consistent routing.
    if (!defined('BASE_URL')) {
        $db_url = $settings['site_url'] ?? 'https://example.com/';
        define('BASE_URL', rtrim($db_url, '/') . '/');
    }

    // Override defaults with database values if they exist
    $active_skin = $settings['active_skin'] ?? $active_skin;
    $site_name = $settings['site_name'] ?? $site_name;

    // Force Pocket Rocket on mobile devices (phones only, not tablets)
    if (snapsmack_is_mobile() && is_dir(__DIR__ . '/skins/' . SNAPSMACK_MOBILE_SKIN)) {
        $active_skin = SNAPSMACK_MOBILE_SKIN;
    }

    // Overlay skin-scoped settings so each skin retains its own customizations
    snapsmack_apply_skin_settings($settings, $active_skin);

    // --- HOMEPAGE MODE: STATIC PAGE ---
    // If no specific slug is requested and homepage_mode is static_page, render the
    // chosen page using the same pattern as page.php instead of the image feed.
    $path_info = $_SERVER['PATH_INFO'] ?? '';
    $requested_slug = trim($path_info, '/');
    if (empty($requested_slug)) $requested_slug = $_GET['s'] ?? $_GET['name'] ?? null;

    $homepage_mode   = $settings['homepage_mode'] ?? 'latest_post';
    $homepage_page_id = (int)($settings['homepage_page_id'] ?? 0);

    $force_blog = !empty($_SERVER['SNAPSMACK_FORCE_BLOG']);

    if (!$force_blog && !$requested_slug && $homepage_mode === 'static_page' && $homepage_page_id > 0) {
        // Load the static page from snap_pages
        $hp_stmt = $pdo->prepare("SELECT * FROM snap_pages WHERE id = ? AND is_active = 1 LIMIT 1");
        $hp_stmt->execute([$homepage_page_id]);
        $page_data = $hp_stmt->fetch(PDO::FETCH_ASSOC);

        if ($page_data) {
            // Render as static page — reuse the page.php template pattern
            $page_title = htmlspecialchars($page_data['title']);
            $skin_path  = 'skins/' . $active_skin;

            if (file_exists(__DIR__ . '/' . $skin_path . '/skin-meta.php')) {
                include __DIR__ . '/' . $skin_path . '/skin-meta.php';
            }
            ?>
            <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/public-facing.css?v=<?php echo time(); ?>">
            <body class="static-transmission homepage-static">
                <div id="page-wrapper">
                    <div id="scroll-stage">
                        <?php
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
                                if (!empty($page_data['content'])) {
                                    echo $snapsmack->parseContent($page_data['content']);
                                } else {
                                    echo "<p class='dim'>No content signal found for this sector.</p>";
                                }
                                ?>
                            </div>
                        </div>

                        <?php
                        $footer_file = __DIR__ . '/' . $skin_path . '/skin-footer.php';
                        if (file_exists($footer_file)) {
                            include $footer_file;
                        }
                        ?>
                    </div>
                </div>
                <?php
                // Load global JS engines (social dock, sticky header, etc.) unless using Photogram
                if ($active_skin !== 'photogram') {
                    include __DIR__ . '/core/footer-scripts.php';
                }
                ?>
            </body>
            </html>
            <?php
            exit; // Static homepage rendered — stop here
        }
        // If page not found, fall through to normal latest-post behaviour
    }

    // --- HASHTAG ARCHIVE ---
    // ?tag=slug routes to the skin's hashtag.php if it exists
    $requested_tag = trim($_GET['tag'] ?? '');
    if ($requested_tag !== '' && preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,49}$/', $requested_tag)) {
        $hashtag_template = __DIR__ . '/skins/' . $active_skin . '/hashtag.php';
        if (file_exists($hashtag_template)) {
            $requested_tag = strtolower($requested_tag); // normalise
            include __DIR__ . '/skins/' . $active_skin . '/skin-meta.php';
            ?><body class="is-hashtag-page"><div id="page-wrapper"><?php
            include $hashtag_template;
            ?></div><?php
            // Load global JS engines (social dock, sticky header, etc.) unless using Photogram
            if ($active_skin !== 'photogram') {
                include __DIR__ . '/core/footer-scripts.php';
            }
            ?></body></html><?php
            exit;
        }
    }

    // --- REQUEST ROUTING (LATEST POST MODE) ---
    // --- IMAGE LOOKUP ---
    if ($requested_slug) {
        $stmt = $pdo->prepare("SELECT * FROM snap_images WHERE img_slug = ? AND img_status = 'published' LIMIT 1");
        $stmt->execute([$requested_slug]);
    } else {
        $stmt = $pdo->query("SELECT * FROM snap_images WHERE img_status = 'published' ORDER BY img_date DESC LIMIT 1");
    }
    $img = $stmt->fetch(PDO::FETCH_ASSOC);

    // --- NAVIGATION LINKS ---
    // Fetches first, last, previous, and next image slugs based on publication date.
    // Timezone is configured globally in core/db.php.
    $now_local = date('Y-m-d H:i:s');
    $where_live = "WHERE img_status = 'published' AND img_date <= '$now_local'";

    // First/Last slug queries
    $f_res = $pdo->query("SELECT img_slug FROM snap_images $where_live ORDER BY img_date ASC LIMIT 1")->fetchColumn();
    if ($f_res) $first_slug = BASE_URL . $f_res;

    $l_res = $pdo->query("SELECT img_slug FROM snap_images $where_live ORDER BY img_date DESC LIMIT 1")->fetchColumn();
    if ($l_res) $last_slug = BASE_URL . $l_res;

    if ($img) {
        $current_date = $img['img_date'];

        // Previous image link
        $p_stmt = $pdo->prepare("SELECT img_slug FROM snap_images WHERE img_date < ? AND img_status = 'published' ORDER BY img_date DESC LIMIT 1");
        $p_stmt->execute([$current_date]);
        $p_res = $p_stmt->fetchColumn();
        if ($p_res) $prev_slug = BASE_URL . $p_res;

        // Next image link
        $n_stmt = $pdo->prepare("SELECT img_slug FROM snap_images WHERE img_date > ? AND img_status = 'published' ORDER BY img_date ASC LIMIT 1");
        $n_stmt->execute([$current_date]);
        $n_res = $n_stmt->fetchColumn();
        if ($n_res) $next_slug = BASE_URL . $n_res;

        // Count approved comments for display
        $c_stmt = $pdo->prepare("SELECT COUNT(*) FROM snap_comments WHERE img_id = ? AND is_approved = 1");
        $c_stmt->execute([$img['id']]);
        $comment_count = $c_stmt->fetchColumn();
    }
} catch (Exception $e) {
    die("GATEWAY_HALT: " . $e->getMessage());
}

$skin_path = 'skins/' . $active_skin;
$page_title = $img['img_title'] ?? 'Home';

// ── Early exit for JSON AJAX requests ────────────────────────────────────────
// Must happen BEFORE skin-meta.php outputs any HTML, otherwise
// feed.php cannot set Content-Type: application/json headers.
if (($_GET['format'] ?? '') === 'json' && ($_GET['pg'] ?? '') !== '') {
    $skin_path = 'skins/' . $active_skin;
    $landing_file = __DIR__ . '/' . $skin_path . '/landing.php';
    if (file_exists($landing_file)) {
        include $landing_file;
        exit;
    }
}

include __DIR__ . '/' . $skin_path . '/skin-meta.php';
?>
<body class="is-photo-page">
<div id="page-wrapper">
    <?php
    if ($img && file_exists(__DIR__ . '/' . $skin_path . '/layout.php')) {
        // Skin landing page: if no explicit slug was requested and the skin provides
        // a landing.php (e.g. a gallery slider), show that instead of the single image.
        if (!$requested_slug && file_exists(__DIR__ . '/' . $skin_path . '/landing.php')) {
            include __DIR__ . '/' . $skin_path . '/landing.php';
        } else {
            include __DIR__ . '/' . $skin_path . '/layout.php';
        }
    } else {
        echo "<div class='not-found-msg' style='text-align:center; padding:100px; color:#fff;'><h1>404</h1>Transmission Lost.<br><small>Looking for: $skin_path</small></div>";
    }
    ?>
</div>

<script>
    window.SNAP_DATA = {
        prevUrl: "<?php echo (string)$prev_slug; ?>",
        nextUrl: "<?php echo (string)$next_slug; ?>",
        firstUrl: "<?php echo (string)$first_slug; ?>",
        lastUrl: "<?php echo (string)$last_slug; ?>"
    };
</script>
<?php
// Load global JS engines (social dock, sticky header, etc.) unless using Photogram,
// which has its own mobile-optimized UI and doesn't need these overlays.
if ($active_skin !== 'photogram') {
    include __DIR__ . '/core/footer-scripts.php';
}
?>
</body>
</html>
