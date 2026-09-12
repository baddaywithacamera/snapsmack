<?php
/**
 * SNAPSMACK.CA - GET YOUR SHIT SORTED (desktop tool page)
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
$page_title       = 'GET YOUR SHIT SORTED - offline archive sorter for SnapSmack';
$page_description = 'GET YOUR SHIT SORTED is an offline sorter for a whole SnapSmack blog: repair missing titles, captions, alt text, tags, categories and albums; reorder the feed; assemble carousels; then push the changes back.';
$page_og_url      = 'https://snapsmack.ca/tool-gyss.php';
$nav_active       = 'tool-gyss';

$tool_name     = 'GET YOUR SHIT SORTED';
$tool_short    = 'Fifteen years of posts, half of them untitled. Sort the whole blog on your desk, offline, and push it back when it&rsquo;s right.';
$tool_platform = 'Windows / Linux';
$tool_status   = 'Shipping';
$tool_group    = 'publish';
$tool_news     = '';
$tool_facts    = [
    ['What it is', 'Offline archive sorter'],
    ['Works on', 'A local library of your posts + thumbs'],
    ['Fixes', 'Titles, captions, alt text, tags, albums, order'],
    ['Builds', 'GRAMOFSMACK carousels']
];
$tool_body = <<<'HTML'
            <h2>What it does for your site</h2>
            <p>An imported archive arrives with gaps: no titles, captions that were hashtags, alt text nobody wrote in 2011. Fixing that through a browser admin one post at a time is how archives stay broken. GYSS syncs a local library of your posts and thumbnails, lets you work through the whole thing at desktop speed &mdash; offline &mdash; and pushes only the changes back.</p>
            <h2>How it works</h2>
            <ul>
                <li>Sync a blog. You get a local library: every post, its thumbnail, its metadata.</li>
                <li>Sort, filter, and search the archive. Find the two hundred posts with no alt text in one click.</li>
                <li>Repair titles, captions, alt text, tags, categories, albums, and colour metadata. By hand, or with the AI enrichment drafting it from the photograph and your site&rsquo;s prompt.</li>
                <li>Reorder a SMACKONEOUT or GRAMOFSMACK feed by drag. Assemble GRAMOFSMACK carousels from loose posts.</li>
                <li>Push. GYSS shows you exactly what changed on which site before it commits, and it will not push to the wrong site &mdash; that failure mode was found, fixed, and tested.</li>
            </ul>
            <h2>The rules it follows</h2>
            <ul>
                <li>It is an <strong>offline sorter</strong>. That is the founding intent. The network only exists at the edges, as sync.</li>
                <li>The local library is a cache of the site, not of your originals.</li>
                <li>Deleting is a step-up action: password and 2FA, every time, on purpose.</li>
            </ul>
HTML;
$tool_shots = [

];
require_once __DIR__ . '/includes/tool-page.php';
// ===== SNAPSMACK EOF =====
