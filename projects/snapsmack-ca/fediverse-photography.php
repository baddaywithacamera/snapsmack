<?php
/**
 * SNAPSMACK.CA - SEO landing: Fediverse photography
 * Layout + the shared strip: includes/seo-landing.php. Only the opener and closer are per-page.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 */

$page_title = 'Fediverse Photography From Your Own Website - SnapSmack';
$page_description = 'Photography on the fediverse from a site you own: readers on Mastodon and Pixelfed can follow, like, boost and reply while the original stays on your domain. A directory, a weekly challenge, and manners included.';
$page_og_url = 'https://snapsmack.ca/fediverse-photography.php';
$landing_eyebrow = 'Fediverse photography';
$landing_h1 = 'On the fediverse,<br>from your own front door.';
$landing_opener = <<<'HTML'
            <p>You&rsquo;ve heard the fediverse is where the grown-ups went. You&rsquo;ve also heard it comes with a lecture. Here&rsquo;s the version without one: your site is your site. Flip one switch and people on Mastodon, Pixelfed, and the rest can follow it, like it, boost it, and reply to it &mdash; from wherever they already are. The photograph never leaves your server.</p>
            <p>And you&rsquo;re not dropped alone into the whole shouting universe. SnapSmack comes with a small, quiet corner: a directory of photoblogs worth browsing, a reader for following them, and a weekly one-word challenge. Just photographers. Manners included.</p>
HTML;
$landing_closer = <<<'HTML'
            <p class="seo-closer-lines"><span>Followed everywhere.</span><span>Hosted in one place.</span><span>That place is <em>yours.</em></span></p>
            <p class="seo-tail">Leave federation off and the site is entirely its own thing. Turn it on and it plays nicely &mdash; consent, attribution, content warnings, the lot. The details, and the weekly challenge, are on <a href="faq-fediverse.php">the FAQ</a>.</p>
HTML;
require_once __DIR__ . '/includes/seo-landing.php';
// ===== SNAPSMACK EOF =====
