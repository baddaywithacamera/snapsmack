<?php
/**
 * SnapSmack - Central Footer Engine
 * Version: 1.2
 * - Fixed: Spacing, Email Linking, and RSS Formatting
 */

// Reverse the email for the CSS RTL trick
$raw_email = $settings['site_email'] ?? 'sean@iswa.ca';
$reversed_email = strrev($raw_email);
$site_name = htmlspecialchars($settings['site_name'] ?? 'SnapSmack');
$year = date("Y");
?>

<footer id="system-footer">
    <div class="inside">
        <p>
            &copy; <?php echo $year; ?> <?php echo $site_name; ?>
            
            <?php if (!empty($raw_email)): ?>
                <span class="sep">|</span>EMAIL: <a href="mailto:<?php echo $raw_email; ?>" class="footer-link"><span class="reverse-email"><?php echo htmlspecialchars($reversed_email); ?></span></a>
            <?php endif; ?>

            <span class="sep">|</span><span class="version-text">POWERED BY SNAPSMACK ALPHA V0.5</span>
            
            <span class="sep">|</span><a href="<?php echo BASE_URL; ?>feed" class="footer-link rss-tag" title="RSS Feed">RSS</a>
        </p>
    </div>
</footer>