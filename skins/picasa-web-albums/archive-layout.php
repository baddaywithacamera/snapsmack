<?php
/**
 * SNAPSMACK - Picasa Web Albums Archive Layout
 * v1.0
 *
 * Skin-specific archive grid with clean square thumbnails.
 * Variables from archive.php: $images, $settings, $all_cats, $all_albums,
 * $cat_filter, $album_filter, $pdo, BASE_URL
 */

$grid_cols = (int)($settings['grid_columns_interior'] ?? 5);

// If album filter active, get album name for breadcrumb
$album_name = '';
if ($album_filter) {
    try {
        $album_stmt = $pdo->prepare("SELECT album_name FROM snap_albums WHERE id = ?");
        $album_stmt->execute([$album_filter]);
        $album_name = $album_stmt->fetchColumn() ?: '';
    } catch (Exception $e) {
        $album_name = '';
    }
}
?>

<?php if ($album_filter && !empty($album_name)): ?>
    <div class="pwa-breadcrumb">
        <a href="<?php echo BASE_URL; ?>">Home</a>
        <span class="pwa-breadcrumb-sep">›</span>
        <a href="<?php echo BASE_URL; ?>archive.php">Archive</a>
        <span class="pwa-breadcrumb-sep">›</span>
        <span><?php echo htmlspecialchars($album_name); ?></span>
    </div>
<?php endif; ?>

<div class="pwa-thumb-grid" style="--pwa-grid-cols: <?php echo $grid_cols; ?>;">
    <?php if ($images): ?>
        <?php foreach ($images as $img):
            $link = BASE_URL . htmlspecialchars($img['img_slug']);
            $full_img_path = ltrim($img['img_file'], '/');
            $filename = basename($full_img_path);
            $folder = str_replace($filename, '', $full_img_path);
            $thumb_url = BASE_URL . $folder . 'thumbs/t_' . $filename;
        ?>
            <a href="<?php echo $link; ?>" class="pwa-thumb-item" title="<?php echo htmlspecialchars($img['img_title']); ?>">
                <img src="<?php echo $thumb_url; ?>"
                     alt="<?php echo htmlspecialchars($img['img_title']); ?>"
                     loading="lazy">
            </a>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="pwa-archive-empty" style="grid-column: 1 / -1; text-align: center; padding: 80px 20px; color: var(--pwa-text-dim);">
            No photos found in this collection.
        </div>
    <?php endif; ?>
</div>
