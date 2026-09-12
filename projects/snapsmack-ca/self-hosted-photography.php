<?php
/**
 * SNAPSMACK.CA - SEO landing: Self-hosted photography website
 * Layout + the shared strip: includes/seo-landing.php. Only the opener and closer are per-page.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 */

$page_title = 'Build a Self-Hosted Photography Website - SnapSmack';
$page_description = 'Build a self-hosted photography website on ordinary shared hosting: free software, a real archive, your own domain, and a free desktop suite that does the heavy lifting on your own computer.';
$page_og_url = 'https://snapsmack.ca/self-hosted-photography.php';
$landing_eyebrow = 'Self-hosted photography website';
$landing_h1 = 'A photography website<br>you host yourself.';
$landing_opener = <<<'HTML'
            <p>&ldquo;Self-hosted&rdquo; sounds like it comes with a beard and a server rack. It doesn&rsquo;t. It means PHP and a database on the cheapest shared hosting you can find, uploaded once, running for years. No Docker. No Node. No monthly platform bill that quietly becomes a yearly platform bill.</p>
            <p>The heavy work &mdash; editing, resizing, batch publishing, backing up &mdash; happens on your own computer with a free desktop suite, so the hosting account never breaks a sweat. The site is a folder and a database. You can open both. You can copy both. Try that with a platform.</p>
HTML;
$landing_closer = <<<'HTML'
            <p class="seo-closer-lines"><span>A folder. A database.</span><span>Your domain.</span><span>Nothing to <em>cancel.</em></span></p>
            <p class="seo-tail">Guided installer, signed updates, built-in help on every admin page, and a <a href="brass-tacks.php">FAQ</a> that answers &ldquo;how techie do I have to be?&rdquo; honestly.</p>
HTML;
require_once __DIR__ . '/includes/seo-landing.php';
// ===== SNAPSMACK EOF =====
