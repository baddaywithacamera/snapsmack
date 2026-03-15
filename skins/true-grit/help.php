<?php
/**
 * SNAPSMACK - Skin Help Topics: True Grit
 * Alpha v0.7.3a
 */

return [

    'skin-overview-truegrit' => [
        'section'  => 'Active Skin: True Grit',
        'title'    => 'Skin Overview',
        'icon'     => '&#x25A3;',
        'content'  => <<<'HTML'
<h3>True Grit — v1.0</h3>
<p>A found-texture photography skin built for foundtextures.ca. The site itself uses
photographic textures — rust, wood grain, cement, peeling paint, weathered wallpaper — as
its visual backdrop, creating thematic coherence between the content and the container.</p>

<h4>Key Features</h4>
<ul>
    <li><strong>20 photographic wall textures</strong> — all real-world found textures
    (not tileable patterns) displayed at full viewport coverage.</li>
    <li><strong>Opacity overlay</strong> — adjustable colour wash over the texture so it
    doesn't fight with the photography. Dark or light overlay with fine-grained control.</li>
    <li><strong>Archival frame styles</strong> — six options from New Horizon: Shadow Float,
    Revival Double, Classic Horizon, Gallery Multi-Mat, Minimal Bevel, and Obsidian.</li>
    <li><strong>Two variants</strong> — Dark (charcoal base) and Light (warm grey base).</li>
    <li><strong>Floating Gallery</strong> — full photo wall support with physics engine.</li>
    <li><strong>Three archive layouts</strong> — Square, Cropped, and Masonry (justified).</li>
</ul>

<h4>Design Philosophy</h4>
<p>The texture background is the signature of this skin. The opacity overlay lets you dial in
exactly how much texture shows through — from barely visible warmth to bold, character-defining
surface. The frame styles are borrowed from New Horizon's archival system, chosen because they
complement rather than compete with textured backgrounds.</p>
HTML
    ],

    'skin-textures-truegrit' => [
        'section'  => 'Active Skin: True Grit',
        'title'    => 'Wall Textures & Overlay',
        'icon'     => '&#x25A8;',
        'content'  => <<<'HTML'
<h3>Wall Textures</h3>
<p>All 20 textures are high-resolution photographs (2400px wide) of real-world surfaces.
They are displayed via <code>background-size: cover</code> with fixed attachment, so the
texture stays in place while content scrolls over it.</p>

<h4>Texture Categories</h4>
<ul>
    <li><strong>Wood</strong> — Knotty Wood, Some Wood I/II/III</li>
    <li><strong>Paint & Rust</strong> — Blue & Gold, Streaked Auto Paint, Skungy Auto Paint,
    Faint Painted Auto, Yellow Cab, Used to be Blue, Not a Blackpink Concert, Two Layers
    of Paint vs None, Seeing the Bottom Layer, A Little of Everything</li>
    <li><strong>Concrete & Plaster</strong> — Painted Concrete</li>
    <li><strong>Wallpaper</strong> — Stained Pastel, Tea Wallpaper, Wallpaper Layers,
    Some Sort of Plant Wallpaper</li>
    <li><strong>Organic</strong> — Leafy Goodness</li>
</ul>

<h3>Overlay System</h3>
<p>The overlay is a CSS pseudo-element (<code>body::before</code>) that sits between the
texture and the page content. Two controls:</p>
<ul>
    <li><strong>Overlay Opacity</strong> — 0 (texture fully visible) to 0.95 (texture barely
    visible). Default: 0.4.</li>
    <li><strong>Overlay Colour</strong> — any colour. Dark overlays darken the texture;
    light overlays wash it out. Default: #1a1a1a (near-black).</li>
</ul>
<p>Tip: For the dark variant, use a dark overlay at 0.3–0.5 opacity. For the light variant,
try a warm grey or cream overlay at 0.5–0.7 opacity.</p>
HTML
    ],

    'skin-community-tg' => [
        'section'  => 'Active Skin: True Grit',
        'title'    => 'Likes, Reactions & Comments',
        'icon'     => '&#x2665;',
        'content'  => <<<'HTML'
<h3>Community Features</h3>
<p>SnapSmack ships a built-in community system: likes, emoji reactions, and comments — all self-hosted on your own server, no third-party tracking or login walls.</p>

<h4>Community Dock</h4>
<p>A floating action button appears on every page. Tap it to expand the dock, which reveals a like button and a reaction picker (up to 6 emoji). Visitors can react without creating an account. Dock position (bottom-left or bottom-right) and the active reaction set are configured in Admin > Community Settings.</p>

<h4>Comments</h4>
<p>Comments appear below each image. To leave a comment, visitors create a free community account directly on your site — no email confirmation required, no third-party auth. Once logged in, their display name appears on all their comments. Approve or reject comments from Admin > Manage.</p>

<h4>Admin Controls</h4>
<p>Go to Admin > Community Settings to toggle likes, reactions, and comments independently; choose the active reaction set; enable or disable the thumbs-down reaction; set rate limits; and configure email notifications for new comments.</p>
HTML
    ],

];
