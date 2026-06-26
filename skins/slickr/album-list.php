<?php
/**
 * SNAPSMACK - Slickr Album Directory (Flickr-style cover grid)
 *
 * FRAGMENT included by the core albums.php controller, which has ALREADY rendered
 * skin-meta, skin-header and the page wrapper, and includes skin-footer +
 * footer-scripts after this. So this file must NOT include header/footer or a
 * full-page wrapper — just the album listing. The controller looks specifically
 * for `album-list.php` (NOT albums.php), which is why the grid never appeared.
 *
 * Provided by the controller: $albums — rows with id, album_name, img_count,
 * latest_date, cover_thumb, cover_file, view_count.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
$albums_list = isset($albums) ? $albums : [];
?>
<main class="sl-album-grid-container">
    <?php if (!empty($albums_list)): ?>
        <div class="sl-album-grid">
            <?php foreach ($albums_list as $alb):
                $album_url = BASE_URL . 'archive.php?album=' . (int)$alb['id'];

                // Prefer the cover thumbnail; fall back to a derived t_ thumb, then
                // the full file, then a neutral placeholder.
                if (!empty($alb['cover_thumb'])) {
                    $cover_src = BASE_URL . ltrim($alb['cover_thumb'], '/');
                } elseif (!empty($alb['cover_file'])) {
                    $full = ltrim($alb['cover_file'], '/');
                    $fn   = basename($full);
                    $dir  = substr($full, 0, strlen($full) - strlen($fn));
                    $cover_src = BASE_URL . $dir . 'thumbs/t_' . $fn;
                } else {
                    $cover_src = BASE_URL . 'assets/images/default-album-cover.jpg';
                }

                $photo_count = (int)($alb['img_count']  ?? 0);
                $view_count  = (int)($alb['view_count'] ?? 0);
                $meta = ($photo_count === 1 ? '1 photo' : number_format($photo_count) . ' photos');
                if ($view_count > 0) {
                    $meta .= ' · ' . ($view_count === 1 ? '1 view' : number_format($view_count) . ' views');
                }
            ?>
                <a href="<?php echo $album_url; ?>" class="sl-album-card" title="<?php echo htmlspecialchars($alb['album_name']); ?>">
                    <img class="sl-album-img" src="<?php echo $cover_src; ?>"
                         alt="<?php echo htmlspecialchars($alb['album_name']); ?>" loading="lazy">
                    <span class="sl-album-grad" aria-hidden="true"></span>
                    <span class="sl-album-cap">
                        <span class="sl-album-title"><?php echo htmlspecialchars($alb['album_name']); ?></span>
                        <span class="sl-album-count"><?php echo $meta; ?></span>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="sl-no-photos" style="text-align:center; padding:64px 0;">
            <p style="color: var(--sl-text-secondary);">No albums yet.</p>
        </div>
    <?php endif; ?>
</main>
<?php // ===== SNAPSMACK EOF =====
