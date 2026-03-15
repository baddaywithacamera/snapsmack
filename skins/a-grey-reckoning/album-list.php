<?php
/**
 * SNAPSMACK - Album listing template for the a-grey-reckoning skin
 * Alpha v0.7.3a
 *
 * Recreates Noah Grey's "Series Collections" page from noahgrey.com
 * circa 2006: thumbnail on the left, album title in small caps on
 * the right, image count and date below. Thin grey border around
 * each entry. Dark, quiet, deliberate.
 *
 * Variables available from albums.php:
 *   $albums     — Array of album rows (id, album_name, album_description,
 *                 img_count, latest_date, cover_file, cover_thumb,
 *                 cover_title, cover_slug)
 *   $settings   — Full settings array
 *   $site_name  — Site display name
 */

$site_display_name = $site_name ?? 'SNAPSMACK';
?>

<div class="ge-canvas">

    <!-- BREADCRUMB BAR -->
    <div class="ge-title-bar">
        <span class="ge-title-text"><?php echo htmlspecialchars($site_display_name); ?> &rsaquo; SERIES COLLECTIONS</span>
    </div>

    <!-- SECTION HEADING -->
    <h2 class="ge-album-heading">Series Collections</h2>

    <?php if ($albums): ?>
        <div class="ge-album-list">
            <?php foreach ($albums as $album):
                $album_url = BASE_URL . 'archive.php?album=' . $album['id'];

                // Build thumbnail URL
                $thumb_url = '';
                if (!empty($album['cover_thumb'])) {
                    $thumb_url = BASE_URL . ltrim($album['cover_thumb'], '/');
                } elseif (!empty($album['cover_file'])) {
                    $full = ltrim($album['cover_file'], '/');
                    $fn = basename($full);
                    $dir = str_replace($fn, '', $full);
                    $thumb_url = BASE_URL . $dir . 'thumbs/t_' . $fn;
                }

                // Format date
                $date_str = '';
                if (!empty($album['latest_date'])) {
                    $date_str = 'POSTED ' . strtoupper(date('j F Y', strtotime($album['latest_date'])));
                }

                // Image count
                $count_str = $album['img_count'] . ' IMAGE' . ($album['img_count'] != 1 ? 'S' : '');
            ?>
                <a href="<?php echo $album_url; ?>" class="ge-album-row">
                    <div class="ge-album-thumb">
                        <?php if ($thumb_url): ?>
                            <img src="<?php echo $thumb_url; ?>"
                                 alt="<?php echo htmlspecialchars($album['cover_title'] ?? $album['album_name']); ?>"
                                 loading="lazy">
                        <?php else: ?>
                            <div class="ge-album-thumb-empty"></div>
                        <?php endif; ?>
                    </div>
                    <div class="ge-album-meta">
                        <span class="ge-album-name"><?php echo htmlspecialchars($album['album_name']); ?></span>
                        <span class="ge-album-count"><?php echo $count_str; ?></span>
                        <?php if ($date_str): ?>
                            <span class="ge-album-date"><?php echo $date_str; ?></span>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="ge-album-empty">
            No series collections found.
        </div>
    <?php endif; ?>

</div>
