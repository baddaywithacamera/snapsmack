<?php
/**
 * SNAPSMACK.CA - SEO landing: Flickr alternative
 * Layout + the shared strip: includes/seo-landing.php. Only the opener and closer are per-page.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 */

$page_title = 'Self-Hosted Flickr Alternative for Photographers - SnapSmack';
$page_description = 'A Flickr alternative on your own domain: bring twenty years of photographs, titles, tags, dates, views, comments and faves to a site you own. Free, self-hosted, no subscription, no sunset.';
$page_og_url = 'https://snapsmack.ca/flickr-alternative.php';
$landing_eyebrow = 'Flickr alternative';
$landing_h1 = 'A Flickr alternative<br>on your own domain.';
$landing_opener = <<<'HTML'
            <p>Flickr was <em>the</em> place. Some of us have a decade or two there, and every couple of years somebody new buys it and we all hold our breath. You are tired of holding your breath. We know; we were too.</p>
            <p>SnapSmack moves the whole archive &mdash; titles, descriptions, tags, upload dates, the views you earned, the comments people left, the faves &mdash; onto a site you own, on a domain you own, and it doesn&rsquo;t reset the clock. Twenty years still reads as twenty years. There&rsquo;s even a skin called SLICKR for when familiarity is part of the plan. Subtle, we are not.</p>
HTML;
$landing_closer = <<<'HTML'
            <p class="seo-closer-lines"><span>Nobody&rsquo;s buying it.</span><span>Nobody&rsquo;s sunsetting it.</span><span>It&rsquo;s <em>yours.</em></span></p>
            <p class="seo-tail">Request your Flickr download, point <a href="tool-flkr-fckr.php">FLKR FCKR</a> at it, and go make a cup of tea. A two-day transfer that dies on hour nineteen resumes at hour nineteen.</p>
HTML;
require_once __DIR__ . '/includes/seo-landing.php';
// ===== SNAPSMACK EOF =====
