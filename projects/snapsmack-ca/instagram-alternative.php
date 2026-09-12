<?php
/**
 * SNAPSMACK.CA - SEO landing: Instagram alternative
 * Layout + the shared strip: includes/seo-landing.php. Only the opener and closer are per-page.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 */

$page_title = 'Self-Hosted Instagram Alternative - SnapSmack';
$page_description = 'An Instagram alternative you actually own: free, self-hosted photo blogging with the classic grid, your original dates and captions, no algorithm, no ads, and a way out whenever you want it.';
$page_og_url = 'https://snapsmack.ca/instagram-alternative.php';
$landing_eyebrow = 'Instagram alternative';
$landing_h1 = 'An Instagram alternative<br>you actually own.';
$landing_opener = <<<'HTML'
            <p>Remember when you posted a photograph and it just&hellip; stayed there? Nobody cropped it into a square, buried it under a dance video, or sold the space next to it to a mattress company. Nobody &ldquo;updated the terms.&rdquo; That&rsquo;s this.</p>
            <p>It&rsquo;s software. You put it on a cheap web host. It&rsquo;s yours the way your camera is yours. The three-across grid is back, your captions and dates come with you, and the only algorithm is the one where newer things are at the top.</p>
HTML;
$landing_closer = <<<'HTML'
            <p class="seo-closer-lines"><span>No algorithm.</span><span>No ads.</span><span>No <em>Zuck.</em></span></p>
            <p class="seo-tail">Just your photographs, on your domain, looking the way you meant them to. <a href="tool-unzucker.php">THE UNZUCKER</a> brings your Instagram export home in an afternoon. See one that made the trip: <a href="https://unzucked.ca/" target="_blank" rel="noopener">unzucked.ca</a>.</p>
HTML;
require_once __DIR__ . '/includes/seo-landing.php';
// ===== SNAPSMACK EOF =====
