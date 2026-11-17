<?php
/**
 * SNAPSMACK.CA - The scannable strip: what SnapSmack is, in one screen.
 * ONE source, used by the home page and every SEO landing page, so the pitch
 * never drifts between them. One line + a link per item. Introduce, don't
 * explain — the inner pages explain.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
?>
            <h2 class="strip-title">Highlights</h2>
            <ul class="strip-list">
                <li><a href="features.php#run-it"><strong>It works.</strong><span><?php if (!empty($_skin_fleet_summary)): ?>Currently running <?php echo number_format($_skin_fleet_summary['sites']); ?> sites holding <?php if (array_key_exists('posts', $_skin_fleet_summary)): ?><?php echo number_format($_skin_fleet_summary['posts']); ?> posts and <?php endif; ?><?php echo number_format($_skin_fleet_summary['photos']); ?> photographs in our own fleet.<?php else: ?>Currently running in our own fleet.<?php endif; ?> Yes, it scales.</span></a></li>
                <li><a href="features.php#yours"><strong>It&rsquo;s free.</strong><span>No subscription, no tiers, no upsell. Your only bill is cheap web hosting.</span></a></li>
                <li><a href="features.php#get-in-get-out"><strong>Bring your archive.</strong><span>Import your history from Instagram, Flickr and Blogger, with dates and captions intact.</span></a></li>
                <li><a href="tools.php"><strong>Tools that do the boring part.</strong><span>Drag your photos into order and post the whole batch at once. Fill in missing alt text across your library in minutes. Edit, sort and back up, all free, for Windows and Linux.</span></a></li>
                <li><a href="features.php#yours"><strong>It&rsquo;s yours.</strong><span>Your site, your domain, your files. Leave whenever you like and take everything with you.</span></a></li>
                <li><a href="buzzers.php"><strong>Audited in public.</strong><span>Every security audit we run is published, open holes included.</span></a></li>
            </ul>
<?php // ===== SNAPSMACK EOF =====
