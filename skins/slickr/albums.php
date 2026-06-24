<?php
/**
 * SNAPSMACK - Slickr Albums Directory Template
 * Spec v0.1 — Flickr visual idiom clone for archive migrations.
 *
 * Renders the full collection registry in a clean grid flow.
 *
 * @author Sean McCormick
 */

/**
 * SNAPSMACK_EOF_HEADER
 * <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

// Verify system payload array availability
$albums_list = isset($albums) ? $albums : [];
?>

<div id="scroll-stage" class="sl-albums-view">

    <?php include('skin-header.php'); ?>

    <main class="sl-album-grid-container">

        <?php if (!empty($albums_list)): ?>
            <div class="sl-album-grid">
                <?php foreach ($albums_list as $alb):
                    // Albums are viewed via the archive filtered by album id
                    // (no slug column / single-album page exists).
                    $album_url = BASE_URL . 'archive.php?album=' . (int)$alb['id'];

                    // Cover photo asset → fall back to neutral placeholder
                    if (!empty($alb['cover_file'])) {
                        $cover_src = BASE_URL . ltrim($alb['cover_file'], '/');
                    } else {
                        $cover_src = BASE_URL . 'assets/images/default-album-cover.jpg';
                    }

                    $photo_count = (int)($alb['img_count']  ?? 0);
                    $view_count  = (int)($alb['view_count'] ?? 0);
                    $meta = ($photo_count === 1 ? '1 photo' : number_format($photo_count) . ' photos')
                          . ' · ' . ($view_count === 1 ? '1 view' : number_format($view_count) . ' views');
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
            <div class="sl-no-photos" style="text-align: center; padding: 64px 0;">
                <p style="color: var(--sl-text-secondary);">No custom albums have been created yet.</p>
            </div>
        <?php endif; ?>

    </main>

    <?php include('skin-footer.php'); ?>
</div>
<?php // ===== SNAPSMACK EOF =====