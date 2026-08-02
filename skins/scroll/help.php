<?php
/**
 * SNAPSMACK — Skin Help Topics: SCROLL
 *
 * Loaded by smack-help.php only while SCROLL is active.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

return [
    'skin-scroll-overview' => [
        'section' => 'Active Skin: SCROLL',
        'title' => 'SCROLL',
        'icon' => '&#x2193;',
        'content' => <<<'HTML'
<h3>SCROLL</h3>
<p>SCROLL is a solo photoblog skin with a large typographic landing page and a
four-column photo wall. Select a photograph to open its normal solo page; select
the photograph again there to use the standard lightbox.</p>
<p>The whole car is somebody else's problem.</p>
HTML
    ],
    'skin-scroll-masthead' => [
        'section' => 'Active Skin: SCROLL',
        'title' => 'Masthead and Navigation',
        'icon' => '&#x2197;',
        'content' => <<<'HTML'
<h3>Masthead and Navigation</h3>
<p>Use a vertical bar (<code>|</code>) in the masthead setting to choose your
own line breaks. Its size is adjustable from 3–8 percent of the viewport
width, and it may be tilted up to 40 degrees counterclockwise. Its typeface and
colour are independent of the rest of the site text. Solo and static pages use
the same colour and type family for their compact headings.</p>
<p>On the landing page, the photographer sits at left, the masthead is centred,
and navigation with Social Dock links sits at right in one visible header row.
After the masthead scrolls away, that navigation becomes a compact sticky bar.
Static pages, the blogroll and individual photo pages now carry the SAME masthead
header as the landing, so every page shares one header.</p>
HTML
    ],
    'skin-scroll-wall' => [
        'section' => 'Active Skin: SCROLL',
        'title' => 'The Photo Wall',
        'icon' => '&#x25A6;',
        'content' => <<<'HTML'
<h3>The Photo Wall</h3>
<p>The landing wall is a set of equal columns — choose <strong>Columns Across</strong>
(3–6, default 4) in the SCROLL settings. Every photograph is shown at its own shape and
never cropped, so in equal columns a tall portrait naturally covers more area than a wide
landscape — that is what makes portraits read as the big pictures.</p>
<p>The wall <strong>loads as you scroll</strong> rather than all at once.
<strong>Images Loaded Per Scroll</strong> sets how many photographs each page adds; new
pages flow into the existing columns with no gap or jump. <strong>Wall Width (% of window)</strong>
sizes the wall to the browser (any resolution), and <strong>Space Between Tiles</strong> is the gutter.</p>
HTML
    ],
];
// ===== SNAPSMACK EOF =====
