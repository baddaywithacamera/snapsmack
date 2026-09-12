<?php
/**
 * SNAPSMACK.CA - THE GOODS (features)
 * ONE catalogue, organised by what the thing does for a photographer, in the
 * same order as the home-page strip. The old page was two overlapping
 * catalogues (six lists + twenty cards); this is the merge.
 * (Rebuilt 2026-09-12.)
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

$page_title       = 'THE GOODS! - SnapSmack Features';
$page_description = 'Everything SnapSmack does: three publishing modes, a real archive, import and export, the photographers\' network, skins, desktop tools, security, AI assistance, and what it takes to run it.';
$page_og_url      = 'https://snapsmack.ca/features.php';
$nav_active       = 'goods';

$page_css = <<<'CSS'
.goods-intro { max-width: 760px; }
.feat { scroll-margin-top: 80px; }
.feat + .feat { border-top: 1px solid var(--border); }
.feat.feat--dark { background: var(--black); color: #ddd; border-top: 8px solid var(--red); }
.feat--dark h2 { color: var(--white); }
.feat--dark .lede { color: #bbb; }
.feat--dark li { color: #ccc; }
.feat--dark strong { color: var(--white); }
.feat.feat--sand { background: #f4f1eb; border-top: 8px solid var(--black); }
.feat-head { max-width: 780px; margin-bottom: 30px; }
.feat-head .site-discovery-kicker { margin-bottom: 6px; }
.feat-head h2 { margin-bottom: 12px; }
.feat-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1px; background: var(--border); border: 1px solid var(--border); }
.feat--dark .feat-grid { background: #383838; border-color: #383838; }
.feat-card { padding: 24px; background: var(--white); }
.feat--dark .feat-card { background: #171717; }
.feat-card h3 { margin-bottom: 8px; color: var(--red); font-size: .95rem; }
.feat-card p { margin: 0; font-size: .88rem; line-height: 1.55; color: var(--mid-grey); }
.feat--dark .feat-card p { color: #bbb; }
.feat-card--wide { grid-column: 1 / -1; }
.feat-list { max-width: 820px; margin: 0 0 0 1.2em; columns: 2; column-gap: 40px; }
.feat-list li { margin-bottom: .6em; break-inside: avoid; font-size: .95rem; }
.mode-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1px; background: var(--border); border: 1px solid var(--border); }
.mode-card { padding: 30px; background: var(--white); }
.mode-num { color: var(--red); font: 900 .72rem/1 Arial, sans-serif; }
.mode-card h3 { margin: 10px 0 12px; font-size: 1.18rem; }
.mode-tagline { margin: -5px 0 14px; color: var(--red); font: 900 .76rem/1.25 Arial Black, Arial, sans-serif; text-transform: uppercase; }
.mode-card p { font-size: .93rem; }
.fed-shots { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin: 30px 0; }
.fed-shots figure { margin: 0; background: var(--white); border: 1px solid var(--border); }
.fed-shots img { width: 100%; aspect-ratio: 16 / 9; object-fit: cover; }
.fed-shots figcaption { padding: 9px 12px; color: var(--black); font: 900 .72rem/1.2 Arial Black, Arial, sans-serif; text-transform: uppercase; }
.fed-band { margin-top: 24px; padding: 22px 26px; color: var(--white); background: var(--black); font-weight: 700; }
.fed-band-kicker { display: block; margin-top: 6px; color: #ff4b4b; font: 900 .8rem/1.25 Arial Black, Arial, sans-serif; text-transform: uppercase; }
.feat-link { margin-top: 26px; }
.feat-link a { font: 900 .78rem/1 Arial Black, Arial, sans-serif; text-transform: uppercase; }
.free-band { padding: 26px 30px; background: var(--red); color: var(--white); font: 900 clamp(1.1rem, 2.2vw, 1.5rem)/1.35 Arial Black, Arial, sans-serif; text-transform: uppercase; }
.free-band a { color: var(--white); text-decoration: underline; }
@media (max-width: 900px) { .feat-grid, .mode-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 700px) { .feat-grid, .mode-grid, .fed-shots { grid-template-columns: 1fr; } .feat-list { columns: 1; } }
CSS;

require_once __DIR__ . '/includes/header.php';
?>

<main>
    <section class="page-header">
        <div class="wrap">
            <p class="site-discovery-kicker">THE GOODS!</p>
            <h1>Everything it does.<br><span>In the order you&rsquo;d ask.</span></h1>
            <p class="lede goods-intro">SnapSmack is a self-hosted photography publishing system: the website, the archive, the network, the security, and the desktop tools to get your work in and back out again. This page is the whole list, once.</p>
        </div>
    </section>

    <div class="wrap goods-nav-wrap">
        <nav class="goods-nav" aria-label="The Goods">
            <a class="active" href="features.php"><strong>THE GOODS!</strong><span>What SnapSmack actually does.</span></a>
            <a href="skins.php"><strong>GLAD RAGS!</strong><span>Skins: different sites, same dependable engine.</span></a>
            <a href="tools.php"><strong>BOX O' TRICKS!</strong><span>The free desktop suite.</span></a>
        </nav>
    </div>

    <section class="feat" id="modes">
        <div class="wrap">
            <div class="feat-head">
                <p class="site-discovery-kicker">Three ways to play</p>
                <h2>Pick the way you always shared.</h2>
                <p class="lede">Three install modes, three personalities: the three most common ways photographers have shared images over the past quarter century. You choose one at install and you can&rsquo;t toggle between them, because they are very different animals. Choose wisely.</p>
            </div>
            <div class="mode-grid">
                <article class="mode-card">
                    <span class="mode-num">01 / ONE IMAGE</span>
                    <h3>SMACKONEOUT</h3>
                    <div class="mode-tagline">One image. One post. Yours.</div>
                    <p>The classic photoblog. One photograph, presented without a feed fighting it for attention. Add context when it earns its place; otherwise let the image speak. Chronological navigation, drafts, scheduling, EXIF, tags, categories, albums, collections, and downloads.</p>
                </article>
                <article class="mode-card">
                    <span class="mode-num">02 / THE GRID</span>
                    <h3>GRAMOFSMACK</h3>
                    <div class="mode-tagline">Got Zuck-fucked?</div>
                    <p>The three-across grid Instagram abandoned when it decided video paid better. Multi-image posts, carousels, panorama rows, cover selection, ordered grids, and phone publishing through an installable PWA or <a href="https://github.com/Daniebeler/pixelix" target="_blank" rel="noopener">Pixelix</a>. <a href="https://unzucked.ca/" target="_blank" rel="noopener">Get classic Insta back.</a></p>
                </article>
                <article class="mode-card">
                    <span class="mode-num">03 / LONGFORM</span>
                    <h3>SMACKTALK</h3>
                    <div class="mode-tagline">For photographers who write.</div>
                    <p>Writing with photographs inside it. Essays, field notes, journals, and stories that need more than a caption and seventeen hashtags. Headings, inline gallery images, covers, captions, and justified MOSAIC panels woven through the text.</p>
                </article>
            </div>
        </div>
    </section>

    <section class="feat" id="yours">
        <div class="wrap">
            <div class="feat-head">
                <p class="site-discovery-kicker">Yours</p>
                <h2>Your domain. Your files. Your database.</h2>
                <p class="lede">A SnapSmack site is a folder of PHP on hosting you pay for, with a database you can open and photographs you can copy. There is no account with us. There is nothing we can switch off.</p>
            </div>
            <div class="feat-grid">
                <article class="feat-card"><h3>A real archive</h3><p>Browse and reuse media, organise in bulk, arrange feeds and galleries, preserve original dates and metadata. The files and the database stay under your control.</p></article>
                <article class="feat-card"><h3>Light Table</h3><p>A full-screen browser workbench for sorting photographs into albums, categories, and collections by drag and drop.</p></article>
                <article class="feat-card"><h3>Media gallery + web-copy editor</h3><p>Visual archive browsing, bulk organisation, reusable image picking, plus non-destructive crop, rotate, brightness, contrast, and sharpening on the web copy. The original is never touched.</p></article>
                <article class="feat-card"><h3>Albums, collections, pages</h3><p>Date archives, curated albums and collections, static pages, blogrolls, shortcodes, slideshows, RSS, and downloadable originals.</p></article>
                <article class="feat-card"><h3>Chronological. Always.</h3><p>No algorithm decides who gets to see what. You publish it, it appears, in the order you chose.</p></article>
                <article class="feat-card"><h3>Stats without the creep</h3><p>Cookie-free visits, per-image views, referrers, bot filtering, local country resolution, feed engagement, and fleet-wide rollups. Nobody but you sees any of it.</p></article>
            </div>
        </div>
    </section>

    <section class="feat feat--sand" id="get-in-get-out">
        <div class="wrap">
            <div class="feat-head">
                <p class="site-discovery-kicker">Get in, get out</p>
                <h2>The door opens both ways.</h2>
                <p class="lede">Your old sites come with you, and everything you make here can leave again. Ownership includes the right to stop using SnapSmack.</p>
            </div>
            <div class="feat-grid">
                <article class="feat-card"><h3>Instagram in</h3><p><a href="tool-unzucker.php">THE UNZUCKER</a> brings an Instagram export home with original dates, captions, hashtags, carousels, and grid order intact.</p></article>
                <article class="feat-card"><h3>Flickr in</h3><p><a href="tool-flkr-fckr.php">FLKR FCKR</a> imports a Flickr archive with photographs, titles, descriptions, tags, upload dates, views, comments, and likes preserved.</p></article>
                <article class="feat-card"><h3>WordPress in</h3><p><a href="tool-smackpress.php">SMACKPRESS</a> moves a WordPress photo blog into SMACKTALK one post at a time, with a review step for each.</p></article>
                <article class="feat-card"><h3>Blogger in</h3><p>BLOGGER FLOGGER brings an old Blogger site &mdash; posts, pages, comments, photos &mdash; into SMACKTALK from a Google Takeout. <a href="coming-soon.php">On the drawing board.</a></p></article>
                <article class="feat-card"><h3>Everything out</h3><p>Export the whole site as a complete, understandable local copy of your photographs and portable data. No hostage situation.</p></article>
                <article class="feat-card"><h3>Dates survive</h3><p>Imports keep the date the photograph was actually taken and posted, so a fifteen-year archive still reads as fifteen years, not &ldquo;imported last Tuesday.&rdquo;</p></article>
            </div>
            <p class="feat-link"><a href="tools.php#get-in">Meet the importers &rarr;</a></p>
        </div>
    </section>

    <section class="feat feat--dark" id="network">
        <div class="wrap">
            <div class="feat-head">
                <p class="site-discovery-kicker">Not alone</p>
                <h2>The ship has sailed on the lonely blog.</h2>
                <p class="lede">Discoverability isn&rsquo;t optional anymore. You need social &mdash; the <em>right</em> social, where you own your art and set the terms. So SnapSmack comes with a small, quiet network of photographers built in, and the wider fediverse one switch away.</p>
            </div>
            <div class="feat-grid">
                <article class="feat-card"><h3>photoblogs.fyi</h3><p>A shared front door for finding independent photography sites, and a reader for following them, without making any of those sites depend on it.</p></article>
                <article class="feat-card"><h3>PHOTOFRI.DAY</h3><p>A weekly one-word photo challenge. Shoot the prompt wherever you already publish, tag it, and it shows up with everyone else&rsquo;s.</p></article>
                <article class="feat-card"><h3>Readers follow you anywhere</h3><p>People on Mastodon, Pixelfed, and the rest of the fediverse can discover, follow, like, boost, and reply to your work while the original stays on your site. Turn it off and your site is entirely its own thing.</p></article>
                <article class="feat-card"><h3>Local community</h3><p>Accounts, comments, reactions, follows, direct messages, moderation queues, keyword controls, and anti-spam filtering on your own site.</p></article>
                <article class="feat-card"><h3>Cross-posting</h3><p>Publish once on your domain and syndicate out. RSS and IndieWeb links included. The canonical copy is always yours.</p></article>
                <article class="feat-card"><h3>Manners included</h3><p>Federation also means consent, attribution, content warnings, and local norms. SnapSmack treats that culture as part of the feature, not an obstacle to growth-hack around.</p></article>
            </div>
            <div class="fed-shots">
                <figure><img src="img/fediverse-blog-view.png?v=20260814" alt="A photography profile on its own SnapSmack blog" width="1920" height="1080" loading="lazy"><figcaption>Your blog</figcaption></figure>
                <figure><img src="img/fediverse-home-view.png?v=20260814" alt="The same photography profile on the Fediverse" width="1920" height="1080" loading="lazy"><figcaption>On the Fediverse</figcaption></figure>
                <figure><img src="img/fediverse-pixelfed-ca-view.png?v=20260814" alt="The same photography profile seen from Pixelfed" width="1920" height="1080" loading="lazy"><figcaption>Seen from Pixelfed</figcaption></figure>
            </div>
            <div class="fed-band">Federation is the +1 that makes an independent blog work in the age of social.<span class="fed-band-kicker">Your art never leaves your server. Ever.</span></div>
        </div>
    </section>

    <section class="feat" id="skins">
        <div class="wrap">
            <div class="feat-head">
                <p class="site-discovery-kicker">Skins</p>
                <h2>One engine. No house style.</h2>
                <p class="lede">A SnapSmack skin is presentation plus a manifest, not a plugin. The manifest lists the layouts, controls, fonts, and effects the skin needs; the CMS supplies the reviewed engines. Fix an engine once and every skin gets the repair. Remove a skin and it leaves nothing behind.</p>
            </div>
            <ul class="feat-list">
                <li>Production skins with their own layout and appearance controls.</li>
                <li>Colour, type, spacing, texture, and layout options without editing CSS.</li>
                <li>Light and dark palettes, scoped settings, live calibration.</li>
                <li>Skins cannot smuggle in their own JavaScript. Ever.</li>
                <li>Lightboxes, moving walls, film effects, puzzles, and galleries all come from shared engines.</li>
                <li>Skins ship and update through a registry, separately from the CMS.</li>
            </ul>
            <p class="feat-link"><a href="skins.php">See every skin, with live sites &rarr;</a></p>
        </div>
    </section>

    <section class="feat feat--sand" id="tools">
        <div class="wrap">
            <div class="feat-head">
                <p class="site-discovery-kicker">Desktop suite</p>
                <h2>The website is home. The desktop does the heavy lifting.</h2>
                <p class="lede">Free Windows and Linux tools handle editing, batch publishing, library sorting, migration, backup, auditing, and recovery on your own computer &mdash; without turning your archive into cloud bait.</p>
            </div>
            <div class="feat-grid">
                <article class="feat-card"><h3>Edit</h3><p><a href="tool-snap-slapper.php">SNAP SLAPPER</a>: a real non-destructive photo editor and library, with LEWKS and LEWK AGAIN.</p></article>
                <article class="feat-card"><h3>Compose offline</h3><p><a href="tool-cold-snap.php">COLD SNAP</a>: write posts and build BIGGIE photo essays with no connection, sync when it returns.</p></article>
                <article class="feat-card"><h3>Publish in bulk</h3><p><a href="tool-sybu.php">SMACK YOUR BATCH UP</a>: load a shoot, order it, tag it, publish the lot.</p></article>
                <article class="feat-card"><h3>Sort the archive</h3><p><a href="tool-gyss.php">GET YOUR SHIT SORTED</a>: an offline sorter for a whole blog &mdash; titles, captions, alt text, order, carousels.</p></article>
                <article class="feat-card"><h3>Back up, recover</h3><p><a href="tool-suyb.php">SMACK UP YOUR BACKUP</a>: complete recovery archives, offsite, audited, resumable.</p></article>
                <article class="feat-card"><h3>Run the fleet</h3><p><a href="tool-snap-hq.php">SNAP HQ</a>: launch the suite, discover your sites, share protected profiles and prompts between tools.</p></article>
            </div>
            <p class="feat-link"><a href="tools.php">The whole box o&rsquo; tricks &rarr;</a></p>
        </div>
    </section>

    <section class="feat feat--dark" id="security">
        <div class="wrap">
            <div class="feat-head">
                <p class="site-discovery-kicker">Locked down</p>
                <h2>Security that admits the internet exists.</h2>
                <p class="lede">Eight independent layers, from the comment box to the software supply chain. Public audits. Same-day disclosure. And no remote control &mdash; nobody but you can touch your site.</p>
            </div>
            <div class="feat-grid">
                <article class="feat-card"><h3>SMACKBACK</h3><p>File-integrity monitoring. A file changes that nobody signed, the site locks itself down and asks its owner to decide.</p></article>
                <article class="feat-card"><h3>Network Alert</h3><p>Several sites tampered in a short window? Every owner is told the same hour: update, back up, rotate keys.</p></article>
                <article class="feat-card"><h3>Break the Glass</h3><p>A signed, one-use recovery card for total account lockout. Yours, printed, offline.</p></article>
                <article class="feat-card"><h3>Mandatory 2FA + IP SMACKER</h3><p>Private login route, scanner rejection, aggressive failed-login bans, and step-up authentication for anything that matters.</p></article>
                <article class="feat-card"><h3>Trolls, handled in layers</h3><p>Fingerprint bans, fleet-wide propagation, community reputation, and stylometric detection of the ones who come back.</p></article>
                <article class="feat-card"><h3>Signed everything</h3><p>Signed releases, published checksums, signed git tags, reviewed dependencies, cryptographically verified updates with rollback.</p></article>
            </div>
            <p class="feat-link"><a href="security.php">How it works, and what happens when it goes wrong &rarr;</a></p>
        </div>
    </section>

    <section class="feat" id="ai">
        <div class="wrap">
            <div class="feat-head">
                <p class="site-discovery-kicker">AI inside</p>
                <h2>The intern, not the boss.</h2>
                <p class="lede">Optional AI assistance, disclosed, under your control, and never required. Five providers &mdash; Claude, ChatGPT, Gemini, Kimi, Deepseek &mdash; with your own key, so the bill and the data relationship are yours.</p>
            </div>
            <div class="feat-grid">
                <article class="feat-card"><h3>Captions, alt text, tags</h3><p>Look at the photograph, draft the words. You edit, you approve, you publish. Filename and your per-site prompt go along so it sounds like you, not like a press release.</p></article>
                <article class="feat-card"><h3>LEWK AGAIN</h3><p>Describe the look you want; the editor builds the LEWK as adjustable steps you can see and change. No more buying action packs from YouTube.</p></article>
                <article class="feat-card"><h3>Skin designer</h3><p>Oh Snap! lets you describe and preview a skin visually, then push it to a live site.</p></article>
                <article class="feat-card feat-card--wide"><h3>One prompt per site</h3><p>Tell your site once how you want to sound and every tool that writes for it &mdash; batch publisher, sorter, composer, CMS &mdash; uses the same voice.</p></article>
            </div>
        </div>
    </section>

    <section class="feat feat--sand" id="run-it">
        <div class="wrap">
            <div class="feat-head">
                <p class="site-discovery-kicker">Cheap to run</p>
                <h2>Shared hosting. Upload. Go.</h2>
                <p class="lede">PHP and MySQL on ordinary shared hosting. No Docker, no Node, no build step, no monthly platform bill. The heavy work happens on your own computer, not your hosting account.</p>
            </div>
            <div class="feat-grid">
                <article class="feat-card"><h3>Guided install</h3><p>Installer, canonical schema sync, maintenance mode, and contextual built-in help on every admin page.</p></article>
                <article class="feat-card"><h3>Signed updates</h3><p>Cryptographically verified updates with rollback. Skins update separately through the registry.</p></article>
                <article class="feat-card"><h3>Several sites, one desk</h3><p>Hub-and-spoke multisite: SSO drill-through, aggregated stats and comments, cross-posting, fleet backups, fleet updates, automatic <strong>My Blogs</strong> blogrolls.</p></article>
                <article class="feat-card"><h3>Support inside the admin</h3><p>The support forum lives behind your authenticated login, not at a public URL for bots and drive-by spam.</p></article>
                <article class="feat-card"><h3>SEO + crawler policy</h3><p>Sitemaps, Open Graph, structured metadata, IndieWeb links, configurable robots and AI-training policy, <code>llms.txt</code>, <code>security.txt</code>.</p></article>
                <article class="feat-card"><h3>Small footprint</h3><p>Pages fold and page. Images are sized once, on your machine. A twenty-year archive doesn&rsquo;t need a twenty-dollar server.</p></article>
            </div>
            <p class="feat-link"><a href="hows-yer-father.php">The architecture and the family tree &rarr;</a></p>
        </div>
    </section>

    <section class="feat" id="free">
        <div class="wrap">
            <div class="free-band">Free means free. No subscription, premium tier, advertising network, or hostage situation. The CMS, the skins, and the desktop suite. <a href="hairy-muff.php">Read the licence.</a></div>
        </div>
    </section>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
