<?php
/**
 * SNAPSMACK.CA — Wotcha (News & Releases)
 *
 * News, releases, and the occasional rant about what got built and why.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

$page_title       = 'WOTCHA! — SnapSmack News';
$page_description = 'SnapSmack news, releases, and the occasional rant about what got built and why.';
$page_og_url      = 'https://snapsmack.ca/wotcha.php';
$nav_active       = 'wotcha';

$page_css = <<<'CSS'
/* ─── WOTCHA — H2/H3 OVERRIDES ─────────────────────────────────────────────────────────── */
h2 {
    font-size: clamp(1.4rem, 2.5vw, 1.9rem);
    color: var(--black);
    margin-bottom: 6px;
    letter-spacing: -0.01em;
}
h3 { font-size: 1rem; }
.lede { margin-bottom: 0; }

/* ─── POST INDEX ──────────────────────────────────────────────────────────────────────────── */
.post-index {
    padding: 48px 0 40px;
    border-bottom: 3px solid var(--black);
}
.post-index h3 {
    font-family: Arial Black, Arial, sans-serif;
    font-size: 0.75rem;
    letter-spacing: 0.12em;
    text-transform: uppercase;
    color: var(--mid-grey);
    margin-bottom: 20px;
}
.post-index ol {
    list-style: none;
    columns: 2;
    column-gap: 48px;
}
.post-index ol li {
    margin-bottom: 10px;
    break-inside: avoid;
    display: flex;
    gap: 12px;
    align-items: baseline;
}
.post-index ol li .idx-date {
    font-size: 0.8rem;
    color: var(--mid-grey);
    white-space: nowrap;
    font-family: Arial, sans-serif;
    flex-shrink: 0;
}
.post-index ol li a {
    font-family: Arial Black, Arial, sans-serif;
    font-size: 0.88rem;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 0.01em;
    color: var(--dark-grey);
    line-height: 1.3;
}
.post-index ol li a:hover { color: var(--red); text-decoration: none; }

/* ─── POSTS ─────────────────────────────────────────────────────────────────────────────────── */
.posts { padding-bottom: 80px; }

article.post {
    padding: 64px 0;
    border-bottom: 1px solid var(--border);
    max-width: 820px;
}
article.post:last-child { border-bottom: none; }

.post-meta {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-bottom: 16px;
}
.post-date {
    font-family: Arial, sans-serif;
    font-size: 0.82rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: var(--mid-grey);
}
.post-tag {
    font-family: Arial Black, Arial, sans-serif;
    font-size: 0.68rem;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: 0.1em;
    color: var(--white);
    background: var(--red);
    padding: 2px 8px;
    display: inline-block;
}
.post h2 {
    margin-bottom: 20px;
    font-size: clamp(1.5rem, 2.8vw, 2.1rem);
    line-height: 1.05;
}
.post h2 a { color: var(--black); text-decoration: none; }
.post h2 a:hover { color: var(--red); }
.post p { margin-bottom: 1.4em; max-width: 72ch; }
.post p:last-child { margin-bottom: 0; }

@media (max-width: 700px) {
    .post-index ol { columns: 1; }
}
CSS;

require_once __DIR__ . '/includes/header.php';
?>

