<?php
/**
 * SNAPSMACK.CA - COMING SOON
 * What is actually in the pipeline, sorted by how real it is. Replaces the
 * "Coming Up the Rear" block that used to sit on the homepage and still
 * listed things that had shipped months ago.
 * (New page, rework 2026-09-12. Statuses as of that date — keep them honest.)
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

$page_title       = 'COMING UP THE REAR! - What SnapSmack is building next';
$page_description = 'The SnapSmack pipeline, honestly sorted: what is in beta on live sites, what is built but not yet released, and what is only on the drawing board. No roadmap theatre.';
$page_og_url      = 'https://snapsmack.ca/coming-soon.php';
$nav_active       = 'coming';

$page_css = <<<'CSS'
.coming-intro { max-width: 800px; }
.tier { border-top: 8px solid var(--black); }
.tier--beta { background: var(--black); color: var(--white); border-top-color: var(--red); }
.tier--beta h2 { color: var(--red); }
.tier--beta .lede { color: #aaa; }
.tier--workshop { background: #f4f1eb; }
.tier--board { background: var(--white); }
.tier-kicker { margin-bottom: 8px; color: var(--red); font: 700 0.7rem/1 Arial, sans-serif; letter-spacing: 0.14em; text-transform: uppercase; }
.coming-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 22px; margin-top: 36px; }
.coming-item { padding-top: 18px; border-top: 3px solid var(--red); }
.coming-item .tag { display: block; margin-bottom: 9px; color: var(--red); font: 700 .68rem/1 'Courier New', monospace; letter-spacing: .08em; text-transform: uppercase; }
.coming-item h3 { margin-bottom: 9px; font-size: 1rem; }
.coming-item p { margin: 0; font-size: .88rem; line-height: 1.55; }
.tier--beta .coming-item h3 { color: var(--white); }
.tier--beta .coming-item p { color: #aaa; }
.tier--workshop .coming-item p, .tier--board .coming-item p { color: var(--mid-grey); }
.shipped { border-top: 8px solid var(--red); }
.shipped-list { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1px; margin-top: 30px; background: var(--border); border: 1px solid var(--border); }
.shipped-list a { display: block; padding: 18px 22px; background: var(--white); color: var(--black); }
.shipped-list a strong { display: block; font: 900 .88rem/1.2 Arial Black, Arial, sans-serif; text-transform: uppercase; }
.shipped-list a strong::after { content: " \2192"; color: var(--red); }
.shipped-list a span { display: block; margin-top: 5px; color: var(--mid-grey); font-size: .85rem; line-height: 1.45; }
.shipped-list a:hover { background: #fff5f3; text-decoration: none; box-shadow: inset 0 -4px 0 var(--red); }
@media (max-width: 850px) { .coming-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 700px) { .coming-grid, .shipped-list { grid-template-columns: 1fr; } }
CSS;

require_once __DIR__ . '/includes/header.php';
?>
<main>
    <section class="page-header">
        <div class="wrap">
            <p class="site-discovery-kicker">COMING UP THE REAR!</p>
            <h1>What&rsquo;s next.<br><span>Sorted by how real it is.</span></h1>
            <p class="lede coming-intro">No roadmap theatre, no investor promises, no dates. Three honest piles: running on live sites in beta, built but not released, and still on the drawing board. It ships when it works.</p>
        </div>
    </section>

    <section class="tier tier--beta">
        <div class="wrap">
            <p class="tier-kicker">Pile one</p>
            <h2>In beta on live sites</h2>
            <p class="lede">Real people are using these on real sites. Rough edges are being found the honest way: by hitting them.</p>
            <div class="coming-grid">
                <article class="coming-item">
                    <span class="tag">Skin</span>
                    <h3>GAME ON</h3>
                    <p>A living field of always-solvable sliding-puzzle photographs behind a clean grid, with a playable full-screen modal. A photoblog you can play. Yes, really.</p>
                </article>
                <article class="coming-item">
                    <span class="tag">Skin</span>
                    <h3>52 Card Pickup</h3>
                    <p>An interactive photo viewer that is neither grid nor feed. Something stranger, shuffling into place.</p>
                </article>
                <article class="coming-item">
                    <span class="tag">Desktop &middot; Editor</span>
                    <h3>SNAP SLAPPER</h3>
                    <p>The non-destructive photo editor and library. In closed beta and used daily on every photograph Sean publishes. <a href="tool-snap-slapper.php">Full tour &rarr;</a></p>
                </article>
            </div>
        </div>
    </section>

    <section class="tier tier--workshop">
        <div class="wrap">
            <p class="tier-kicker">Pile two</p>
            <h2>In the workshop</h2>
            <p class="lede">Built, working on the bench, not yet packaged and released. Close.</p>
            <div class="coming-grid">
                <article class="coming-item">
                    <span class="tag">Editor</span>
                    <h3>BIGGIE goes WYSIWYG</h3>
                    <p>The SMACKTALK long-form editor grows up: mosaics render as real photographs while you write, Enter makes a paragraph, and no shortcode gibberish in sight. A photographic essay should look like one while you&rsquo;re making it.</p>
                </article>
                <article class="coming-item">
                    <span class="tag">Skin Builder</span>
                    <h3>Oh Snap!</h3>
                    <p>Design and preview your own skin visually, then push it to a live site without hand-editing the bloody thing.</p>
                </article>
                <article class="coming-item">
                    <span class="tag">Data Freedom</span>
                    <h3>Take Your Shit With You</h3>
                    <p>A complete, understandable local copy of your photographs and portable data. Ownership includes the right to leave, and this is the door.</p>
                </article>
                <article class="coming-item">
                    <span class="tag">Desktop &middot; Moderation</span>
                    <h3>Smack Your Mouth</h3>
                    <p>Offline comment moderation and replies for a whole fleet of sites. The inbound twin of COLD SNAP: read, decide, reply, sync when you&rsquo;re back online.</p>
                </article>
                <article class="coming-item">
                    <span class="tag">Desktop &middot; Ops</span>
                    <h3>CRONOMETER</h3>
                    <p>One board showing whether every scheduled job on every site &mdash; feeds, updates, federation delivery, backups, integrity checks &mdash; is actually running. Catches a silently dead cron before it bites.</p>
                </article>
                <article class="coming-item">
                    <span class="tag">Skin</span>
                    <h3>Comrade</h3>
                    <p>A GRAMOFSMACK skin with revolving-spear propaganda energy. Needs one more piece of artwork before it&rsquo;s finished.</p>
                </article>
            </div>
        </div>
    </section>

    <section class="tier tier--board">
        <div class="wrap">
            <p class="tier-kicker">Pile three</p>
            <h2>On the drawing board</h2>
            <p class="lede">Specified, argued over, not started. Listed so you know where we&rsquo;re pointed, not so you plan around it.</p>
            <div class="coming-grid">
                <article class="coming-item">
                    <span class="tag">Importer</span>
                    <h3>BLOGGER FLOGGER</h3>
                    <p>Bring an old Blogger site &mdash; posts, pages, comments, labels, photographs &mdash; into a SMACKTALK site from a Google Takeout, without a live Blogger account. Copies the photos off Blogger instead of leaving fragile hotlinks. Sean has two old Blogger blogs to rescue; they&rsquo;re the test.</p>
                </article>
                <article class="coming-item">
                    <span class="tag">Rescue Tool</span>
                    <h3>Midnight Move</h3>
                    <p>Pull your photographs and surviving metadata out of an old or dying website before the lights go off.</p>
                </article>
                <article class="coming-item">
                    <span class="tag">Preservation Tool</span>
                    <h3>Memento Mori</h3>
                    <p>Helps friends and family preserve a photographer&rsquo;s work after they have died, so the photographs and surviving words do not quietly go dark. This one matters.</p>
                </article>
                <article class="coming-item">
                    <span class="tag">Skin</span>
                    <h3>Lookbook</h3>
                    <p>A clean, high-resolution portfolio skin with minimal chrome and nowhere for weak photographs to hide.</p>
                </article>
                <article class="coming-item">
                    <span class="tag">Skins</span>
                    <h3>Rainfall, Rehash, Booklet, Sideways</h3>
                    <p>Four more skins in various states of half-built. They&rsquo;ll get names on this page when they earn them.</p>
                </article>
                <article class="coming-item">
                    <span class="tag">Editor &middot; Parser</span>
                    <h3><code>[pullquote]</code></h3>
                    <p>Advertised in the appearance settings for months, never actually built. Small, embarrassing, on the list.</p>
                </article>
            </div>
        </div>
    </section>

    <section class="shipped">
        <div class="wrap">
            <p class="site-discovery-kicker">Off this page, because they shipped</p>
            <h2>Recently landed</h2>
            <p class="lede">Things that used to live on this list and now live on real sites. The full running log is on <a href="wotcha.php">WOTCHA!</a></p>
            <div class="shipped-list">
                <a href="tool-cold-snap.php"><strong>COLD SNAP</strong><span>Offline post composer with BIGGIE blocks and a local cold-storage library. Shipped.</span></a>
                <a href="tool-snap-slapper.php#lewks"><strong>LEWK AGAIN</strong><span>AI-assisted look builder inside SNAP SLAPPER, all five providers. Shipped.</span></a>
                <a href="features.php#network"><strong>photoblogs.fyi + the challenge network</strong><span>The directory, the reader, and the weekly #photofri challenge. Live.</span></a>
                <a href="skins.php"><strong>PARADE, TILEZ, TRUE GRIT, SLICKR&hellip;</strong><span>The skin roster keeps growing. See what&rsquo;s in production.</span></a>
            </div>
        </div>
    </section>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
