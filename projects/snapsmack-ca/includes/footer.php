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

<!-- Kept with the footer markup so a stale shared stylesheet cannot break it. -->
<style>
.site-discovery {
    background: #f4f1eb;
    border-top: 3px solid var(--red);
    padding: 48px 0 52px;
}
.site-discovery-kicker {
    margin: 0 0 8px;
    color: var(--red);
    font: 700 0.7rem/1 Arial, sans-serif;
    letter-spacing: 0.14em;
    text-transform: uppercase;
}
.site-discovery h2 {
    margin: 0 0 28px;
    color: var(--black);
    font: 900 clamp(1.7rem, 3vw, 2.4rem)/1.05 Arial, sans-serif;
    letter-spacing: -0.035em;
}
.site-discovery .footer-discovery {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0;
    margin: 0;
    border-top: 1px solid #c9c4ba;
    border-left: 1px solid #c9c4ba;
    font: inherit;
    letter-spacing: normal;
    text-transform: none;
}
.site-discovery .footer-discovery a {
    min-height: 108px;
    padding: 20px 22px;
    color: var(--black);
    background: #fff;
    border-right: 1px solid #c9c4ba;
    border-bottom: 1px solid #c9c4ba;
    text-decoration: none;
    transition: background 120ms ease, color 120ms ease;
}
.site-discovery .footer-discovery strong {
    display: block;
    margin-bottom: 8px;
    font: 800 0.9rem/1.2 Arial, sans-serif;
}
.site-discovery .footer-discovery strong::after {
    content: " →";
    color: var(--red);
}
.site-discovery .footer-discovery span {
    display: block;
    color: #555;
    font: 0.84rem/1.45 Georgia, serif;
}
.site-discovery .footer-discovery a:hover,
.site-discovery .footer-discovery a:focus-visible {
    color: var(--black);
    background: #fff8f6;
    box-shadow: inset 0 -3px 0 var(--red);
}
.site-discovery .footer-discovery a:hover span,
.site-discovery .footer-discovery a:focus-visible span { color: #555; }
#site-footer {
    background: var(--black);
    border-top: 0;
    padding: 26px 0;
}
#site-footer .footer-copy { line-height: 1.5; }
@media (max-width: 760px) {
    .site-discovery { padding: 36px 0 40px; }
    .site-discovery .footer-discovery { grid-template-columns: 1fr; }
    .site-discovery .footer-discovery a { min-height: 0; }
}
</style>

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
