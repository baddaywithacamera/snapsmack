<?php
/**
 * SnapSmack - Core Public Footer
 * Version: 3.0 - Reconstructed
 * -------------------------------------------------------------------------
 * Renders the public-facing footer bar with branding, reversed email
 * (scraper protection), and injects any footer scripts from DB settings.
 * -------------------------------------------------------------------------
 */

$footer_style = $settings['footer_branding_style'] ?? 'standard';
$copyright_override = $settings['footer_copyright_override'] ?? '';
$site_email = $settings['site_email'] ?? '';
$footer_scripts = $settings['footer_injection_scripts'] ?? '';
$site_display = $settings['site_name'] ?? 'SNAPSMACK';
?>

<?php if ($footer_style !== 'ghost'): ?>
<div id="system-footer">
    <div class="inside">
        <?php if ($footer_style === 'standard'): ?>
            <span class="footer-link">
                &copy; <?php echo date('Y'); ?> 
                <?php echo !empty($copyright_override) ? htmlspecialchars($copyright_override) : htmlspecialchars($site_display); ?>
            </span>

            <?php if (!empty($site_email)): ?>
                <span class="sep">|</span>
                <span class="reverse-email"><?php echo strrev(htmlspecialchars($site_email)); ?></span>
            <?php endif; ?>

            <span class="sep">|</span>
            <a href="<?php echo BASE_URL; ?>rss.php" class="footer-link">
                <span class="rss-tag">RSS</span>
            </a>
        <?php elseif ($footer_style === 'minimal'): ?>
            <span class="footer-link">
                &copy; <?php echo date('Y'); ?> 
                <?php echo !empty($copyright_override) ? htmlspecialchars($copyright_override) : htmlspecialchars($site_display); ?>
            </span>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php
// Inject any footer scripts stored in DB (from the handshake system)
if (!empty($footer_scripts)) {
    echo $footer_scripts;
}
?>
