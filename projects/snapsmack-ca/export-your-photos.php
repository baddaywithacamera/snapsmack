<?php
/**
 * SNAPSMACK.CA - SEO landing: Export your photos
 * Layout + the shared strip: includes/seo-landing.php. Only the opener and closer are per-page.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 */

$page_title = 'Export Your Photography Archive - SnapSmack';
$page_description = 'Get your photographs off somebody else\'s platform and into a site you own, with dates, captions and tags intact. Then keep the exit: everything in SnapSmack comes back out in a form other software reads.';
$page_og_url = 'https://snapsmack.ca/export-your-photos.php';
$landing_eyebrow = 'Export your photos';
$landing_h1 = 'Take your entire<br>archive with you.';
$landing_opener = <<<'HTML'
            <p>Every platform will let you leave. Eventually. In a zip file that only a machine could love, with the dates wrong and the captions in a JSON file nobody asked for. That&rsquo;s not an export; that&rsquo;s a hostage note with an attachment.</p>
            <p>SnapSmack reads those zips &mdash; Instagram, Flickr, WordPress, soon Blogger &mdash; and rebuilds the archive on your site with the dates, captions, tags, and order intact. Then it keeps the promise going the other way: everything you make here comes back out, whenever you like, in a form other software can read. Doors work in both directions. Ownership includes the right to leave. Even us.</p>
HTML;
$landing_closer = <<<'HTML'
            <p class="seo-closer-lines"><span>Get in.</span><span>Get out.</span><span>Either way, it&rsquo;s <em>yours.</em></span></p>
            <p class="seo-tail">The importers are on <a href="tools.php#get-in">BOX O&rsquo; TRICKS</a>. The no-lock-in promise is in writing on <a href="faq-content.php#q-snapsmack-lock-in">the FAQ</a>, and it&rsquo;s the kind of promise that&rsquo;s embarrassing to break in public.</p>
HTML;
require_once __DIR__ . '/includes/seo-landing.php';
// ===== SNAPSMACK EOF =====
