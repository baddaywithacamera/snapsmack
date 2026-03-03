<?php
/**
 * SNAPSMACK - Skin Help Topics: New Horizon Dark
 * Alpha v0.7
 *
 * Returns an array of help topics for the Man Pages system.
 * These are merged into smack-help.php when this skin is active.
 */

return [

    'skin-overview' => [
        'section'  => 'Active Skin: New Horizon Dark',
        'title'    => 'Skin Overview',
        'icon'     => '&#x25CF;',
        'content'  => <<<'HTML'
<h3>New Horizon Dark — v5.5</h3>
<p>New Horizon Dark is the flagship SnapSmack skin. It is a high-contrast dark-mode theme
built for photographers who want maximum control over presentation. Every visual element
— framing, typography, spacing, wall physics — is configurable through the Pimpotron
(Smooth Your Skin).</p>

<h4>Key Features</h4>
<ul>
    <li><strong>Gallery Wall support</strong> — fully interactive 3D drag-and-drop wall
    with configurable physics, shadows, and typography.</li>
    <li><strong>Three archive layouts</strong> — Square (uniform grid), Cropped (constrained
    aspect ratio), and Masonry (justified rows preserving full aspect ratio).</li>
    <li><strong>Five image frame styles</strong> — Revival Double, Classic White, Gallery Mat,
    Minimal Bevel, and Obsidian.</li>
    <li><strong>Full JS library</strong> — lightbox, keyboard navigation, justified layout
    engine, and footer animations.</li>
    <li><strong>Dropcap support</strong> — three styles (None, Simple Bold, Tactical Block)
    for decorative first letters in content.</li>
    <li><strong>Extensive typography controls</strong> — separate font, size, and spacing
    settings for headers, body content, and footers.</li>
</ul>
HTML
    ],

    'skin-framing' => [
        'section'  => 'Active Skin: New Horizon Dark',
        'title'    => 'Image Framing',
        'icon'     => '&#x25A1;',
        'content'  => <<<'HTML'
<h3>Image Frame Styles</h3>
<p>New Horizon Dark offers five distinct frame styles for single-image views and a separate
set for archive thumbnails. Configure these in Smooth Your Skin under "Vertical Locks".</p>

<h4>Single Image Frames</h4>
<ul>
    <li><strong>Revival Double</strong> — a double-border frame with a thin inner rule and a
    heavier outer border. Elegant and classical.</li>
    <li><strong>Classic White</strong> — a clean white border that acts as digital matting.
    Works well with both colour and black-and-white photography.</li>
    <li><strong>Gallery Mat</strong> — simulates a traditional gallery mat with a wider border
    and subtle depth. The most museum-like option.</li>
    <li><strong>Minimal Bevel</strong> — a thin single border with a slight bevel effect.
    Understated and modern.</li>
    <li><strong>Obsidian</strong> — a dark frame that blends with the background, making the
    image appear to float. Best for high-key or bright images.</li>
</ul>

<h4>Archive Frames</h4>
<p>Archive thumbnails have their own frame options, typically simpler: thin border, medium
border, shadow, heavy shadow, gallery mat, or none. These are set separately because what
works at full size often doesn't work at thumbnail scale.</p>
HTML
    ],

    'skin-wall' => [
        'section'  => 'Active Skin: New Horizon Dark',
        'title'    => 'Gallery Wall Settings',
        'icon'     => '&#x25A6;',
        'content'  => <<<'HTML'
<h3>Gallery Wall Configuration</h3>
<p>The gallery wall is a 3D interactive experience unique to skins that support it.
Configure it in Smooth Your Skin under "Wall Specific".</p>

<h4>Physics</h4>
<ul>
    <li><strong>Friction</strong> (0.1–0.99) — controls how quickly the wall decelerates
    after dragging. Low values make it feel like ice; high values feel heavy and controlled.
    Default: 0.92.</li>
    <li><strong>Drag Weight</strong> — multiplier for drag resistance. Higher values require
    more effort to move the wall.</li>
</ul>

<h4>Visual</h4>
<ul>
    <li><strong>Wall Background</strong> — the colour behind the tiles. Pure black by default.</li>
    <li><strong>Text Colour</strong> — title text that appears on hover.</li>
    <li><strong>Shadow Colour &amp; Intensity</strong> — the glow or shadow behind each tile.
    Options: none, light, heavy.</li>
    <li><strong>Font</strong> — the typeface used for tile titles on the wall.</li>
</ul>

<h4>Layout</h4>
<p>Wall rows (1–4), tile gap, and maximum tile count are configured in the global
Configuration page, not the skin customizer. These affect all wall-capable skins.</p>
HTML
    ],

    'skin-typography' => [
        'section'  => 'Active Skin: New Horizon Dark',
        'title'    => 'Typography',
        'icon'     => '&#x0041;',
        'content'  => <<<'HTML'
<h3>Typography Controls</h3>
<p>New Horizon Dark provides separate typography settings for different areas of the site.</p>

<h4>Header</h4>
<ul>
    <li><strong>Font Family</strong> — the typeface for the site header/title area.</li>
    <li><strong>Font Size</strong> — range from small to large.</li>
</ul>

<h4>Content &amp; Static Pages</h4>
<ul>
    <li><strong>Heading Font</strong> — used for h1, h2, h3 in static pages and descriptions.</li>
    <li><strong>Body Font</strong> — the main reading font for paragraph text.</li>
    <li><strong>Line Height</strong> (1.0–3.0) — vertical spacing between lines.</li>
    <li><strong>Letter Spacing</strong> (-2 to 10px) — horizontal spacing between characters.</li>
</ul>

<h4>Footer</h4>
<ul>
    <li><strong>Footer Font</strong> — typically a smaller or lighter weight for credits
    and metadata.</li>
    <li><strong>Footer Font Size</strong> — independent of other sizes.</li>
</ul>

<h4>Font Library</h4>
<p>SnapSmack ships with a curated font library. The available fonts depend on what's
installed in <code>assets/fonts/</code>. Each font has a license file in the
<code>licenses/</code> directory.</p>
HTML
    ],

    'skin-blogroll' => [
        'section'  => 'Active Skin: New Horizon Dark',
        'title'    => 'Blogroll Display',
        'icon'     => '&#x2661;',
        'content'  => <<<'HTML'
<h3>Blogroll Display Settings</h3>
<p>Configure how the blogroll page looks in Smooth Your Skin under "Blogroll".</p>

<h4>Options</h4>
<ul>
    <li><strong>Columns</strong> (1–3) — how many columns the peer list displays in.</li>
    <li><strong>Max Width</strong> — constrains the blogroll container width.</li>
    <li><strong>Column Gap</strong> — spacing between columns.</li>
    <li><strong>Show Description</strong> — toggle visibility of peer descriptions.</li>
    <li><strong>Show URL</strong> — toggle visibility of peer website URLs.</li>
</ul>
HTML
    ],

];
