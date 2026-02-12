<?php
/**
 * SnapSmack - Central Script Engine
 * Version: 1.0
 * - Handles: HUD Message container, Skin-specific scripts, and Global Hotkey Engine.
 */
?>

<div id="hud" class="hud-msg"></div>

<?php if (isset($active_skin)): ?>
    <script src="<?php echo BASE_URL; ?>skins/<?php echo $active_skin; ?>/script.js"></script>
<?php endif; ?>

<script src="<?php echo BASE_URL; ?>assets/js/hotkey-engine.js"></script>

</body>
</html>