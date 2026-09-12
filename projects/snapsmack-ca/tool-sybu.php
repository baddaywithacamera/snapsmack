<?php
/**
 * SNAPSMACK.CA - SMACK YOUR BATCH UP (desktop tool page)
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
$page_title       = 'SMACK YOUR BATCH UP - batch publishing for SnapSmack';
$page_description = 'SMACK YOUR BATCH UP loads a whole shoot, lets you reorder and tag it, preserves EXIF copyright, and publishes every photograph to your SnapSmack site from the desktop. Optional AI writes captions and tags as the queue runs.';
$page_og_url      = 'https://snapsmack.ca/tool-sybu.php';
$nav_active       = 'tool-sybu';

$tool_name     = 'SMACK YOUR BATCH UP';
$tool_short    = 'Load a shoot. Order it. Tag it. Publish the lot. Go outside.';
$tool_platform = 'Windows / Linux';
$tool_status   = 'Shipping';
$tool_group    = 'publish';
$tool_news     = '';
$tool_facts    = [
    ['What it is', 'Batch publisher'],
    ['Keeps', 'EXIF copyright, capture dates'],
    ['AI', 'Optional: captions, tags, categories, colour'],
    ['Resume', 'Interrupted queues pick up where they stopped']
];
$tool_body = <<<'HTML'
            <h2>What it does for your site</h2>
            <p>A day&rsquo;s shoot is eighty photographs. The browser upload form is built for one. SMACK YOUR BATCH UP is the space between: point it at a folder, put the photographs in the order you want them to appear, assign categories and albums, and let it publish the queue while you do something better with your evening.</p>
            <h2>How it works</h2>
            <ul>
                <li>Drop in a folder or a selection. Thumbnails, capture dates, and EXIF come along.</li>
                <li>Reorder by drag, by date, or by filename. The order you see is the order the site gets.</li>
                <li>Assign categories, albums, and tags to the whole batch or to individual frames.</li>
                <li>Optional AI enrichment looks at each photograph and drafts the caption, alt text, tags, categories, and colour metadata &mdash; using your site&rsquo;s own prompt so it sounds like you. You review before anything publishes.</li>
                <li>Publish. The queue runs, throttled so your host doesn&rsquo;t notice, and resumes if it&rsquo;s interrupted.</li>
            </ul>
            <h2>The rules it follows</h2>
            <ul>
                <li>Your EXIF copyright is preserved on the way up. If you&rsquo;ve configured a copyright string, it&rsquo;s written; nothing else in the EXIF is touched.</li>
                <li>Images are sized to your site&rsquo;s contract on your machine, once.</li>
                <li>It never copies your originals anywhere. The folder you pointed it at is the only copy it knows.</li>
            </ul>
HTML;
$tool_shots = [
    ['sybu-uploading.png', 'Smack Your Batch Up publishing a batch of photographs', 'The queue running: each photograph sized, enriched if you asked, and posted in the order you set.'],
    ['sybu-credentials.png', 'Smack Your Batch Up site credentials screen', 'Site profiles are shared with the rest of the suite through SNAP HQ; enter them once.']
];
require_once __DIR__ . '/includes/tool-page.php';
// ===== SNAPSMACK EOF =====
