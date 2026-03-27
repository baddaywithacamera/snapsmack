<?php
/**
 * SNAPSMACK - Main layout template for the kiosk skin
 * Alpha v0.7.6
 *
 * Renders a kiosk display with pimpotron slideshow, logo glitch effects, and navigation.
 */
require_once dirname(__DIR__, 2) . '/core/layout_logic.php';
?>

<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/ss-engine-pimpotron.css?v=<?php echo time(); ?>">
<link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/ss-engine-logo.css?v=<?php echo time(); ?>">

<div id="kiosk-grid" class="red-all-over">

    <header id="kiosk-header">
        <h1 class="snapsmack-logo"
            data-glitch-enabled="<?php echo ($settings['logo_glitch_enabled'] ?? '1') === '1' ? 'true' : 'false'; ?>"
            data-glitch-frequency="<?php echo htmlspecialchars($settings['logo_frequency'] ?? 'normal'); ?>"
            data-split-position="<?php echo (int)($settings['logo_split_position'] ?? 50); ?>"
            data-split-drift="<?php echo ($settings['logo_split_drift'] ?? '1') === '1' ? 'true' : 'false'; ?>"
            data-fonts="<?php
                $logo_fonts = [];
                if (($settings['logo_font_blackcasper'] ?? '1') === '1') $logo_fonts[] = 'blackcasper';
                if (($settings['logo_font_courier']     ?? '1') === '1') $logo_fonts[] = 'courier';
                echo htmlspecialchars(implode(',', $logo_fonts));
            ?>">SNAPSMACK</h1>
    </header>

    <div id="hero-stage">
        <div id="pimpotron-sequencer"
             data-endpoint="<?php echo BASE_URL; ?>api/pimpotron-payload.php?slideshow_id=1"
             data-stage-id="pimpotron-sequencer"></div>
    </div>

    <footer id="kiosk-hud">
        <nav id="kiosk-navigation">
            <?php include dirname(__DIR__, 2) . '/core/navigation_bar.php'; ?>
        </nav>
    </footer>

</div>

<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-pimpotron.js?v=<?php echo time(); ?>"></script>
<script src="<?php echo BASE_URL; ?>assets/js/ss-engine-logo.js?v=<?php echo time(); ?>"></script>
