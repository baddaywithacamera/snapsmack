<?php
/**
 * SNAPSMACK - Picasa Web Albums Landing Page
 * v1.0
 *
 * Album grid or flat thumbnail grid landing.
 * Variables from index.php: $pdo, $settings, $img, $active_skin, $site_name, BASE_URL, $snapsmack
 */

$landing_mode = $settings['landing_mode'] ?? 'albums-grid';
$grid_cols_album = (int)($settings['grid_columns_album'] ?? 4);
$grid_cols_interior = (int)($settings['grid_columns_interior'] ?? 5);
$now_local = date('Y-m-d H:i:s');

$albums = [];
$flat_images = [];
$categories = [];

try {
    // Load categories for filter bar
    $cats_stmt = $pdo->query("SELECT id, cat_name FROM snap_categories ORDER BY cat_name ASC");
    $categories = $cats_stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($landing_mode === 'albums-grid') {
        // Fetch albums with cover images and counts
        $albums_stmt = $pdo->prepare("
            SELECT a.*,
                   COUNT(DISTINCT m.image_id) as img_count,
                   (SELECT i.img_thumb_square FROM snap_images i
                    INNER JOIN snap_image_album_map m2 ON i.id = m2.image_id
                    WHERE m2.album_id = a.id AND i.img_status = 'published' AND i.img_date <= ?
                    ORDER BY i.img_date DESC LIMIT 1) as cover_thumb
            FROM snap_albums a
            INNER JOIN snap_image_album_map m ON a.id = m.album_id
            INNER JOIN snap_images img ON m.image_id = img.id
                AND img.img_status = 'published' AND img.img_date <= ?
            GROUP BY a.id
            HAVING img_count > 0
            ORDER BY a.album_name ASC
        ");
        $albums_stmt->execute([$now_local, $now_local]);
        $albums = $albums_stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Flat grid: recent published images
        $flat_stmt = $pdo->prepare("
            SELECT id, img_slug, img_thumb_square, img_title
            FROM snap_images
            WHERE img_status = 'published' AND img_date <= ?
            ORDER BY img_date DESC LIMIT 60
        ");
        $flat_stmt->execute([$now_local]);
        $flat_images = $flat_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $albums = [];
    $flat_images = [];
}
?>

<div id="scroll-stage" class="pwa-landing">

    <?php include('skin-header.php'); ?>

    <div class="pwa-landing-content">

        <?php if (!empty($categories)): ?>
        <div class="pwa-filter-bar">
            <a href="<?php echo BASE_URL; ?>" class="pwa-filter-pill active">All</a>
            <?php foreach ($categories as $cat): ?>
                <a href="<?php echo BASE_URL; ?>archive.php?cat=<?php echo (int)$cat['id']; ?>" class="pwa-filter-pill">
                    <?php echo htmlspecialchars($cat['cat_name']); ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($landing_mode === 'albums-grid'): ?>

            <?php if (!empty($albums)): ?>
            <div class="pwa-album-grid" style="--pwa-grid-cols-album: <?php echo $grid_cols_album; ?>;">
                <?php foreach ($albums as $album):
                    $album_link = BASE_URL . 'archive.php?album=' . (int)$album['id'];
                    $cover = !empty($album['cover_thumb']) ? BASE_URL . ltrim($album['cover_thumb'], '/') : '';
                ?>
                    <a href="<?php echo $album_link; ?>" class="pwa-album-card" title="<?php echo htmlspecialchars($album['album_name']); ?>">
                        <div class="pwa-album-cover">
                            <?php if ($cover): ?>
                                <img src="<?php echo $cover; ?>" alt="<?php echo htmlspecialchars($album['album_name']); ?>" loading="lazy">
                            <?php else: ?>
                                <div class="pwa-album-placeholder"></div>
                            <?php endif; ?>
                        </div>
                        <div class="pwa-album-info">
                            <div class="pwa-album-title"><?php echo htmlspecialchars($album['album_name']); ?></div>
                            <div class="pwa-album-count"><?php echo (int)$album['img_count']; ?> photo<?php echo (int)$album['img_count'] !== 1 ? 's' : ''; ?></div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
                <div class="pwa-empty-state">No albums available yet.</div>
            <?php endif; ?>

        <?php else: ?>

            <?php if (!empty($flat_images)): ?>
            <div class="pwa-thumb-grid" style="--pwa-grid-cols: <?php echo $grid_cols_interior; ?>;">
                <?php foreach ($flat_images as $fi):
                    $fi_link = BASE_URL . htmlspecialchars($fi['img_slug']);
                    $fi_thumb = !empty($fi['img_thumb_square']) ? BASE_URL . ltrim($fi['img_thumb_square'], '/') : '';
                ?>
                    <a href="<?php echo $fi_link; ?>" class="pwa-thumb-item" title="<?php echo htmlspecialchars($fi['img_title']); ?>">
                        <img src="<?php echo $fi_thumb; ?>" alt="<?php echo htmlspecialchars($fi['img_title']); ?>" loading="lazy">
                    </a>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
                <div class="pwa-empty-state">No photos available yet.</div>
            <?php endif; ?>

        <?php endif; ?>

    </div>

    <?php include('skin-footer.php'); ?>
</div>
