<?php
/**
 * SNAPSMACK - Skin Help Topics: 50 Shades of Noah Grey
 * Alpha v0.7
 */

return [

    'skin-overview-grey' => [
        'section'  => 'Active Skin: 50 Shades of Noah Grey',
        'title'    => 'Skin Overview',
        'icon'     => '&#x25CF;',
        'content'  => <<<'HTML'
<h3>50 Shades of Noah Grey — v2.4</h3>
<p>A pure greyscale photography skin with zero colour accents. Everything — backgrounds,
borders, text, frames — exists on the grey spectrum. This skin is built for photographers
who work primarily in black and white, or who want their colour work to be the only
chromatic element on the page.</p>

<h4>Key Features</h4>
<ul>
    <li><strong>Three monochrome variants</strong> — Dark (near-black background), Medium
    (mid-grey), and Light (off-white). Switch between them in Smooth Your Skin.</li>
    <li><strong>Gallery Wall support</strong> — with greyscale-appropriate shadow and text
    defaults.</li>
    <li><strong>Three archive layouts</strong> — Square, Cropped, and Masonry (justified).</li>
    <li><strong>Frame styles</strong> — six options: Thin Border, Medium Border, Heavy Border,
    Soft Shadow, Heavy Shadow, and None.</li>
    <li><strong>Typography controls</strong> — includes text-transform options
    (uppercase/lowercase/capitalize/none) and font-weight fine-tuning (300–900).</li>
</ul>

<h4>Variants</h4>
<p>The three variants (Dark, Medium, Light) control the overall tonal range of the site.
Each variant is a separate CSS file that adjusts backgrounds, borders, and text colours
while sharing the same layout structure. The default variant is Dark.</p>
<p>Choose your variant based on the dominant tone of your photography: dark variants suit
high-contrast or low-key work; light variants suit high-key or documentary photography.</p>

<h4>Design Philosophy</h4>
<p>This skin deliberately avoids any colour. No accent colours, no coloured links, no
highlighted buttons. The only colour comes from your photographs. This forces the viewer's
attention entirely onto the work.</p>
HTML
    ],

    'skin-variants-grey' => [
        'section'  => 'Active Skin: 50 Shades of Noah Grey',
        'title'    => 'Variants & Typography',
        'icon'     => '&#x25D1;',
        'content'  => <<<'HTML'
<h3>Variants</h3>
<ul>
    <li><strong>Dark</strong> — near-black background (#111), light grey text. The default.
    Best for dramatic, high-contrast photography.</li>
    <li><strong>Medium</strong> — mid-grey background (~#666), balanced text. Works well
    for documentary or mixed-tone collections.</li>
    <li><strong>Light</strong> — off-white background (#eee), dark text. Best for high-key,
    minimal, or editorial-style presentation.</li>
</ul>

<h3>Typography</h3>
<p>This skin uses Raleway as the default header font and DM Sans for body text, chosen
for their clean geometric character that complements greyscale imagery.</p>

<h4>Unique Typography Options</h4>
<ul>
    <li><strong>Text Transform</strong> — apply uppercase, lowercase, capitalize, or none
    to header text. Uppercase Raleway is a particularly strong look for this skin.</li>
    <li><strong>Font Weight</strong> — fine-tune from 300 (light) to 900 (black). Lighter
    weights work well on dark variants; heavier weights on light variants.</li>
    <li><strong>Letter Spacing</strong> — widen or tighten character spacing independently
    of font choice.</li>
</ul>
HTML
    ],

];
