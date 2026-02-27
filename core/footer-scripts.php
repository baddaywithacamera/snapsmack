<?php
/**
 * SNAPSMACK - Central Script Footer
 * Version: 2026.1 - Engine Handshake Output
 * Last changed: 2026-02-23
 * -------------------------------------------------------------------------
 * Outputs the HUD container and whatever engines the active skin checked out.
 * footer_injection_scripts is built by smack-skin.php when skin is saved.
 * hotkey-engine.js is DEPRECATED. ss-engine-comms.js handles all hotkeys.
 * -------------------------------------------------------------------------
 */
?>

<div id="hud" class="hud-msg"></div>

<?php if (!empty($settings['footer_injection_scripts'])): ?>
    <?php echo $settings['footer_injection_scripts']; ?>
<?php endif; ?>

</body>
</html>
