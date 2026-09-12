<?php
/**
 * SNAPSMACK.CA - FLKR FCKR (desktop tool page)
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
$page_title       = 'FLKR FCKR - Flickr to SnapSmack importer';
$page_description = 'FLKR FCKR imports a Flickr archive into SnapSmack with photographs, titles, descriptions, tags, upload dates, views, comments, and likes preserved. Pair it with the SLICKR skin to keep the familiar look.';
$page_og_url      = 'https://snapsmack.ca/tool-flkr-fckr.php';
$nav_active       = 'tool-flkr-fckr';

$tool_name     = 'FLKR FCKR';
$tool_short    = 'Twenty years of Flickr, moved without resetting the clock. Titles, descriptions, tags, dates, views, comments, and faves all come along.';
$tool_platform = 'Windows / Linux';
$tool_status   = 'Shipping';
$tool_group    = 'get-in';
$tool_news     = '';
$tool_facts    = [
    ['What it is', 'Flickr importer'],
    ['Input', 'The Flickr data download'],
    ['Keeps', 'Dates, titles, descriptions, tags, views, comments, faves'],
    ['Pairs with', 'The SLICKR skin']
];
$tool_body = <<<'HTML'
            <h2>What it does for your site</h2>
            <p>Flickr was the photographers&rsquo; place, and a lot of us have a decade or two there. Its data download is thorough but scattered across hundreds of JSON files. FLKR FCKR reads the lot, matches every photograph to its metadata, and rebuilds the archive on your site with its history intact &mdash; including the view counts, comments, and faves you earned.</p>
            <h2>How it works</h2>
            <ul>
                <li>Request your data from Flickr. It arrives as several zips. Point FLKR FCKR at the folder.</li>
                <li>It matches photographs to their sidecar metadata, including the ones Flickr renamed on the way out.</li>
                <li>Review the inventory: what matched, what didn&rsquo;t, what you want to skip.</li>
                <li>Import. Checkpointed, so a two-day transfer that dies on hour nineteen resumes at hour nineteen.</li>
            </ul>
            <h2>The rules it follows</h2>
            <ul>
                <li>The date the photograph was taken is the date it gets. Where Flickr&rsquo;s date and the EXIF date disagree, you choose which wins.</li>
                <li>Comments and faves are preserved as engagement on the post, not discarded as &ldquo;someone else&rsquo;s data.&rdquo;</li>
                <li>Pair it with the <a href="skins.php">SLICKR</a> skin when familiarity is part of the plan. It looks like where you came from, on a domain you own.</li>
            </ul>
HTML;
$tool_shots = [
    ['flkr-fckr.png', 'FLKR FCKR importing a Flickr archive', 'The import running against a real Flickr download.'],
    ['flkr-fckr-01.png', 'FLKR FCKR setup', 'Where the archive is, where it&rsquo;s going, and how fast.']
];
require_once __DIR__ . '/includes/tool-page.php';
// ===== SNAPSMACK EOF =====
