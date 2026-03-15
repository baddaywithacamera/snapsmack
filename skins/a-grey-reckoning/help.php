<?php
/**
 * SNAPSMACK - Skin Help Topics: Grey Expectations
 * Alpha v0.7.3a
 */

return [

    'skin-overview-ge' => [
        'section'  => 'Active Skin: A Grey Reckoning',
        'title'    => 'Skin Overview',
        'icon'     => '&#x25CF;',
        'content'  => <<<'HTML'
<h3>A Grey Reckoning — v1.0</h3>
<p>A quiet, reverent photography skin inspired by the 2006-era websites of Noah Grey
(greyexpectations.com, noahgrey.com). Solid dark backgrounds, thin borders, small-caps
navigation with generous letter-spacing, and nothing between the viewer and the photograph.</p>

<h4>Key Features</h4>
<ul>
    <li><strong>Single image view</strong> — Title bar with site name and date, centred photograph,
    caption below, and a compact navigation row (BACK / INFO / COMMENTS / ARCHIVES / RSS / NEXT).</li>
    <li><strong>Split landing page</strong> — Navigation menu stacked on the left with descriptions,
    most recent photograph filling the right. Optional hero layout also available.</li>
    <li><strong>White-bordered archive grid</strong> — Square thumbnails with crisp white borders on
    black, reminiscent of prints on a gallery table.</li>
    <li><strong>Typography</strong> — Raleway for navigation and titles (small-caps, spaced), Cormorant
    Garamond for captions and body text (literary, elegant).</li>
</ul>

<h4>Design Philosophy</h4>
<p>This skin deliberately avoids anything flashy. No animations, no frames, no textures. The
background is black. The type is small. The borders are thin. The photograph speaks for itself.</p>
HTML
    ],

    'skin-layout-ge' => [
        'section'  => 'Active Skin: A Grey Reckoning',
        'title'    => 'Page Layouts',
        'icon'     => '&#x25A1;',
        'content'  => <<<'HTML'
<h3>Landing Page</h3>
<p>Two layout options:</p>
<ul>
    <li><strong>Split</strong> (default) — Navigation menu on the left with section titles and short
    descriptions, most recent photograph on the right. Inspired by Noah Grey's hub pages.</li>
    <li><strong>Hero</strong> — Full-width photograph centred on the page with a minimal navigation
    row at the bottom.</li>
</ul>

<h3>Single Image View</h3>
<p>The core of the skin. A title bar spans the top with the site name left-aligned and the date
right-aligned. Below it, the photograph is centred. The caption appears beneath the image in
Cormorant Garamond. A navigation row at the bottom provides BACK, INFO, COMMENTS, ARCHIVES,
RSS, and NEXT links — all in small-caps with generous spacing.</p>

<h3>Archive</h3>
<p>A clean square grid of thumbnails with white borders. Grid columns are configurable (2–6).
Titles appear below each thumbnail in tiny spaced uppercase. The border width is adjustable.</p>
HTML
    ],

    'skin-community-ge' => [
        'section'  => 'Active Skin: A Grey Reckoning',
        'title'    => 'Likes, Reactions & Comments',
        'icon'     => '&#x2665;',
        'content'  => <<<'HTML'
<h3>Community Features</h3>
<p>SnapSmack ships a built-in community system: likes, emoji reactions, and comments — all
self-hosted on your own server, no third-party tracking or login walls.</p>

<h4>Community Dock</h4>
<p>A floating action button appears on every page. Tap it to expand the dock, which reveals
a like button and a reaction picker. Visitors can react without creating an account. Dock
position and the active reaction set are configured in Admin > Community Settings.</p>

<h4>Comments</h4>
<p>Comments appear below each image via the INFO / COMMENTS navigation row. To leave a comment,
visitors create a free community account on your site. Approve or reject comments from
Admin > Manage.</p>
HTML
    ],

];
