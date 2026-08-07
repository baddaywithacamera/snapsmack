<?php
/**
 * SNAPSMACK - Skin Help Topics: CRIMSON ONYX
 * CRIMSON ONYX 0.1.0
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

return [

    'crimson-onyx-overview' => [
        'section'  => 'Active Skin: CRIMSON ONYX',
        'title'    => 'Skin Overview',
        'icon'     => '&#x25CF;',
        'content'  => <<<'HTML'
<h3>CRIMSON ONYX</h3>
<p>The photofri.day identity, as a skin. Crimson on near-black, an Arial Black wordmark,
hairline rules, and a faint red glow behind the canvas so the black never reads as a flat
slab. It is a straight port of the photofri.day static site &mdash; the colours, sizes and
spacing are the same values that site shipped with, not an interpretation of them.</p>

<h4>What it is for</h4>
<p>A challenge or campaign site: a static front door with a blog behind it. It expects a
handful of written pages doing most of the talking, which is the opposite of most skins
here.</p>

<h4>What it does not have</h4>
<ul>
    <li><strong>No JavaScript.</strong> None. The FAQ accordion is plain
    <code>&lt;details&gt;</code> markup, exactly as the original site did it.</li>
    <li><strong>No web fonts.</strong> Arial Black, Georgia and Courier New are all system
    faces. Nothing is fetched, so nothing arrives a beat late and shifts the page.</li>
    <li><strong>No variants.</strong> Other skins ship light/medium/dark. This one is an
    identity, and an identity with three interchangeable colour schemes is not an identity.
    Everything adjustable is a control instead.</li>
</ul>
HTML
    ],

    'crimson-onyx-pages' => [
        'section'  => 'Active Skin: CRIMSON ONYX',
        'title'    => 'The Front Page & Nav',
        'icon'     => '&#x25A0;',
        'content'  => <<<'HTML'
<h3>The front page is a static page</h3>
<p>This skin expects <strong>Settings &rarr; Homepage mode &rarr; Static page</strong>, with
your landing page chosen there. The blog moves to <code>/blog</code> automatically and gets
a BLOG link in the nav.</p>
<p>The front page and the interior pages look different on purpose &mdash; a big centred
wordmark on the front, a smaller title on the others. That is automatic. The CMS marks the
homepage render, and the skin styles the two cases differently. There is nothing to set.</p>

<h3>The nav builds itself</h3>
<p>Every active page appears in the nav in <strong>menu order</strong>, except whichever
page is the homepage &mdash; that one is already the HOME link, so it is not listed twice.
Add a page and it shows up. Reorder them in Pages and the nav reorders.</p>

<h3>Two things worth knowing when writing pages</h3>
<ul>
    <li><strong>Do not type a full stop after a page title.</strong> The skin adds a crimson
    one after every title. <code>HOW IT WORKS</code> renders as
    <code>HOW IT WORKS.</code> &mdash; typing the stop yourself gets you two.</li>
    <li><strong>Do not put a <code>&lt;div&gt;</code> directly inside a
    <code>&lt;div&gt;</code></strong> in page content. The content parser matches an opening
    block tag to its first matching close, so the inner one ends the outer one early and the
    rest of the page picks up stray paragraph tags. Use <code>&lt;section&gt;</code> for the
    inner block and it behaves.</li>
</ul>

<h3>Ready-made page markup</h3>
<p>The four photofri.day pages, converted to paste straight into Pages, live in the repo at
<code>projects/photofri-day/cms-pages/</code> with a README covering titles, slugs and menu
order.</p>
HTML
    ],

    'crimson-onyx-branding' => [
        'section'  => 'Active Skin: CRIMSON ONYX',
        'title'    => 'Logo, Avatar & Colour',
        'icon'     => '&#x25C6;',
        'content'  => <<<'HTML'
<h3>The wordmark</h3>
<p>The skin ships the photofri.day artwork in its own <code>img/</code> folder. Upload
<code>logo-pf-white.png</code> as the header logo to get the original wordmark with its
crimson full stop &mdash; a site title typed as plain text cannot colour a character in the
middle of itself, so the text version is one colour. <strong>Header Logo Height</strong>
under CANVAS LAYOUT sizes it.</p>

<h3>The avatar is the Fediverse icon</h3>
<p><strong>Profile Avatar</strong> is not decoration. It becomes this site's ActivityPub
actor icon &mdash; the picture other servers show beside your posts, in follower lists and
in notifications. Without it, your account shows up blank on every other instance.
<code>logo-pf-black.png</code> ships with the skin and reads well small.</p>
<p>It is stored per skin, so switching skins and switching back keeps it.</p>

<h3>One control moves the colour</h3>
<p><strong>Accent</strong> under COLOURS drives every rule, border, step numeral, the footer
bar and the glow behind the page. Change that one value and the whole identity moves
together &mdash; there is no second place where the red is written down.</p>
<p><strong>Glow Strength</strong> controls the soft wash behind the canvas. Set it to 0 for
flat black.</p>
HTML
    ],

];
// ===== SNAPSMACK EOF =====
