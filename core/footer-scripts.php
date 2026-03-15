<?php
/**
 * SNAPSMACK - Global JavaScript Engine Loader
 * Alpha v0.7.4
 *
 * Loads core JavaScript engines used on all public pages: the HUD (toast
 * notifications), communications engine (keyboard shortcuts), and Thomas the
 * Bear easter egg. Include this once per controller.
 *
 * NOTE: The drawer engine is loaded by skin-footer.php (for info/comment
 * drawer on photo pages). The wall engine is loaded directly by gallery-wall.php
 * (page-specific). This file only outputs the shared global engines.
 */
?>

<div id="hud" class="hud-msg"></div>

<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/public-download-overlay.css">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/ss-engine-comms.css">
<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-comms.js?v=<?php echo time(); ?>"></script>

<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/ss-engine-thomas.css">
<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-thomas.js?v=<?php echo time(); ?>"></script>

<?php include __DIR__ . '/social-dock.php'; ?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/ss-engine-social-dock.css">
<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-social-dock.js?v=<?php echo time(); ?>"></script>

<?php include __DIR__ . '/sticky-header.php'; ?>
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/ss-engine-sticky-header.css">
<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-sticky-header.js?v=<?php echo time(); ?>"></script>
