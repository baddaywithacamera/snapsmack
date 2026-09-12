<?php
/**
 * SNAPSMACK.CA - THE UNZUCKER (desktop tool page)
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
$page_title       = 'THE UNZUCKER - Instagram to SnapSmack importer';
$page_description = 'THE UNZUCKER moves an Instagram data export into a SnapSmack site with images, captions, hashtags, carousels, and original dates intact, and lets you arrange the archive as the classic three-across grid.';
$page_og_url      = 'https://snapsmack.ca/tool-unzucker.php';
$nav_active       = 'tool-unzucker';

$tool_name     = 'THE UNZUCKER';
$tool_short    = 'Your Instagram, un-Zucked. Every photograph, caption, hashtag, carousel, and date, on your own domain, in the grid they took away.';
$tool_platform = 'Windows / Linux';
$tool_status   = 'Shipping';
$tool_group    = 'get-in';
$tool_news     = '';
$tool_facts    = [
    ['What it is', 'Instagram importer'],
    ['Input', 'The official Instagram data export'],
    ['Keeps', 'Dates, captions, hashtags, carousels, grid order'],
    ['Destination', 'GRAMOFSMACK']
];
$tool_body = <<<'HTML'
            <h2>What it does for your site</h2>
            <p>Instagram will give you your data if you ask: a zip of photographs and a JSON file that only a machine could love. THE UNZUCKER reads it, rebuilds your posts with their real dates and captions, keeps carousels as carousels, and lets you lay the whole archive out as the classic three-across grid before a single byte reaches your site.</p>
            <h2>How it works</h2>
            <ul>
                <li>Request your export from Instagram. Point THE UNZUCKER at the zip.</li>
                <li>It parses the export, fixes the mangled text encoding Instagram ships, and shows you the archive as a grid.</li>
                <li>Arrange it. Drag frames, lock panorama groups so three-across stays three-across, decide what stays and what doesn&rsquo;t.</li>
                <li>Throttle the transfer so your host is happy, hit go, walk away. Interrupted jobs resume.</li>
            </ul>
            <h2>The rules it follows</h2>
            <ul>
                <li>Original dates are the dates the site gets. Your 2014 posts stay in 2014.</li>
                <li>Captions and hashtags are preserved as written; hashtags become tags.</li>
                <li>It writes your EXIF copyright on the way in if you&rsquo;ve set one. Instagram stripped it; you get it back.</li>
            </ul>
            <p>Want to see the result before you commit? <a href="https://unzucked.ca/" target="_blank" rel="noopener">unzucked.ca</a> is an Instagram archive that went through exactly this.</p>
HTML;
$tool_shots = [
    ['unzucker-gridsorter.png', 'The Unzucker arranging an imported Instagram grid', 'The grid sorter: your archive as three-across, before it goes anywhere.'],
    ['unzucker-config.png', 'The Unzucker configuration screen', 'Site, throttle, copyright, and what to do with carousels &mdash; set once.']
];
require_once __DIR__ . '/includes/tool-page.php';
// ===== SNAPSMACK EOF =====
