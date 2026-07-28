<?php
/**
 * SNAPSMACK — SCROLL landing page.
 * Uses the established shared justified engine and aspect thumbnails.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$now_local = date('Y-m-d H:i:s');
$grid_stmt = $pdo->prepare(
    "SELECT id, img_title, img_slug, img_file, img_thumb_aspect, img_width, img_height
     FROM snap_images
     WHERE img_status = 'published' AND img_date <= ?
     ORDER BY sort_order ASC, id DESC"
);
$grid_stmt->execute([$now_local]);
$images = $grid_stmt->fetchAll(PDO::FETCH_ASSOC);

$masthead_raw = trim((string)($settings['scroll_masthead_lines'] ?? 'USED CAR|PARTS'));
$masthead_lines = array_values(array_filter(array_map('trim', explode('|', $masthead_raw)), 'strlen'));
if (!$masthead_lines) $masthead_lines = [$settings['site_name'] ?? 'SnapSmack'];

include dirname(__DIR__, 2) . '/core/meta.php';
?>
<div class="scroll-landing">
    <section class="scroll-profile" aria-labelledby="scroll-site-title">
        <div class="scroll-photographer">
            <?php echo htmlspecialchars($settings['photographer_name'] ?? 'Sean McCormick'); ?>
        </div>
        <h1 class="scroll-masthead" id="scroll-site-title">
            <?php foreach ($masthead_lines as $line): ?>
                <span><?php echo htmlspecialchars($line); ?></span>
            <?php endforeach; ?>
        </h1>
        <p class="scroll-tagline">
            <?php echo htmlspecialchars($settings['site_tagline'] ?? 'parting out cars since 1972'); ?>
        </p>
    </section>

    <?php
    $grid_nav_config = [
        'prefix' => 'scroll',
        'observer_class' => 'scroll-profile',
        'identity' => $settings['photographer_name'] ?? ($settings['site_name'] ?? 'SnapSmack'),
        'inline_social' => true,
        'links' => [
            ['label' => 'Home', 'url' => BASE_URL, 'icon' => 'home'],
            ['label' => 'Archive', 'url' => BASE_URL . 'archive.php', 'icon' => 'archive'],
            ['label' => 'Blogroll', 'url' => BASE_URL . 'blogroll.php', 'icon' => 'people']
        ]
    ];
    include dirname(__DIR__, 2) . '/core/components/grid-sticky-nav.php';
    ?>

    <main class="scroll-wall">
        <div class="justified-grid"
             data-row-height="<?php echo (int)($settings['justified_row_height'] ?? 280); ?>"
             data-gap="8">
            <?php foreach ($images as $image):
                $width = max(1, (int)($image['img_width'] ?? 1));
                $height = max(1, (int)($image['img_height'] ?? 1));
                $thumb = trim((string)($image['img_thumb_aspect'] ?? ''));
                $src = BASE_URL . ltrim($thumb !== '' ? $thumb : $image['img_file'], '/');
            ?>
            <a class="justified-item"
               href="<?php echo BASE_URL . htmlspecialchars($image['img_slug']); ?>"
               title="<?php echo htmlspecialchars($image['img_title'] ?? ''); ?>">
                <img src="<?php echo htmlspecialchars($src); ?>"
                     width="<?php echo $width; ?>"
                     height="<?php echo $height; ?>"
                     alt="<?php echo htmlspecialchars($image['img_title'] ?? ''); ?>"
                     loading="lazy">
            </a>
            <?php endforeach; ?>
        </div>
        <?php if (!$images): ?>
            <p class="scroll-empty">No parts in inventory yet.</p>
        <?php endif; ?>
    </main>
</div>
<?php include __DIR__ . '/skin-footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
