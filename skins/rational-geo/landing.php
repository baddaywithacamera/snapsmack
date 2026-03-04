<?php
/**
 * SNAPSMACK - Rational Geo Landing Page
 * v1.0
 *
 * Hero image (latest upload) with NatGeo yellow border,
 * followed by a recent-photos grid preview.
 *
 * Variables provided by index.php:
 *   $pdo, $settings, $active_skin, $site_name, BASE_URL, $snapsmack
 */

$now_local = date('Y-m-d H:i:s');
$show_map_bg = ($settings['show_map_background'] ?? '1') === '1';

// Border config
$border_colors = [
    'yellow' => '#FFCC00',
    'white'  => '#ffffff',
    'black'  => '#000000',
    'grey'   => '#808080',
    'none'   => 'transparent'
];
$bc = $settings['image_border_color'] ?? 'yellow';
$border_val = $border_colors[$bc] ?? '#FFCC00';
$hero_bw = (int)($settings['hero_border_width'] ?? '8');
$thumb_bw = (int)($settings['thumb_border_width'] ?? '2');

// Fetch latest published image for hero
$hero_stmt = $pdo->prepare("
    SELECT id, img_title, img_slug, img_date, img_file, img_thumb_square
    FROM snap_images
    WHERE img_status = 'published' AND img_date <= ?
    ORDER BY img_date DESC
    LIMIT 1
");
$hero_stmt->execute([$now_local]);
$hero = $hero_stmt->fetch(PDO::FETCH_ASSOC);

// Fetch recent images for grid (skip the hero)
$recent_stmt = $pdo->prepare("
    SELECT id, img_title, img_slug, img_date, img_thumb_square
    FROM snap_images
    WHERE img_status = 'published' AND img_date <= ?
    ORDER BY img_date DESC
    LIMIT 13
");
$recent_stmt->execute([$now_local]);
$recent_all = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);

// Skip the hero image from the grid
$recent_images = [];
foreach ($recent_all as $ri) {
    if ($hero && $ri['id'] == $hero['id']) continue;
    $recent_images[] = $ri;
    if (count($recent_images) >= 12) break;
}
?>

<div id="scroll-stage" class="rg-landing">

    <?php include('skin-header.php'); ?>

    <?php if ($hero): ?>
    <!-- Hero section -->
    <div class="rg-hero">
        <a href="<?php echo BASE_URL . htmlspecialchars($hero['img_slug']); ?>" class="rg-hero-link">
            <img src="<?php echo BASE_URL . ltrim($hero['img_file'], '/'); ?>"
                 alt="<?php echo htmlspecialchars($hero['img_title'] ?? ''); ?>"
                 class="rg-hero-image"
                 style="border: <?php echo $hero_bw; ?>px solid <?php echo htmlspecialchars($border_val); ?>;">
        </a>
        <div class="rg-hero-caption">
            <h2 class="rg-hero-title"><?php echo htmlspecialchars($hero['img_title'] ?? ''); ?></h2>
            <span class="rg-hero-date"><?php echo date('F j, Y', strtotime($hero['img_date'])); ?></span>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($recent_images)): ?>
    <!-- Recent photographs grid -->
    <div class="rg-recent-section">
        <h3 class="rg-recent-title">Recent Photographs</h3>
        <div class="rg-recent-grid">
            <?php foreach ($recent_images as $ri): ?>
                <a href="<?php echo BASE_URL . htmlspecialchars($ri['img_slug']); ?>"
                   class="rg-recent-item"
                   title="<?php echo htmlspecialchars($ri['img_title'] ?? ''); ?>">
                    <img src="<?php echo BASE_URL . ltrim($ri['img_thumb_square'], '/'); ?>"
                         alt="<?php echo htmlspecialchars($ri['img_title'] ?? ''); ?>"
                         loading="lazy"
                         style="border: <?php echo $thumb_bw; ?>px solid <?php echo htmlspecialchars($border_val); ?>;">
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- View all link -->
    <div class="rg-landing-more">
        <a href="<?php echo BASE_URL; ?>archive.php" class="rg-view-all">View All Photographs →</a>
    </div>

    <?php include('skin-footer.php'); ?>
</div>

<?php if ($show_map_bg): ?>
<script>document.body.classList.add('rg-map-bg');</script>
<?php endif; ?>
