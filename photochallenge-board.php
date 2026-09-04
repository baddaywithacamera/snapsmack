<?php
/**
 * SNAPSMACK - PHOTO CHALLENGE public board (/board)
 *
 * 0.7.621D: served THROUGH the CMS. The site's own skin header (top nav) and
 * skin footer (bottom nav) now wrap the board, so /board matches every other
 * page on the site instead of being a standalone page with its own chrome.
 * The entry grid is the shared [board] renderer (pc_board_embed_html) — same
 * ranked data, same look, no participant image stored: every card hotlinks the
 * origin thumbnail and links, rel=canonical, back to the origin post.
 *
 * 404s (redirects to archive) unless the challenge feed is enabled.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/parser.php';
require_once __DIR__ . '/core/skin-settings.php';
require_once __DIR__ . '/core/stats-logger.php';   // snapsmack_log_hit()
require_once __DIR__ . '/core/fediverse.php';      // pulls core/photochallenge.php (pc_*)

$settings    = [];
$active_skin = 'smackdown';

try {
    $snapsmack = new SnapSmack($pdo);
    $settings  = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

    require_once __DIR__ . '/core/maintenance-gate.php';

    if (!defined('BASE_URL')) {
        define('BASE_URL', rtrim($settings['site_url'] ?? 'https://example.com/', '/') . '/');
    }

    $active_skin = $settings['active_skin'] ?? 'smackdown';
    if (function_exists('snapsmack_is_mobile') && defined('SNAPSMACK_MOBILE_SKIN')
        && snapsmack_is_mobile() && is_dir(__DIR__ . '/skins/' . SNAPSMACK_MOBILE_SKIN)) {
        $active_skin = SNAPSMACK_MOBILE_SKIN;
    }
    if (function_exists('snapsmack_apply_skin_settings')) {
        snapsmack_apply_skin_settings($settings, $active_skin);
    }
} catch (Throwable $e) {
    error_log('BOARD_TRANSMISSION_ERROR: ' . $e->getMessage());
    http_response_code(500);
    die('Sorry — something went wrong loading the board. Please try again shortly.');
}

// The board only exists when the challenge feed is on — same guard as before.
if (!function_exists('pc_enabled') || !pc_enabled($settings) || !pc_feed_enabled($settings)) {
    header('Location: ' . BASE_URL . 'archive.php');
    exit;
}

$esc        = static fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$win        = pc_window($settings);
$tag        = pc_tag($settings);
$state      = $win['open'] ? 'OPEN' : 'CLOSED';
$page_title = 'The Board';
$skin_path  = 'skins/' . $active_skin;

if (function_exists('snapsmack_log_hit')) {
    snapsmack_log_hit($pdo, $settings, ['page_type' => 'page', 'page_slug' => 'board']);
}

if (file_exists(__DIR__ . '/' . $skin_path . '/skin-meta.php')) {
    include __DIR__ . '/' . $skin_path . '/skin-meta.php';
}
?>

<body class="static-transmission page-slug-board">
    <div id="page-wrapper">
        <div id="scroll-stage">

            <?php
            // Top nav — the site's own header (skin's, else the core fallback).
            $header_file = __DIR__ . '/' . $skin_path . '/skin-header.php';
            if (file_exists($header_file)) {
                include $header_file;
            } else {
                include __DIR__ . '/core/header.php';
            }
            ?>

            <div class="static-content">
                <h1 class="static-page-title"><?php echo $esc($page_title); ?></h1>
                <div class="description">
                    <p class="dim">
                        <strong><?php echo $esc($state); ?></strong> &middot; <?php echo $esc($win['label']); ?>
                        &mdash; post a photo tagged <code>#<?php echo $esc($tag); ?></code> and follow to join.
                    </p>
                    <?php echo pc_board_embed_html($pdo, $settings); ?>
                </div>
            </div>

            <?php
            // Bottom nav — the site's own footer.
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
<?php // ===== SNAPSMACK EOF =====
