<?php
/**
 * SNAPSMACK.CA — Shared Page Footer
 *
 * Include at the bottom of every page.
 * Outputs the footer, mini-header scroll script, and closes body/html.
 * Pages that need additional inline scripts should echo them before this include.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */
?>

<!-- DISCOVERY -->
<aside class="site-discovery" aria-labelledby="site-discovery-title">
    <div class="wrap">
        <p class="site-discovery-kicker">Keep exploring</p>
        <h2 id="site-discovery-title">Find your way into SnapSmack.</h2>
        <nav class="footer-discovery" aria-label="Explore SnapSmack">
            <a href="instagram-alternative.php"><strong>Leave Instagram</strong><span>Keep the feed. Lose the landlord.</span></a>
            <a href="flickr-alternative.php"><strong>Move beyond Flickr</strong><span>Your archive, under your domain.</span></a>
            <a href="self-hosted-photography.php"><strong>Host it yourself</strong><span>Own the files, database, and future.</span></a>
            <a href="photo-blog-software.php"><strong>Build a photo blog</strong><span>Photography and writing, together again.</span></a>
            <a href="fediverse-photography.php"><strong>Join the Fediverse</strong><span>Connect without surrendering your home.</span></a>
            <a href="export-your-photos.php"><strong>Rescue your photos</strong><span>Get your work off somebody else’s platform.</span></a>
        </nav>
    </div>
</aside>

<!-- FOOTER -->
<footer id="site-footer">
    <div class="wrap">
        <p class="footer-copy">&copy; 2026 Sean McCormick &middot; Dedicated to Raymond A. Vanderwoning, photographer and friend. <a href="https://www.serenity.ca/obituaries/Raymond-Anthony-Vanderwoning?obId=30943370" target="_blank" rel="noopener noreferrer">He is missed.</a></p>
    </div>
</footer>

<?php if (isset($page_footer_script)) echo $page_footer_script; ?>

<script>
const mainHeader = document.getElementById('site-header');
const miniHeader = document.getElementById('mini-header');
new IntersectionObserver(
    ([entry]) => miniHeader.classList.toggle('visible', !entry.isIntersecting),
    { threshold: 0 }
).observe(mainHeader);
</script>
</body>
</html>
<!-- ===== SNAPSMACK EOF ===== -->
<?php // ===== SNAPSMACK EOF ===== ?>
