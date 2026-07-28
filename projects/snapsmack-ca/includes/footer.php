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

<!-- FOOTER -->
<footer id="site-footer">
    <div class="wrap">
        <nav class="footer-discovery" aria-label="Explore SnapSmack">
            <a href="instagram-alternative.php">Instagram Alternative</a>
            <a href="flickr-alternative.php">Flickr Alternative</a>
            <a href="self-hosted-photography.php">Self-Hosted Photography</a>
            <a href="photo-blog-software.php">Photo Blog Software</a>
            <a href="fediverse-photography.php">Fediverse Photography</a>
            <a href="export-your-photos.php">Export Your Photos</a>
        </nav>
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
