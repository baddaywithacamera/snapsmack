<?php
/**
 * SNAPSMACK — Preview Endpoint
 * Alpha v0.7.3
 *
 * Two modes:
 *   Default (AJAX)  — returns JSON with rendered HTML for inline use.
 *   ?mode=full      — returns a standalone HTML page for preview-in-new-tab.
 * Admin-only — requires active session.
 */

require_once __DIR__ . '/core/auth.php';
require_once __DIR__ . '/core/parser.php';
require_once __DIR__ . '/core/skin-settings.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'POST only']);
    exit;
}

$content = $_POST['content'] ?? '';

$snapsmack = new SnapSmack($pdo);
$rendered  = $snapsmack->parseContent($content);

// --- FULL PAGE MODE (new tab preview) ---
if (isset($_GET['mode']) && $_GET['mode'] === 'full') {
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    $active_skin = $settings['active_skin'] ?? 'new-horizon-dark';

    // Force Pocket Rocket on mobile devices (phones only, not tablets)
    if (function_exists('snapsmack_is_mobile') && snapsmack_is_mobile() && is_dir(__DIR__ . '/skins/' . SNAPSMACK_MOBILE_SKIN)) {
        $active_skin = SNAPSMACK_MOBILE_SKIN;
    }

    // Overlay skin-scoped settings so each skin retains its own customizations
    snapsmack_apply_skin_settings($settings, $active_skin);

    if (!defined('BASE_URL')) {
        define('BASE_URL', rtrim($settings['site_url'] ?? '/', '/') . '/');
    }
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PREVIEW — SnapSmack</title>
    <link rel="stylesheet" href="skins/<?php echo htmlspecialchars($active_skin); ?>/style.css">
    <link rel="stylesheet" href="assets/css/columns.css">
    <style>
        body { padding: 40px 20px; max-width: 900px; margin: 0 auto; }
        .preview-banner { text-align: center; font-size: 0.65rem; text-transform: uppercase;
            letter-spacing: 2px; color: #666; border-bottom: 1px solid #333;
            padding-bottom: 12px; margin-bottom: 30px; }
    </style>
</head>
<body>
    <div class="preview-banner">PREVIEW — NOT PUBLISHED</div>
    <div class="description"><?php echo $rendered; ?></div>
</body>
</html>
    <?php
    exit;
}

// --- DEFAULT: JSON MODE (legacy AJAX) ---
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'html'    => '<div class="description">' . $rendered . '</div>'
]);
