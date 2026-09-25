<?php
/**
 * SNAPSMACK.CA - COLD SNAP (desktop tool page)
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
$page_title       = 'COLD SNAP - offline composer for SnapSmack';
$page_description = 'COLD SNAP lets you write SnapSmack posts with no internet connection - plain text with the shortcode bar (TWIGGY) or the visual block editor (BIGGIE) - from a local cold-storage copy of your site, then sync when you are back online.';
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
    ['Writing', 'TWIGGY (text) + BIGGIE (visual)'],
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
                <li><strong>TAKE</strong> &mdash; the SMACKTALK long-form editor. Two ways to write the same post: <strong>TWIGGY</strong> and <strong>BIGGIE</strong>, below. A real photographic essay, composed offline.</li>
                <li><strong>COLD STORAGE</strong> &mdash; the local copy of your site. Sync it, browse it, pick from it, and let the AI enrichment fill in captions and alt text if you want it to.</li>
            </ul>
            <h2>Two ways to write: TWIGGY and BIGGIE</h2>
            <p>The TAKE tab has one switch above the body, and two faces behind it. Both build the same post. Flip between them as often as you like &mdash; nothing is lost either way.</p>
            <ul>
                <li><strong>TWIGGY</strong> &mdash; the plain text box with the shortcode bar. The same way the website&rsquo;s own editor works: you type, you click a button, it drops a shortcode into the text.</li>
                <li><strong>BIGGIE</strong> &mdash; the visual editor. Click and type; Enter starts a paragraph. Headings look like headings, pull-quotes look like pull-quotes, and a MOSAIC is painted with your actual photographs, tiled in the layout you picked, sitting where it will sit on the page. Double-click a mosaic to change it. Ctrl+Z undoes any of it, mosaics included.</li>
            </ul>
            <p>BIGGIE sends the exact same shortcodes TWIGGY makes, character for character. Your site cannot tell which face you used, and a post written in one opens fine in the other.</p>
            <h2>Why the visual editor is on your desktop, not in your website</h2>
            <p>Your site sits on shared hosting: one machine, a lot of sites, a CPU allowance you can blow through. A visual editor is the most expensive thing you can put on it. Every time you drop a photograph into the page it wants a preview built, and building previews of full-size photographs, over and over, for every person editing at once, is exactly the load that gets a shared account throttled or shut off. That cost lands on you and on every site sharing the box &mdash; while you are writing, and while nobody is even reading your site.</p>
            <p>Your own computer is sitting there doing nothing, and it already has the photographs on it. So that is where the heavy editor runs. The website keeps the light shortcode bar, which costs the server nothing, and COLD SNAP does the expensive part on hardware you already paid for.</p>
            <h2>Only SMACKTALK gets it</h2>
            <p>SMACKONEOUT is one photograph and a caption. GRAMOFSMACK is a run of them. Neither is a page you lay out, so neither needs a page-layout editor. COLD ONE and STACK show the plain box and the bar, with no switch and no clutter. BIGGIE appears in the TAKE tab, because SMACKTALK is the only mode where anyone would use it.</p>
            <h2>The rules it follows</h2>
            <ul>
                <li>The local store is a <strong>cache of your site</strong>, never a second copy of your originals. It never shadow-copies your files anywhere.</li>
                <li>Image metadata travels with the image. Caption it once, it stays captioned.</li>
                <li>Every image you size here is sized once, on your machine, to your site&rsquo;s contract. Your hosting account never resizes a thing.</li>
                <li>One AI prompt per site. COLD SNAP reads the same prompt the rest of the suite uses, so the voice is consistent.</li>
            </ul>
            <p>BIGGIE already draws mosaics as real photographs while you write, with no shortcode text on screen. What&rsquo;s still in the workshop is the rest of the block set. <a href="coming-soon.php">See what&rsquo;s coming.</a></p>
HTML;
$tool_shots = [

];
require_once __DIR__ . '/includes/tool-page.php';
// ===== SNAPSMACK EOF =====
