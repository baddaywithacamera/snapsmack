<?php
/**
 * SNAPSMACK.CA - Homepage
 * A FRONT DOOR, not a flag: clarity at the door, personality the second they
 * step in. Says what it is and why in ten seconds; the inside pages explain.
 * (Rework 2026-09-12 — spec: _spec/SPEC-snapsmack-ca-rework-v0_2.docx)
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

require_once __DIR__ . '/includes/skin-stats.php';

$page_title       = 'SnapSmack - Self-Hosted Photo Publishing and Instagram Alternative';
$page_description = 'Free, self-hosted photo blogging that brings back the gallery, the grid, and the photoblog. Your photos, your domain, your archive. Your work is never our content.';
$page_og_url      = 'https://snapsmack.ca/';
$page_social_title = 'SnapSmack - Your photos. Your voice. Your style. Your dignity.';
$page_social_description = 'Self-hosted photo blogging that brings back the gallery, the grid, and the photoblog. Your work is never our content.';
$nav_active       = 'index';

$page_css = <<<'CSS'
.beta-banner { display: grid; grid-template-columns: auto 1fr auto; align-items: center; gap: 20px; padding: 12px max(32px, calc((100vw - var(--max)) / 2 + 32px)); background: var(--black); color: var(--white); font: .76rem/1.35 Arial, sans-serif; }
.beta-banner:hover { color: var(--white); text-decoration: none; background: #222; }
.beta-banner-flag { padding: 5px 8px; background: var(--red); font-weight: 900; letter-spacing: .06em; }
.beta-banner-cta { font-weight: 900; text-transform: uppercase; }

/* --- THE DOOR: one thing wins --- */
#door { padding: 88px 0 72px; }
.door-inner { max-width: var(--max); margin: 0 auto; padding: 0 32px; }
.door-hook { max-width: 980px; font-size: clamp(1.9rem, 4vw, 3.3rem); }
.door-sub { max-width: 800px; margin-top: 8px; font-size: clamp(1.15rem, 2vw, 1.4rem); line-height: 1.5; color: #333; }
.door-never { max-width: 800px; margin-top: 14px; color: var(--black); font: 900 clamp(1.05rem, 1.8vw, 1.3rem)/1.35 Arial Black, Arial, sans-serif; text-transform: uppercase; letter-spacing: -.01em; }
.door-never em { color: var(--red); font-style: italic; text-transform: none; font-family: Georgia, serif; font-weight: 400; }
.door-actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 34px; }
.btn { display: inline-block; padding: 14px 22px; border: 2px solid var(--black); color: var(--black); font: 900 .8rem/1 Arial Black, Arial, sans-serif; text-transform: uppercase; letter-spacing: .03em; }
.btn:hover { text-decoration: none; }
.btn-primary { color: var(--white); background: var(--red); border-color: var(--red); }
.btn-primary:hover { color: var(--white); background: #ad0000; }
.btn-secondary:hover { color: var(--white); background: var(--black); }


/* --- SKINS BAND --- */
#featured-skins { background: var(--black); color: #ddd; border-top: 8px solid var(--red); }
#featured-skins h2 { color: var(--white); }
#featured-skins .lede { color: #bbb; }
.section-heading { max-width: 760px; margin-bottom: 38px; }
.featured-skin-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 20px; }
.featured-skin { color: #ddd; }
.featured-skin:hover { color: var(--white); text-decoration: none; }
.featured-skin img { width: 100%; aspect-ratio: 16 / 10; box-sizing: border-box; object-fit: cover; border: 3px solid var(--white); }
.featured-skin strong { display: block; margin-top: 12px; color: var(--white); font: 900 .95rem/1.15 Arial Black, Arial, sans-serif; text-transform: uppercase; }
.featured-skin span { display: block; margin-top: 4px; color: #aaa; font-size: .78rem; }
.section-link { margin-top: 30px; }
.section-link a { font: 900 .78rem/1 Arial Black, Arial, sans-serif; text-transform: uppercase; }

/* --- BELOW THE FOLD: the flag lives here --- */
.xkcd-proof { padding: 54px 0; background: #f4f1eb; border-top: 1px solid var(--border); border-bottom: 1px solid var(--border); }
.xkcd-proof-inner { max-width: 900px; margin: 0 auto; padding: 0 32px; }
.xkcd-proof-kicker { margin-bottom: 18px; color: var(--black); font: 900 clamp(1.15rem, 2vw, 1.5rem)/1.15 Arial Black, Arial, sans-serif; text-transform: uppercase; letter-spacing: -.01em; }
.xkcd-proof figure { margin: 0; }
.xkcd-proof-image { width: 100%; background: var(--white); }
.xkcd-proof figcaption { margin-top: 10px; color: var(--mid-grey); font: .7rem/1.4 'Courier New', monospace; }

#custodian { background: var(--white); border-top: 8px solid var(--black); }
.custodian-inner { max-width: 820px; }
.custodian-quote { margin: 0 0 26px; padding-left: 26px; border-left: 6px solid var(--red); color: var(--black); font-size: clamp(1.25rem, 2.4vw, 1.7rem); line-height: 1.45; font-style: italic; }
.custodian-quote cite { display: block; margin-top: 12px; color: var(--mid-grey); font: 700 .72rem/1.3 'Courier New', monospace; font-style: normal; letter-spacing: .08em; text-transform: uppercase; }
.custodian-points { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1px; margin-top: 34px; background: var(--border); border: 1px solid var(--border); }
.custodian-points article { padding: 24px; background: var(--white); }
.custodian-points h3 { color: var(--red); }
.custodian-points p { margin: 0; font-size: .92rem; line-height: 1.55; }

#respect, #whodat { border-top: 8px solid var(--black); }
#whodat { padding-top: 46px; background: var(--light-grey); }
.whodat-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 26px; }
.whodat-card { background: var(--white); border: 1px solid var(--border); padding: 22px; }
.whodat-portrait { margin: -22px -22px 20px; background: var(--white); }
.whodat-portrait img { display: block; width: 100%; aspect-ratio: 1 / 1; padding: 10px; box-sizing: border-box; object-fit: contain; object-position: center; }
.whodat-name { margin: 0; color: var(--black); font: 900 1rem/1.2 Arial Black, Arial, sans-serif; text-transform: uppercase; }
.whodat-title { margin: 5px 0 15px; color: var(--red); font: 700 .72rem/1.35 'Courier New', monospace; text-transform: uppercase; }
.whodat-bio { font-size: .83rem; line-height: 1.55; }
.whodat-honest { max-width: 820px; margin-bottom: 30px; }

#beta { background: var(--red); color: var(--white); border-top: 8px solid var(--black); }
#beta h2, #beta .lede { color: var(--white); }
#beta .wrap { max-width: 820px; }

@media (max-width: 850px) {
    .featured-skin-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .custodian-points { grid-template-columns: 1fr; }
    .whodat-grid { grid-template-columns: 1fr; }
    .whodat-card { display: grid; grid-template-columns: 150px 1fr; gap: 22px; }
    .whodat-portrait { margin: -22px 0 -22px -22px; }
    .whodat-portrait img { height: 100%; }
}
@media (max-width: 700px) {
    .beta-banner { grid-template-columns: 1fr auto; }
    .beta-banner-text { display: none; }
    #door { padding: 56px 0 44px; }
    .featured-skin-grid { grid-template-columns: 1fr; }
    .whodat-card { display: block; }
    .whodat-portrait { margin: -22px -22px 20px; }
}
CSS;

require_once __DIR__ . '/includes/header.php';
?>

<a href="#beta" class="beta-banner">
    <span class="beta-banner-flag">CLOSED BETA</span>
    <span class="beta-banner-text"><strong>Applications are open.</strong> First wave opens November 4 for twenty photographers with real archives.</span>
    <span class="beta-banner-cta">Apply &rarr;</span>
</a>

<main>
    <section id="door">
        <div class="door-inner">
            <h1 class="door-hook">Your photos. Your voice.<br>Your style. <span>Your dignity.</span></h1>
            <p class="door-sub">Self-hosted photo blogging that brings back the gallery, the grid, and the photoblog &mdash; the ways photographers actually shared their work before the platforms ate everything.</p>
            <p class="door-never">Your work is never <em>&ldquo;our content.&rdquo;</em></p>
            <div class="door-actions">
                <a href="#beta" class="btn btn-primary">Try the Beta</a>
                <a href="features.php" class="btn btn-secondary">What it does</a>
            </div>
        </div>
    </section>

    <section id="strip" aria-label="What SnapSmack is, in one screen">
        <div class="wrap">
<?php require __DIR__ . '/includes/strip.php'; ?>
        </div>
    </section>

    <section id="featured-skins">
        <div class="wrap">
            <div class="section-heading">
                <p class="site-discovery-kicker">Real sites, running today</p>
                <h2>One engine. No house style.</h2>
                <p class="lede">Four live SnapSmack sites, four completely different answers to what a photography website should look like. Hover for the numbers.</p>
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
                <a class="featured-skin" href="skins.php">
                    <img src="img/gameon-landing.png" alt="GAME ON skin: a field of sliding-puzzle photographs" width="1920" height="1080" loading="lazy">
                    <strong>GAME ON</strong><span>a sliding-puzzle photoblog. Yes, really.</span>
                </a>
            </div>
            <p class="section-link"><a href="skins.php">See every skin &rarr;</a></p>
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

    <section id="custodian">
        <div class="wrap">
            <div class="custodian-inner">
                <p class="site-discovery-kicker">Why it&rsquo;s built this way</p>
                <h2>Custodian, not landlord.</h2>
                <blockquote class="custodian-quote">It&rsquo;s a blessing to be trusted with someone&rsquo;s most beautiful possessions. You honour them back. You don&rsquo;t monetize them.<cite>&mdash; Sean McCormick, who built this</cite></blockquote>
                <p>That one sentence is the whole design. Every rule below follows from it, and none of them are negotiable.</p>
            </div>
            <div class="custodian-points">
                <article><h3>Your site is yours</h3><p>Nobody at SnapSmack can lock you out, switch you off, or reach into your site. Not for a bug, not for a breach, not for anything. We have no right to, so we built it so we can&rsquo;t.</p></article>
                <article><h3>You know what we know</h3><p>Security findings, breaches, and audit results are published the day we have them. <a href="buzzers.php">The audits are public</a>. <a href="ding-dong-bell.php">So are the failures.</a></p></article>
                <article><h3>The door opens both ways</h3><p>Everything you put in comes back out in a form other software can read. Ownership includes the right to stop using SnapSmack.</p></article>
            </div>
        </div>
    </section>

    <section id="respect">
        <div class="wrap">
            <h2>Respect Where It&rsquo;s Due</h2>
            <p>SnapSmack&rsquo;s design owes a debt to <a href="https://github.com/pixelpost/pixelpost/wiki" target="_blank" rel="noopener">Pixelpost</a> &mdash; a photo blogging platform that quietly disappeared but never stopped being right about a few things. Its UI shaped a lot of what SnapSmack became.</p>
            <p>And particular thanks to photographer, writer, and developer <a href="https://bsky.app/profile/thatnoahgrey.bsky.social" target="_blank" rel="noopener">Noah Grey</a> &mdash; creator of <a href="https://en.wikipedia.org/wiki/Greymatter_(software)" target="_blank" rel="noopener">Greymatter</a>, one of the earliest open-source blogging platforms &mdash; for proving that when the software you need doesn&rsquo;t exist, you build it. And for knowing, twenty-five years ago, that you honour the people who trust you with their work.</p>
        </div>
    </section>

    <section id="whodat">
        <div class="wrap">
            <h2>Who&rsquo;s Responsible for All This?!?</h2>
            <p class="whodat-honest"><strong>Said up front:</strong> the code is AI-written, under the direction of a photographer who is not a programmer. That is not hidden anywhere on this site &mdash; <a href="the-reckoning.php">THE RECKONING</a> counts every line. It is also exactly why the software is audited, pen-tested, and held to a public disclosure policy instead of being trusted on faith.</p>
            <div class="whodat-grid">
                <article class="whodat-card">
                    <div class="whodat-portrait"><img src="img/whodat-sean.png" alt="Sean McCormick" width="686" height="784" loading="lazy"></div>
                    <div>
                        <p class="whodat-name">Sean McCormick</p>
                        <p class="whodat-title">Just a guy with a camera.</p>
                        <p class="whodat-bio">Photographer, creator, product designer, chief tester, and final decision-maker who got tired of watching his archive evaporate into the memory hole of dying platforms. Conceived and directed SnapSmack because the alternative was continuing to post between ads for hemorrhoid cream. Not a programmer beyond a few minor CSS contributions; the implementation is AI-authored under his direction. Has opinions about light. Runs several <a href="https://linktr.ee/mccormickphotography" target="_blank">photo sites</a> using software he envisioned to avoid having opinions about Squarespace. Based in Canada, which is polite for "somewhere cold with good coffee."</p>
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

    <section id="beta">
        <div class="wrap">
            <h2>Apply for the Closed Beta</h2>
            <p class="lede">The first wave opens <strong>November 4, 2026</strong> for twenty photographers. It is built for real back-catalogues, so you will want at least 500 images ready to post. Flickr and Instagram refugees are particularly welcome.</p>
            <div class="ml-embedded" data-form="Z4oY86"></div>
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
