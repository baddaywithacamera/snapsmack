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
                <li><a href="features.php#run-it"><strong>It works.</strong><span><?php if (!empty($_skin_fleet_summary)): ?>Currently running <?php echo number_format($_skin_fleet_summary['sites']); ?> sites holding <?php echo number_format($_skin_fleet_summary['photos']); ?> photographs in our own fleet.<?php else: ?>Currently running in our own fleet.<?php endif; ?> Yes, it scales.</span></a></li>
                <li><a href="features.php#yours"><strong>It&rsquo;s free.</strong><span>No subscription, no tiers, no upsell. Your only bill is cheap web hosting.</span></a></li>
                <li><a href="skins.php"><strong>Free skins.</strong><span>A whole gallery of real designs, not templates, all free. Swap any time.</span></a></li>
                <li><a href="tools.php"><strong>Tools that do the boring part.</strong><span>Drag your photos into order and post the whole batch at once. Fill in missing alt text across your library in minutes. Edit, sort and back up, all free, for Windows and Linux.</span></a></li>
                <li><a href="features.php#yours"><strong>It&rsquo;s yours.</strong><span>Your site, your domain, your files. Leave whenever you like and take everything with you.</span></a></li>
                <li><a href="features.php#network"><strong>People can follow you.</strong><span>Folks on Mastodon and Pixelfed can follow your blog without you joining anything.</span></a></li>
            </ul>
<?php // ===== SNAPSMACK EOF =====
