<?php
/**
 * SnapSmack - Central Script Engine
 * Version: 2.0 - Unified Asset Paths
 * -------------------------------------------------------------------------
 * - FIXED: skin/script.js reference removed. Drawer engine is global.
 * - RENAMED: hotkey-engine → ss-engine-hotkey
 * - RENAMED: script.js → ss-engine-drawer
 * - All JS now lives in assets/js/
 * -------------------------------------------------------------------------
 */
?>

<div id="hud" class="hud-msg"></div>

<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-drawer.js"></script>
<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-hotkey.js"></script>

</body>
</html>
