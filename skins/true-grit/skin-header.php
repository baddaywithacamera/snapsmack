<?php
/**
 * SNAPSMACK - Skin header for the true-grit skin
 * Alpha v0.7.8
 */

// When a fixed aspect ratio is selected, thumbnails must fill the container
// with cover cropping. Natural/auto mode leaves images at their native ratio.
$tg_aspect = $settings['archive_aspect_ratio'] ?? 'auto';
if ($tg_aspect !== 'auto'):
?>
<style>
.square-grid .thumb-link img,.cropped-grid .thumb-link img{height:100%;object-fit:cover;}
</style>
<?php endif; ?>
<div id="tg-header">
    <div class="tg-header-inside">
        <?php include(dirname(__DIR__, 2) . '/core/header.php'); ?>
    </div>
</div>
