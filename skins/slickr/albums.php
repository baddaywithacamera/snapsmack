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

    <div id="infobox">
        <div class="sl-sorter-bar">
            <span>Albums Collection Index</span>
        </div>
    </div>

    <main class="sl-album-grid-container" style="max-width: <?php echo (int)($settings['main_canvas_width'] ?? 1400); ?>px; margin: 0 auto; padding: 32px 24px;">
        
        <?php if (!empty($albums_list)): ?>
            <div class="sl-album-grid">
                <?php foreach ($albums_list as $alb): 
                    $album_url = BASE_URL . 'albums/' . htmlspecialchars($alb['album_slug']);
                    
                    // Route cover photo asset or drop back to system neutral placeholder
                    if (!empty($alb['album_cover_file'])) {
                        $cover_src = BASE_URL . ltrim($alb['album_cover_file'], '/');
                    } else {
                        $cover_src = BASE_URL . 'assets/images/default-album-cover.jpg';
                    }
                    
                    $photo_count = (int)($alb['photo_count'] ?? 0);
                    $count_label = $photo_count === 1 ? '1 photo' : $photo_count . ' photos';
                ?>
                    <article class="sl-album-card" style="display: flex; flex-direction: column;">
                        <a href="<?php echo $album_url; ?>" class="sl-album-cover-anchor" style="display: block; width: 100%; aspect-ratio: 1; overflow: hidden; margin-bottom: 12px; background-color: #f3f5f6; border: 1px solid #e1e4e6;">
                            <img src="<?php echo $cover_src; ?>" 
                                 alt="<?php echo htmlspecialchars($alb['album_name']); ?>" 
                                 loading="lazy"
                                 style="width: 100%; height: 100%; object-fit: cover; transition: opacity 0.15s ease;">
                        </a>
                        
                        <h2 class="sl-album-title" style="font-size: 16px; font-weight: 600; line-height: 1.3; margin-bottom: 2px;">
                            <a href="<?php echo $album_url; ?>" style="color: var(--sl-text-primary); text-decoration: none;">
                                <?php echo htmlspecialchars($alb['album_name']); ?>
                            </a>
                        </h2>
                        
                        <span class="sl-album-count" style="font-size: 13px; color: var(--sl-text-secondary);">
                            <?php echo $count_label; ?>
                        </span>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="rg-no-photos" style="text-align: center; padding: 64px 0;">
                <p style="color: var(--sl-text-secondary);">No custom albums have been created yet.</p>
            </div>
        <?php endif; ?>

    </main>

    <?php include('skin-footer.php'); ?>
</div>
<?php // ===== SNAPSMACK EOF =====