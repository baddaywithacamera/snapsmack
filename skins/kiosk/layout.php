<?php
/**
 * SnapSmack - Pimpotron Kiosk Layout
 * Version: 5.3 - Logo engine wired in
 * -------------------------------------------------------------------------
 * - Pimpotron JS + CSS load from assets/ (platform-level)
 * - Logo glitch engine loaded, config injected from settings
 * - window.PIMPOTRON_CONFIG and window.SNAP_LOGO_CONFIG both injected
 * -------------------------------------------------------------------------
 */
require_once dirname(__DIR__, 2) . '/core/layout_logic.php';
?>

<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/ss-engine-pimpotron.css?v=<?php echo time(); ?>">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/ss-engine-logo.css?v=<?php echo time(); ?>">

<div id="kiosk-grid" class="red-all-over">

    <header id="kiosk-header">
        <h1 class="snapsmack-logo">SNAPSMACK</h1>
    </header>

    <div id="hero-stage">
        <div id="pimpotron-sequencer"></div>
    </div>

    <footer id="kiosk-hud">
        <nav id="kiosk-navigation">
            <?php include dirname(__DIR__, 2) . '/core/navigation_bar.php'; ?>
        </nav>
    </footer>

</div>

<script>
    window.PIMPOTRON_CONFIG = {
        endpoint: '<?php echo BASE_URL; ?>api/pimpotron-payload.php?slideshow_id=1',
        stageId:  'pimpotron-sequencer'
    };

    window.SNAP_LOGO_CONFIG = {
        enabled:       <?php echo ($settings['logo_glitch_enabled'] ?? '1') === '1' ? 'true' : 'false'; ?>,
        frequency:     '<?php echo htmlspecialchars($settings['logo_frequency']      ?? 'normal'); ?>',
        splitPosition: <?php echo (int)($settings['logo_split_position'] ?? 50); ?>,
        splitDrift:    <?php echo ($settings['logo_split_drift'] ?? '1') === '1' ? 'true' : 'false'; ?>,
        fonts: [
            <?php echo ($settings['logo_font_blackcasper'] ?? '1') === '1' ? "'blackcasper'," : ''; ?>
            <?php echo ($settings['logo_font_courier']     ?? '1') === '1' ? "'courier'"      : ''; ?>
        ]
    };
</script>
<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-pimpotron.js?v=<?php echo time(); ?>"></script>
<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-logo.js?v=<?php echo time(); ?>"></script>
