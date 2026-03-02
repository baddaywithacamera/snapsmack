<?php
/**
 * SnapSmack - Global Engine Loader
 * Version: 3.0 - Single Source of Truth
 * -------------------------------------------------------------------------
 * EVERY controller includes this file ONCE. It loads:
 *   - HUD container (toast notifications)
 *   - Comms engine (keyboard shortcuts: H, X, 1, 2, arrows, space)
 *   - Thomas the Bear (Ctrl+Shift+Y — for Noah Grey)
 *
 * RETIRED: ss-engine-hotkey.js (replaced by ss-engine-comms.js)
 *
 * NOTE: Drawer engine is loaded by skin-footer.php handshake (smack-footer)
 *       because it's only needed on photo pages with the info/comment drawer.
 * NOTE: Wall engine is loaded by gallery-wall.php directly (page-specific).
 * NOTE: Does NOT output </body></html> — the calling controller owns that.
 * -------------------------------------------------------------------------
 */
?>

<div id="hud" class="hud-msg"></div>

<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/public-download-overlay.css">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/ss-engine-comms.css">
<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-comms.js?v=<?php echo time(); ?>"></script>

<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/ss-engine-thomas.css">
<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-thomas.js?v=<?php echo time(); ?>"></script>
