<?php
/**
 * SNAPSMACK — Sticky Header Engine Template
 * Alpha v0.8
 *
 * Outputs CSS custom properties onto the <html> element for the sticky
 * header engine. Included by core/footer-scripts.php.
 * The JS engine (ss-engine-sticky-header.js) handles the actual behaviour.
 */

// Bail if disabled
if (empty($settings['sticky_header_enabled']) || $settings['sticky_header_enabled'] !== '1') {
    return;
}

$_sticky_opacity = max(0, min(100, (int)($settings['sticky_header_opacity'] ?? 12)));
$_sticky_blur    = max(0, min(30, (int)($settings['sticky_header_blur'] ?? 14)));
?>
<style>
:root {
    --sticky-opacity: <?php echo $_sticky_opacity / 100; ?>;
    --sticky-blur: <?php echo $_sticky_blur; ?>px;
}
</style>
