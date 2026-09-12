<?php
/**
 * SNAPSMACK.CA - Shared SEO landing-page layout (v2, rework 2026-09-12).
 *
 * These pages are where cold search traffic lands, so each one is the home
 * front door with the first line swapped for what the visitor searched:
 *   H1 matches the query  ->  the hook  ->  a saucy opener  ->  the SAME
 *   scannable strip as home (includes/strip.php)  ->  a closer  ->  the button.
 * ~300 words, not 1,350. Google wants the page to MATCH the query, not to be
 * long. Voice ON, venom OFF (Sean: "saucy and a bit weird").
 *
 * The requiring page supplies metadata plus:
 *   $landing_eyebrow  - what they searched, as a kicker
 *   $landing_h1       - matches the search
 *   $landing_opener   - HTML: 1-2 saucy paragraphs, the only per-page prose
 *   $landing_closer   - HTML: 2-3 short lines before the button
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

$nav_active = $nav_active ?? '';
$page_css = <<<'CSS'
.seo-hero { padding: clamp(56px, 9vw, 104px) 0 48px; background: var(--black); color: var(--white); border-bottom: 5px solid var(--red); }
.seo-hero .eyebrow { color: var(--red); font: 800 .78rem/1.2 Arial, sans-serif; letter-spacing: .13em; text-transform: uppercase; margin-bottom: 18px; }
.seo-hero h1 { max-width: 900px; color: var(--white); font-size: clamp(2.2rem, 5vw, 3.8rem); letter-spacing: -.045em; margin-bottom: 22px; }
.seo-hook { max-width: 860px; color: var(--white); font: 900 clamp(1.15rem, 2.4vw, 1.7rem)/1.3 Arial Black, Arial, sans-serif; text-transform: uppercase; letter-spacing: -.01em; }
.seo-hook em { color: var(--red); font-style: normal; }
.seo-sub { max-width: 760px; margin-top: 14px; color: #ccc; font-size: clamp(1.02rem, 1.8vw, 1.25rem); line-height: 1.6; }
.seo-never { max-width: 760px; margin-top: 12px; color: var(--white); font: 900 1rem/1.4 Arial Black, Arial, sans-serif; text-transform: uppercase; }
.seo-never em { color: var(--red); font-style: normal; }
.seo-actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 30px; }
.seo-actions a { display: inline-block; padding: 13px 20px; border: 2px solid var(--red); color: var(--white); font: 900 .8rem/1 Arial Black, Arial, sans-serif; letter-spacing: .04em; text-transform: uppercase; text-decoration: none; }
.seo-actions a:first-child { background: var(--red); }
.seo-actions a:hover, .seo-actions a:focus-visible { background: var(--white); color: var(--black); border-color: var(--white); }
.seo-opener { padding: 48px 0 8px; border-top: 0; }
.seo-opener .wrap { max-width: 860px; }
.seo-opener p { font-size: 1.12rem; line-height: 1.7; color: var(--dark-grey); }
.seo-opener p:first-child::first-letter { color: var(--red); font: 900 2.2em/0.9 Arial Black, Arial, sans-serif; float: left; margin: 6px 8px 0 0; }
.seo-strip { padding: 24px 0 56px; border-top: 0; }
.seo-closer { padding: 56px 0 64px; background: var(--black); color: var(--white); border-top: 8px solid var(--red); }
.seo-closer .wrap { max-width: 860px; }
.seo-closer-lines { color: var(--white); font: 900 clamp(1.3rem, 3vw, 2.2rem)/1.2 Arial Black, Arial, sans-serif; text-transform: uppercase; letter-spacing: -.02em; }
.seo-closer-lines span { display: block; }
.seo-closer-lines em { color: var(--red); font-style: normal; }
.seo-closer .seo-tail { margin-top: 18px; color: #bbb; font-size: 1.02rem; }
.seo-closer .seo-ps { margin-top: 26px; color: #888; font: italic .86rem/1.5 Georgia, serif; }
@media (max-width: 480px) {
    .seo-hero .wrap { padding-left: 18px; padding-right: 18px; }
    .seo-hero h1 { font-size: 1.9rem; overflow-wrap: anywhere; }
}
CSS;

require_once __DIR__ . '/header.php';
?>
<main>
    <header class="seo-hero">
        <div class="wrap">
            <p class="eyebrow"><?php echo htmlspecialchars($landing_eyebrow); ?></p>
            <h1><?php echo $landing_h1; ?></h1>
            <p class="seo-hook">Retro Photo Blogging. <em>Modern Technology.</em></p>
            <p class="seo-sub">Self-hosted photo blogging that brings back the gallery, the grid, and the photoblog &mdash; the ways photographers actually shared their work before the platforms ate everything.</p>
            <p class="seo-never">Your photos. Your voice. Your style. <em>Your dignity.</em></p>
            <div class="seo-actions">
                <a href="index.php#beta">Try the Beta</a>
                <a href="features.php">What it does</a>
            </div>
        </div>
    </header>

    <section class="seo-opener">
        <div class="wrap">
<?php echo $landing_opener; ?>
        </div>
    </section>

    <section class="seo-strip" aria-label="What SnapSmack is, in one screen">
        <div class="wrap">
<?php require __DIR__ . '/strip.php'; ?>
        </div>
    </section>

    <section class="seo-closer">
        <div class="wrap">
<?php echo $landing_closer; ?>
            <div class="seo-actions">
                <a href="index.php#beta">Try the Beta</a>
                <a href="brass-tacks.php">Read the honest FAQ</a>
            </div>
            <p class="seo-ps">Built by a photographer, not a company. <a href="oi.php">Ask him anything</a> &mdash; he&rsquo;s rude but he answers.</p>
        </div>
    </section>
</main>
<?php require_once __DIR__ . '/footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
