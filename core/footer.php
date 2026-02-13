<?php
/**
 * SnapSmack - Central Footer Engine
 * Version: 2.2 - Total Replacement Build
 * -------------------------------------------------------------------------
 * - LOGIC: If 'footer_injection_scripts' is not empty, hide the system footer.
 * - MODE: All-or-Nothing Replacement.
 * -------------------------------------------------------------------------
 */

// 1. DATA PREPARATION
$raw_email = $settings['site_email'] ?? 'sean@iswa.ca';
$reversed_email = strrev($raw_email);
$site_name = htmlspecialchars($settings['site_name'] ?? 'SnapSmack');
$year = date("Y");

$copyright_text = !empty($settings['footer_copyright_override']) 
    ? htmlspecialchars($settings['footer_copyright_override']) 
    : "&copy; {$year} {$site_name}";

$branding_style = $settings['footer_branding_style'] ?? 'standard';
?>

<footer id="system-footer">
    <div class="inside">
        <?php if (!empty($settings['footer_injection_scripts'])): ?>
            
            <?php echo $settings['footer_injection_scripts']; ?>

        <?php else: ?>
            
            <p>
                <?php echo $copyright_text; ?>
                
                <?php if (!empty($raw_email)): ?>
                    <span class="sep">|</span>EMAIL: <a href="mailto:<?php echo $raw_email; ?>" class="footer-link"><span class="reverse-email"><?php echo htmlspecialchars($reversed_email); ?></span></a>
                <?php endif; ?>

                <?php if ($branding_style === 'standard'): ?>
                    <span class="sep">|</span><span class="version-text">POWERED BY SNAPSMACK ALPHA V0.5</span>
                <?php elseif ($branding_style === 'minimal'): ?>
                    <span class="sep">|</span><span class="version-text">SS V0.5</span>
                <?php endif; ?>
                
                <span class="sep">|</span><a href="<?php echo BASE_URL; ?>feed" class="footer-link rss-tag" title="RSS Feed">RSS</a>
            </p>

        <?php endif; ?>
    </div>
</footer>