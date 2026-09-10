<?php
/**
 * GAME ON living puzzle background for non-landing templates.
 * Keep the eligibility rules identical to landing.php: no carousel covers and
 * no trigram/triptych slices; interior carousel images remain eligible.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$_go_now_local = $now_local ?? date('Y-m-d H:i:s');
$_go_puzzle_mode = $settings['go_puzzle_mode'] ?? 'moving';
$_go_puzzle_brightness = max(-100, min(100, (int)($settings['go_treatment_overlay'] ?? 0)));
$_go_puzzle_tone_style = '';
if ($_go_puzzle_brightness < 0) {
    $_go_puzzle_tone_style = 'background:rgba(0,0,0,' . round(abs($_go_puzzle_brightness) / 100, 2) . ');';
} elseif ($_go_puzzle_brightness > 0) {
    $_go_puzzle_tone_style = 'background:rgba(255,255,255,' . round($_go_puzzle_brightness / 100, 2) . ');';
}

$_go_puzzle_pool = [];
if ($_go_puzzle_mode !== 'off') {
    $_go_pool_stmt = $pdo->prepare("
        SELECT p.title, i.img_file, i.img_thumb_square,
               i.img_slug, pi.img_focus_x, pi.img_focus_y, pi.img_zoom,
               COALESCE(ci.img_slug, i.img_slug) AS post_img_slug
          FROM snap_posts p
          JOIN snap_post_images pi ON pi.post_id = p.id AND pi.sort_position >= 0
          JOIN snap_images i ON i.id = pi.image_id
          LEFT JOIN snap_post_images cpi ON cpi.post_id = p.id AND cpi.is_cover = 1
          LEFT JOIN snap_images ci ON ci.id = cpi.image_id
         WHERE p.status = 'published'
           AND p.created_at <= ?
           AND p.trigram_id IS NULL
           AND i.img_thumb_square IS NOT NULL
           AND i.img_thumb_square <> ''
           AND NOT (
               pi.is_cover = 1
               AND (SELECT COUNT(*) FROM snap_post_images spi
                     WHERE spi.post_id = p.id AND spi.sort_position >= 0) > 1
           )
         ORDER BY i.id DESC
    ");
    $_go_pool_stmt->execute([$_go_now_local]);
    $_go_puzzle_pool = $_go_pool_stmt->fetchAll(PDO::FETCH_ASSOC);
    shuffle($_go_puzzle_pool);
}

$_go_puzzle_slots = [];
if (!empty($_go_puzzle_pool)) {
    for ($i = 0; $i < 144; $i++) {
        $_go_puzzle_slots[] = $_go_puzzle_pool[$i % count($_go_puzzle_pool)];
    }
}

$_go_asset_url = static function (string $path): string {
    if (preg_match('~^https?://~i', $path)) return $path;
    return BASE_URL . ltrim($path, '/');
};
?>
<?php if (!empty($_go_puzzle_slots)): ?>
<div class="go-puzzle-field"
     data-game-on
     data-mode="<?php echo htmlspecialchars($_go_puzzle_mode); ?>"
     data-palette="<?php echo htmlspecialchars($settings['go_border_palette'] ?? 'automatic'); ?>"
     data-direction="<?php echo htmlspecialchars($settings['go_border_direction'] ?? 'automatic'); ?>"
     data-puzzle-density="<?php echo (int)($settings['go_puzzle_density'] ?? 100); ?>"
     data-activity="<?php echo (int)($settings['go_motion_activity'] ?? 3); ?>"
     data-speed="<?php echo (int)($settings['go_motion_speed'] ?? 3); ?>"
     data-border-activity="<?php echo (int)($settings['go_border_activity'] ?? 3); ?>"
     data-modal-theme="<?php echo htmlspecialchars($settings['go_modal_theme'] ?? 'light'); ?>"
     data-score-url="<?php echo htmlspecialchars(BASE_URL . 'game-on-scores.php'); ?>"
     data-help-url="<?php echo htmlspecialchars(BASE_URL . 'page.php?slug=how-to-play-15-puzzle'); ?>"
     aria-hidden="true">
    <?php foreach ($_go_puzzle_slots as $_go_index => $_go_image):
        $_go_title = trim((string)($_go_image['title'] ?? '')) ?: 'Photograph';
        $_go_thumb = $_go_asset_url((string)$_go_image['img_thumb_square']);
        $_go_full  = $_go_asset_url((string)$_go_image['img_file']);
        $_go_url   = BASE_URL . '?s=' . urlencode((string)$_go_image['post_img_slug']);
    ?>
    <button class="go-puzzle" type="button" tabindex="-1"
            data-puzzle-index="<?php echo (int)$_go_index; ?>"
            data-thumb="<?php echo htmlspecialchars($_go_thumb); ?>"
            data-full="<?php echo htmlspecialchars($_go_full); ?>"
            data-post-url="<?php echo htmlspecialchars($_go_url); ?>"
            data-label="<?php echo htmlspecialchars($_go_title); ?>"
            data-focus-x="<?php echo (int)($_go_image['img_focus_x'] ?? 50); ?>"
            data-focus-y="<?php echo (int)($_go_image['img_focus_y'] ?? 50); ?>"
            data-zoom="<?php echo (int)($_go_image['img_zoom'] ?? 100); ?>"></button>
    <?php endforeach; ?>
</div>
<div id="go-puzzle-candidates" hidden aria-hidden="true">
<?php foreach ($_go_puzzle_pool as $_go_candidate): ?>
    <i data-thumb="<?php echo htmlspecialchars($_go_asset_url((string)$_go_candidate['img_thumb_square'])); ?>"
       data-full="<?php echo htmlspecialchars($_go_asset_url((string)$_go_candidate['img_file'])); ?>"
       data-post-url="<?php echo htmlspecialchars(BASE_URL . '?s=' . urlencode((string)$_go_candidate['post_img_slug'])); ?>"
       data-label="<?php echo htmlspecialchars(trim((string)($_go_candidate['title'] ?? '')) ?: 'Photograph'); ?>"
       data-focus-x="<?php echo (int)($_go_candidate['img_focus_x'] ?? 50); ?>"
       data-focus-y="<?php echo (int)($_go_candidate['img_focus_y'] ?? 50); ?>"
       data-zoom="<?php echo (int)($_go_candidate['img_zoom'] ?? 100); ?>"></i>
<?php endforeach; ?>
</div>
<?php if ($_go_puzzle_tone_style !== ''): ?>
<div class="go-puzzle-tone" style="<?php echo $_go_puzzle_tone_style; ?>" aria-hidden="true"></div>
<?php endif; ?>
<div class="go-puzzle-edge-mask" aria-hidden="true"></div>
<?php endif; ?>
<?php // ===== SNAPSMACK EOF =====
