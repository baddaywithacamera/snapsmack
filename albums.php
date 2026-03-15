<?php
/**
 * SNAPSMACK - Public Album Listing Page
 * Alpha v0.7.3a
 *
 * Displays all albums that contain at least one published image.
 * Each album shows a representative thumbnail (most recent image),
 * album name, image count, and date of the latest entry. Skins
 * can provide an album-list.php template to control presentation;
 * otherwise a minimal default listing is rendered.
 *
 * Variables passed to skin template (album-list.php):
 *   $albums     — Array of album rows, each containing:
 *                  id, album_name, album_description, img_count,
 *                  latest_date, cover_file, cover_title, cover_slug
 *   $settings   — Full settings array
 *   $site_name  — Site display name
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

try {
    $snapsmack = new SnapSmack($pdo);

    // --- SETTINGS LOADING ---
    $settings = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

    if (!defined('BASE_URL')) {
        $db_url = $settings['site_url'] ?? 'https://example.com/';
        define('BASE_URL', rtrim($db_url, '/') . '/');
    }

    $active_skin = $settings['active_skin'] ?? 'smackdown';
    $site_name = $settings['site_name'] ?? $site_name;

    // Force mobile skin on phones
    if (snapsmack_is_mobile() && is_dir(__DIR__ . '/skins/' . SNAPSMACK_MOBILE_SKIN)) {
        $active_skin = SNAPSMACK_MOBILE_SKIN;
    }

    // Overlay skin-scoped settings
    snapsmack_apply_skin_settings($settings, $active_skin);

    // --- ALBUM QUERY ---
    // Fetch all albums that contain at least one published image.
    // Derives cover image (most recent), image count, and latest date
    // from the image table via the album mapping.
    $now_local = date('Y-m-d H:i:s');

    $albums = $pdo->prepare("
        SELECT
            a.id,
            a.album_name,
            a.album_description,
            COUNT(i.id) AS img_count,
            MAX(i.img_date) AS latest_date,
            cover.img_file AS cover_file,
            cover.img_thumb_square AS cover_thumb,
            cover.img_title AS cover_title,
            cover.img_slug AS cover_slug
        FROM snap_albums a
        INNER JOIN snap_image_album_map m ON a.id = m.album_id
        INNER JOIN snap_images i ON m.image_id = i.id
            AND i.img_status = 'published'
            AND i.img_date <= ?
        LEFT JOIN snap_images cover ON cover.id = (
            SELECT i2.id
            FROM snap_image_album_map m2
            INNER JOIN snap_images i2 ON m2.image_id = i2.id
            WHERE m2.album_id = a.id
                AND i2.img_status = 'published'
                AND i2.img_date <= ?
            ORDER BY i2.img_date DESC
            LIMIT 1
        )
        GROUP BY a.id
        ORDER BY latest_date DESC
    ");
    $albums->execute([$now_local, $now_local]);
    $albums = $albums->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    die("<div style='background:#300;color:#f99;padding:20px;border:1px solid red;font-family:monospace;'><h3>ALBUM_LISTING_ERROR</h3>" . $e->getMessage() . "</div>");
}

$page_title = "Albums";
$skin_path  = 'skins/' . $active_skin;

if (file_exists(__DIR__ . '/' . $skin_path . '/skin-meta.php')) {
    include __DIR__ . '/' . $skin_path . '/skin-meta.php';
}
?>

<body class="album-listing-page">
    <div id="page-wrapper">

        <?php
        $header_file = __DIR__ . '/' . $skin_path . '/skin-header.php';
        include (file_exists($header_file)) ? $header_file : __DIR__ . '/core/header.php';
        ?>

        <div id="scroll-stage">

            <?php
            // Skin-specific album listing template
            $skin_album_list = __DIR__ . '/' . $skin_path . '/album-list.php';
            if (file_exists($skin_album_list)):
                include $skin_album_list;
            else:
            ?>
            <!-- Default album listing (no skin template) -->
            <div style="max-width: 960px; margin: 40px auto; padding: 0 24px;">
                <h2 style="text-transform: uppercase; letter-spacing: 3px; font-size: 14px; margin-bottom: 32px;">Albums</h2>
                <?php if ($albums): ?>
                    <?php foreach ($albums as $album):
                        $album_url = BASE_URL . 'archive.php?album=' . $album['id'];
                        $thumb_url = '';
                        if (!empty($album['cover_thumb'])) {
                            $thumb_url = BASE_URL . ltrim($album['cover_thumb'], '/');
                        } elseif (!empty($album['cover_file'])) {
                            $full = ltrim($album['cover_file'], '/');
                            $fn = basename($full);
                            $dir = str_replace($fn, '', $full);
                            $thumb_url = BASE_URL . $dir . 'thumbs/t_' . $fn;
                        }
                    ?>
                        <div style="display: flex; gap: 24px; margin-bottom: 24px; border: 1px solid #333; padding: 0;">
                            <?php if ($thumb_url): ?>
                                <a href="<?php echo $album_url; ?>" style="flex: 0 0 140px;">
                                    <img src="<?php echo $thumb_url; ?>" alt="" style="width: 140px; height: 140px; object-fit: cover; display: block;">
                                </a>
                            <?php endif; ?>
                            <div style="padding: 20px 20px 20px 0; display: flex; flex-direction: column; justify-content: center;">
                                <a href="<?php echo $album_url; ?>" style="text-transform: uppercase; letter-spacing: 2px; font-size: 14px;">
                                    <?php echo htmlspecialchars($album['album_name']); ?>
                                </a>
                                <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 1px; margin-top: 6px; opacity: 0.6;">
                                    <?php echo $album['img_count']; ?> IMAGE<?php echo $album['img_count'] != 1 ? 'S' : ''; ?>
                                    <?php if (!empty($album['latest_date'])): ?>
                                        &middot; POSTED <?php echo strtoupper(date('j F Y', strtotime($album['latest_date']))); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p style="opacity: 0.5; text-align: center; padding: 60px 0;">No albums found.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php
            $footer_file = __DIR__ . '/' . $skin_path . '/skin-footer.php';
            if (file_exists($footer_file)) include $footer_file;
            ?>
        </div>
    </div>

    <?php include __DIR__ . '/core/footer-scripts.php'; ?>

</body>
</html>
