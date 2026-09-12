<?php
/**
 * SNAPSMACK.CA - SEO landing: Photo blog software
 * Layout + the shared strip: includes/seo-landing.php. Only the opener and closer are per-page.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 */

$page_title = 'Retro Photo Blog Software, Reimagined - SnapSmack';
$page_description = 'Photo blog software the way it used to work, rebuilt: one photograph per post, the classic grid, or long-form writing with photographs inside it. Free, self-hosted, no plugins, no house style.';
$page_og_url = 'https://snapsmack.ca/photo-blog-software.php';
$landing_eyebrow = 'Photo blog software';
$landing_h1 = 'The photo blog,<br>reimagined. Not reheated.';
$landing_opener = <<<'HTML'
            <p>Pixelpost is gone. Greymatter is a memory. WordPress is a hotel lobby with a photo plugin. The photoblog &mdash; one photograph, one post, a real archive behind it &mdash; deserved better than being a theme somebody stopped updating in 2014.</p>
            <p>SnapSmack is four photoblogs in one engine: the single-image classic, the three-across grid, long-form writing with photographs woven through it, and Picasa-style albums for friends and family. Skins that don&rsquo;t look like templates. No plugin pile. And it knows who taught it the good bits &mdash; the people who built the originals get thanked by name.</p>
HTML;
$landing_closer = <<<'HTML'
            <p class="seo-closer-lines"><span>One photograph.</span><span>One post.</span><span><em>Yours.</em></span></p>
            <p class="seo-tail">Pick your way to play on <a href="features.php#modes">THE GOODS</a>, then pick a look on <a href="skins.php">GLAD RAGS</a>. The family tree is on <a href="hows-yer-father.php">HOW&rsquo;S YER FATHER</a>, if you want to know where it came from.</p>
HTML;
require_once __DIR__ . '/includes/seo-landing.php';
// ===== SNAPSMACK EOF =====
