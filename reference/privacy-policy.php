<?php
/**
 * SNAPSMACK - Privacy Policy Public Page
 *
 * Renders the site's privacy policy using the active skin.
 * Content is managed in the admin at smack-privacy.php.
 * Redirects to homepage if the privacy policy is not enabled or has no content.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/parser.php';
require_once __DIR__ . '/core/skin-settings.php';
require_once __DIR__ . '/core/stats-logger.php';

$settings  = [];
$site_name = '';

try {
    $snapsmack = new SnapSmack($pdo);

    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

    if (!defined('BASE_URL')) {
        $db_url = $settings['site_url'] ?? 'https://example.com/';
        define('BASE_URL', rtrim($db_url, '/') . '/');
    }

    // Redirect if privacy policy is not enabled or has no content
    if (empty($settings['privacy_policy_enabled']) || $settings['privacy_policy_enabled'] !== '1'
        || empty(trim($settings['privacy_policy_content'] ?? ''))) {
        header("Location: " . BASE_URL);
        exit;
    }

    $active_skin = $settings['active_skin'] ?? '50-shades-of-noah-grey';
    $site_name   = $settings['site_name'] ?? '';

    if (snapsmack_is_mobile() && is_dir(__DIR__ . '/skins/' . SNAPSMACK_MOBILE_SKIN)) {
        $active_skin = SNAPSMACK_MOBILE_SKIN;
    }

    snapsmack_apply_skin_settings($settings, $active_skin);

} catch (Exception $e) {
    die("<div style='background:#300;color:#f99;padding:20px;border:1px solid red;font-family:monospace;'><h3>PRIVACY_PAGE_ERROR</h3>" . $e->getMessage() . "</div>");
}

$pp_title   = htmlspecialchars($settings['privacy_policy_title'] ?? 'Privacy Policy');
$pp_content = $settings['privacy_policy_content'] ?? '';
$skin_path  = 'skins/' . $active_skin;

snapsmack_log_hit($pdo, $settings, ['page_type' => 'privacy-policy']);

if (file_exists(__DIR__ . '/' . $skin_path . '/skin-meta.php')) {
    include __DIR__ . '/' . $skin_path . '/skin-meta.php';
}
?>

<body class="static-transmission">
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

            <div class="static-content">
                <h1 class="static-page-title"><?php echo $pp_title; ?></h1>
                <div class="static-page-body">
                    <?php echo $pp_content; ?>
                </div>
            </div>

            <?php
            $footer_file = __DIR__ . '/' . $skin_path . '/skin-footer.php';
            if (file_exists($footer_file)) {
                include $footer_file;
            } else {
                include __DIR__ . '/core/footer.php';
            }
            ?>

        </div>
    </div>
</body>
</html>
<?php // ===== SNAPSMACK EOF =====
