<?php
/**
 * SNAPSMACK - Skin header for Rational Geo
 * v1.0
 *
 * Bold serif masthead — blog name only, National Geographic editorial style.
 * Map background is pure CSS on body — when disabled, --rg-map-pct is forced to 0
 * which makes all gradient alphas zero (invisible).
 */
$border_color = $settings['image_border_color'] ?? 'yellow';
$site_display_name = $site_name ?? 'SNAPSMACK';
$show_map_bg = ($settings['show_map_background'] ?? '1') === '1';

// RG doesn't use Gallery View — manifest defaults show_wall_link to '0'
// but the DB may not have a row yet, so enforce it here.
if (!isset($settings['show_wall_link'])) {
    $settings['show_wall_link'] = '0';
}

// If map toggle is off, force the CSS variable to zero so gradients are invisible
if (!$show_map_bg): ?>
<style>:root { --rg-map-pct: 0 !important; }</style>
<?php endif; ?>
<div id="rg-header">
    <div class="rg-header-inside">
        <a href="<?php echo BASE_URL; ?>" class="rg-logo-link">
            <span class="rg-masthead"><?php echo htmlspecialchars($site_display_name); ?></span>
        </a>
        <nav class="rg-header-nav">
            <?php include(dirname(__DIR__, 2) . '/core/header.php'); ?>
        </nav>
    </div>
</div>
