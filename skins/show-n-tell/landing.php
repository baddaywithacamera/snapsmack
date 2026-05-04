<?php
/**
 * Show N Tell - Landing Page
 *
 * Hero slider (media library assets) + justified grid of recent posts.
 * Slider images come from snap_settings htbs_slider_assets (JSON array of asset IDs).
 * Grid excludes no categories — slider is media library, not posts.
 * Variables available from index.php: $pdo, $settings, $img, $active_skin, $site_name
 */

// ── Slider config ────────────────────────────────────────────────────────
$slider_enabled  = ($settings['htbs_slider_enabled'] ?? '1') === '1';
$slider_autoplay = ($settings['htbs_slider_autoplay'] ?? '1') === '1';
$slider_interval = (int)($settings['htbs_slider_interval'] ?? 5000);
$slider_speed    = (int)($settings['htbs_slider_transition'] ?? 800);
$slider_max      = (int)($settings['htbs_slider_max'] ?? 10);

// ── Overlay config ───────────────────────────────────────────────────────
$overlay_enabled  = ($settings['htbs_overlay_enabled'] ?? '1') === '1';
$overlay_source   = $settings['htbs_overlay_source'] ?? 'global';
$overlay_name     = $settings['htbs_overlay_name'] ?? '';
$overlay_tagline  = $settings['htbs_overlay_tagline'] ?? '';
$overlay_position = $settings['htbs_overlay_position'] ?? 'bottom-left';
$overlay_style    = $settings['htbs_overlay_style'] ?? 'scrim';

if (!$overlay_name)    $overlay_name    = $settings['site_name'] ?? $site_name ?? '';
if (!$overlay_tagline) $overlay_tagline = $settings['site_description'] ?? '';

// ── Load slider assets from media library ────────────────────────────────
$slider_assets = [];
if ($slider_enabled) {
    $asset_ids_json = $settings['htbs_slider_assets'] ?? '[]';
    $asset_ids = json_decode($asset_ids_json, true);
    if (!empty($asset_ids)) {
        $placeholders = implode(',', array_fill(0, min(count($asset_ids), $slider_max), '?'));
        $ids_slice = array_slice(array_map('intval', $asset_ids), 0, $slider_max);
        $stmt = $pdo->prepare("SELECT id, asset_name, asset_path FROM snap_assets WHERE id IN ($placeholders)");
        $stmt->execute($ids_slice);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Preserve the user-defined order from the JSON array
        $by_id = [];
        foreach ($rows as $r) $by_id[$r['id']] = $r;
        foreach ($ids_slice as $id) {
            if (isset($by_id[$id])) $slider_assets[] = $by_id[$id];
        }
    }
}

$has_slider = $slider_enabled && !empty($slider_assets);

// ── Load recent images for justified grid ────────────────────────────────
$now_local = date('Y-m-d H:i:s');
$grid_per_page = (int)($settings['htbs_grid_per_page'] ?? 24);
$grid_page = max(1, (int)($_GET['gp'] ?? 1));
$grid_offset = ($grid_page - 1) * $grid_per_page;

$grid_total = (int)$pdo->prepare("SELECT COUNT(*) FROM snap_images WHERE img_status = 'published' AND img_date <= ?")
    ->execute([$now_local]) ? $pdo->query("SELECT FOUND_ROWS()")->fetchColumn() : 0;
// Simpler count
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM snap_images WHERE img_status = 'published' AND img_date <= ?");
$count_stmt->execute([$now_local]);
$grid_total = (int)$count_stmt->fetchColumn();
$grid_pages = ceil($grid_total / $grid_per_page);

$grid_stmt = $pdo->prepare("
    SELECT id, img_title, img_slug, img_file, img_thumb_aspect, img_width, img_height
    FROM snap_images
    WHERE img_status = 'published' AND img_date <= ?
    ORDER BY sort_order ASC, img_date DESC
    LIMIT ? OFFSET ?
");
$grid_stmt->execute([$now_local, $grid_per_page, $grid_offset]);
$grid_images = $grid_stmt->fetchAll(PDO::FETCH_ASSOC);

$row_height = (int)($settings['htbs_grid_row_height'] ?? 280);
?>

<?php include('skin-header.php'); ?>

<?php if ($has_slider): ?>
<!-- ══════════════════════════════════════════════════════════════════════
     HERO SLIDER
     ══════════════════════════════════════════════════════════════════════ -->
<div class="snt-hero-slider">
    <div id="snt-slider" class="ss-slider" data-auto-init
         data-slider-mode="landing"
         data-per-view="1"
         data-speed="<?php echo $slider_speed; ?>"
         data-easing="ease-in-out"
         data-auto-advance="<?php echo $slider_autoplay ? 'true' : 'false'; ?>"
         data-auto-interval="<?php echo $slider_interval; ?>"
         data-loop="true">
        <div class="slider-track">
            <?php foreach ($slider_assets as $i => $asset): ?>
                <div class="slider-slide snt-hero-slide">
                    <img src="<?php echo BASE_URL . ltrim($asset['asset_path'], '/'); ?>"
                         alt="<?php echo htmlspecialchars($asset['asset_name']); ?>"
                         loading="<?php echo $i < 2 ? 'eager' : 'lazy'; ?>">

                    <?php if ($overlay_enabled): ?>
                        <?php
                        $ol_name = $overlay_name;
                        $ol_tag  = $overlay_tagline;
                        if ($overlay_source === 'image') {
                            $ol_name = $asset['asset_name'] ?? '';
                            $ol_tag  = '';
                        }
                        ?>
                        <?php if ($ol_name || $ol_tag): ?>
                        <div class="snt-overlay snt-overlay--<?php echo $overlay_position; ?> snt-overlay--<?php echo $overlay_style; ?>">
                            <?php if ($ol_name): ?><p class="snt-overlay-name"><?php echo htmlspecialchars($ol_name); ?></p><?php endif; ?>
                            <?php if ($ol_tag): ?><p class="snt-overlay-tagline"><?php echo htmlspecialchars($ol_tag); ?></p><?php endif; ?>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <button class="snt-slider-prev" aria-label="Previous">&lsaquo;</button>
    <button class="snt-slider-next" aria-label="Next">&rsaquo;</button>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════════════
     JUSTIFIED GRID
     ══════════════════════════════════════════════════════════════════════ -->
<div class="snt-grid-section">
    <h2>RECENT WORK</h2>

    <div class="snt-justified-grid" data-justified data-row-height="<?php echo $row_height; ?>" data-gap="6">
        <?php foreach ($grid_images as $gi):
            $link = BASE_URL . htmlspecialchars($gi['img_slug']);
            $src = !empty($gi['img_thumb_aspect'])
                ? BASE_URL . ltrim($gi['img_thumb_aspect'], '/')
                : BASE_URL . ltrim($gi['img_file'], '/');
            $w = (int)($gi['img_width'] ?: 800);
            $h = (int)($gi['img_height'] ?: 600);
        ?>
            <a href="<?php echo $link; ?>" data-width="<?php echo $w; ?>" data-height="<?php echo $h; ?>">
                <img src="<?php echo $src; ?>"
                     alt="<?php echo htmlspecialchars($gi['img_title']); ?>"
                     loading="lazy">
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($grid_pages > 1): ?>
    <div class="snt-pagination">
        <?php for ($i = 1; $i <= $grid_pages; $i++): ?>
            <a href="?gp=<?php echo $i; ?>" class="<?php echo ($grid_page === $i) ? 'active' : ''; ?>">
                <?php echo $i; ?>
            </a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<?php include('skin-footer.php'); ?>
<?php // EOF
