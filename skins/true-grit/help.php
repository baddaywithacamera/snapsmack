<?php
/**
 * SNAPSMACK - Skin Help Topics: True Grit
 * Alpha v0.7
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

];
