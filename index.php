<?php
/**
 * SnapSmack Public Controller
 * Version: PRO-4.9.1 - The Pencil (Orphan Scrubbed)
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/parser.php'; 

// 1. INITIALIZE SCOPE
$settings = [];
$site_name = 'ISWA.CA';
$active_skin = 'new_horizon_dark'; // FIXED: Updated fallback from smackdown
$prev_slug = $next_slug = $first_slug = $last_slug = "";
$comment_count = 0;

try {
    $snapsmack = new SnapSmack($pdo); 
    
    // Fetch Settings
    $settings_stmt = $pdo->query("SELECT setting_key, setting_val FROM snap_settings");
    $settings = $settings_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // 2. THE PENCIL: Define BASE_URL (Force Trailing Slash)
    if (!defined('BASE_URL')) {
        // Fallback updated to current domain
        $db_url = $settings['site_url'] ?? 'https://iswa.ca/';
        define('BASE_URL', rtrim($db_url, '/') . '/'); 
    }

    // Overwrite defaults with DB values if they exist
    $active_skin = $settings['active_skin'] ?? $active_skin;
    $site_name = $settings['site_name'] ?? $site_name;

    // 3. ROUTING
    $path_info = $_SERVER['PATH_INFO'] ?? '';
    $requested_slug = trim($path_info, '/');
    if (empty($requested_slug)) $requested_slug = $_GET['s'] ?? $_GET['name'] ?? null;

    // 4. IMAGE QUERY
    if ($requested_slug) {
        $stmt = $pdo->prepare("SELECT * FROM snap_images WHERE img_slug = ? AND img_status = 'published' LIMIT 1");
        $stmt->execute([$requested_slug]);
    } else {
        $stmt = $pdo->query("SELECT * FROM snap_images WHERE img_status = 'published' ORDER BY img_date DESC LIMIT 1");
    }
    $img = $stmt->fetch(PDO::FETCH_ASSOC);

    // 5. NAVIGATION (Clean Link Logic)
    $where_live = "WHERE img_status = 'published' AND img_date <= NOW()";
    
    // First/Last logic
    $f_res = $pdo->query("SELECT img_slug FROM snap_images $where_live ORDER BY img_date ASC LIMIT 1")->fetchColumn();
    if ($f_res) $first_slug = BASE_URL . $f_res;

    $l_res = $pdo->query("SELECT img_slug FROM snap_images $where_live ORDER BY img_date DESC LIMIT 1")->fetchColumn();
    if ($l_res) $last_slug = BASE_URL . $l_res;

    if ($img) {
        $current_date = $img['img_date'];
        
        // Previous
        $p_stmt = $pdo->prepare("SELECT img_slug FROM snap_images WHERE img_date < ? AND img_status = 'published' ORDER BY img_date DESC LIMIT 1");
        $p_stmt->execute([$current_date]);
        $p_res = $p_stmt->fetchColumn();
        if ($p_res) $prev_slug = BASE_URL . $p_res;

        // Next
        $n_stmt = $pdo->prepare("SELECT img_slug FROM snap_images WHERE img_date > ? AND img_status = 'published' ORDER BY img_date ASC LIMIT 1");
        $n_stmt->execute([$current_date]);
        $n_res = $n_stmt->fetchColumn();
        if ($n_res) $next_slug = BASE_URL . $n_res;

        // Comment Count
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
<link rel="stylesheet" href="<?php echo BASE_URL; ?>skins/<?php echo $active_skin; ?>/style.css?v=<?php echo time(); ?>">
<body class="is-photo-page">
<div id="page-wrapper">
    <?php 
    if ($img && file_exists(__DIR__ . '/' . $skin_path . '/layout.php')) {
        include __DIR__ . '/' . $skin_path . '/layout.php'; 
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