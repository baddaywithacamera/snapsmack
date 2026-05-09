<?php
/**
 * SNAPSMACK - Public collection landing page (0.7.79 — v0.2)
 *
 * URL: /collection.php?slug=u-s-trip-best-eight
 *      Pretty rewrite to /collection/<slug> can be added later via .htaccess.
 *
 * Renders only if snap_collections.is_visible = 1. Hidden collections 404.
 * Member order = collection's sort_order (curator's choice), not date.
 *
 * Layout contract per _spec/collections-v0_2.md:
 *   - <h1> collection name
 *   - description as intro paragraph
 *   - featured image as full-width hero
 *   - member grid below — uses skin's archive grid render style
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


require_once __DIR__ . '/core/db.php';
require_once __DIR__ . '/core/skin-settings.php';

$slug = trim($_GET['slug'] ?? '');
if ($slug === '') {
    header('Location: collections.php');
    exit;
}

$settings    = $pdo->query("SELECT setting_key, setting_val FROM snap_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$active_skin = $settings['active_skin'] ?? 'smackdown';
$site_name   = $settings['site_name']   ?? 'SNAPSMACK';

if (!defined('BASE_URL')) {
    define('BASE_URL', rtrim($settings['site_url'] ?? '/', '/') . '/');
}

if (snapsmack_is_mobile() && is_dir(__DIR__ . '/skins/' . SNAPSMACK_MOBILE_SKIN)) {
    $active_skin = SNAPSMACK_MOBILE_SKIN;
}

snapsmack_apply_skin_settings($settings, $active_skin);

// Fetch collection — only if visible.
$stmt = $pdo->prepare(
    "SELECT id, name, slug, description, featured_post_id, is_visible
     FROM snap_collections
     WHERE slug = ? AND is_visible = 1
     LIMIT 1"
);
$stmt->execute([$slug]);
$collection = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$collection) {
    http_response_code(404);
    $page_title = 'Collection not found';
    if (file_exists(__DIR__ . '/error404.php')) {
        include __DIR__ . '/error404.php';
        exit;
    }
    echo '<h1>404 — Collection not found</h1>';
    exit;
}

// Fetch members (images only, in curator's sort_order).
$mstmt = $pdo->prepare(
    "SELECT i.id, i.img_title AS title, i.img_slug AS slug,
            i.img_thumb_square, i.img_thumb_aspect, i.img_file,
            i.img_date, ci.sort_order
     FROM snap_collection_items ci
     INNER JOIN snap_images i ON i.id = ci.item_id
     WHERE ci.collection_id = ?
       AND ci.item_type     = 'image'
       AND i.img_status     = 'published'
     ORDER BY ci.sort_order ASC, ci.added_at ASC"
);
$mstmt->execute([$collection['id']]);
$members = $mstmt->fetchAll(PDO::FETCH_ASSOC);

// Featured image: explicit pick if set, else first member.
$featured = null;
if ($collection['featured_post_id']) {
    $fstmt = $pdo->prepare(
        "SELECT id, img_title, img_slug, img_thumb_aspect, img_file
         FROM snap_images WHERE id=? AND img_status='published' LIMIT 1"
    );
    $fstmt->execute([(int)$collection['featured_post_id']]);
    $featured = $fstmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
if (!$featured && !empty($members)) {
    $featured = $members[0];
}

$page_title = $collection['name'];
$skin_path  = 'skins/' . $active_skin;

if (file_exists(__DIR__ . '/' . $skin_path . '/skin-meta.php')) {
    include __DIR__ . '/' . $skin_path . '/skin-meta.php';
}
?>

<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/public-facing.css?v=<?php echo SNAPSMACK_VERSION_SHORT; ?>">

<body class="static-transmission is-collection">
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

            <div class="collection-canvas">
                <h1 class="static-page-title"><?php echo htmlspecialchars($collection['name']); ?></h1>

                <?php if (!empty($collection['description'])): ?>
                    <div class="collection-description">
                        <?php echo nl2br(htmlspecialchars($collection['description'])); ?>
                    </div>
                <?php endif; ?>

                <?php if ($featured): ?>
                    <div class="collection-hero">
                        <a href="<?php echo BASE_URL . htmlspecialchars($featured['img_slug'] ?? ''); ?>">
                            <img src="<?php echo BASE_URL . 'img_uploads/' . htmlspecialchars($featured['img_file'] ?? ''); ?>"
                                 alt="<?php echo htmlspecialchars($featured['img_title'] ?? $collection['name']); ?>"
                                 class="collection-hero-img">
                        </a>
                    </div>
                <?php endif; ?>

                <?php if (empty($members)): ?>
                    <p class="dim">This collection is empty.</p>
                <?php else: ?>
                    <div class="collection-grid">
                        <?php foreach ($members as $m):
                            $thumb = $m['img_thumb_square'] ?: $m['img_file'];
                            $href  = BASE_URL . htmlspecialchars($m['slug'] ?? '');
                        ?>
                            <a class="collection-tile" href="<?php echo $href; ?>">
                                <img src="<?php echo BASE_URL . 'img_uploads/' . htmlspecialchars($thumb); ?>"
                                     alt="<?php echo htmlspecialchars($m['title'] ?? ''); ?>"
                                     loading="lazy">
                                <span class="collection-tile-title"><?php echo htmlspecialchars($m['title'] ?? ''); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
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
