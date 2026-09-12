<?php
/**
 * SNAPSMACK.CA - SMACKPRESS (desktop tool page)
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
$page_title       = 'SMACKPRESS - WordPress to SnapSmack migration';
$page_description = 'SMACKPRESS moves a WordPress photo blog into a SnapSmack SMACKTALK site one post at a time, with a review step for each post, its images, and its formatting.';
$page_og_url      = 'https://snapsmack.ca/tool-smackpress.php';
$nav_active       = 'tool-smackpress';

$tool_name     = 'SMACKPRESS';
$tool_short    = 'A WordPress photo blog into SMACKTALK, one post at a time, with a look at each before it lands. For the blogs where every post deserves it.';
$tool_platform = 'Windows / Linux';
$tool_status   = 'Closed beta';
$tool_group    = 'get-in';
$tool_news     = '';
$tool_facts    = [
    ['What it is', 'WordPress migration workbench'],
    ['Pace', 'One post at a time, reviewed'],
    ['Destination', 'SMACKTALK'],
    ['Images', 'Pulled off WordPress into your library']
];
$tool_body = <<<'HTML'
            <h2>What it does for your site</h2>
            <p>A long-running WordPress blog is not a bulk import. It has fifteen years of formatting drift, three plugin eras, and posts you would write differently now. SMACKPRESS is a workbench, not a firehose: it shows you each post, its images, and how it will read in SMACKTALK, and you say yes, fix, or skip.</p>
            <h2>How it works</h2>
            <ul>
                <li>Connect to the WordPress site (or its export). SMACKPRESS lists the posts.</li>
                <li>Open one. See the original and the SMACKTALK conversion side by side. Images are pulled off WordPress and into your site&rsquo;s image bucket, so nothing hotlinks back to a server you&rsquo;re leaving.</li>
                <li>Fix what needs fixing &mdash; a heading, a caption, a mosaic that should be a single image &mdash; and post it.</li>
                <li>Next.</li>
            </ul>
            <h2>The rules it follows</h2>
            <ul>
                <li>Dates are the original publish dates.</li>
                <li>Nothing is published without you looking at it. That&rsquo;s the point of the tool.</li>
                <li>The WordPress site is read, never modified. Leave it up until you&rsquo;re sure.</li>
            </ul>
HTML;
$tool_shots = [

];
require_once __DIR__ . '/includes/tool-page.php';
// ===== SNAPSMACK EOF =====
