<?php
/**
 * SNAPSMACK - Skin Help Topics: ONYX
 * ONYX 0.1.6
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

return [

    'onyx-overview' => [
        'section'  => 'Active Skin: ONYX',
        'title'    => 'Skin Overview',
        'icon'     => '&#x25CF;',
        'content'  => <<<'HTML'
<h3>ONYX</h3>
<p>A minimal dark editorial skin: an onyx (near-black) canvas, an Arial Black wordmark,
hairline rules, and a faint accent glow so the black never reads as a flat slab. It is built
to get out of the way &mdash; a clean shell for a photoblog or for longform reading, where the
work carries the page rather than the decoration.</p>

<h4>Colour is a palette, not a paint job</h4>
<p>Pick the accent from the <strong>SKIN PALETTE</strong> dropdown on the Skins page. The palettes
are birthstones: <strong>Garnet</strong> (red, the default &mdash; the original look),
<strong>Topaz</strong> (amber), <strong>Emerald</strong> (green), <strong>Amethyst</strong> (purple),
<strong>Aquamarine</strong> (light blue), and <strong>Sapphire</strong> (royal blue). The onyx canvas
and the geometry stay put &mdash; only the accent moves. This is how sibling blogs on the same network
tell themselves apart at a glance: give each one its own stone. Each palette is one small
<code>variant-*.css</code> file, so adding another colour later is a file plus a line, not a forked skin.</p>

<h4>What it does not have</h4>
<ul>
    <li><strong>No JavaScript of its own.</strong> The FAQ accordion is plain
    <code>&lt;details&gt;</code> markup. The one moving part &mdash; the optional launch
    countdowns &mdash; is a shared core engine the skin loads by name
    (<code>smack-countdown</code>), not script shipped inside the skin.</li>
    <li><strong>No web fonts.</strong> Arial Black, Georgia and Courier New are all system
    faces. Nothing is fetched, so nothing arrives a beat late and shifts the page.</li>
    <li><strong>No favicon baked in.</strong> Set your site's favicon in <strong>Global
    Vibe</strong>; the skin hardcodes none, so it never stamps one site's icon onto another.</li>
</ul>
HTML
    ],

    'onyx-pages' => [
        'section'  => 'Active Skin: ONYX',
        'title'    => 'The Front Page & Nav',
        'icon'     => '&#x25A0;',
        'content'  => <<<'HTML'
<h3>The front page can be a static page</h3>
<p>For a static front door, set <strong>Settings &rarr; Homepage mode &rarr; Static page</strong>
and choose your landing page there. The blog moves to <code>/blog</code> automatically and gets
a BLOG link in the nav.</p>
<p>The front page and the interior pages look different on purpose &mdash; a big centred wordmark
on the front, a smaller title on the others. That is automatic: the CMS marks the homepage
render, and the skin styles the two cases differently. There is nothing to set.</p>

<h3>The nav builds itself</h3>
<p>Every active page appears in the nav in <strong>menu order</strong>, except whichever page is
the homepage &mdash; that one is already the HOME link, so it is not listed twice. Add a page and
it shows up. Reorder them in Pages and the nav reorders.</p>

<h3>Two things worth knowing when writing pages</h3>
<ul>
    <li><strong>Do not type a full stop after a page title.</strong> The skin adds an accent one
    after every title. <code>HOW IT WORKS</code> renders as <code>HOW IT WORKS.</code> &mdash;
    typing the stop yourself gets you two.</li>
    <li><strong>Do not put a <code>&lt;div&gt;</code> directly inside a
    <code>&lt;div&gt;</code></strong> in page content. The content parser matches an opening block
    tag to its first matching close, so the inner one ends the outer one early and the rest of the
    page picks up stray paragraph tags. Use <code>&lt;section&gt;</code> for the inner block and it
    behaves.</li>
</ul>

<h3>Launch countdowns (optional)</h3>
<p>A page body can carry a live countdown in place of a static badge. It is an element with a
<code>data-until</code> timestamp in UTC (ISO-8601, e.g. <code>2026-08-27T10:00:00Z</code>) and a
<code>data-done</code> line to show once it passes. To move a date, edit that attribute in Pages
&mdash; no skin file, no recompile. The shared <code>smack-countdown</code> engine does the ticking.</p>
HTML
    ],

    'onyx-branding' => [
        'section'  => 'Active Skin: ONYX',
        'title'    => 'Logo, Avatar & Colour',
        'icon'     => '&#x25C6;',
        'content'  => <<<'HTML'
<h3>The wordmark / header logo</h3>
<p>A site title typed as plain text renders in the wordmark face, but it can only be one colour
&mdash; plain text cannot colour a single character in the middle of itself. To get a coloured
accent inside the wordmark, upload it as an image (your site's header logo). The skin's
<strong>Header Logo Height</strong> under CANVAS LAYOUT sizes it.</p>

<h3>The avatar is the Fediverse icon</h3>
<p><strong>Profile Avatar</strong> is not decoration. It becomes this site's ActivityPub actor
icon &mdash; the picture other servers show beside your posts, in follower lists and in
notifications. Without it, your account shows up blank on every other instance. It is stored per
skin, so switching skins and switching back keeps it.</p>

<h3>Colour &amp; glow</h3>
<p><strong>SKIN PALETTE</strong> (Garnet / Topaz / Emerald / Amethyst / Aquamarine / Sapphire) sets
the accent that drives every rule, border, step numeral, the footer bar and the glow behind the page.
Change the palette and the whole look moves together &mdash; there is no second place where the accent
is written down.
<strong>Glow Strength</strong> controls the soft wash behind the canvas; set it to 0 for flat
black.</p>

<h3>Favicon</h3>
<p>Set your favicon in <strong>Global Vibe</strong>, not the skin. ONYX ships none of its own, so
whatever you upload there is what every page shows.</p>
HTML
    ],

];
// ===== SNAPSMACK EOF =====
