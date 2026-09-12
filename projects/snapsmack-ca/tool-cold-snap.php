<?php
/**
 * SNAPSMACK.CA - COLD SNAP (desktop tool page)
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
$page_title       = 'COLD SNAP - offline composer for SnapSmack';
$page_description = 'COLD SNAP lets you write SnapSmack posts and build BIGGIE photo essays with no internet connection, from a local cold-storage copy of your site, then sync when you are back online.';
$page_og_url      = 'https://snapsmack.ca/tool-cold-snap.php';
$nav_active       = 'tool-cold-snap';

$tool_name     = 'COLD SNAP';
$tool_short    = 'Write the post on the train. Build the photo essay in the tent. Sync it when the signal comes back.';
$tool_platform = 'Windows / Linux';
$tool_status   = 'Shipping';
$tool_group    = 'make';
$tool_news     = 'cold-snap';
$tool_facts    = [
    ['What it is', 'Offline post composer'],
    ['Modes', 'SMACKONEOUT, GRAMOFSMACK, SMACKTALK'],
    ['Essays', 'BIGGIE blocks + MOSAIC'],
    ['Store', 'COLD STORAGE: a local copy of your site']
];
$tool_body = <<<'HTML'
            <h2>What it does for your site</h2>
            <p>Your site lives on a server. Your writing happens wherever you are, which is frequently somewhere with no signal. COLD SNAP keeps a local copy of your site&rsquo;s posts and images &mdash; the COLD STORAGE tab &mdash; so you can compose against the real archive, pick images from your real library, and queue finished posts. When you&rsquo;re back online, it syncs: your posts go up, the local copy refreshes.</p>
            <h2>Four tabs</h2>
            <ul>
                <li><strong>COLD ONE</strong> &mdash; a single post. Title, caption, tags, categories, album, schedule. The same fields as the site, without the site.</li>
                <li><strong>STACK</strong> &mdash; a run of posts, queued in order, sent as a batch.</li>
                <li><strong>TAKE</strong> &mdash; the SMACKTALK long-form editor, with BIGGIE blocks: paragraphs, headings, pull-quotes, single images, and justified MOSAIC panels built from your library. A real photographic essay, composed offline.</li>
                <li><strong>COLD STORAGE</strong> &mdash; the local copy of your site. Sync it, browse it, pick from it, and let the AI enrichment fill in captions and alt text if you want it to.</li>
            </ul>
            <h2>The rules it follows</h2>
            <ul>
                <li>The local store is a <strong>cache of your site</strong>, never a second copy of your originals. It never shadow-copies your files anywhere.</li>
                <li>Image metadata travels with the image. Caption it once, it stays captioned.</li>
                <li>Every image you size here is sized once, on your machine, to your site&rsquo;s contract. Your hosting account never resizes a thing.</li>
                <li>One AI prompt per site. COLD SNAP reads the same prompt the rest of the suite uses, so the voice is consistent.</li>
            </ul>
            <p>The BIGGIE editor is growing up: the next version renders mosaics as real photographs while you write, with no shortcode text. <a href="coming-soon.php">It&rsquo;s in the workshop.</a></p>
HTML;
$tool_shots = [

];
require_once __DIR__ . '/includes/tool-page.php';
// ===== SNAPSMACK EOF =====
