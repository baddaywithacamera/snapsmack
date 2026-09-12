<?php
/**
 * SNAPSMACK.CA - SECURITY
 * The doctrine (Sean's words), what happens when something goes wrong, the
 * eight layers, and the honest answer to "should AI-written code be on the
 * fediverse". Audits themselves live on BUZZERS; failures on DING DONG BELL.
 * (New page, rework 2026-09-12.)
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

$page_title       = 'LOCKED DOWN! - SnapSmack Security';
$page_description = 'How SnapSmack protects your site and what happens when something goes wrong: local lockout only, fleet-wide same-day alerts, public audits, and a no-remote-control rule. Custodian, not landlord.';
$page_og_url      = 'https://snapsmack.ca/security.php';
$nav_active       = 'security';

$_breach_shot = file_exists(__DIR__ . '/img/smackback-breach.png');

$page_css = <<<'CSS'
.sec-intro { max-width: 800px; }
.doctrine { background: var(--black); color: #ddd; border-top: 8px solid var(--red); }
.doctrine h2 { color: var(--white); }
.doctrine .lede { color: #bbb; }
.doctrine-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1px; margin-top: 34px; background: #383838; border: 1px solid #383838; }
.doctrine-card { padding: 26px; background: #171717; }
.doctrine-card h3 { color: var(--red); margin-bottom: 12px; }
.doctrine-card p { margin: 0 0 .8em; color: #bbb; font-size: .92rem; line-height: 1.6; }
.doctrine-card p:last-child { margin-bottom: 0; }
.doctrine-card q { color: var(--white); font-style: italic; }
.wrong { border-top: 8px solid var(--black); }
.wrong-steps { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1px; margin-top: 34px; background: var(--border); border: 1px solid var(--border); }
.wrong-step { padding: 26px; background: var(--white); }
.wrong-step .step-num { display: block; margin-bottom: 10px; color: var(--red); font: 900 .7rem/1 Arial Black, Arial, sans-serif; letter-spacing: .12em; text-transform: uppercase; }
.wrong-step h3 { margin-bottom: 12px; }
.wrong-step p { margin: 0 0 .8em; font-size: .92rem; line-height: 1.6; }
.wrong-step p:last-child { margin-bottom: 0; }
.wrong-shot { margin: 34px 0 0; border: 1px solid var(--border); background: var(--black); }
.wrong-shot img { width: 100%; }
.wrong-shot figcaption { padding: 10px 14px; color: #aaa; background: var(--black); font: .7rem/1.4 'Courier New', monospace; }
.yellow { margin-top: 34px; padding: 24px 28px; background: #fff3c4; border-left: 6px solid #e0a800; }
.yellow h3 { color: var(--black); margin-bottom: 10px; }
.yellow p { margin: 0 0 .8em; font-size: .95rem; }
.yellow p:last-child { margin-bottom: 0; }
.layers { background: #2e2e2e; color: var(--white); border-top: 8px solid var(--red); }
.layers h2 { color: var(--red); }
.layers .site-discovery-kicker { color: #bbb; }
.layers .lede { color: #bbb; max-width: 900px; }
.security-layers { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 34px 42px; margin-top: 42px; }
.security-layer { padding-top: 22px; border-top: 3px solid var(--red); }
.security-layer .layer-num { margin-bottom: 10px; color: var(--red); font: 900 .7rem/1 Arial Black, Arial, sans-serif; letter-spacing: .12em; text-transform: uppercase; }
.security-layer h3 { color: var(--white); margin-bottom: 12px; font-size: 1.1rem; }
.security-layer p { margin: 0; color: #bbb; font-size: .9rem; line-height: 1.6; }
.defence { border-top: 8px solid var(--black); background: #f4f1eb; }
.defence-body { max-width: 820px; }
.defence-q { margin-top: 30px; }
.defence-q h3 { color: var(--red); font-size: 1.1rem; margin-bottom: 8px; }
.defence-line { margin-top: 34px; padding: 22px 26px; border-left: 5px solid var(--red); background: var(--white); color: var(--black); font: 900 clamp(1.05rem, 2vw, 1.3rem)/1.4 Arial Black, Arial, sans-serif; }
.receipts { border-top: 8px solid var(--red); }
.receipts-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1px; background: var(--border); border: 1px solid var(--border); }
.receipts-grid a { display: block; padding: 22px 24px; background: var(--white); color: var(--black); }
.receipts-grid a strong { display: block; font: 900 .9rem/1.2 Arial Black, Arial, sans-serif; text-transform: uppercase; }
.receipts-grid a strong::after { content: " \2192"; color: var(--red); }
.receipts-grid a span { display: block; margin-top: 6px; color: var(--mid-grey); font-size: .88rem; line-height: 1.45; }
.receipts-grid a:hover { background: #fff5f3; text-decoration: none; box-shadow: inset 0 -4px 0 var(--red); }
@media (max-width: 850px) { .doctrine-grid, .wrong-steps { grid-template-columns: 1fr; } }
@media (max-width: 700px) { .security-layers, .receipts-grid { grid-template-columns: 1fr; } }
CSS;

require_once __DIR__ . '/includes/header.php';
?>
<main>
    <section class="page-header">
        <div class="wrap">
            <p class="site-discovery-kicker">LOCKED DOWN!</p>
            <h1>Locked down.<br><span>Never locked in.</span></h1>
            <p class="lede sec-intro">Eight layers of protection, public audits, same-day disclosure, and one rule above all of it: nobody but you can touch your site. Not even us.</p>
        </div>
    </section>

    <section class="doctrine">
        <div class="wrap">
            <p class="site-discovery-kicker">The rules, in the builder&rsquo;s words</p>
            <h2>Custodian, not landlord.</h2>
            <p class="lede">You are trusting this software with your life&rsquo;s work. That earns you three promises, and they are not marketing &mdash; they are how the code is built.</p>
            <div class="doctrine-grid">
                <article class="doctrine-card">
                    <h3>No remote control. Ever.</h3>
                    <p><q>I have no right to lock someone out of their site. That is a massive violation of their rights.</q></p>
                    <p>SnapSmack has no kill switch, no vendor login, no back door. When a site locks itself down after tampering, that lockout is <strong>local</strong>, triggered only by tampering on <strong>that site</strong>, and lifted only by <strong>its owner</strong> with their own password and 2FA. The network can warn you. It cannot act on your behalf, and we would not want it to.</p>
                </article>
                <article class="doctrine-card">
                    <h3>You know what we know, when we know it.</h3>
                    <p><q>We don&rsquo;t hide important information from people who trusted us with their art. That is a violation of our duty of care.</q></p>
                    <p>Security findings, breaches, and audit results go public the day we have them. <q>We don&rsquo;t tell people they were hacked two weeks later when we can&rsquo;t hide it.</q> The <a href="buzzers.php">audits</a> and the <a href="ding-dong-bell.php">failures</a> are both on this site, in full.</p>
                </article>
                <article class="doctrine-card">
                    <h3>Honour the work.</h3>
                    <p><q>It&rsquo;s a blessing to be trusted with someone&rsquo;s most beautiful possessions. You honour them back. You don&rsquo;t monetize them.</q></p>
                    <p>No analytics sold, no tracking pixels, no &ldquo;anonymised&rdquo; data deals. Your visitors are not counted by anyone but you. The full privacy position is on <a href="tnb.php">TWIG N BERRIES</a>.</p>
                </article>
            </div>
        </div>
    </section>

    <section class="wrong">
        <div class="wrap">
            <p class="site-discovery-kicker">When something goes wrong</p>
            <h2>What actually happens.</h2>
            <p class="lede">Every SnapSmack site watches its own files. Here is the chain, end to end, as it ran in a real test on 11 September 2026.</p>
            <div class="wrong-steps">
                <article class="wrong-step">
                    <span class="step-num">Step 1 &mdash; your site</span>
                    <h3>SMACKBACK catches it</h3>
                    <p>A file on the server changed and nobody signed it. Within minutes the site goes to <strong>LOCKOUT</strong>: public pages are protected, the admin shows exactly which file, and nothing is trusted again until the owner enters their password and 2FA and decides &mdash; restore the clean copy, or bless the change because it was theirs.</p>
                    <p>It caught our own developer dropping an unsigned file. That is the point.</p>
                </article>
                <article class="wrong-step">
                    <span class="step-num">Step 2 &mdash; the network</span>
                    <h3>Sites tell each other</h3>
                    <p>The site reports the incident to SMACK CENTRAL. One site with one tampered file is a local matter. <strong>Several</strong> sites reporting tampering inside a short window is a different animal: a probable zero-day, someone working through the fleet.</p>
                </article>
                <article class="wrong-step">
                    <span class="step-num">Step 3 &mdash; every owner</span>
                    <h3>Yellow alert, same hour</h3>
                    <p>Every SnapSmack owner is told, immediately: apply updates, take a backup, rotate keys and passwords, consider pausing federation until it&rsquo;s understood. Then we publish what we know. Nobody&rsquo;s site is touched. Everybody&rsquo;s owner is informed.</p>
                </article>
            </div>
<?php if ($_breach_shot): ?>
            <figure class="wrong-shot">
                <img src="img/smackback-breach.png" alt="SMACKBACK file integrity monitor showing BREACH DETECTED, LOCKOUT response mode, the tampered file, and the password + 2FA fields required to restore or re-bless it" width="1920" height="1080" loading="lazy">
                <figcaption>The real screen, from the real incident: one unsigned file, one locked site, one owner asked to decide.</figcaption>
            </figure>
<?php endif; ?>
            <div class="yellow">
                <h3>What a yellow alert means</h3>
                <p>Multiple SnapSmack sites have reported tampering in a very small window and we might be under attack. It means: <strong>apply updates, do backups, rotate keys and passwords, immediately.</strong></p>
                <p>What it does <em>not</em> mean: that anyone has taken your site offline. Only you can do that. Sean takes <em>his own</em> fleet off the fediverse when it&rsquo;s his fleet that&rsquo;s the risk. Yours is yours.</p>
            </div>
        </div>
    </section>

    <section class="layers" id="layers">
        <div class="wrap">
            <p class="site-discovery-kicker">Eight layers of FAFO</p>
            <h2>The stack</h2>
            <p class="lede">The more work a troll or attacker has to do, the more likely they are to go bother someone else. Each layer is independent; each blog keeps control of its own thresholds and decisions.</p>
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
                    <p>Automated file-integrity monitoring catches tampering, locks down compromised public pages, <a href="https://www.youtube.com/watch?v=7YPy1MbqM8s" target="_blank" rel="noopener">alerts the owner</a>, and reports confirmed incidents to the network so other owners can be warned.</p>
                </article>
                <article class="security-layer">
                    <div class="layer-num">Layer 7 &mdash; The Admin</div>
                    <h3>IP Smacker</h3>
                    <p>Scanner rejection, a configurable private login route, aggressive failed-login bans, mandatory 2FA, and an independent <a href="bugger.php">break-glass recovery card</a> harden the front door.</p>
                </article>
                <article class="security-layer">
                    <div class="layer-num">Layer 8 &mdash; The Software</div>
                    <h3>Snap Decision</h3>
                    <p>Cryptographically signed releases, published checksums, signed git tags, reviewed bundled dependencies, and public security audits protect the software supply chain.</p>
                </article>
            </div>
        </div>
    </section>

    <section class="defence" id="ai-code">
        <div class="wrap">
            <div class="defence-body">
                <p class="site-discovery-kicker">The fair question</p>
                <h2>&ldquo;Should AI-written software be on the fediverse?&rdquo;</h2>
                <p>It&rsquo;s a fair question and it deserves a straight answer, because federated software is a shared-trust arrangement: a hole in our code could become somebody else&rsquo;s problem. So, three questions, answered plainly.</p>
                <div class="defence-q">
                    <h3>Are you a danger to the network?</h3>
                    <p>No. At any sign of trouble Sean takes his own fleet off the fediverse first and asks questions second, and every independent owner gets a yellow alert the same hour. Most hand-written fediverse projects have no fleet-wide alert at all. We would rather be the project that over-warns.</p>
                </div>
                <div class="defence-q">
                    <h3>Can you fix a hole when one is found?</h3>
                    <p>Yes. Fixes are generated fast, tested against a private lab of Pixelfed, Mastodon and GoToSocial servers, and shipped as signed releases. When it&rsquo;s critical, it goes out by hand. This is stewarded software, not a passenger seat.</p>
                </div>
                <div class="defence-q">
                    <h3>Are you honest about what it is?</h3>
                    <p>Yes. The provenance is published on <a href="the-reckoning.php">THE RECKONING</a> down to the line count. The failures are published on <a href="ding-dong-bell.php">DING DONG BELL</a>. Nothing about how this was made is hidden, because hiding it would be the actual risk.</p>
                </div>
                <p class="defence-line">Don&rsquo;t trust my typing &mdash; judge the artifact. The code is AI-produced, which is exactly why I don&rsquo;t just trust it. That&rsquo;s what the audits, the pen tests, and the disclosure policy are for.</p>
                <p>And the part that answers the fear underneath the question &mdash; <em>could it turn on the network?</em> &mdash; is the first rule on this page. SnapSmack cannot remotely control anybody&rsquo;s site. Not Sean, not the network, not a compromised central server. A warning network, not a botnet. The lack of a kill switch isn&rsquo;t a missing feature. It&rsquo;s the feature.</p>
            </div>
        </div>
    </section>

    <section class="receipts">
        <div class="wrap">
            <p class="site-discovery-kicker">Receipts</p>
            <h2>Don&rsquo;t take our word for it.</h2>
            <div class="receipts-grid">
                <a href="buzzers.php"><strong>BUZZERS! &middot; security audits</strong><span>Every closed audit, findings and fixes, published as they happen.</span></a>
                <a href="ding-dong-bell.php"><strong>DING DONG BELL! &middot; operability audits</strong><span>Where our own plumbing fell down, and what it cost to learn.</span></a>
                <a href="tnb.php"><strong>TWIG N BERRIES! &middot; privacy</strong><span>What is collected (very little), what is shared (nothing), and what you opt into.</span></a>
                <a href="bugger.php"><strong>BUGGER! &middot; emergency help</strong><span>Locked out, signature failed, something on fire: start here.</span></a>
            </div>
        </div>
    </section>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
