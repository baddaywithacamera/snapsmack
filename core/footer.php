<?php
/**
 * SnapSmack - Central Footer Engine
 * Version: 2.3 - Dual-Channel Build (Additive)
 * -------------------------------------------------------------------------
 * - LOGIC: Injects scripts AND maintains UI visibility.
 * - SECURITY: Reversed email logic for basic bot mitigation.
 * - FIXED: Removed the 'else' block that was breaking hotkey button targets.
 * -------------------------------------------------------------------------
 */

// 1. DATA PREPARATION
$raw_email      = $settings['site_email'] ?? 'sean@iswa.ca';
$reversed_email = strrev($raw_email);
$site_name      = htmlspecialchars($settings['site_name'] ?? 'SnapSmack');
$year           = date("Y");

$copyright_text = !empty($settings['footer_copyright_override']) 
    ? htmlspecialchars($settings['footer_copyright_override']) 
    : "&copy; {$year} {$site_name}";

$branding_style = $settings['footer_branding_style'] ?? 'standard';
?>

<footer id="system-footer">
    <div class="inside">
        
        <?php 
        /**
         * CHANNEL 1: SYSTEM INJECTION
         * This is where the JS Handshake outputs its <script> tags.
         * We keep this separate so it never interferes with the visual UI.
         */
        if (!empty($settings['footer_injection_scripts'])): 
            echo $settings['footer_injection_scripts']; 
        endif; 
        ?>

        <?php 
        /**
         * CHANNEL 2: VISUAL UI
         * Standard branding and legal info.
         */
        ?>
        <div class="footer-metadata-bar">
            <p>
                <?php echo $copyright_text; ?>
                
                <?php if (!empty($raw_email)): ?>
                    <span class="sep">|</span>EMAIL: 
                    <a href="mailto:<?php echo $raw_email; ?>" class="footer-link">
                        <span class="reverse-email" style="unicode-bidi:bidi-override; direction:rtl;">
                            <?php echo htmlspecialchars($reversed_email); ?>
                        </span>
                    </a>
                <?php endif; ?>

                <?php if ($branding_style === 'standard'): ?>
                    <span class="sep">|</span><span class="version-text">POWERED BY SNAPSMACK ALPHA V0.5</span>
                <?php elseif ($branding_style === 'minimal'): ?>
                    <span class="sep">|</span><span class="version-text">SS V0.5</span>
                <?php endif; ?>
                
                <span class="sep">|</span>
                <a href="<?php echo BASE_URL; ?>feed" class="footer-link rss-tag" title="RSS Feed">RSS</a>
            </p>
        </div>
    </div>
</footer>