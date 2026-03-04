<?php
/**
 * SNAPSMACK - Skin header for Rational Geo
 * v1.0
 *
 * Yellow square logo + editorial masthead header.
 */
$show_map_bg = ($settings['show_map_background'] ?? '1') === '1';
$border_color = $settings['image_border_color'] ?? 'yellow';
?>
<div id="rg-header">
    <div class="rg-header-inside">
        <a href="<?php echo BASE_URL; ?>" class="rg-logo-link">
            <div class="rg-logo-square"></div>
            <div class="rg-masthead">
                <span class="rg-masthead-rational">Rational</span>
                <span class="rg-masthead-geo">Geo</span>
            </div>
        </a>
        <nav class="rg-header-nav">
            <?php include(dirname(__DIR__, 2) . '/core/header.php'); ?>
        </nav>
    </div>
</div>
