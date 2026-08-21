<?php
/**
 * SNAPSMACK.CA - THE GOODS
 * A readable product overview. Detailed catalogues live on Skins and Tools.
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

$page_title       = 'THE GOODS! - SnapSmack Features';
$page_description = 'What SnapSmack does: independent photo publishing, flexible post formats, skins, federation, migration, backup, and recovery.';
$page_og_url      = 'https://snapsmack.ca/features.php';
$nav_active       = 'goods';

$page_css = <<<'CSS'
.goods-intro { max-width: 760px; }
.feature-groups { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1px; background: var(--border); border: 1px solid var(--border); }
.feature-group { background: var(--white); padding: 32px; }
.feature-group h2 { font-size: 1.25rem; margin-bottom: 14px; }
.feature-group ul { margin: 0 0 0 1.2em; }
.feature-group li { margin-bottom: 0.65em; }
.feature-group li:last-child { margin-bottom: 0; }
.feature-proof { background: var(--black); color: #ddd; }
.feature-proof h2 { color: var(--white); }
.feature-proof strong { color: var(--white); }
.architecture-story { border-top: 1px solid var(--border); }
.architecture-story .story-copy { max-width: 820px; }
.architecture-story .story-copy > p { max-width: 72ch; margin-bottom: 1.35em; }
.architecture-story .story-copy > p:last-child { margin-bottom: 0; }
.working-now { background: var(--light-grey); border-top: 1px solid var(--border); }
.working-head { max-width: 800px; margin-bottom: 34px; }
.working-head h2 { margin-bottom: 12px; }
.working-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1px; background: var(--border); border: 1px solid var(--border); }
.working-card { min-width: 0; padding: 22px; background: var(--white); }
.working-card--full { grid-column: 1 / -1; }
.working-card h3 { margin-bottom: 9px; font-size: .93rem; }
.working-card p { margin: 0; color: var(--mid-grey); font-size: .83rem; line-height: 1.55; }
.working-card strong { color: var(--black); }
@media (max-width: 980px) { .working-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 760px) { .feature-groups, .working-grid { grid-template-columns: 1fr; } }
CSS;

require_once __DIR__ . '/includes/header.php';
?>

<main>
    <section class="page-header">
        <div class="wrap">
            <p class="site-discovery-kicker">THE GOODS!</p>
            <h1>Everything it does.<br><span>Without the wall of text.</span></h1>
            <p class="lede goods-intro">SnapSmack is a self-hosted photography publishing system: the website, the archive, the social plumbing, and the tools needed to get your work in and back out again.</p>
        </div>
    </section>

    <div class="wrap" style="padding-top:32px;">
        <nav class="goods-nav" aria-label="The Goods">
            <a class="active" href="features.php"><strong>THE GOODS!</strong><span>What SnapSmack actually does.</span></a>
            <a href="skins.php"><strong>GLAD RAGS!</strong><span>Skins: different sites, same dependable engine.</span></a>
            <a href="tools.php"><strong>BOX O' TRICKS!</strong><span>Companion apps for migration, posting, and backup.</span></a>
        </nav>
    </div>

    <section>
        <div class="wrap">
            <div class="feature-groups">
            <article class="feature-group">
                <h2>Publish your way</h2>
                <ul>
                    <li><strong>SMACKONEOUT</strong> for a single photograph with room to breathe.</li>
                    <li><strong>GRAMOFSMACK</strong> for multi-image posts, carousels, panoramas, a curated grid, and working phone publishing through either its installable PWA or <a href="https://github.com/Daniebeler/pixelix" target="_blank" rel="noopener">Pixelix</a>, a native Pixelfed client.</li>
                    <li><strong>SMACKTALK</strong> for longform writing with photographs inside the story.</li>
                    <li>Albums, collections, static pages, categories, tags, EXIF, captions, and high-resolution download links.</li>
                </ul>
            </article>
            <article class="feature-group">
                <h2>Make it yours</h2>
                <ul>
                    <li>Production-ready skins with their own layout and appearance controls.</li>
                    <li>Colour, type, spacing, texture, and layout options without editing CSS.</li>
                    <li><strong>Skins declare; the CMS delivers.</strong> A skin manifest lists the approved layouts, controls, fonts, and effects it needs. The shared CMS library supplies the reviewed executable engines, so skins do not carry arbitrary JavaScript or their own plugin pile.</li>
                    <li>Your domain, files, database, and archive remain under your control.</li>
                </ul>
            </article>
            <article class="feature-group">
                <h2>Leave the platforms</h2>
                <ul>
                    <li>Import Instagram and Flickr archives while preserving original dates and metadata.</li>
                    <li>RSS, IndieWeb ownership, POSSE syndication, and ActivityPub federation.</li>
                    <li>Community reactions, comments, following, and cross-site discovery.</li>
                    <li>Chronological publishing without an algorithm deciding who gets to see it.</li>
                </ul>
            </article>
            <article class="feature-group">
                <h2>Run the whole thing</h2>
                <ul>
                    <li>Guided installation, updates, maintenance mode, and built-in help.</li>
                    <li>Multisite management, fleet updates, comment moderation, and privacy-first statistics.</li>
                    <li>THE HUB launches the desktop suite from one place, with shared local profiles, protected credentials, libraries, and prompts.</li>
                    <li>Desktop batch posting, migration, backup, audit, and recovery tools.</li>
                    <li>Optional AI writing and metadata assistance, disclosed and under your control.</li>
                </ul>
            </article>
            <article class="feature-group feature-proof">
                <h2>Security that admits the internet exists</h2>
                <p>Two-factor authentication, file-integrity monitoring, breach lockdown, recovery tooling, and public reporting of closed security audits.</p>
                <p><a href="buzzers.php"><strong>Read the security audits &rarr;</strong></a></p>
            </article>
            <article class="feature-group feature-proof">
                <h2>Free means free</h2>
                <p>No subscription, premium tier, advertising network, or hostage situation. The CMS, skins, and companion tools are free.</p>
                <p><a href="brass-tacks.php"><strong>Read the FAQ &rarr;</strong></a></p>
            </article>
            </div>
        </div>
    </section>

    <section class="architecture-story" id="why-built-this-way">
        <div class="wrap">
            <div class="story-copy">
                <p class="site-discovery-kicker">BUILT ON A HISTORY WORTH PRESERVING</p>
                <h2>Architecture that refuses the usual answer</h2>
                <p>SNAPSMACK did not appear from nowhere. It follows a trail broken by earlier photoblogging projects and the people who sustained them, often with too little help or recognition. Preserving that history means acknowledging their work, learning from what they endured, and carrying the best of their ideas forward.</p>
                <p><strong><a href="https://bsky.app/profile/thatnoahgrey.bsky.social" target="_blank" rel="noopener">Noah Grey</a> comes first.</strong> He inspired me as a photographer, a creator, and a person. He is senpai. I am kohai. SnapSmack is the kohai&rsquo;s offering. His photographs and his work on <a href="https://en.wikipedia.org/wiki/Greymatter_(software)" target="_blank" rel="noopener">Greymatter</a> showed me that publishing software could be personal, independent, generous, strange, and unmistakably human.</p>
                <p><strong>No arbitrary plugins.</strong> In SnapSmack, a <em>skin manifest</em> is not executable code. It is a validated, declarative shopping list describing the skin and naming the approved layouts, controls, fonts, and effects it wants. The CMS then supplies those capabilities from its own shared, reviewed library. A skin can ask for the masonry engine or a film effect; it cannot arrive carrying an unknown script and run it.</p>
                <p>This keeps design separate from machinery. The same library engine can serve many radically different skins, and a security or compatibility repair made there reaches every skin that declared it. Removing a skin removes its presentation without leaving an abandoned plugin behind. The idea owes a debt to b2evolution's resistance to plugin hell; SNAPSMACK takes the boundary further.</p>
                <p><strong>Support without a public forum surface.</strong> The support forum lives inside authenticated SNAPSMACK administration. Operators can ask for help from the software itself, while the usual public registration, login, and posting endpoints are not left outside for bots and drive-by spammers.</p>
                <p>This decision owes something to <a href="https://github.com/pixelpost/pixelpost/wiki">Jay Williams' candid account of the attempted Pixelpost rewrite</a>. Jay deserves enormous credit: he wrote most of the code that moved Pixelpost from version 1.5 to 1.6, contributed substantially to the rewrite, and spent countless hours helping its users. He deserves far more recognition for that work than he received. SNAPSMACK stands in the shadow of that work.</p>
                <p>As Pixelpost's last active developer and moderator, Jay described automatic spam across the forum and blog as nearly impossible for one person to clean up. He was equally clear that the rewrite was put on hold for broader reasons: too few developers, too little available time, and the difficulty of maintaining the site and finishing the software without the collaboration the project needed. That was not a failure of effort. SNAPSMACK's internal forum applies one practical lesson from his experience: public support infrastructure should not be allowed to consume the limited time available to maintain the software itself.</p>
                <p><strong>A deliberately layered security system.</strong> Authentication, authorization, abuse prevention, signed distribution, integrity monitoring, breach containment, recovery, fleet intelligence, and public audit closure reinforce one another rather than operating as isolated features. GOBSMACKED adds local stylometric ban-evasion detection to the privacy-preserving federated troll-reputation system.</p>
                <p>None of those ingredients is being claimed as an invention. What is unusual is the approach: putting all the fixings on the burger and bringing the coordinated architecture and security depth of paid software to freeware built for a small community.</p>
                <p><a href="index.php#security"><strong>See the eight-layer security stack &rarr;</strong></a> &nbsp; <a href="buzzers.php"><strong>Read the closed security audits &rarr;</strong></a></p>
            </div>
        </div>
    </section>

    <section class="working-now" id="working-now">
        <div class="wrap">
            <header class="working-head">
                <p class="site-discovery-kicker">SHIPPING, NOT PROMISED</p>
                <h2>Working Right Now</h2>
                <p class="lede">Specific features and tools already doing the job on real SnapSmack sites.</p>
            </header>
            <div class="working-grid">
                <article class="working-card"><h3>SMACKONEOUT</h3><p>One photograph per post, chronological navigation, drafts, scheduling, EXIF, tags, categories, albums, collections, and downloads.</p></article>
                <article class="working-card"><h3>GRAMOFSMACK</h3><p>Classic three-across publishing with ordered grids, multi-image carousels, cover selection, panorama rows, a touch-friendly installable PWA, and tested direct Pixelix posting through owner-approved OAuth and the normal two-factor login.</p></article>
                <article class="working-card"><h3>SMACKTALK + MOSAIC</h3><p>Longform photo essays with headings, inline Gallery images, covers, captions, and justified MOSAIC panels woven through the writing.</p></article>
                <article class="working-card"><h3>Light Table</h3><p>A full-screen browser workbench for sorting photographs into albums, categories, and collections by drag and drop.</p></article>

                <article class="working-card"><h3>Media Gallery + Photo Editor</h3><p>Visual archive browsing, bulk organization, reusable image picking, plus non-destructive web-copy crop, rotate, brightness, contrast, and sharpening.</p></article>
                <article class="working-card"><h3>Skin System</h3><p>Manifest-driven skins with shared CMS engines, scoped settings, colour and typography controls, light/dark palettes, and no plugin dependency pile.</p></article>
                <article class="working-card"><h3>Albums, Collections + Pages</h3><p>Date archives, curated albums and collections, static pages, blogrolls, shortcodes, slideshows, RSS, and downloadable originals.</p></article>
                <article class="working-card"><h3>Traffic Stats + SCROLL TIME</h3><p>Cookie-free visits, per-image views, referrers, bot filtering, local country resolution, feed engagement, and fleet-wide rollups.</p></article>

                <article class="working-card"><h3>The Fediverse</h3><p>Two-way ActivityPub: Pixelfed-compatible profiles, follows, likes, boosts, replies, discovery, and interaction from the SnapSmack admin.</p></article>
                <article class="working-card"><h3>Local Community</h3><p>Accounts, comments, reactions, follows, direct messages, moderation queues, keyword controls, and anti-spam filtering.</p></article>
                <article class="working-card"><h3>Multisite</h3><p>Hub-and-spoke monitoring, SSO drill-through, aggregated statistics and comments, cross-posting, fleet backups, fleet updates, and automatic <strong>My Blogs</strong> blogrolls.</p></article>
                <article class="working-card"><h3>Support Forum</h3><p>Support built inside authenticated SnapSmack admin rather than exposed at a public URL for bots and drive-by spam.</p></article>

                <article class="working-card"><h3>SMACKBACK + Break the Glass</h3><p>File-integrity monitoring, breach lockdown, clean-file recovery, network alerts, and a signed one-use recovery card for total account lockout.</p></article>
                <article class="working-card"><h3>SMACK DAB through SNAP DECISION</h3><p>Fingerprint bans, fleet and community reputation, stylometric evasion checks, IP SMACKER login protection, mandatory 2FA, and signed releases.</p></article>
                <article class="working-card"><h3>Installer, Updates + Help</h3><p>Guided installation, canonical schema synchronization, cryptographically verified updates, rollback support, maintenance mode, and contextual built-in help.</p></article>
                <article class="working-card"><h3>SEO + Crawler Policy</h3><p>Sitemaps, Open Graph, structured metadata, IndieWeb links, configurable robots and AI-training policy, <code>llms.txt</code>, and <code>security.txt</code>.</p></article>

                <article class="working-card"><h3>SMACK YOUR BATCH UP</h3><p>Batch-process photographs, preserve their metadata, and publish complete posts to SNAPSMACK from the desktop.</p></article>
                <article class="working-card"><h3>SMACK UP YOUR BACKUP</h3><p>Build complete recovery archives and move them offsite, with support for multiple sites and fleet discovery.</p></article>
                <article class="working-card"><h3>THE UNZUCKER</h3><p>Bring an Instagram export home with original dates, captions, hashtags, carousels, and grid order intact.</p></article>
                <article class="working-card"><h3>FLKR FCKR</h3><p>Import a Flickr archive with its photographs, titles, descriptions, tags, and original upload dates preserved.</p></article>
                <article class="working-card working-card--full"><h3>GET YOUR SHIT SORTED</h3><p>Sort and edit a SNAPSMACK photograph library from the desktop, work from a synchronized offline copy, repair missing titles, captions, ALT text, tags, categories, albums and colour metadata, reorder SMACKONEOUT or GRAMOFSMACK feeds, and assemble GRAMOFSMACK carousels without wrestling the archive through a browser.</p></article>
            </div>
            <p style="margin-top:28px;"><a href="tools.php"><strong>See the desktop tools &rarr;</strong></a> &nbsp; <a href="buzzers.php"><strong>See the security layers &rarr;</strong></a></p>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
