<?php
/**
 * SNAPSMACK - Main public controller that handles image display and navigation
 * Alpha v0.6
 *
 * Routes requests to images by slug, loads the active skin template, and
 * manages navigation between published images with proper timestamp filtering.
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/parser.php';

// --- INITIALIZATION ---
$settings = [];
$site_name = 'ISWA.CA';
$active_skin = 'new-horizon-dark';
$prev_slug = $next_slug = $first_slug = $last_slug = "";
$comment_count = 0;

try {
    $snapsmack = new SnapSmack($pdo);

    // --- SETTINGS LOADING ---
    $settings_stmt = $pdo->query("SELECT setting_key, setting_val FROM snap_settings");
    $settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // Define BASE_URL from settings or fallback. Ensures trailing slash for consistent routing.
    if (!defined('BASE_URL')) {
        $db_url = $settings['site_url'] ?? 'https://iswa.ca/';
        define('BASE_URL', rtrim($db_url, '/') . '/');
    }

    // Override defaults with database values if they exist
    $active_skin = $settings['active_skin'] ?? $active_skin;
    $site_name = $settings['site_name'] ?? $site_name;

    // Force Pocket Rocket on mobile devices (phones only, not tablets)
    if (snapsmack_is_mobile() && is_dir(__DIR__ . '/skins/' . SNAPSMACK_MOBILE_SKIN)) {
        $active_skin = SNAPSMACK_MOBILE_SKIN;
    }

    // --- REQUEST ROUTING ---
    $path_info = $_SERVER['PATH_INFO'] ?? '';
    $requested_slug = trim($path_info, '/');
    if (empty($requested_slug)) $requested_slug = $_GET['s'] ?? $_GET['name'] ?? null;

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
<?php include __DIR__ . '/core/footer-scripts.php'; ?>
</body>
</html>
