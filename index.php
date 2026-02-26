<?php
/**
 * SNAPSMACK - Public controller.
 * Primary entry point for rendering individual photo posts and the homepage.
 * Handles slug routing, navigation logic, and skin-specific layout injection.
 * Git Version Official Alpha 0.5
 */

// Enable error reporting for debugging during Alpha phase.
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Load core dependencies for database and content parsing.
require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/parser.php'; 

// --- INITIALIZE SCOPE ---
// Setup defaults to prevent undefined variable errors in the skin templates.
$settings = [];
$site_name = 'ISWA.CA';
$active_skin = 'new_horizon_dark'; 
$prev_slug = $next_slug = $first_slug = $last_slug = "";
$comment_count = 0;

try {
    $snapsmack = new SnapSmack($pdo); 
    
    // Load all site-wide configuration settings.
    $settings_stmt = $pdo->query("SELECT setting_key, setting_val FROM snap_settings");
    $settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Define the BASE_URL with a forced trailing slash for consistent asset pathing.
    if (!defined('BASE_URL')) {
        $db_url = $settings['site_url'] ?? 'https://iswa.ca/';
        define('BASE_URL', rtrim($db_url, '/') . '/'); 
    }

    // Override local defaults with values from the database.
    $active_skin = $settings['active_skin'] ?? $active_skin;
    $site_name = $settings['site_name'] ?? $site_name;

    // --- ROUTING LOGIC ---
    // Detect the requested post by slug from PATH_INFO or GET parameters.
    $path_info = $_SERVER['PATH_INFO'] ?? '';
    $requested_slug = trim($path_info, '/');
    if (empty($requested_slug)) {
        $requested_slug = $_GET['s'] ?? $_GET['name'] ?? null;
    }

    // --- IMAGE DATA FETCH ---
    if ($requested_slug) {
        // Fetch specific image requested by the user.
        $stmt = $pdo->prepare("SELECT * FROM snap_images WHERE img_slug = ? AND img_status = 'published' LIMIT 1");
        $stmt->execute([$requested_slug]);
    } else {
        // Default to the most recent published image for the homepage.
        $stmt = $pdo->query("SELECT * FROM snap_images WHERE img_status = 'published' ORDER BY img_date DESC LIMIT 1");
    }
    $img = $stmt->fetch(PDO::FETCH_ASSOC);

    // --- NAVIGATION ENGINE ---
    // Build the "First, Last, Prev, Next" links for the gallery.
    $where_live = "WHERE img_status = 'published' AND img_date <= NOW()";
    
    // Discovery: Absolute First and Absolute Last slugs.
    $f_res = $pdo->query("SELECT img_slug FROM snap_images $where_live ORDER BY img_date ASC LIMIT 1")->fetchColumn();
    if ($f_res) $first_slug = BASE_URL . $f_res;

    $l_res = $pdo->query("SELECT img_slug FROM snap_images $where_live ORDER BY img_date DESC LIMIT 1")->fetchColumn();
    if ($l_res) $last_slug = BASE_URL . $l_res;

    if ($img) {
        $current_date = $img['img_date'];
        
        // Find the slug for the image immediately preceding the current one.
        $p_stmt = $pdo->prepare("SELECT img_slug FROM snap_images WHERE img_date < ? AND img_status = 'published' ORDER BY img_date DESC LIMIT 1");
        $p_stmt->execute([$current_date]);
        $p_res = $p_stmt->fetchColumn();
        if ($p_res) $prev_slug = BASE_URL . $p_res;

        // Find the slug for the image immediately following the current one.
        $n_stmt = $pdo->prepare("SELECT img_slug FROM snap_images WHERE img_date > ? AND img_status = 'published' ORDER BY img_date ASC LIMIT 1");
        $n_stmt->execute([$current_date]);
        $n_res = $n_stmt->fetchColumn();
        if ($n_res) $next_slug = BASE_URL . $n_res;

        // Fetch count of approved comments for display in the skin layout.
        $c_stmt = $pdo->prepare("SELECT COUNT(*) FROM snap_comments WHERE img_id = ? AND is_approved = 1");
        $c_stmt->execute([$img['id']]);
        $comment_count = $c_stmt->fetchColumn();
    }
} catch (Exception $e) { 
    // Emergency halt if database or core components fail.
    die("GATEWAY_HALT: " . $e->getMessage()); 
}

$skin_path = 'skins/' . $active_skin;
$page_title = $img['img_title'] ?? 'Home';

// Load the skin's meta header and primary stylesheet.
include __DIR__ . '/' . $skin_path . '/meta.php'; 
?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>skins/<?php echo $active_skin; ?>/style.css?v=<?php echo time(); ?>">

<body class="is-photo-page">
<div id="page-wrapper">
    <?php 
    // Hand over control to the active skin's layout.php file.
    if ($img && file_exists(__DIR__ . '/' . $skin_path . '/layout.php')) {
        include __DIR__ . '/' . $skin_path . '/layout.php'; 
    } else {
        // Fallback display if the requested skin or image is missing.
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
</body>
</html>