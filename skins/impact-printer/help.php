<?php
/**
 * SNAPSMACK - Skin Help Topics: Impact Printer
 * Alpha v0.7
 */

return [

    'skin-overview-printer' => [
        'section'  => 'Active Skin: Impact Printer',
        'title'    => 'Skin Overview',
        'icon'     => '&#x25CF;',
        'content'  => <<<'HTML'
<h3>Impact Printer — v2.1</h3>
<p>A retro skin that recreates the aesthetic of a continuous-feed dot-matrix printer from
1983. Tractor-feed paper textures, ASCII character borders, faded ribbon ink, and strictly
monospace fonts. This is a novelty skin with genuine personality — it commits fully to the
bit and does not break character.</p>

<h4>Key Features</h4>
<ul>
    <li><strong>Two paper stocks</strong> — Green-bar ledger (the classic alternating-stripe
    computer paper) and Plain white.</li>
    <li><strong>ASCII frame borders</strong> — images are framed using keyboard characters:
    box-drawing, plus signs, equals signs, slashes, or none.</li>
    <li><strong>Ink quality simulation</strong> — four levels from Fresh (crisp black) to
    Dying (barely visible, smeared), controlling opacity and bleed effects.</li>
    <li><strong>Restricted font palette</strong> — only DotMatrix and Tiny5 font families
    are available. No proportional fonts.</li>
    <li><strong>No floating gallery</strong> — wall support is disabled. The dot-matrix printer
    cannot render a 3D experience (it is, after all, a printer).</li>
    <li><strong>Two archive layouts</strong> — Square and Cropped only. Masonry/justified
    is disabled (a printer feeds paper in fixed columns).</li>
</ul>

<h4>Design Constraints</h4>
<p>This skin intentionally limits your options to maintain authenticity. You cannot choose
arbitrary fonts, the colour palette is monochrome, and many layout features available in
other skins are disabled. These constraints are the point — the skin works because it
commits to the aesthetic completely.</p>
HTML
    ],

    'skin-printer-options' => [
        'section'  => 'Active Skin: Impact Printer',
        'title'    => 'Paper, Ink & Borders',
        'icon'     => '&#x2592;',
        'content'  => <<<'HTML'
<h3>Paper Stock (Variants)</h3>
<ul>
    <li><strong>Green-bar</strong> — alternating pale green and white horizontal stripes,
    like the fan-fold computer paper used in offices. The default.</li>
    <li><strong>Plain</strong> — clean white paper with no stripes. A more restrained look
    that still uses the dot-matrix typography and borders.</li>
</ul>

<h3>ASCII Frame Borders</h3>
<p>Images are surrounded by character-based borders drawn from the ASCII set. Five styles:</p>
<ul>
    <li><strong>Box</strong> — box-drawing characters (┌─┐│└─┘). The most authentic.</li>
    <li><strong>Plus</strong> — plus signs at corners, dashes on sides (+--+).</li>
    <li><strong>Equals</strong> — double-line effect using equals signs.</li>
    <li><strong>Slash</strong> — forward slashes and backslashes for a rough look.</li>
    <li><strong>None</strong> — no border.</li>
</ul>
<p>Frame weight (8–40px) and padding (0–40px) are configurable. Heavier weights are more
visible but take up more space. Archive thumbnails have their own frame setting.</p>

<h3>Ink Quality</h3>
<p>Simulates the state of the printer ribbon:</p>
<ul>
    <li><strong>Fresh</strong> — crisp, full-darkness text and borders.</li>
    <li><strong>Normal</strong> — slight fade, the comfortable default.</li>
    <li><strong>Faded</strong> — noticeably washed out, like a ribbon that needs replacing.</li>
    <li><strong>Dying</strong> — barely legible, with bleed and smearing effects. Purely
    for aesthetic commitment.</li>
</ul>

<h3>Paper Margins</h3>
<p>Control left and right margins (20–120px each) to simulate the tractor-feed margins
of real computer paper. The canvas width is configurable from 700–1600px.</p>
HTML
    ],

];
