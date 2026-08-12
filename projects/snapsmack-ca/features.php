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
.working-now { background: var(--light-grey); border-top: 1px solid var(--border); }
.working-head { max-width: 800px; margin-bottom: 34px; }
.working-head h2 { margin-bottom: 12px; }
.working-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1px; background: var(--border); border: 1px solid var(--border); }
.working-card { min-width: 0; padding: 22px; background: var(--white); }
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
        <div class="wrap feature-groups">
            <article class="feature-group">
                <h2>Publish your way</h2>
                <ul>
                    <li><strong>SMACKONEOUT</strong> for a single photograph with room to breathe.</li>
                    <li><strong>GRAMOFSMACK</strong> for multi-image posts, carousels, panoramas, and a curated grid.</li>
                    <li><strong>SMACKTALK</strong> for longform writing with photographs inside the story.</li>
                    <li>Albums, collections, static pages, categories, tags, EXIF, captions, and high-resolution download links.</li>
                </ul>
            </article>
            <article class="feature-group">
                <h2>Make it yours</h2>
                <ul>
                    <li>Production-ready skins with their own layout and appearance controls.</li>
                    <li>Colour, type, spacing, texture, and layout options without editing CSS.</li>
                    <li>A manifest and shared-library system instead of a pile of competing plugins.</li>
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
                <article class="working-card"><h3>GRAMOFSMACK</h3><p>Classic three-across publishing with ordered grids, multi-image carousels, cover selection, panorama rows, and PHOTOGRAM on phones.</p></article>
                <article class="working-card"><h3>SMACKTALK + MOSAIC</h3><p>Longform photo essays with headings, inline Gallery images, covers, captions, and justified MOSAIC panels woven through the writing.</p></article>
                <article class="working-card"><h3>Light Table</h3><p>A full-screen browser workbench for sorting photographs into albums, categories, and collections by drag and drop.</p></article>

                <article class="working-card"><h3>Media Gallery + Photo Editor</h3><p>Visual archive browsing, bulk organization, reusable image picking, plus non-destructive web-copy crop, rotate, brightness, contrast, and sharpening.</p></article>
                <article class="working-card"><h3>Skin System</h3><p>Manifest-driven skins with shared CMS engines, scoped settings, colour and typography controls, light/dark palettes, and no plugin dependency pile.</p></article>
                <article class="working-card"><h3>Albums, Collections + Pages</h3><p>Date archives, curated albums and collections, static pages, blogrolls, shortcodes, slideshows, RSS, and downloadable originals.</p></article>
                <article class="working-card"><h3>Traffic Stats + SCROLL TIME</h3><p>Cookie-free visits, per-image views, referrers, bot filtering, local country resolution, feed engagement, and fleet-wide rollups.</p></article>

                <article class="working-card"><h3>SMACKVERSE</h3><p>Two-way ActivityPub: Pixelfed-compatible profiles, follows, likes, boosts, replies, discovery, and interaction from the SnapSmack admin.</p></article>
                <article class="working-card"><h3>Local Community</h3><p>Accounts, comments, reactions, follows, direct messages, moderation queues, keyword controls, and anti-spam filtering.</p></article>
                <article class="working-card"><h3>Multisite</h3><p>Hub-and-spoke monitoring, SSO drill-through, aggregated statistics and comments, cross-posting, fleet backups, fleet updates, and automatic <strong>My Blogs</strong> blogrolls.</p></article>
                <article class="working-card"><h3>Support Forum</h3><p>Support built inside authenticated SnapSmack admin rather than exposed at a public URL for bots and drive-by spam.</p></article>

                <article class="working-card"><h3>SMACKBACK + Break the Glass</h3><p>File-integrity monitoring, breach lockdown, clean-file recovery, network alerts, and a signed one-use recovery card for total account lockout.</p></article>
                <article class="working-card"><h3>SMACK DAB through SNAP DECISION</h3><p>Fingerprint bans, fleet and community reputation, stylometric evasion checks, IP SMACKER login protection, mandatory 2FA, and signed releases.</p></article>
                <article class="working-card"><h3>Installer, Updates + Help</h3><p>Guided installation, canonical schema synchronization, cryptographically verified updates, rollback support, maintenance mode, and contextual built-in help.</p></article>
                <article class="working-card"><h3>SEO + Crawler Policy</h3><p>Sitemaps, Open Graph, structured metadata, IndieWeb links, configurable robots and AI-training policy, <code>llms.txt</code>, and <code>security.txt</code>.</p></article>

                <article class="working-card"><h3>Desktop Workflow</h3><p><strong>Smack Your Batch Up</strong> handles batch posting, <strong>Smack Up Your Backup</strong> protects the archive, and <strong>The Unzucker</strong> and <strong>FLKR FCKR</strong> bring Instagram and Flickr collections home. The full app catalogue lives in BOX O' TRICKS.</p></article>
            </div>
            <p style="margin-top:28px;"><a href="tools.php"><strong>See the desktop tools &rarr;</strong></a> &nbsp; <a href="buzzers.php"><strong>See the security layers &rarr;</strong></a></p>
        </div>
    </section>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