<main>
    <div class="page-header">
        <div class="wrap">
            <h1>WOTCHA!</h1>
            <p class="lede">News, releases, and the occasional rant about what got built and why.</p>
        </div>
    </div>

    <section class="post-index">
        <div class="wrap">
            <h3>All Posts</h3>
            <ol>
                <li>
                    <span class="idx-date">Jul 1</span>
                    <a href="#box-seat">A Box Seat on Your Own Numbers</a>
                </li>
                <li>
                    <span class="idx-date">Jun 29</span>
                    <a href="#feds-at-door">The Feds Are at Your Door</a>
                </li>
                <li>
                    <span class="idx-date">Jun 29</span>
                    <a href="#cloud-keys">Your Cloud Keys Stay Yours</a>
                </li>
                <li>
                    <span class="idx-date">Jun 27</span>
                    <a href="#skin-drop">Slickr, Aurora, Parade — Live Today</a>
                </li>
                <li>
                    <span class="idx-date">Jun 27</span>
                    <a href="#open-books">Straight Answers, Open Audits</a>
                </li>
                <li>
                    <span class="idx-date">Jun 23</span>
                    <a href="#break-glass">Break the Glass</a>
                </li>
                <li>
                    <span class="idx-date">Jun 22</span>
                    <a href="#flkr-fckr">Ten Thousand Photos, Imported Clean</a>
                </li>
                <li>
                    <span class="idx-date">Jun 22</span>
                    <a href="#email">Your Site Can Email Again</a>
                </li>
                <li>
                    <span class="idx-date">Jun 15</span>
                    <a href="#ejector-seat">Locked Down and Sealed Up</a>
                </li>
                <li>
                    <span class="idx-date">Jun 14</span>
                    <a href="#the-grid">More Skin in the Game</a>
                </li>
                <li>
                    <span class="idx-date">Jun 8</span>
                    <a href="#unzucker">Your Instagram Archive, Moved Out</a>
                </li>
                <li>
                    <span class="idx-date">May 30</span>
                    <a href="#chaplin">Chaplin Is Live</a>
                </li>
                <li>
                    <span class="idx-date">May 26</span>
                    <a href="#update-tracks">Two Tracks: BORING and BITCHIN'</a>
                </li>
                <li>
                    <span class="idx-date">May 26</span>
                    <a href="#skin-js-scanner">SMACKBACK Now Watches Your Skins</a>
                </li>
                <li>
                    <span class="idx-date">May 26</span>
                    <a href="#archive-landing">The Archive as the Front Door</a>
                </li>
                <li>
                    <span class="idx-date">May 22</span>
                    <a href="#maintenance-mode">Your Site, Under Construction</a>
                </li>
                <li>
                    <span class="idx-date">May 13</span>
                    <a href="#bulk-update-fixed">UPDATE ALL BEHIND Actually Works Now</a>
                </li>
                <li>
                    <span class="idx-date">May 12</span>
                    <a href="#fleet-intelligence">The Fleet Knows Its Numbers Now</a>
                </li>
                <li>
                    <span class="idx-date">May 10</span>
                    <a href="#fleet-update">The Fleet Updates Itself Now</a>
                </li>
                <li>
                    <span class="idx-date">Apr 29</span>
                    <a href="#lockdown">Bots Can't Find the Door If You Move It</a>
                </li>
                <li>
                    <span class="idx-date">Apr 22</span>
                    <a href="#smackattack">SMACKATTACK: The Reputation Network</a>
                </li>
                <li>
                    <span class="idx-date">Apr 20</span>
                    <a href="#spam">The Spam Is Handled. Took Three Layers.</a>
                </li>
                <li>
                    <span class="idx-date">Apr 17</span>
                    <a href="#fleet">Running a Fleet Without Losing Your Mind</a>
                </li>
                <li>
                    <span class="idx-date">Apr 16</span>
                    <a href="#trolls">Every Troll Leaves a Trail</a>
                </li>
                <li>
                    <span class="idx-date">Apr 15</span>
                    <a href="#backup">Your Photos Live Here. Permanently.</a>
                </li>
                <li>
                    <span class="idx-date">Apr 14</span>
                    <a href="#ohsnap">Design Your Skin Without Touching Code</a>
                </li>
                <li>
                    <span class="idx-date">Apr 13</span>
                    <a href="#gallery">Your Archive, Actually Managed</a>
                </li>
                <li>
                    <span class="idx-date">Apr 12</span>
                    <a href="#sybu">The Posting Machine</a>
                </li>
                <li>
                    <span class="idx-date">Apr 10</span>
                    <a href="#skins">Make It Look Like Yours</a>
                </li>
                <li>
                    <span class="idx-date">Apr 8</span>
                    <a href="#modes">Simple First, Everything Later</a>
                </li>
                <li>
                    <span class="idx-date">Apr 7</span>
                    <a href="#roadmap">What Comes Next</a>
                </li>
            </ol>
        </div>
    </section>

    <section class="posts">
        <div class="wrap">

            <!-- POST: BOX SEAT -->
            <article class="post" id="box-seat">
                <div class="post-meta">
                    <span class="post-date">July 1, 2026</span>
                    <span class="post-tag">Stats</span>
                </div>
                <h2><a href="#box-seat">A Box Seat on Your Own Numbers</a></h2>
                <p>Here's an uncomfortable truth about a photoblog: most of the people who look never click. They land on your grid, scroll through a dozen frames, take it in, and leave without ever opening a single post. Your view counts never saw them. As far as your stats were concerned, they were never there.</p>
                <p><strong>SCROLL TIME</strong> changes that. On GRAMOFSMACK landing feeds and SMACKONEOUT archives, SnapSmack now measures how long a visitor was actually <em>engaged</em> — the clock only runs while they're on the page and doing something, and it stops the moment they tab away or go idle, so a tab left open in the background never inflates the number. It reports home when they leave, and it never sets a cookie. The result is one honest figure: how long people really spend with your work, including everyone who browsed and bounced without clicking a thing.</p>
                <p>You can also see where they're coming from. The <strong>COUNTRIES</strong> panel breaks your traffic down by country — resolved entirely on your own server against a local database, because shipping your visitors' IP addresses off to some geolocation API is exactly the kind of thing we tell you never to do. Your readers' locations are your business, not a third party's.</p>
                <p>And the fleet rollup finally counts straight. A SMACKONEOUT post is one photograph; a GRAMOFSMACK carousel is one post carrying up to ten. Lumping those together was never honest, so the FLEET STATS page now counts <strong>posts and images separately</strong> — nine tiles across, TOTAL POSTS and TOTAL IMAGES sitting alongside views, visitors, bots, and your peak day. A box seat on your own numbers, and none of it phones home.</p>
            </article>

            <!-- POST: FEDS AT DOOR -->
            <article class="post" id="feds-at-door">
                <div class="post-meta">
                    <span class="post-date">June 29, 2026</span>
                    <span class="post-tag">Federation</span>
                </div>
                <h2><a href="#feds-at-door">The Feds Are at Your Door</a></h2>
                <p>Photoblogs are so last decade (or so we're told). Today is social, connected. So we built an Einstein Rosen Bridge to connect your photoblog of yesterday to the wider Fediverse of the present. Get the social aspect of ActivityPub based networks like Pixelfed without handing over your art to some stranger running a sketchy server out of his mom's basement.</p>
                <p>Your images stay under your control and are presented exactly how you like. You get to keep your blog while presenting as your own Pixelfed server instance through a compatibility layer. Fediverse users see what they expect from your Pixelfed user-id URL. You see more traffic. You see integrated comments and likes. You see yourself in control of your art the way it should be.</p>
                <p>This feature is a bazooka and you need a lot of space to fire it in without hitting bystanders. Not for cheap shared hosting, a VPS at a minimum and your own server is preferred. For photo blogging Mack Daddies only. SMACKVERSE makes the old new again.</p>
                <p>Currently in development and will be stable by beta release.</p>
            </article>

            <!-- POST: CLOUD KEYS -->
            <article class="post" id="cloud-keys">
                <div class="post-meta">
                    <span class="post-date">June 29, 2026</span>
                    <span class="post-tag">Security</span>
                </div>
                <h2><a href="#cloud-keys">Your Cloud Keys Stay Yours</a></h2>
                <p>We pulled a feature this week, and we'd rather tell you why than quietly let it rot in the menu. SnapSmack used to push your backups straight to Google Drive or OneDrive from your web host. It sounded handy. It was a mistake — one we only made because it seemed like a good idea until we thought about it properly.</p>
                <p>Here's the problem. For the server to upload to your Drive on its own, it had to hold a long-lived key with broad permission over your cloud, and sit on it — on a shared web host — indefinitely. That key is a loaded gun pointed at your account. Shared servers get compromised; it's a question of when, not if. Whoever gets in doesn't just get your blog — they get a key that can read, overwrite, or <em>delete</em> your cloud files, including the very backups you set this up to protect. That's the "convenient integration turns into breach multiplier" pattern that's bitten every big CMS in turn. We're not going to be the next one.</p>
                <p>So we took it out — not disabled, gone. The code's deleted, the menu item's gone, and the next time your site updates it deletes the stored keys itself, so a blog that had Drive linked ends up with nothing on the server to steal. Still want cloud backups? Push them from the desktop tool, where your keys live on your own machine and never touch a server we don't control — or just download or FTP your backup straight off the box. Your photos, your cloud, your keys. None of it parked somewhere it can be turned against you.</p>
            </article>

            <!-- POST: SKIN DROP -->
            <article class="post" id="skin-drop">
                <div class="post-meta">
                    <span class="post-date">June 27, 2026</span>
                    <span class="post-tag">Skins</span>
                </div>
                <h2><a href="#skin-drop">Slickr, Aurora, Parade — Live Today</a></h2>
                <p>SLICKR ships today, and it brings the cover with it. It's the magazine skin — an editorial layout built around a full-width masthead cover, the kind of front page a printed photography quarterly would run. New in this release: you position and zoom that cover by dragging it into place against a live preview, so a panorama or an off-centre subject sits exactly where you want it instead of wherever a fixed crop happened to land. Nothing is re-encoded, the move is fully reversible, and the banner always fills its frame. The archive underneath carries a unified filter panel and browse-by-date, so a deep catalogue stays walkable.</p>
                <p>AURORA and PARADE ship alongside it — the carousel skins, where your photographs ride a moving stage instead of sitting still. AURORA is the quiet one: a slow wash of pastel light behind the tiles, animated nav lines, a border that drifts rather than blinks. PARADE is its louder sibling — slow-motion fireworks on white, with pride palettes built in for anyone who wants them. Both put a rotating set of your work front and centre, and the motion speeds, nav-line opacity, and spacing are all adjustable in the skin settings so you can dial the energy up or down to suit the pictures.</p>
                <p>Two more are close. INSTANT CAMERA is the instant-film skin — the white-bordered, slightly-imperfect snapshot look, for archives that want that warmth. ORGANIZED MAYHEM is a background engine rather than a full skin: controlled motion behind your content, chaotic on the surface and deliberate underneath. Both are nearing completion and land shortly.</p>
                <p>SLICKR, AURORA, and PARADE are available now through the SnapSmack Skin Packager. INSTANT CAMERA and ORGANIZED MAYHEM follow when they're ready — which, going by where they are, won't be long.</p>
            </article>

            <!-- POST: OPEN BOOKS -->
            <article class="post" id="open-books">
                <div class="post-meta">
                    <span class="post-date">June 27, 2026</span>
                    <span class="post-tag">Transparency</span>
                </div>
                <h2><a href="#open-books">Straight Answers, Open Audits</a></h2>
                <p>Two pages went up on this site today, and both exist for the same reason: you shouldn't have to take our word for anything.</p>
                <p><strong>BRASS TACKS</strong> is the FAQ — straight answers to the questions people actually ask before trusting a platform with years of their work. What it costs (nothing), who owns your photographs (you), what happens if the project goes away (you still have everything, because it's on your server), and the blunter ones we'd rather answer head-on than dodge. It's written the way we talk, which is to say not always gently.</p>
                <p><strong>BUZZERS</strong> is the security ledger. Every security audit we've run on SnapSmack and its tools, the findings, and the releases that closed them — published once they're closed. Today that list grew: site-wide protection against cross-site request forgery, the one item we'd carried as deferred across the early review series, is now in place everywhere, and the reports that were waiting on it are public. What you won't find there is the blueprint for a live, unpatched hole — those stay private until they're fixed, then they join the list. That's the deal, and now you can read it.</p>
                <p>The codebase has always been open for inspection. These two pages just make the rest of the story — what we fixed, and what we won't pretend we did — open too.</p>
            </article>

            <!-- POST: BREAK THE GLASS -->
            <article class="post" id="break-glass">
                <div class="post-meta">
                    <span class="post-date">June 23, 2026</span>
                    <span class="post-tag">Security</span>
                </div>
                <h2><a href="#break-glass">Break the Glass</a></h2>
                <p>Two-factor authentication keeps the wrong people out of your site. The obvious problem with a lock that good is the day you're the one standing outside it — password forgotten, phone replaced, recovery codes in a notebook you can no longer find. Most systems answer that with a "contact support" link and a shrug. We don't have a support desk, and you own your own server, so we built you a fire axe instead.</p>
                <p>It's called Break the Glass. You generate a small recovery file ahead of time and keep it somewhere safe and offline — an encrypted USB stick, a password manager — well away from the site it belongs to. If you're ever completely locked out, you upload that one file to your site and you're back in. That's the whole ritual. No email round-trip, no third party, no begging a platform to believe you're really you.</p>
                <p>The reason a forged card can't be turned against you is the cryptography under it. The file is signed with a key that only your install trusts, using Ed25519 — the same class of signature your SSH connections and your browser already lean on. Your site checks that signature before it does anything, and a faked or substituted file is rejected on sight: nobody can edit yours, mint one of their own, or swap in a different key. The glass is bulletproof, and only the right axe goes through on the first swing.</p>
                <p>But understand what that axe is: a master key, and it is yours to guard. The real card works — that's the entire point of it — which means whoever holds the genuine file can get into your site, exactly the way your house key works for anyone holding it. So keep it offline and out of sight, treat it like the spare key to everything you own, and never write down or mention which site it unlocks. Naming the site turns a lost file into an instant break-in. Forgeries fail; the real one doesn't — so the real one stays secret.</p>
                <p>It is also, deliberately, the <em>only</em> time SnapSmack wants you to put a file on your site by hand. The rest of the time, an unexpected file appearing is exactly what an attack looks like, and the software treats it as one. Break the Glass is the documented exception — the signed envelope that says "this really is the owner, having a very bad day." Make one now, while you still can, then lock it away somewhere only you can reach — and don't breathe a word of which site it opens until the day you need it.</p>
            </article>

            <!-- POST: FLKR FCKR -->
            <article class="post" id="flkr-fckr">
                <div class="post-meta">
                    <span class="post-date">June 22, 2026</span>
                    <span class="post-tag">Tools</span>
                </div>
                <h2><a href="#flkr-fckr">Ten Thousand Photos, Imported Clean</a></h2>
                <p>Flickr isn't dead, exactly, but it has the look of a place being quietly managed toward the exit. If your photographs have been living there, you can ask for them back — and what comes down is the familiar pile of JSON and image files that means everything and nothing until something reads it. FLKR FCKR reads it, and puts the whole thing on a site you own.</p>
                <p>Point the desktop tool at your Flickr export and it parses the lot: images, titles, descriptions, tags, and — the part that matters most for an archive — the original upload dates. It connects to your SnapSmack site over HTTPS and posts everything in order, spread across as many days as your server can comfortably handle, so a decade of photographs doesn't all land on the same timestamp and collapse into a single day. Throttle and off-peak controls keep a shared host from falling over while it works.</p>
                <p>This release brings two things across that earlier imports left behind. Your <strong>album covers</strong> now come over and map to the right albums, so the gallery looks the way it did on Flickr instead of defaulting to whatever happened to be newest. And your <strong>Flickr fave counts</strong> ride along as each post's starting like tally — not the individual people who faved you, Flickr never handed those over, but the number, so the work doesn't arrive looking like nobody ever saw it.</p>
                <p>We don't ask you to trust a figure we made up. FLKR FCKR just ran a real ten-thousand-image archive — a decade of one photographer's actual Flickr — onto a live SnapSmack site, and it landed clean: every image, the albums rebuilt, the dates intact, in order. That's the bar. Your archive, your server, your dates, your order. To Flickr: thanks for the photographs. We'll take it from here.</p>
            </article>

            <!-- POST: EMAIL -->
            <article class="post" id="email">
                <div class="post-meta">
                    <span class="post-date">June 22, 2026</span>
                    <span class="post-tag">Platform</span>
                </div>
                <h2><a href="#email">Your Site Can Email Again</a></h2>
                <p>Email is the unglamorous plumbing every site needs and no self-hosted setup handles gracefully. Password resets, contact-form messages, the alert that fires when something is wrong — all of it depends on your server actually being able to send mail, and on a cheap shared host that's a coin flip at best. Run a handful of sites and you have a handful of separate mail headaches.</p>
                <p>SnapSmack now sends its mail through a single, reliable path. Drop in one key from a transactional email provider and every message — resets, recovery links, contact replies, breach alerts — goes out over plain HTTPS, the same way the rest of the software talks to the world. No SMTP ports to wrestle open, no exposing your home connection to your ISP, and no dependency on some central hub being awake. If no provider key is set, it quietly falls back to the old method, so nothing breaks on the way in.</p>
                <p>The part that matters if you run more than one site: you configure it once, on your hub, and push it to every site you manage in a single action. One key, one setting, the whole fleet emailing properly. The breach alert in particular now leaves each site directly, so even a site having a genuinely bad day can still shout for help. It isn't exciting. It's just supposed to work now, and it does.</p>
            </article>

            <!-- POST: EJECTOR SEAT -->
            <article class="post" id="ejector-seat">
                <div class="post-meta">
                    <span class="post-date">June 15, 2026</span>
                    <span class="post-tag">Security</span>
                </div>
                <h2><a href="#ejector-seat">Locked Down and Sealed Up</a></h2>
                <p>This release is about keeping your site yours — even on a bad day. Six security and platform features land together, and most of them you will never have to think about until the one moment they matter.</p>
                <p><strong>Two-factor authentication is now mandatory.</strong> Every install gets a 30-day grace period, then admins who haven't switched it on get walked to the setup screen and can't go further until they do. We point you at open-source authenticator apps first — Aegis, Ente, 2FAS — because that is the company we keep. Lost your phone and your recovery codes? There is a documented emergency override file so you are never permanently locked out of your own site. A password alone stopped being enough a long time ago.</p>
                <p><strong>If a file gets tampered with, the site seals itself.</strong> SnapSmack already watches its own files for tampering. Now, when it catches something, the public side drops behind a plain "temporarily unavailable" page so a compromised install can't be used to throw malicious code at the people who read your work — and it never announces the breach to the public. On the admin side you are held to four things: the breach screen, the updater to replace the bad files, the support forum to get help, and your backups so you can grab a copy before you fix anything. Everything else waits. The point is to make you deal with it, not click past it. And because a breached box is exactly what an attacker would use to spam a forum, posting to the community forum during a lockdown requires you to re-confirm who you are first.</p>
                <p><strong>Turning the watchdog off now takes more than a click.</strong> Disabling file-integrity monitoring asks for your password and your 2FA code first. Turning it on stays a single click — friction belongs on the dangerous direction, not the safe one.</p>
                <p><strong>And two quality-of-life additions.</strong> Basic SEO settings are here — a real meta description, a per-page title template, an XML sitemap at <code>/sitemap.xml</code>, and honest control over your social-share image: it uses a photo you actually chose, never a logo or a random recent shot. There is also an opt-in page cache for sites that get real traffic — anonymous visitors only, never logged-in admins, with a dev-mode switch that pauses it for anywhere from five minutes to a week while you work, then turns itself back on.</p>
                <p>None of this costs anything. None of it phones home. It is just the locks being good locks.</p>
            </article>

            <!-- POST: THE GRID -->
            <article class="post" id="the-grid">
                <div class="post-meta">
                    <span class="post-date">June 14, 2026</span>
                    <span class="post-tag">Skins</span>
                </div>
                <h2><a href="#the-grid">More Skin in the Game</a></h2>
                <p>The Grid ships today as a production skin. It is the square-grid, three-across, tap-a-tile-to-open experience — Instagram on the desktop, from back when Instagram was a place you looked at photographs rather than a video platform that occasionally remembers it has a feed. If that is the layout your work belongs in, The Grid is now a download and a click away.</p>
                <p>Every tile resolves to a square and crops to fill it, so the grid stays even no matter what aspect ratios you feed it. Click a tile and the post opens in a modal over the grid — not a navigation to a separate page — showing the image at its natural proportions inside a square frame, with the border drawn in CSS rather than baked into the file. Deep links open the modal too: send someone a direct post URL and they land on the grid with that post already open. Close it and they are back in the grid where they started, no reload.</p>
                <p>Carousels work the way carousels should. A multi-image post opens as a swipeable set inside the modal — dots, arrows, swipe — with one like and one comment thread for the whole post rather than a separate set of reactions per frame. The grid marks anything with more than one image with a small stacked-squares badge, so you know what you are about to open before you open it.</p>
                <p>This is the part that matters if you ever composed for the grid. The Grid preserves the triptych — a panoramic image cut into three squares that sit side by side across a row, reading as one wide photograph and as three tiles at the same time — and it preserves the larger carousel post with a shared cover, a set held together under a single frame. The square chunks line up the way you arranged them, the cover stays the cover, and the composition holds across the row. This is what a curated feed looked like when the grid was the point: before Instagram handed the scroll to 3:4 vertical video and quietly stopped rewarding anyone who treated their profile as a composed surface. If you built a feed back then, with intent, it renders here the way you meant it — and the same triptychs and shared-cover carousels carry through to Photogram on the phone.</p>
                <p>The Grid is more than the grid. A background treatment system sits behind the content — a full-bleed image (1920×1080 or larger) or a solid colour, with a single bidirectional overlay slider that runs from dark through none to light, so you can settle the contrast against your own photographs without touching code. The profile header is centred and identical on every page of the site, the avatar opens in a lightbox, and the network blogroll is restyled to match. All of it lives in the skin settings.</p>
                <p>Photogram is the other half of the same idea — the phone-native skin SnapSmack serves automatically to mobile visitors — and it is now updated to match. Bringing a carousel-heavy archive in had exposed that Photogram wasn't rendering this content properly on the phone. That's fixed. Multi-image posts now open as swipeable carousels with dot indicators, likes and comments keyed to the post rather than the individual image, and carousel badges in the grid. The Grid on the desktop and Photogram on the phone now speak the same square-grid language — the same content, each rendered the right way for the screen it lands on.</p>
                <p>This is where your Instagram archive was always headed. Move it out with UNZUCKER, and your carousels import as carousels — and now they look like carousels, on the desktop with The Grid and on the phone with Photogram. The Grid is available through the SnapSmack Skin Packager and is the default skin for GramOfSmack installs. It is live now at <a href="https://unzucked.ca" target="_blank">unzucked.ca</a>.</p>
            </article>

            <!-- POST: UNZUCKER -->
            <article class="post" id="unzucker">
                <div class="post-meta">
                    <span class="post-date">June 8, 2026</span>
                    <span class="post-tag">Tools</span>
                </div>
                <h2><a href="#unzucker">Your Instagram Archive, Moved Out</a></h2>
                <p>Instagram will give you your data if you ask for it. A folder arrives: JSON files, image files, a structure that makes sense to a machine and not immediately to a person. What it does not give you is a way to put that data somewhere you control. That is what UNZUCKER does.</p>
                <p>UNZUCKER is a Windows desktop application. Point it at your Instagram export folder and it parses the archive: every post, every carousel, every caption, every hashtag extracted and stripped from the body text, timestamps preserved. The result is a three-column square-thumbnail grid — the same layout Instagram uses — showing your posts in chronological order. Click any post to see the full caption and, for carousels, all images in the set. The grid shows a small stacked-squares badge on anything with more than one image so you know what you're looking at before you click.</p>
                <p>Connect UNZUCKER to a SnapSmack site with an API key and hit Transfer &amp; Post. Each post is uploaded image by image over HTTPS — no FTP, no separate file transfer tool — and created in SnapSmack with its original caption, hashtags converted to tags, and the original posting date preserved. Carousels import as carousel posts. Singles import as singles. The whole export can process in one session, with a progress bar and per-post status so you can see what landed and what didn't.</p>
                <p>One caveat, stated plainly: importing a carousel-heavy archive exposed that the Photogram mobile skin — the phone-native interface SnapSmack serves automatically to mobile visitors — does not yet handle this content properly. The import broke it. Multi-image posts and the profile grid don't render the way they should on the phone. Photogram was always the right skin for an Instagram archive, and getting it to handle carousels fully — swipe, dot indicators, post-level likes and comments, the carousel badge in the grid — is the next thing on the list. We would rather tell you that than pretend it already works.</p>
                <p>SnapSmack is where we eat our own dog food, so that's where UNZUCKER shipped first. If SnapSmack isn't your destination, Pixelfed support is coming — the import side is the same either way, and we'll add Pixelfed as a target when it's confirmed stable. Your archive. Your server. Your call where it lands.</p>
            </article>

            <!-- POST: CHAPLIN -->
            <article class="post" id="chaplin">
                <div class="post-meta">
                    <span class="post-date">May 30, 2026</span>
                    <span class="post-tag">Skins</span>
                </div>
                <h2><a href="#chaplin">Chaplin Is Live</a></h2>
                <p>The sixth production skin ships today. Chaplin is a silent-film-era theme built specifically for black and white photography — which is what it looks like when a creative constraint is treated as a design brief instead of a limitation.</p>
                <p>The frame system is the thing. Every image in Chaplin sits inside a multi-rule Art Deco border built from CSS outline and box-shadow layers — a thin outer line, a measured gap, a heavier inner rule — with SVG ornaments at the corners and midpoints. The number of rules, their weights, the gap between them, the ornament style, all of it is adjustable in the skin settings without touching a line of code. The border isn't decorative trim. It's the point.</p>
                <p>The film engine runs on canvas. On page load, Chaplin applies a grain texture, a periodic flicker, and intermittent vertical scratch effects to the photograph — not as CSS filters, but as a JavaScript canvas layer composited over the image. The intensity of each effect is independently tunable. Turn them all the way down and you have a clean dark-room presentation. Turn them up and the image looks like it arrived on a print that has been handled a few times. The grain is subtle by design. The scratches are not.</p>
                <p>Typography is Cinzel and Cormorant — both pulled from Google Fonts, both chosen to feel like a title card from something made before sound was the default. The archive uses a justified Flickr-style grid that lets aspect ratios breathe rather than forcing every photograph into the same crop. The INFO and SIGNALS overlays are full-screen intertitles, not drawers. They disappear the same way they appeared: cleanly, all at once.</p>
                <p>Chaplin is live now at <a href="https://acolourlesslife.ca" target="_blank">acolourlesslife.ca</a>. It will be available through the SnapSmack Skin Packager once the content is settled.</p>
            </article>

            <!-- POST: UPDATE TRACKS -->
            <article class="post" id="update-tracks">
                <div class="post-meta">
                    <span class="post-date">May 26, 2026</span>
                    <span class="post-tag">Admin</span>
                </div>
                <h2><a href="#update-tracks">Two Tracks: BORING and BITCHIN'</a></h2>
                <p>There has always been one update track in SnapSmack. A release ships when it's tested and signed. You update to it when you're ready. That works fine if your definition of "ready" is "it's been through a full release cycle and confirmed stable on production sites." But if you want new features as they land — not after a full release cycle, not weeks later, now — that one track was a ceiling.</p>
                <p>0.7.184 introduces update tracks. Every SnapSmack site now has a track setting in Settings → Update Track: <strong>BORING</strong> or <strong>BITCHIN'</strong>. BORING means stable releases only. The same tested, signed packages that have always been the default. Nothing changes for you if you don't touch this setting. BITCHIN' means development builds: features as they ship, version numbers with a D suffix (0.7.184D, 0.7.185D, and so on). Development builds are real code — they just haven't had the full release cycle run on them. Not for sites with real visitors who will be annoyed when something breaks. Explicitly not for that.</p>
                <p>On multisite fleet dashboards, each spoke's row now shows a <strong>BORING</strong> or <strong>BITCHIN'</strong> badge alongside the version number. If you've got a mix — test spoke on dev, production spokes on stable — you can see that at a glance without drilling into each site individually. The hub has its own track setting independent of its spokes, so running the hub on dev while keeping spokes on stable is a supported configuration.</p>
                <p>The intended use is straightforward: keep production on BORING and stop thinking about it. If you're running a development install, or you want to test a new feature before it hits stable, or you are constitutionally incapable of not having the very latest thing — BITCHIN' is for you. Switch back any time. No data migrations, no drama.</p>
                <p>Alpha 0.7.184. Available through the in-admin updater.</p>
            </article>

            <!-- POST: SKIN JS SCANNER -->
            <article class="post" id="skin-js-scanner">
                <div class="post-meta">
                    <span class="post-date">May 26, 2026</span>
                    <span class="post-tag">Security</span>
                </div>
                <h2><a href="#skin-js-scanner">SMACKBACK Now Watches Your Skins</a></h2>
                <p>SMACKBACK has been watching SnapSmack's core PHP and JavaScript files since it shipped — hashing them at install time, re-verifying on schedule, screaming in high-contrast red if anything changes. That coverage was always deliberately limited to core files. Skins were excluded. The logic was sound: skins are distributed through Smack Central, reviewed before they ship, and covered by the signed release process. A skin from the gallery is as trusted as the core. What SMACKBACK wasn't watching was what happens to a skin after it lands on your server.</p>
                <p>A skin that gets modified on-server — by an attacker who found a foothold, or by a plugin from somewhere else that decided your skin files were a convenient place to leave something — can run arbitrary JavaScript in every visitor's browser. The core file check wouldn't catch it, because skin files aren't in the core manifest. That gap closes in 0.7.184.</p>
                <p>The new Skin JS Security Scanner in SMACKBACK audits every non-base skin for patterns that should not be there: <code>eval()</code> calls, <code>atob()</code> (the base64 decoder that shows up reliably in obfuscated injection payloads), <code>document.write()</code>, external script tags loading resources from outside your domain, and inline <code>&lt;script&gt;</code> blocks embedded in template PHP files. The scan runs on demand from the SMACKBACK admin page. Findings are presented per skin in collapsible panels — if a result is something you deliberately put there, you can see exactly what triggered it and decide accordingly.</p>
                <p>There's a site-level toggle: <strong>Allow Custom Skin JS</strong>. Off by default — the scanner treats any of the patterns above as a violation regardless of whether they look benign, so nothing ends up in a skin that the original signed package didn't put there. Turn it on only if you deliberately maintain your own JavaScript in a skin and would rather those patterns be reported as warnings than as violations.</p>
                <p>The base skins — 50 Shades of Noah Grey and New Horizon — are excluded. They're covered by the core integrity manifest. Everything else gets scanned.</p>
                <p>Alpha 0.7.184. Available through the in-admin updater.</p>
            </article>

            <!-- POST: ARCHIVE AS LANDING PAGE -->
            <article class="post" id="archive-landing">
                <div class="post-meta">
                    <span class="post-date">May 26, 2026</span>
                    <span class="post-tag">Admin</span>
                </div>
                <h2><a href="#archive-landing">The Archive as the Front Door</a></h2>
                <p>The homepage options in SnapSmack have always been: a static welcome page, or your most recent post. Both are reasonable choices. Neither is right if your photoblog is the kind of site where visitors are supposed to land in the middle of your back catalog — where the archive grid is the experience, not a destination you navigate to from somewhere else. A texture blog, a daily photo archive, a years-deep body of work that rewards browsing. For those sites, the front door has always been slightly wrong.</p>
                <p>0.7.184 adds a third option. Under Settings → Homepage, you can now set your homepage to <strong>Archive</strong>. Visitors land directly on the archive — the same grid, the same calendar engine, the same filters, pagination, and layout — as your front page. No extra click. No static page asking them what they'd like to do. The archive.</p>
                <p>Two layout choices: <strong>Grid</strong> (the justified thumbnail grid, same as the standard archive page) and <strong>List</strong>. Two thumbnail styles: <strong>Square</strong> (cropped to a consistent ratio) and <strong>Natural</strong> (full image proportions, letterboxed). These are site-level settings rather than skin settings, so they survive a skin swap without needing to be reconfigured.</p>
                <p>Static pages still work exactly as they did. This is an additional option for sites where the archive is the point. If your front door should be the grid — it can be now.</p>
                <p>Alpha 0.7.184. Available through the in-admin updater.</p>
            </article>

            <!-- POST: MAINTENANCE MODE -->
            <article class="post" id="maintenance-mode">
                <div class="post-meta">
                    <span class="post-date">May 22, 2026</span>
                    <span class="post-tag">Admin</span>
                </div>
                <h2><a href="#maintenance-mode">Your Site, Under Construction</a></h2>
                <p>Not every site goes live the moment the domain resolves. Sometimes you're building something, and you'd rather nobody see it until it's ready. Sometimes you're running maintenance — pushing an update, reworking a skin, doing something you don't want a visitor to walk into mid-process. Until now SnapSmack had no answer for that. You could take the whole server offline, or hope nobody visited, or hand-craft an <code>.htaccess</code> redirect and remember to undo it. None of those are good answers.</p>
                <p>0.7.168 adds maintenance mode. You turn it on from <strong>Settings</strong>. Visitors get a clean, self-contained holding page — your site name, a title and a short message you write yourself, a slow-rocking wrench icon, nothing else. No external dependencies. No skin assets. The page returns a proper <code>503 Service Unavailable</code> with a <code>Retry-After</code> header so search engines know to come back later, and a <code>noindex,nofollow</code> meta tag as a belt-and-suspenders for crawlers that ignore the status code. Your archive doesn't get deindexed during a maintenance window.</p>
                <p>If you're logged in, you see the normal site. This matters. It means you can check your work in a real browser, at full resolution, with real content, while the public sees the holding page. No preview toggle. No separate staging domain. You're looking at the same thing your visitors will see once you turn it back on.</p>
                <p>Turning it off is the same as turning it on: the toggle in Settings, a save, and the site is live again immediately.</p>
                <p>Alpha 0.7.168. Available through the in-admin updater.</p>
            </article>

            <!-- POST: BULK UPDATE FIXED -->
            <article class="post" id="bulk-update-fixed">
                <div class="post-meta">
                    <span class="post-date">May 13, 2026</span>
                    <span class="post-tag">Multisite</span>
                </div>
                <h2><a href="#bulk-update-fixed">UPDATE ALL BEHIND Actually Works Now</a></h2>
                <p>The UPDATE ALL BEHIND button has been on the fleet dashboard for a while. The description of what it does — instructs each out-of-date spoke to pull the latest release, verify the signature and checksum, apply migrations, and report back, all without you touching FTP or SSH — is accurate and has been accurate since it was written. What was also true, and less prominently advertised, is that it has not actually worked since 0.7.105.</p>
                <p>The endpoint the hub calls on each spoke to trigger a remote update is <code>multisite/updates/trigger</code>. It was introduced in 0.7.102 alongside the hub update push feature. In 0.7.105 — a masonry borders release, completely unrelated — the handler for that endpoint was silently dropped from the spoke-side API file. The hub kept calling it. The spokes kept returning 404. The update button sat there looking confident and doing nothing.</p>
                <p>0.7.115 restores the handler. The endpoint is back. The button now does what it says. If you have been on a multisite setup and have been manually FTP-ing updates to each spoke because the button never seemed to work, this is why, and it is fixed. UPDATE ALL BEHIND will now pull the latest release to every behind spoke in sequence, run migrations, and show you a tick or a cross for each one. One button. The whole fleet.</p>
                <p>Alpha 0.7.115. Available through the in-admin updater on any site running 0.7.x, or by updating the hub and using UPDATE ALL BEHIND to push it to the spokes — which, as of this version, will actually work.</p>
            </article>

            <!-- POST: FLEET INTELLIGENCE -->
            <article class="post" id="fleet-intelligence">
                <div class="post-meta">
                    <span class="post-date">May 12, 2026</span>
                    <span class="post-tag">Multisite</span>
                </div>
                <h2><a href="#fleet-intelligence">The Fleet Knows Its Numbers Now</a></h2>
                <p>Running a network of photo blogs is one thing. Knowing how it is actually performing — which images are pulling traffic, which sites are getting hammered by bots, which day last month was your best across the whole fleet — is another thing entirely. Until now Fleet Stats gave you aggregate views and unique visitors, a sparkline, and a referrer list. Useful, but thin. That changes in 0.7.105.</p>
                <p>The Fleet Stats rollup page now pulls enriched data from each spoke on every load. The summary tiles have expanded: alongside total views and unique visitors you now see <strong>bot views with a percentage</strong> (so you know how much of your traffic is actually people), <strong>average views per day</strong> (excluding zero-traffic days so an outage doesn't tank the average), and a <strong>peak day</strong> tile showing the single best day in the selected window and how many views it generated across the entire fleet. The old three tiles have become six.</p>
                <p>The new <strong>Most Viewed — Fleet Wide</strong> panel is the part that required the most work. Each spoke now queries its raw per-hit stats table and returns its top ten most-viewed images for the period, with titles, thumbnail URLs, and view counts. The hub collects these from every spoke, merges them with its own top images queried locally, sorts the combined pool by views, and renders a grid of image cards — each one linked directly to its live page on the spoke it came from. You can see at a glance which images in your network are resonating, regardless of which site posted them. The grid shows the top twelve across the fleet.</p>
                <p>The <strong>Network Breakdown</strong> table has two new columns. <strong>Bots</strong> shows each site's bot traffic as a percentage of its total traffic — if one spoke is sitting at 40% bot views while the others are in single digits, something on that server is attracting attention it shouldn't be. <strong>Top Image</strong> shows a small thumbnail and title of the most-viewed image on that site for the period, linking directly to it. No more clicking into each spoke individually to see what is working.</p>
                <p>Time windows now run from 7 days to all-time. The 6-month and 1-year options were added in the previous release; the all-time option removes the date filter entirely and returns every stat row in the database. On sites that have been running for a while this gives a full career view of what has been most popular — useful to look at occasionally, not something you need every day.</p>
                <p>Alpha 0.7.105. Available through the in-admin updater on any site running 0.7.x.</p>
            </article>

            <!-- POST: FLEET UPDATE -->
            <article class="post" id="fleet-update">
                <div class="post-meta">
                    <span class="post-date">May 10, 2026</span>
                    <span class="post-tag">Multisite</span>
                </div>
                <h2><a href="#fleet-update">The Fleet Updates Itself Now</a></h2>
                <p>The part of running a multisite network that nobody talks about is update day. You know a new version shipped. You have four sites. Each one needs to be logged into, backed up, updated through the admin, and verified. In sequence. Because running them all simultaneously and having two fail at once is how you spend a Saturday afternoon you didn't plan to spend that way. Update day is fine when everything goes smoothly and genuinely terrible when it doesn't.</p>
                <p>The hub now handles this. The fleet dashboard has always shown which spokes are behind on version. Now it does something about it. An UPDATE button sits next to each out-of-date spoke. Click it and the hub instructs that spoke to pull the latest release package, verify the Ed25519 cryptographic signature and SHA-256 checksum before extracting a single file, apply any pending database migrations in order, acquire and release the maintenance lock cleanly, and report back. The spoke does the work. The hub watches. The row shows live status — a spinner while it runs, a green tick and updated version number when it finishes, a red cross with the error text if it doesn't. UPDATE ALL BEHIND does the whole fleet in sequence with one click. No SSH. No FTP. No logging into each site individually.</p>
                <p>The same release ships a fix for blogroll sync that anyone running a multisite hub should apply promptly. The hub's My Blogs push — which maintains a network-wide blogroll section on every site in the fleet — now includes the hub itself alongside the spokes. Previously the hub omitted its own entry, which meant the hub's own blog was the one site in the network that visitors couldn't find from the blogroll. That is fixed. The hub pushes its own name, URL, and tagline alongside every active spoke. Run Blogroll Sync from the hub dashboard once after updating and it propagates to the whole network.</p>
                <p>One note on timing: the My Blogs section uses each spoke's site tagline as the description. The tagline is returned by the heartbeat that runs when the hub checks in with each spoke. If you have just updated and run the blogroll sync immediately, the taglines may be blank until the first heartbeat sweep completes. Let the hub run its scheduled heartbeat after updating — or trigger it manually from the multisite dashboard — before pushing the blogroll sync. After that, descriptions populate correctly and stay current automatically.</p>
                <p>Alpha 0.7.102. Available now through the in-admin updater on any site running 0.7.x.</p>
            </article>

            <!-- POST 1 -->
            <!-- POST: LOCKDOWN -->
            <article class="post" id="lockdown">
                <div class="post-meta">
                    <span class="post-date">April 29, 2026</span>
                    <span class="post-tag">Security</span>
                </div>
                <h2><a href="#lockdown">Bots Can't Find the Door If You Move It</a></h2>

                <p>The standard WordPress login URL is <code>/wp-login.php</code>. Every bot on the internet knows this. They scan every server they find, try the URL, and start spraying credentials. The attacks are not sophisticated. They do not need to be. They just need enough targets and enough time.</p>

                <p>SnapSmack's login does not live at a predictable path. You set the URL slug yourself — anything you want, under Configuration → Security. The default is <code>/snap-in</code>, but it could be anything. The actual <code>snap-in.php</code> file returns a 403 if accessed directly. The only way in is through the slug. Bots scanning <code>wp-login.php</code>, <code>admin</code>, <code>login</code>, and several hundred other common paths get nothing. No error page that confirms the software. No redirect. Nothing to work with.</p>

                <p>The second gate is the Probe Guard. A list of known scanner paths — <code>wp-login.php</code>, <code>xmlrpc.php</code>, <code>.env</code>, shell upload filenames, phpmyadmin variants, Git directory probes, SQL dump filenames, and others — is wired into the server routing layer. Any request for one of those paths is intercepted before it reaches any application code, the source IP is automatically banned for thirty days, and a 403 is returned with no body. There is no error message. There is no application fingerprint. The scanner learns nothing except that the request stopped.</p>

                <p>The third gate is User-Agent filtering. Every real browser identifies itself. Curl does not claim to be Chrome. Python-requests does not claim to be Safari. Any request that arrives at the login endpoint with a blank, scripted, or obviously automated User-Agent is dropped with a 403 before any authentication logic runs. They do not learn whether the credentials were wrong. They do not learn whether the page exists. The request just stops.</p>

                <p>The fourth gate is automatic IP banning on failed logins. Failed login attempts are counted per IP in a ten-minute window. Five failures means a seven-day ban. The IP is blocked at the start of every subsequent request — before the page renders, before credentials are checked. The counter resets when the ban is issued. The ban is noted in the database with a reason and an expiry time. You can review the list and lift individual bans early from the IP Shield tab in Troll Control if the system catches a real user who fat-fingered their password too many times.</p>

                <p>If you forget your own login slug, there is a recovery token. Put a long random string in Configuration → Security as the Recovery Token. If you need to find the login page again, visit <code>snap-in.php?key=TOKEN</code> and you will be redirected to the correct URL. The recovery path is disabled by default and is only active when you have set a token.</p>

                <p>None of this stops a determined, targeted attack from a human being who knows what they are looking at. What it stops is the automated, undifferentiated internet noise that hits every server constantly and compromises the ones that were not paying attention.</p>
            </article>

            <!-- POST: SMACKATTACK -->
            <article class="post" id="smackattack">
                <div class="post-meta">
                    <span class="post-date">April 22, 2026</span>
                    <span class="post-tag">Security</span>
                </div>
                <h2><a href="#smackattack">SMACKATTACK: The Reputation Network</a></h2>

                <p>A single SnapSmack install can ban someone. SMACKATTACK is what happens when all opted-in SnapSmack installs share that information — anonymously, automatically, and without any of the raw identifying data leaving your server.</p>

                <p>When a comment is banned on a participating site, a SHA-256 hash of the relevant fingerprint — browser signature, IP, or email — is reported to the SMACKATTACK hub. The hub maintains a running score for each fingerprint across all reporting sites, weighted by how reliable each site's reports have historically been and adjusted for time decay so that old reports matter less than recent ones. A fingerprint that a dozen sites have independently flagged in the last week scores very differently from one that a single site flagged eight months ago.</p>

                <p>The output is a colour-coded threat level. Green is fine. Yellow is a watch. Orange means the network has seen this before and you should look at it. Red means auto-ban is reasonable. Black means the network has extensive, recent, multi-site evidence and the default action is silence with no explanation to the commenter.</p>

                <p>SMACKATTACK also runs coordination detection. When multiple fingerprints score above threshold and their reports cluster temporally — the same wave of activity across multiple sites — the system flags it as a coordination event. This surfaces in the SMACKATTACK admin tab as a cluster, and you can inspect it, escalate individual fingerprints to the hub's shared registry, or dismiss it.</p>

                <p>The fourth layer — and the one that required building a separate analysis system — is GOBSMACKED. It does not use fingerprints or IPs at all. It builds a stylometric profile from comment text: a 25-dimension vector measuring sentence length, vocabulary range, punctuation patterns, the specific idioms someone reaches for. When a new comment arrives, its vector is compared against all prior submissions. A cosine similarity above 55% means this new account writes like an existing banned account, regardless of what IP they are coming from or what browser they are using.</p>

                <p>A VPN changes your IP. GOBSMACKED does not care. A new email address changes your identity. GOBSMACKED does not care. People write the way they write. This is not something most of them think to change, and changing it consistently under pressure is harder than it sounds.</p>

                <p>SMACKATTACK is opt-in. Join from Configuration → SMACKATTACK. You can opt out at any time, at which point your site is removed from the network and your contribution to the shared registry is marked for expiry. The hub stores hashes, not content. Your commenters' text never leaves your installation.</p>
            </article>

            <article class="post" id="spam">
                <div class="post-meta">
                    <span class="post-date">April 20, 2026</span>
                    <span class="post-tag">Security</span>
                </div>
                <h2><a href="#spam">The Spam Is Handled. Took Three Layers.</a></h2>

                <p>Somewhere along the way, during a settings page rebuild, the Akismet API key field quietly disappeared from the admin. Nobody noticed until the comment queue started filling up with the kind of enthusiastic pharmaceutical advertising that suggests the internet has given up on subtlety entirely. It's back now, and it has company.</p>

                <p>SnapSmack's anti-spam stack now runs three layers deep. First, Akismet — the battle-hardened cloud filter that has been reading spam for longer than most current web developers have been working. It has processed north of 800 billion spam comments at this point and is, to put it politely, very good at its job. The API key goes in under Admin → Settings → Global Comments, there is a test button so you know immediately whether it's working, and then you mostly forget it exists. That's the goal.</p>

                <p>Second layer: SnapSmack Shield. When a SnapSmack multisite installation bans a fingerprint — a browser signature, an IP hash, an email hash — that ban propagates automatically to every site in the network. Not the raw data. SHA-256 hashes only. No IP addresses leave your server. The hub maintains a central registry of consolidated hashes, distributes them to each spoke, and spokes block matching fingerprints silently before a comment is ever posted. It costs nothing extra and requires no configuration once multisite is running.</p>

                <p>Third layer, and the one that required the most engineering: SMACKATTACK. A distributed reputation network across all opted-in SnapSmack installations. Every site that opts in contributes anonymised fingerprint reports; the network scores each fingerprint by weighted site reputation, applies time decay so old reports fade, detects coordination clusters where the same bad actor is operating across multiple accounts, and issues a colour-coded threat level. Green is fine. Black means the network has seen this fingerprint at enough sites with enough frequency that auto-banning is entirely reasonable.</p>

                <p>One anti-spam layer is not enough. Anyone who has run a public comment section for more than fifteen minutes already knows this. Three layers might be more than strictly necessary. It probably isn't.</p>
            </article>

            <!-- POST 2 -->
            <article class="post" id="fleet">
                <div class="post-meta">
                    <span class="post-date">April 17, 2026</span>
                    <span class="post-tag">Multisite</span>
                </div>
                <h2><a href="#fleet">Running a Fleet Without Losing Your Mind</a></h2>

                <p>Managing three separate SnapSmack installations from three separate browser tabs, three separate logins, and three separate bookmark folders is the kind of administrative friction that makes you question every decision that led to running more than one site. The hub/spoke multisite architecture exists because that situation is deeply, unnecessarily stupid.</p>

                <p>The hub is a SnapSmack install that knows about all the others. Spokes register with a one-time token, exchange Bearer keys, and from that point forward the hub can see the entire fleet from a single dashboard: version, post count, pending comments, last backup, disk usage, whether anyone is online. When a spoke goes quiet the hub marks it offline and stops bothering it until it comes back.</p>

                <p>The useful parts go further than a status board. The hub can push posts from one site to any spoke — image, EXIF, title, all of it — which is how cross-posting a texture from foundtextures.ca to a secondary site takes thirty seconds instead of a morning. The blogroll sync pushes the hub's link list to all spokes in one button press. The fleet stats page rolls up traffic across all sites into a single view: combined daily sparkline, per-spoke share bars, top referrers across the whole network.</p>

                <p>The thing that gets used most often is the SSO drill-through. There is a REMOTE LOGIN button next to each spoke on the hub dashboard. Click it. The hub generates a one-time token, bounces the browser to the spoke with that token in the URL, and the spoke validates it, burns it, creates a session, and drops the admin into the spoke dashboard — already logged in. No username. No password. Five seconds. It sounds like a small thing. It is not a small thing.</p>

                <p>When a new version of SnapSmack ships, the hub knows which spokes are behind. The fleet dashboard shows each spoke's current version against the latest release, marks anything out of date, and puts an UPDATE button next to it. Clicking it tells the hub to instruct that spoke to download, verify, and apply the release package — cryptographic checksum and Ed25519 signature checked before a single file is extracted, migrations run automatically, maintenance lock acquired and released cleanly. The update happens on the spoke. The hub watches. Each row shows live progress: spinning while it runs, a version number change and a green tick when it's done, a red cross with the error if it fails. UPDATE ALL BEHIND does the whole fleet in sequence with a single click. No SSH. No FTP. No logging into each site individually. The hub does it.</p>

                <p>My Blogs takes the fleet in the other direction — outward instead of inward. Every site in your network gets a blogroll section called My Blogs, automatically maintained by the hub, listing every site in the fleet. The hub pushes its own entry plus every active spoke's name, URL, and tagline to each spoke's blogroll on demand. A visitor to any site in your network can see the whole family from there. One sync button on the hub. Done across all spokes simultaneously.</p>
            </article>

            <!-- POST 3 -->
            <article class="post" id="trolls">
                <div class="post-meta">
                    <span class="post-date">April 16, 2026</span>
                    <span class="post-tag">Security</span>
                </div>
                <h2><a href="#trolls">Every Troll Leaves a Trail</a></h2>

                <p>The obvious troll countermeasure is the IP ban. The IP ban works until the troll discovers VPNs, which takes approximately four minutes. Then you're banning a new IP every other day and achieving nothing except an ever-growing ban list and a gradually deteriorating mood.</p>

                <p>Browser fingerprinting goes further. A modern browser leaks a lot of information: screen resolution, installed fonts, canvas rendering characteristics, timezone, language preferences, hardware concurrency, GPU model. None of these are individually identifying. Combined, they produce a signature that is specific enough to distinguish one visitor from another across sessions even when the IP address changes. When someone is banned on SnapSmack, the ban includes their fingerprint hash. A VPN changes the IP. It does not change the browser.</p>

                <p>But fingerprints can be spoofed with enough effort. Which is why there is a third detection layer that does not depend on anything technical at all: semantic analysis. The system stores the text of every comment posted through a SnapSmack install and builds a TF-IDF vector from it — a mathematical representation of how someone writes. Their vocabulary, their idioms, the phrases they reach for, how they structure a sentence. When a new comment arrives, its vector is compared against all prior submissions. A cosine similarity above 55% means this new account writes like an existing banned account. The comment gets flagged before a human looks at it.</p>

                <p>A VPN does not change how you write. Neither does a new email address. People have characteristic patterns of expression that persist whether or not they think they're being clever. The system exploits this quietly and without announcement. There is also a keyword and phrase ban list, with exact match, substring match, and regex match, and two severity levels: flag for review or reject silently. Silent rejection tells the troll their comment posted successfully. It did not. This is considered appropriate.</p>
            </article>

            <!-- POST 4 -->
            <article class="post" id="backup">
                <div class="post-meta">
                    <span class="post-date">April 15, 2026</span>
                    <span class="post-tag">Tools</span>
                </div>
                <h2><a href="#backup">Your Photos Live Here. Permanently.</a></h2>

                <p>There is a mental model that treats a photo blog as a website. It is not. It is an archive. An archive that represents years of work, thousands of images, and decisions about what is worth keeping. The difference matters most when the server hosting provider sends an email with the subject line "Important: Your Account" and you realise you have no idea when you last backed anything up.</p>

                <p>Smack Up Your Backup — SUYB — is a Windows desktop application that solves this without requiring any familiarity with rsync, cron, or the FTP client you installed in 2019 and have not opened since. Connect it to a SnapSmack site, configure a Google Drive service account, and it pulls the full recovery kit, packages it into a versioned ZIP, and pushes it offsite. The recovery kit is a self-contained archive: all images, the MySQL dump, configuration. Everything needed to restore a complete installation to a different server from cold, with no data loss.</p>

                <p>SUYB also supports Backblaze B2 for object storage and can manage multiple profiles — one per site, each with its own cloud destination. The hub/spoke discovery feature connects to a hub install, finds every spoke in the network, and creates profiles for all of them automatically. Running a fleet backup then becomes a matter of running each profile in sequence rather than logging into every site individually.</p>

                <p>The photos on a hard drive are yours. Hard drives have a mean time between failures measured in years, which sounds fine until you think about how many years of photos are on there. Google Drive is backed by infrastructure that costs more to run per day than most photographers spend on gear in a career. The redundancy is someone else's problem. That is the correct arrangement.</p>
            </article>

            <!-- POST 5 -->
            <article class="post" id="ohsnap">
                <div class="post-meta">
                    <span class="post-date">April 14, 2026</span>
                    <span class="post-tag">Tools</span>
                </div>
                <h2><a href="#ohsnap">Design Your Skin Without Touching Code</a></h2>

                <p>There are people who can look at a CSS file and immediately understand what colour the background will be. There are also people — a much larger group — who want to move a slider and see what happens. Oh Snap! exists for the second group, and also for the first group on days when they would rather not.</p>

                <p>Oh Snap! is a desktop application for designing SnapSmack skins. Open it, connect it to a live SnapSmack site via API key, and it pulls the active skin's manifest, CSS, and CSS variable definitions and builds a control panel from them automatically. Colour pickers for background colours. Range sliders for typography sizes. Select inputs for layout options. Every change updates a live preview in an embedded browser frame — not a screenshot, not a mock, an actual rendering of the skin with real content pulled from the site. The preview shows three view modes: single post, archive grid, and landing page. Three viewport widths: desktop, tablet, mobile.</p>

                <p>There is an AI assistant built into the bottom of the application. Describe what you want in plain English — "warm charcoal background, amber accent colour, more breathing room between archive tiles" — and the assistant returns a set of CSS variable overrides which are applied directly to the preview. It supports Claude, Gemini, GPT-4o, and local Ollama models. The resulting overrides can be pushed to the live site immediately or exported as a CSS file.</p>

                <p>The push-to-site feature is the important one. Finishing a skin in Oh Snap! and clicking Push sends the CSS variable overrides to the live SnapSmack install, where they are stored in the database and injected after all other skin CSS. The change is live in seconds, without uploading a file, without touching the server, and without breaking anything — because variables are overrides, not rewrites. The skin's own defaults remain intact underneath.</p>
            </article>

            <!-- POST 6 -->
            <article class="post" id="gallery">
                <div class="post-meta">
                    <span class="post-date">April 13, 2026</span>
                    <span class="post-tag">Media</span>
                </div>
                <h2><a href="#gallery">Your Archive, Actually Managed</a></h2>

                <p>The old archive management page was a list. A long list. With a search box. On a site with fourteen hundred published posts, it was the kind of interface that makes you feel productive for approximately thirty seconds before you realise you have no practical way to find the image from three years ago that you half-remember had orange in it and was definitely a rust shot of some kind, probably from the rail yard, possibly from 2023.</p>

                <p>The Media Gallery replaces this with a proper digital asset manager: an AJAX-driven grid with lazy-loaded thumbnails, full-text search across titles, descriptions, and tags, and filter combinations for album, category, status, camera model, date range, and colour palette. Multiple images can be selected with rubber-band drag or keyboard shortcuts. Inline quick-edit opens a panel for updating title, status, tags, categories, and albums without leaving the grid. Bulk operations — publish, draft, assign category, assign album — apply to the whole selection at once.</p>

                <p>Alongside the gallery is a canvas-based photo editor accessible from any edit page. Non-destructive in the sense that it operates on the web copy and regenerates thumbnails rather than touching the original file. It handles the operations that come up constantly: crop with freeform or fixed aspect ratios, rotate, flip, brightness and contrast, sharpening, black and white conversion using the luminosity method. Full undo stack. Saves at full resolution. It is not Lightroom. It is not trying to be. It handles the things you should not need to open Lightroom for.</p>

                <p>EXIF copyright embedding was added at the same time: a pure PHP binary IFD0 writer, no external dependencies, that stamps the artist and copyright fields into every image uploaded through the web interface. The fields are configured once in Global Settings. After that it runs silently. If someone lifts an image and strips the filename, the EXIF data goes with it.</p>
            </article>

            <!-- POST 7 -->
            <article class="post" id="sybu">
                <div class="post-meta">
                    <span class="post-date">April 12, 2026</span>
                    <span class="post-tag">Tools</span>
                </div>
                <h2><a href="#sybu">The Posting Machine</a></h2>

                <p>Photographing textures is meditative. Uploading fourteen hundred of them one at a time through a web form is the opposite of meditative. It is the kind of task that makes a person briefly consider whether the photos needed to be on the internet at all and then, in a darker moment, whether anything needs to be on the internet at all. Smack Your Batch Up — SYBU — exists so this never has to happen again.</p>

                <p>SYBU is a Windows desktop application. It connects to a SnapSmack site via API key, reads a Google Drive folder, and processes each image in the queue: reads EXIF data for camera model, focal length, and date; sends the image to Google Gemini for a descriptive haiku-style title (SnapSmack's native title format — four lines, image-driven, no filler); uploads to Drive; creates the post on the blog. The entire pipeline runs unattended. Walking away while it works is the point.</p>

                <p>The AUDIT tab shows the state of the archive: how many posts have Drive links, how many are missing, how many share a title with another post. The REPAIR tab fixes what the audit finds. Rename Drive Files renames every Drive file to its post ID, giving the folder a stable, sortable naming scheme. Re-enrich Duplicate Titles downloads each flagged image from Drive, sends it back through Gemini with a uniqueness constraint, and updates the blog title. Backfill Missing Drive Links automatically searches Drive for files matching each post's title and saves the URL directly — no manual entry required if the file is there to be found.</p>

                <p>The tool exists because batch operations that used to mean a morning of repetitive clicking now mean starting a job before bed and finding it finished in the morning. Whether that frees up time for more photography or more paddleboarding is left as an exercise for the operator.</p>
            </article>

            <!-- POST 8 -->
            <article class="post" id="skins">
                <div class="post-meta">
                    <span class="post-date">April 10, 2026</span>
                    <span class="post-tag">Design</span>
                </div>
                <h2><a href="#skins">Make It Look Like Yours</a></h2>

                <p>The worst thing about most photo blogging platforms — after the algorithm, after the ads, after the platform's unilateral right to delete your account — is that every site using the same theme looks identical. The default skin is the default skin. A thousand photographers, one aesthetic. It signals nothing about the work itself.</p>

                <p>SnapSmack's skin system is built around the idea that a skin is a complete, self-contained design: its own CSS, its own layout templates, its own options. A skin manifest declares what it can do — colour variables, typography options, layout modes, feature flags — and the admin exposes only those controls, compiled into a CSS blob that is injected after the skin's own stylesheet. No skin CSS is in the core. No core CSS is in the skins. The boundary is real and enforced.</p>

                <p>The skin gallery ships skins as signed packages distributed from Smack Central. Installing a skin is a download and a click. Removing it doesn't break anything; the core falls back gracefully. The base release includes two skins: 50 Shades of Noah Grey, a dark editorial skin built around a specific grey palette, and New Horizon, a clean light skin with strong typographic structure. The gallery currently holds another seven stable and beta skins, with more in development.</p>

                <p>The archive layout is independently configurable from the skin: square crop, letterboxed crop, or masonry flow. Visitors can toggle between modes if the site owner enables multiple. The calendar engine — an opt-in sidebar panel with month navigation and recent posts, declared in the skin manifest — overlays any layout without touching the skin itself. The skin controls how the site looks. The owner controls how the archive works. They are different concerns and they are correctly separated.</p>
            </article>

            <!-- POST 9 -->
            <article class="post" id="modes">
                <div class="post-meta">
                    <span class="post-date">April 8, 2026</span>
                    <span class="post-tag">Admin</span>
                </div>
                <h2><a href="#modes">Simple First, Everything Later</a></h2>

                <p>The full SnapSmack admin is a lot. There are pages for skin configuration, CSS overrides, script injection, static page appearance, archive appearance, solo image appearance, multisite management, backup configuration, troll control, API keys, and a forum. Presenting all of that to someone who has just installed SnapSmack for the first time and wants to post a photo is an error in judgment.</p>

                <p>The admin now starts in Big Wheel mode. Big Wheel shows the essentials: Dashboard, New Post, Manage Archive, Categories, Signals, and the help and settings sections. That is the complete list of things a new user needs for the first hundred posts. Everything else — the skin tooling, the custom CSS editor, the script injector, the multisite management, the full security stack — is hidden until it is needed.</p>

                <p>At 100 published posts, the dashboard shows an offer card explaining Pimpmobile mode and what it unlocks. Accepting switches the admin immediately. Declining defers it; the offer comes back every 100 posts thereafter. After three declines it moves to every 200 posts. After the second decline at that cadence, a permanent "Leave Me Alone" option appears. The mode is also manually toggleable at any time from the bottom of the sidebar, in both directions, with no migration and no consequences. Big Wheel and Pimpmobile use the same database, the same settings, the same everything. The only difference is what the sidebar shows.</p>

                <p>The reasoning behind the unlock threshold is straightforward: 100 posts means the site is real. The person running it has committed. They have been through the process enough times to know what the workflow is and what is missing from it. That is the right moment to say "here is a lot more you can do with this." Not before.</p>
            </article>

            <!-- POST 10 -->
            <article class="post" id="roadmap">
                <div class="post-meta">
                    <span class="post-date">April 7, 2026</span>
                    <span class="post-tag">Roadmap</span>
                </div>
                <h2><a href="#roadmap">What Comes Next</a></h2>
                <p>There are three ways to share photos on the internet and SnapSmack currently does one of them. A single image: title, haiku description, download link, EXIF data, one photograph that stands on its own. That is the original SnapSmack use case and it will always be the core one. But it is not the only one.</p>
                <p>SMACKEDAROUND is carousel posting mode. A set of related images — the morning's shoot, a series that only makes sense as a group, a before-and-after — presented together as a single post with navigation between frames. Different database structure, different editor, different skin requirements, different archive layout. Incompatible with single-image mode, which is why it installs separately. At six megabytes, running it in a subdirectory alongside a standard SnapSmack install is not a significant imposition. Both can share a domain.</p>
                <p>SMACKTALK is long-form photo essay mode. This is the WordPress replacement. Not a content management system in the general sense, not a blogging platform that happens to support images — a tool for writing essays where the photographs and the words are equals, where placement, sizing, and caption matter as much as the image itself. The editor handles this. The skin system is designed for it. It will be in beta for a while, possibly a long while, and the beta warning will not be gentle about the implications of running beta software on a production site. The people who need it most are already comfortable with that tradeoff.</p>
                <p>All three modes share the same security stack, the same backup tooling, the same multisite architecture, and the same skin engine — with skins declaring which modes they support. The work done to make SnapSmack stable and defensible carries forward. What changes is what gets posted, and how.</p>
                <p>That is the plan. Plans change. The important thing is that the work is not going backward toward any platform that serves ads between someone's photographs, decides algorithmically who sees them, and reserves the right to delete the entire account if the wrong word appears in the wrong post at the wrong moment. That direction is closed. The only direction is forward and self-hosted.</p>
            </article>
        </div>
    </section>
</main>
<!-- FOOTER -->

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
