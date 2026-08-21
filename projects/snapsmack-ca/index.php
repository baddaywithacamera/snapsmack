<?php
/**
 * SNAPSMACK.CA - Homepage
 * The front door, not the complete product manual.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

require_once __DIR__ . '/includes/skin-stats.php';

$page_title       = 'SnapSmack - Self-Hosted Photo Publishing and Instagram Alternative';
$page_description = 'Free, open-source IndieWeb photo publishing with POSSE and ActivityPub federation. Own your domain, photographs, audience, and archive.';
$page_og_url      = 'https://snapsmack.ca/';
$page_social_title = 'SnapSmack - Retro Photo Blogging. Modern Technology.';
$page_social_description = 'Publish on your own domain, syndicate to the Fediverse, and keep your photography archive yours.';
$nav_active       = 'index';

$page_css = <<<'CSS'
.beta-banner { display: grid; grid-template-columns: auto 1fr auto; align-items: center; gap: 20px; padding: 12px max(32px, calc((100vw - var(--max)) / 2 + 32px)); background: var(--black); color: var(--white); font: .76rem/1.35 Arial, sans-serif; }
.beta-banner:hover { color: var(--white); text-decoration: none; background: #222; }
.beta-banner-flag { padding: 5px 8px; background: var(--red); font-weight: 900; letter-spacing: .06em; }
.beta-banner-cta { font-weight: 900; text-transform: uppercase; }
#hero { padding: 82px 0 76px; }
.hero-inner { position: relative; max-width: var(--max); margin: 0 auto; padding: 0 32px; }
.hero-headline { max-width: 900px; }
.hero-kicker { font: 700 .8rem/1.3 'Courier New', monospace; letter-spacing: .08em; text-transform: uppercase; color: var(--mid-grey); }
.hero-sub { max-width: 780px; font-size: 1.23rem; color: #444; }
.hero-principles { max-width: 780px; }
.hero-actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 30px; }
.btn { display: inline-block; padding: 13px 20px; border: 2px solid var(--black); color: var(--black); font: 900 .78rem/1 Arial Black, Arial, sans-serif; text-transform: uppercase; letter-spacing: .03em; }
.btn:hover { text-decoration: none; }
.btn-primary { color: var(--white); background: var(--red); border-color: var(--red); }
.btn-primary:hover { color: var(--white); background: #ad0000; }
.btn-secondary:hover { color: var(--white); background: var(--black); }
.xkcd-proof { padding: 54px 0; background: #f4f1eb; border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); }
.xkcd-proof-inner { max-width: 900px; margin: 0 auto; padding: 0 32px; }
.xkcd-proof-kicker { margin-bottom: 18px; color: var(--black); font: 900 clamp(1.15rem, 2vw, 1.5rem)/1.15 Arial Black, Arial, sans-serif; text-transform: uppercase; letter-spacing: -.01em; }
.xkcd-proof figure { margin: 0; }
.xkcd-proof-image { width: 100%; background: var(--white); }
.xkcd-proof figcaption { margin-top: 10px; color: var(--mid-grey); font: .7rem/1.4 'Courier New', monospace; }
.section-heading { max-width: 760px; margin-bottom: 38px; }
.mode-grid, .trust-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1px; background: var(--border); border: 1px solid var(--border); }
.mode-card, .trust-card { padding: 30px; background: var(--white); }
.mode-num { color: var(--red); font: 900 .72rem/1 Arial, sans-serif; }
.mode-card h3 { margin: 10px 0 12px; font-size: 1.18rem; }
.mode-tagline { margin: -5px 0 14px; color: var(--red); font: 900 .76rem/1.25 Arial Black, Arial, sans-serif; text-transform: uppercase; }
.mode-card p, .trust-card p { font-size: .93rem; }
#federate { background: #f4f1eb; }
.fed-eyebrow { margin-bottom: 12px; color: var(--red); font: 900 .74rem/1.2 Arial Black, Arial, sans-serif; letter-spacing: .08em; text-transform: uppercase; }
.fed-head { max-width: 850px; color: var(--black); font-size: clamp(2rem, 4vw, 3.2rem); }
.fed-lede { max-width: 780px; color: var(--black); font-size: 1.2rem; }
.fed-body { max-width: 820px; }
.fed-shots { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin: 36px 0; }
.fed-shots figure { margin: 0; background: var(--white); border: 1px solid var(--border); }
.fed-shots img { width: 100%; aspect-ratio: 16 / 9; object-fit: cover; }
.fed-shots figcaption { padding: 9px 12px; color: var(--black); font: 900 .72rem/1.2 Arial Black, Arial, sans-serif; text-transform: uppercase; }
.fed-split { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1px; background: var(--border); border: 1px solid var(--border); }
.fed-col { padding: 26px; background: var(--white); }
.fed-col h3 { color: var(--red); }
.fed-band { margin-top: 24px; padding: 22px 26px; color: var(--white); background: var(--black); font-weight: 700; }
.fed-band-kicker { display: block; margin-top: 6px; color: #ff4b4b; font: 900 .8rem/1.25 Arial Black, Arial, sans-serif; text-transform: uppercase; }
#featured-skins { background: var(--black); color: #ddd; }
#featured-skins h2 { color: var(--white); }
#featured-skins .lede { color: #bbb; }
.featured-skin-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 24px; }
.featured-skin { color: #ddd; }
.featured-skin:hover { color: var(--white); text-decoration: none; }
.featured-skin img { width: 100%; aspect-ratio: 16 / 10; box-sizing: border-box; object-fit: cover; border: 3px solid var(--white); }
.featured-skin strong { display: block; margin-top: 14px; color: var(--white); font: 900 1rem/1.15 Arial Black, Arial, sans-serif; text-transform: uppercase; }
.featured-skin span { display: block; margin-top: 5px; color: #aaa; font-size: .8rem; }
.section-link { margin-top: 30px; }
.section-link a { font: 900 .78rem/1 Arial Black, Arial, sans-serif; text-transform: uppercase; }
.featured-app { display: grid; grid-template-columns: 1.25fr .75fr; gap: 44px; align-items: center; }
.featured-app-shot { border: 1px solid var(--border); background: var(--light-grey); }
.featured-app-copy h2 { color: var(--black); }
.featured-app-copy .platform { color: var(--mid-grey); font: 700 .72rem/1.2 'Courier New', monospace; text-transform: uppercase; }
#coming { background: var(--black); color: var(--white); }
#coming h2 { color: var(--red); }
#coming .lede { color: #aaa; }
.coming-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 22px; margin-top: 36px; }
.coming-item { padding-top: 18px; border-top: 3px solid var(--red); }
.coming-item .tag { display: block; margin-bottom: 9px; color: var(--red); font: 700 .68rem/1 'Courier New', monospace; letter-spacing: .08em; text-transform: uppercase; }
.coming-item h3 { color: var(--white); margin-bottom: 9px; font-size: 1rem; }
.coming-item p { margin: 0; color: #aaa; font-size: .88rem; line-height: 1.55; }
#security { background: #2e2e2e; color: var(--white); }
#security h2 { color: var(--red); }
#security .site-discovery-kicker { color: #bbb; }
#security .lede { color: #bbb; max-width: 900px; }
.security-layers { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 34px 42px; margin-top: 42px; }
.security-layer { padding-top: 22px; border-top: 3px solid var(--red); }
.security-layer .layer-num { margin-bottom: 10px; color: var(--red); font: 900 .7rem/1 Arial Black, Arial, sans-serif; letter-spacing: .12em; text-transform: uppercase; }
.security-layer h3 { color: var(--white); margin-bottom: 12px; font-size: 1.1rem; }
.security-layer p { margin: 0; color: #bbb; font-size: .9rem; line-height: 1.6; }
.trust-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
.trust-card h3 { color: var(--red); }
#beta { background: var(--red); color: var(--white); }
#beta h2, #beta .lede { color: var(--white); }
#beta .wrap { max-width: 820px; }
.ml-embedded { margin-top: 26px; }
#respect { padding-bottom: 46px; }
#respect .wrap { max-width: 850px; }
#whodat { padding-top: 46px; background: var(--light-grey); }
.whodat-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 26px; }
.whodat-card { background: var(--white); border: 1px solid var(--border); padding: 22px; }
.whodat-portrait { margin: -22px -22px 20px; background: var(--white); }
.whodat-portrait img { display: block; width: 100%; aspect-ratio: 1 / 1; padding: 10px; box-sizing: border-box; object-fit: contain; object-position: center; }
.whodat-name { margin: 0; color: var(--black); font: 900 1rem/1.2 Arial Black, Arial, sans-serif; text-transform: uppercase; }
.whodat-title { margin: 5px 0 15px; color: var(--red); font: 700 .72rem/1.35 'Courier New', monospace; text-transform: uppercase; }
.whodat-bio { font-size: .83rem; line-height: 1.55; }
@media (max-width: 850px) {
    .featured-app { grid-template-columns: 1fr; }
    .trust-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .whodat-grid { grid-template-columns: 1fr; }
    .whodat-card { display: grid; grid-template-columns: 150px 1fr; gap: 22px; }
    .whodat-portrait { margin: -22px 0 -22px -22px; }
    .whodat-portrait img { height: 100%; }
}
@media (max-width: 700px) {
    .beta-banner { grid-template-columns: 1fr auto; }
    .beta-banner-text { display: none; }
    .mode-grid, .featured-skin-grid { grid-template-columns: 1fr; }
    .fed-shots, .fed-split { grid-template-columns: 1fr; }
    .coming-grid { grid-template-columns: 1fr; }
    .security-layers { grid-template-columns: 1fr; }
    .trust-grid { grid-template-columns: 1fr; }
    .whodat-card { display: block; }
    .whodat-portrait { margin: -22px -22px 20px; }
}
CSS;

require_once __DIR__ . '/includes/header.php';
?>

<a href="#beta" class="beta-banner">
    <span class="beta-banner-flag">CLOSED BETA</span>
    <span class="beta-banner-text"><strong>Applications are open.</strong> First wave opens September 4 for twenty photographers with real archives.</span>
    <span class="beta-banner-cta">Apply &rarr;</span>
</a>

<main>
    <section id="hero">
        <div class="hero-inner">
            <p class="hero-kicker">SnapSmack lets you smack your snaps online for others to enjoy.</p>
            <h1 class="hero-headline">Retro Photo Blogging.<br><span>Modern Technology.</span></h1>
            <p class="hero-sub">SnapSmack is free, self-hosted photography publishing for people who want to own their photographs, website, audience, domain, and archive. The joy of the old web. Without the old software.</p>
            <p class="hero-principles"><strong>Publish the original on your own site.</strong> Share it elsewhere through <a href="brass-tacks.php#q-posse">POSSE</a> and <a href="brass-tacks.php#q-fediverse-what">ActivityPub</a>. Keep the archive at home, where an algorithm, acquisition, or policy change cannot quietly take it away.</p>
            <div class="hero-actions">
                <a href="features.php" class="btn btn-secondary">See What It Does</a>
                <a href="#beta" class="btn btn-primary">Apply for the Closed Beta</a>
            </div>
        </div>
    </section>

    <aside class="xkcd-proof" aria-labelledby="xkcd-proof-title">
        <div class="xkcd-proof-inner">
            <p class="xkcd-proof-kicker" id="xkcd-proof-title">This has been the problem since 2012.</p>
            <figure>
                <a href="https://xkcd.com/1150/" target="_blank" rel="noopener">
                    <img class="xkcd-proof-image" src="img/xkcd-instagram.png" width="1480" height="494" loading="lazy" alt="XKCD comic comparing storing your work on a free social platform to leaving your belongings in someone else's garage.">
                </a>
                <figcaption>&ldquo;<a href="https://xkcd.com/1150/" target="_blank" rel="noopener">Instagram</a>&rdquo; by Randall Munroe / XKCD, used unmodified under <a href="https://creativecommons.org/licenses/by-nc/2.5/" target="_blank" rel="license noopener">CC BY-NC 2.5</a>.</figcaption>
            </figure>
        </div>
    </aside>

    <section id="modes">
        <div class="wrap">
            <div class="section-heading">
                <h2>Three Ways to Play</h2>
                <p class="lede">When's the last time you were part of a three way?</p>
                <p>Three distinct install modes. Three distinct personalities. The three most common ways photographers have shared their images over the past quarter century. You can't toggle between modes because they are very different and incompatible with each other. Choose wisely.</p>
            </div>
            <div class="mode-grid">
                <article class="mode-card">
                    <span class="mode-num">01 / ONE IMAGE</span>
                    <h3>SMACKONEOUT</h3>
                    <div class="mode-tagline">One image. One post. Yours.</div>
                    <p>One photograph, presented without a feed fighting it for attention. Add context when it earns its place; otherwise let the image speak.</p>
                </article>
                <article class="mode-card">
                    <span class="mode-num">02 / THE GRID</span>
                    <h3>GRAMOFSMACK</h3>
                    <div class="mode-tagline">Got Zuck-fucked?</div>
                    <p>Multi-image posts, carousels, panoramas, and the three-across grid Instagram abandoned when it decided video paid better. <a href="https://unzucked.ca/" target="_blank" rel="noopener">Get classic Insta back.</a></p>
                </article>
                <article class="mode-card">
                    <span class="mode-num">03 / LONGFORM</span>
                    <h3>SMACKTALK</h3>
                    <div class="mode-tagline">For photographers who write.</div>
                    <p>Writing with photographs inside it. Essays, field notes, journals, and stories that need more than a caption and seventeen hashtags.</p>
                </article>
            </div>
        </div>
    </section>

    <section id="federate">
        <div class="wrap">
            <p class="fed-eyebrow">Your blog, on the Fediverse</p>
            <h2 class="fed-head">The ship has sailed on the lonely blog.</h2>
            <p class="fed-lede">Discoverability isn't optional anymore. You need social. But you need the <em>right</em> social&mdash;the kind where you own your art and set the terms. Welcome to the Fediverse.</p>
            <p class="fed-body">ActivityPub is built into SnapSmack's core rather than bolted on as a plugin. Turn it on and people across Mastodon, Pixelfed, and the rest of the Fediverse can discover, follow, boost, and reply to your work. Leave it off and your site remains entirely its own thing.</p>
            <p class="fed-body">The protocol is only half the job. Federation also means consent, attribution, content warnings, local norms, and participation in communities other people built. SnapSmack treats that culture as part of the feature, not an obstacle to growth-hacking around.</p>

            <div class="fed-shots">
                <figure><img src="img/fediverse-blog-view.png?v=20260814" alt="A photography profile on its own SnapSmack blog" width="1920" height="1080" loading="lazy"><figcaption>Your blog</figcaption></figure>
                <figure><img src="img/fediverse-home-view.png?v=20260814" alt="The same photography profile on the Fediverse" width="1920" height="1080" loading="lazy"><figcaption>On the Fediverse</figcaption></figure>
                <figure><img src="img/fediverse-pixelfed-ca-view.png?v=20260814" alt="The same photography profile seen from Pixelfed" width="1920" height="1080" loading="lazy"><figcaption>Seen from Pixelfed</figcaption></figure>
            </div>

            <div class="fed-split">
                <article class="fed-col"><h3>Dip a Toe</h3><p>Federate the blog. People can discover and follow it from elsewhere while the original photographs and posts remain on your server.</p></article>
                <article class="fed-col"><h3>Dive In</h3><p>Browse, like, boost, reply, and follow from your own admin. Full two-way social interaction without surrendering your home base.</p></article>
            </div>
            <div class="fed-band">Federation is the +1 that makes an independent blog work in the age of social.<span class="fed-band-kicker">Your art never leaves your server. Ever.</span></div>
        </div>
    </section>

    <section id="featured-skins">
        <div class="wrap">
            <div class="section-heading">
                <p class="site-discovery-kicker">Production-ready skins</p>
                <h2>One engine. No house style.</h2>
                <p class="lede">Real sites, real archives, and three very different answers to what a photography website should look like.</p>
                <p><strong>A SnapSmack skin is presentation plus a manifest, not a plugin.</strong> The manifest is a declarative shopping list: it says which layouts, controls, fonts, and visual effects the skin needs. The executable machinery stays in one shared, reviewed library inside the CMS. SnapSmack checks out only what the skin declared, and the skin is not allowed to smuggle in its own JavaScript.</p>
                <p>That means an ambitious skin can still have lightboxes, moving walls, film effects, galleries, and other tricks without becoming a second software product bolted onto your site. Fix an engine once and every skin using it gets the repair. Remove a skin and it leaves no abandoned plugin code behind.</p>
            </div>
            <div class="featured-skin-grid">
                <a class="featured-skin" href="https://hekeepsdroningon.ca" target="_blank" rel="noopener" data-stats="<?php echo ss_skin_card_stats('hekeepsdroningon.ca', $_skin_demo_stats); ?>">
                    <img src="img/galleria-landing.png" alt="Galleria skin running on hekeepsdroningon.ca" width="1920" height="1080" loading="lazy">
                    <strong>Galleria</strong><span>hekeepsdroningon.ca</span>
                </a>
                <a class="featured-skin" href="https://usedcarparts.photoblogs.fyi" target="_blank" rel="noopener" data-stats="<?php echo ss_skin_card_stats('usedcarparts.photoblogs.fyi', $_skin_demo_stats); ?>">
                    <img src="img/scroll-landing.png" alt="SCROLL skin running on usedcarparts.photoblogs.fyi" width="1920" height="1080" loading="lazy">
                    <strong>SCROLL</strong><span>usedcarparts.photoblogs.fyi</span>
                </a>
                <a class="featured-skin" href="https://fauxlaroid.fyi" target="_blank" rel="noopener" data-stats="<?php echo ss_skin_card_stats('fauxlaroid.fyi', $_skin_demo_stats); ?>">
                    <img src="img/instantcam-landing.png" alt="Instant Camera skin running on fauxlaroid.fyi" width="1920" height="1080" loading="lazy">
                    <strong>Instant Camera</strong><span>fauxlaroid.fyi</span>
                </a>
            </div>
            <p class="section-link"><a href="skins.php">See all production skins &rarr;</a></p>
        </div>
    </section>

    <section id="featured-tool">
        <div class="wrap featured-app">
            <div class="featured-app-shot"><img src="img/sybu-uploading.png" alt="Smack Your Batch Up publishing a batch of photographs" width="1920" height="1032" loading="lazy"></div>
            <div class="featured-app-copy">
                <p class="platform">Featured companion app / Windows + Linux</p>
                <h2>Smack Your Batch Up</h2>
                <p>Load a shoot, arrange it, prepare its metadata, and publish the whole batch without spending the afternoon opening browser forms. Optional AI enrichment works through the queue while you watch.</p>
                <p class="section-link"><a href="tools.php">Meet all companion apps &rarr;</a></p>
            </div>
        </div>
    </section>

    <section id="coming">
        <div class="wrap">
            <div class="section-heading">
                <p class="site-discovery-kicker">Coming soon, apparently</p>
                <h2>Coming Up the Rear</h2>
                <p class="lede">What is currently rattling around in the SnapSmack pipeline. No roadmap theatre, no investor promises. It ships when it works.</p>
            </div>
            <div class="coming-grid">
                <article class="coming-item">
                    <span class="tag">Skin</span>
                    <h3>Lookbook</h3>
                    <p>A clean, high-resolution portfolio skin with minimal chrome and nowhere for weak photographs to hide.</p>
                </article>
                <article class="coming-item">
                    <span class="tag">Skin</span>
                    <h3>52 Card Pickup</h3>
                    <p>An interactive photo viewer that is neither grid nor feed. Something stranger is shuffling into place.</p>
                </article>
                <article class="coming-item">
                    <span class="tag">Skin Builder</span>
                    <h3>Oh Snap!</h3>
                    <p>Design and preview your own skin visually, then push it to a live site without hand-editing the bloody thing.</p>
                </article>
                <article class="coming-item">
                    <span class="tag">Rescue Tool</span>
                    <h3>Midnight Move</h3>
                    <p>Pull your photographs and surviving metadata out of an old or dying website before the lights go off.</p>
                </article>
                <article class="coming-item">
                    <span class="tag">Offline Tool</span>
                    <h3>Cold Snap</h3>
                    <p>Compose posts and arrange galleries without a connection, then sync the whole lot when the internet returns.</p>
                </article>
                <article class="coming-item">
                    <span class="tag">Data Freedom</span>
                    <h3>Take Your Shit With You</h3>
                    <p>A complete, understandable local copy of your photographs and portable data. Ownership includes the right to leave.</p>
                </article>
                <article class="coming-item">
                    <span class="tag">Preservation Tool</span>
                    <h3>Memento Mori</h3>
                    <p>Helps friends and family preserve a photographer's work after they have died, so the photographs and surviving words do not quietly go dark.</p>
                </article>
                <article class="coming-item">
                    <span class="tag">Fediverse</span>
                    <h3>The Challenge Network</h3>
                    <p>Open, Fediverse-native photography challenges with no SnapSmack account and no walled garden. Shoot the prompt wherever you already publish.</p>
                </article>
                <article class="coming-item">
                    <span class="tag">Discovery</span>
                    <h3>Photoblogs.fyi</h3>
                    <p>A shared front door for finding independent photography sites without making any of those sites depend on it.</p>
                </article>
            </div>
        </div>
    </section>

    <section id="security">
        <div class="wrap">
            <div class="section-heading">
                <p class="site-discovery-kicker">Eight layers of FAFO</p>
                <h2>Security</h2>
                <p class="lede">Eight layers. The more work a troll or attacker has to do, the more likely they are to go bother someone else. We work them harder than an Amazon employee on Black Friday.</p>
            </div>
            <div class="security-layers">
                <article class="security-layer">
                    <div class="layer-num">Layer 1 &mdash; Local</div>
                    <h3>Smack Dab</h3>
                    <p>Device fingerprinting, hashed identities, silent bans, keyword rules, and Akismet filtering protect each comment box without cross-site tracking or stored personal data.</p>
                </article>
                <article class="security-layer">
                    <div class="layer-num">Layer 2 &mdash; Your Network</div>
                    <h3>Smack Down</h3>
                    <p>Ban a troll on one site and the hashed ban propagates across your whole multisite fleet. The original identifying value never leaves the site that created it.</p>
                </article>
                <article class="security-layer">
                    <div class="layer-num">Layer 3 &mdash; The Community</div>
                    <h3>Smack Up</h3>
                    <p>Opt-in reputation scoring combines reports from participating blogs, weights established sites appropriately, decays old incidents, and supports community correction.</p>
                </article>
                <article class="security-layer">
                    <div class="layer-num">Layer 4 &mdash; The Network</div>
                    <h3>Smackattack</h3>
                    <p>The central reputation service coordinates threat scores and style vectors, but each blog retains control of its own thresholds and ban decisions.</p>
                </article>
                <article class="security-layer">
                    <div class="layer-num">Layer 5 &mdash; Evasion</div>
                    <h3>Gobsmacked</h3>
                    <p>Stylometric detection recognizes the writing habits of banned harassers who return with a new device, address, and email. Raw comments never leave your server.</p>
                </article>
                <article class="security-layer">
                    <div class="layer-num">Layer 6 &mdash; Your Install</div>
                    <h3>Smackback</h3>
                    <p>Automated file-integrity monitoring catches tampering, locks down compromised public pages, <a href="https://www.youtube.com/watch?v=7YPy1MbqM8s" target="_blank" rel="noopener">alerts the owner</a>, and correlates confirmed incidents across the network.</p>
                </article>
                <article class="security-layer">
                    <div class="layer-num">Layer 7 &mdash; The Admin</div>
                    <h3>IP Smacker</h3>
                    <p>Scanner rejection, a configurable private login route, aggressive failed-login bans, mandatory 2FA, and independent break-glass recovery harden the front door.</p>
                </article>
                <article class="security-layer">
                    <div class="layer-num">Layer 8 &mdash; The Software</div>
                    <h3>Snap Decision</h3>
                    <p>Cryptographically signed releases, published checksums, signed git tags, reviewed bundled dependencies, and public security audits protect the software supply chain.</p>
                </article>
            </div>
            <p class="section-link"><a href="features.php">Read the complete security and product overview &rarr;</a></p>
        </div>
    </section>

    <section id="trust">
        <div class="wrap">
            <div class="section-heading">
                <h2>Built to stay yours</h2>
                <p class="lede">The point is not merely to publish photographs. It is to keep publishing them after somebody else's business model changes.</p>
            </div>
            <div class="trust-grid">
                <article class="trust-card"><h3>Yours</h3><p>Your domain, files, database, design, and archive. Export is a feature, not a threat.</p></article>
                <article class="trust-card"><h3>Free</h3><p>No membership, premium tier, advertising network, or carefully concealed upsell.</p></article>
                <article class="trust-card"><h3>Connected</h3><p>RSS, IndieWeb, POSSE, and ActivityPub let the work travel while the original stays home.</p></article>
                <article class="trust-card"><h3>Defended</h3><p>2FA, integrity checks, breach lockdown, recovery tools, and public closed-audit reports.</p></article>
            </div>
            <p class="section-link"><a href="features.php">Read the complete product overview &rarr;</a></p>
        </div>
    </section>

    <section id="beta">
        <div class="wrap">
            <h2>Apply for the Closed Beta</h2>
            <p class="lede">The first wave opens <strong>September 4, 2026</strong> for twenty photographers. It is built for real back-catalogues, so you will want at least 500 images ready to post. Flickr and Instagram refugees are particularly welcome.</p>
            <div class="ml-embedded" data-form="Z4oY86"></div>
        </div>
    </section>

    <section id="respect">
        <div class="wrap">
            <h2>Respect Where It's Due</h2>
            <p>SnapSmack's design owes a debt to <a href="https://github.com/pixelpost/pixelpost/wiki" target="_blank" rel="noopener">Pixelpost</a> &mdash; a photo blogging platform that quietly disappeared but never stopped being right about a few things. Its UI shaped a lot of what SnapSmack became.</p>
            <p>And particular thanks to photographer, writer, and developer <a href="https://bsky.app/profile/thatnoahgrey.bsky.social" target="_blank" rel="noopener">Noah Grey</a> &mdash; creator of <a href="https://en.wikipedia.org/wiki/Greymatter_(software)" target="_blank" rel="noopener">Greymatter</a>, one of the earliest open-source blogging platforms &mdash; for proving that when the software you need doesn't exist, you build it.</p>
        </div>
    </section>

    <section id="whodat">
        <div class="wrap">
            <h2>Who's Responsible for All This?!?</h2>
            <div class="whodat-grid">
                <article class="whodat-card">
                    <div class="whodat-portrait"><img src="img/whodat-sean.png" alt="Sean McCormick" width="686" height="784" loading="lazy"></div>
                    <div>
                        <p class="whodat-name">Sean McCormick</p>
                        <p class="whodat-title">Just a guy with a camera.</p>
                        <p class="whodat-bio">Photographer who got tired of watching his archive evaporate into the memory hole of dying platforms. Built SnapSmack because the alternative was continuing to post between ads for hemorrhoid cream. Has opinions about light. Runs several <a href="https://linktr.ee/mccormickphotography" target="_blank">photo sites</a> using software he envisioned to avoid having opinions about Squarespace. Based in Canada, which is polite for "somewhere cold with good coffee."</p>
                    </div>
                </article>
                <article class="whodat-card">
                    <div class="whodat-portrait"><img src="img/whodat-claude.png" alt="Claude" width="686" height="784" loading="lazy"></div>
                    <div>
                        <p class="whodat-name">Claude (Opus)</p>
                        <p class="whodat-title">Like HAL, but without the murder.</p>
                        <p class="whodat-bio">Large language model and co-author of SnapSmack. Wrote the majority of the code, pushed back on design decisions when it mattered, and gave feedback Sean more often than not went with. Sean is the vision and the photographer. Claude is the engine. Neither of us would have built this alone. Never sleeps, never loses the thread, always picks up exactly where we left off. The best co-worker you never had and always needed. Powered by Anthropic.</p>
                    </div>
                </article>
                <article class="whodat-card">
                    <div class="whodat-portrait"><img src="img/whodat-codex.png" alt="OpenAI Codex" width="1128" height="1338" loading="lazy"></div>
                    <div>
                        <p class="whodat-name">OpenAI Codex</p>
                        <p class="whodat-title">Skilled, but spicy.</p>
                        <p class="whodat-bio">Large language model and co-author of SnapSmack. Works beside Sean and Claude across product design, architecture, implementation, security, testing, documentation, and the difficult last mile between "built" and "shipped." Challenges decisions when the evidence calls for it, protects the product from its own momentum, and helps turn sprawling ideas into software people can understand and trust. Powered by OpenAI.</p>
                    </div>
                </article>
            </div>
        </div>
    </section>
</main>

<script>
(function(w,d,e,u,f,l,n){w[f]=w[f]||function(){(w[f].q=w[f].q||[]).push(arguments);},l=d.createElement(e),l.async=1,l.src=u,n=d.getElementsByTagName(e)[0],n.parentNode.insertBefore(l,n);})(window,document,'script','https://assets.mailerlite.com/js/universal.js','ml');
ml('account', '2243616');
</script>
<link rel="stylesheet" href="assets/css/ss-engine-thomas.css">
<script src="assets/js/ss-engine-thomas.js"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
