<?php
/**
 * SNAPSMACK - The Grid Skin Header
 * Alpha v0.7.9
 *
 * Outputs the sticky top nav bar and opens the page wrapper.
 * No conditional CSS overrides are needed in Phase 1 — all dynamic
 * values are handled via :root custom properties in the compiled CSS blob.
 *
 * $settings is available from the calling template.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


?>
<header class="tg-topbar">
    <div class="tg-topbar-inner">
        <a href="<?php echo BASE_URL; ?>" class="tg-site-name">
            <?php echo htmlspecialchars($settings['site_name'] ?? 'SnapSmack'); ?>
        </a>
    </div>
</header>
<?php // ===== SNAPSMACK EOF =====
