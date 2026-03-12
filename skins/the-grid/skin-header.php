<?php
/**
 * SNAPSMACK - The Grid Skin Header
 * Alpha v0.7.2
 *
 * Outputs the sticky top nav bar and opens the page wrapper.
 * No conditional CSS overrides are needed in Phase 1 — all dynamic
 * values are handled via :root custom properties in the compiled CSS blob.
 *
 * $settings is available from the calling template.
 */
?>
<header class="tg-topbar">
    <div class="tg-topbar-inner">
        <a href="<?php echo BASE_URL; ?>" class="tg-site-name">
            <?php echo htmlspecialchars($settings['site_name'] ?? 'SnapSmack'); ?>
        </a>
    </div>
</header>
