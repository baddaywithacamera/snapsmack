<?php
/**
 * SNAPSMACK.CA - BOX O' TRICKS (desktop suite hub)
 * One card per tool, grouped by the job it does for your site. Each card
 * links to its own page (tool-*.php, shared layout includes/tool-page.php).
 * (Rebuilt 2026-09-12 — was one long page with SNAP SLAPPER's whole story
 * on it and COLD SNAP missing entirely.)
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
$page_title       = 'BOX O\' TRICKS! - The SnapSmack Desktop Suite';
$page_description = 'Free Windows and Linux desktop tools for SnapSmack: a non-destructive photo editor, offline composer, batch publisher, archive sorter, importers for Instagram, Flickr and WordPress, backup, and fleet management.';
$page_og_url      = 'https://snapsmack.ca/tools.php';
$nav_active       = 'goods-tools';

$page_css = <<<'CSS'
.tools-intro { max-width: 780px; }
.tool-group { scroll-margin-top: 80px; }
.tool-group + .tool-group { border-top: 1px solid var(--border); }
.tool-group-head { max-width: 780px; margin-bottom: 28px; }
.tool-group-head h2 { margin-bottom: 8px; }
.tool-group-head p { color: var(--mid-grey); }
.tool-cards { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 24px; }
.tool-card { display: grid; grid-template-rows: auto 1fr; border: 1px solid var(--border); background: var(--white); color: var(--dark-grey); }
.tool-card:hover, .tool-card:focus-visible { text-decoration: none; color: var(--dark-grey); box-shadow: inset 0 -5px 0 var(--red); }
.tool-card-shot { aspect-ratio: 16 / 9; overflow: hidden; background: var(--black); border-bottom: 1px solid var(--border); }
.tool-card-shot img { width: 100%; height: 100%; object-fit: cover; object-position: top; }
.tool-card-shot--none { display: grid; place-content: center; text-align: center; background: #1a1a1a; color: #666; font: 900 1.6rem/1.1 Arial Black, Arial, sans-serif; letter-spacing: -.02em; text-transform: uppercase; padding: 20px; }
.tool-card-shot--none small { display: block; margin-top: 10px; color: #444; font: .62rem/1 "Courier New", monospace; letter-spacing: .1em; text-transform: uppercase; }
.tool-card-copy { padding: 20px 22px 24px; }
.tool-card-copy .meta { display: flex; flex-wrap: wrap; gap: 8px 12px; align-items: center; margin-bottom: 8px; }
.tool-card-copy .platform { color: var(--mid-grey); font: 700 .66rem/1.2 'Courier New', monospace; text-transform: uppercase; letter-spacing: .08em; }
.tool-card-copy .status { padding: 4px 8px; background: var(--black); color: var(--white); font: 700 .62rem/1 Arial, sans-serif; text-transform: uppercase; }
.tool-card-copy .status--beta { background: var(--red); }
.tool-card-copy h3 { margin-bottom: 8px; color: var(--black); font-size: 1.05rem; }
.tool-card-copy h3::after { content: " \2192"; color: var(--red); }
.tool-card-copy p { margin: 0; font-size: .9rem; line-height: 1.55; }
.workshop { border-top: 8px solid var(--black); background: #f4f1eb; }
.workshop-list { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1px; margin-top: 26px; background: var(--border); border: 1px solid var(--border); }
.workshop-list a { display: block; padding: 18px 20px; background: var(--white); color: var(--black); }
.workshop-list a strong { display: block; font: 900 .85rem/1.2 Arial Black, Arial, sans-serif; text-transform: uppercase; }
.workshop-list a span { display: block; margin-top: 5px; color: var(--mid-grey); font-size: .82rem; line-height: 1.45; }
.workshop-list a:hover { background: #fff5f3; text-decoration: none; box-shadow: inset 0 -4px 0 var(--red); }
.suite-band { padding: 22px 26px; margin-top: 34px; border-left: 5px solid var(--red); background: var(--black); color: var(--white); font: 900 clamp(1rem, 1.9vw, 1.25rem)/1.4 Arial Black, Arial, sans-serif; }
@media (max-width: 850px) { .workshop-list { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 700px) { .tool-cards, .workshop-list { grid-template-columns: 1fr; } }
CSS;

require_once __DIR__ . '/includes/header.php';

function ss_tool_card(string $href, string $name, string $platform, string $status, string $line, ?string $shot, string $alt = ''): string {
    $beta = (stripos($status, 'beta') !== false) ? ' status--beta' : '';
    $img  = $shot
        ? '<div class="tool-card-shot"><img src="img/' . htmlspecialchars($shot) . '" alt="' . htmlspecialchars($alt) . '" width="1920" height="1080" loading="lazy"></div>'
        : '<div class="tool-card-shot tool-card-shot--none" aria-hidden="true">' . '<span>' . htmlspecialchars($name) . '</span><small>screenshot coming</small>' . '</div>';
    return '<a class="tool-card" href="' . $href . '">' . $img .
        '<div class="tool-card-copy"><p class="meta"><span class="platform">' . htmlspecialchars($platform) . '</span><span class="status' . $beta . '">' . htmlspecialchars($status) . '</span></p>' .
        '<h3>' . htmlspecialchars($name) . '</h3><p>' . $line . '</p></div></a>';
}
?>
<main>
    <section class="page-header">
        <div class="wrap">
            <p class="site-discovery-kicker">BOX O' TRICKS!</p>
            <h1>The heavy lifting<br><span>happens on your desk.</span></h1>
            <p class="lede tools-intro">Free Windows and Linux tools for the jobs that are too large, too slow, or too important to trust to one browser tab on a shared host. Your photographs never go through anyone&rsquo;s cloud to get to your own site.</p>
        </div>
    </section>

    <div class="wrap goods-nav-wrap">
        <nav class="goods-nav" aria-label="The Goods">
            <a href="features.php"><strong>THE GOODS!</strong><span>What SnapSmack actually does.</span></a>
            <a href="skins.php"><strong>GLAD RAGS!</strong><span>Skins: different sites, same dependable engine.</span></a>
            <a class="active" href="tools.php"><strong>BOX O' TRICKS!</strong><span>The free desktop suite.</span></a>
        </nav>
    </div>

    <section class="tool-group" id="make">
        <div class="wrap">
            <div class="tool-group-head">
                <p class="site-discovery-kicker">Make</p>
                <h2>Edit and compose</h2>
                <p>The photograph and the words, finished on your machine before they go anywhere.</p>
            </div>
            <div class="tool-cards">
                <?php echo ss_tool_card('tool-snap-slapper.php', 'SNAP SLAPPER', 'Windows / Linux', 'Closed beta', 'A private photo library and a real non-destructive editor. Normal mode for most photographs, Advanced mode when one needs the whole bench. LEWKS you can see, change, and keep.', 'snapslapper-editor-norm.png', 'SNAP SLAPPER editor in Normal mode'); ?>
                <?php echo ss_tool_card('tool-cold-snap.php', 'COLD SNAP', 'Windows / Linux', 'Shipping', 'Write posts and build BIGGIE photo essays with no connection, from a local cold-storage copy of your site. Sync when the internet comes back.', null); ?>
            </div>
        </div>
    </section>

    <section class="tool-group" id="publish">
        <div class="wrap">
            <div class="tool-group-head">
                <p class="site-discovery-kicker">Publish &amp; sort</p>
                <h2>Get a shoot onto the site, and keep the archive straight</h2>
                <p>Batch work belongs on a desktop, not in forty browser tabs.</p>
            </div>
            <div class="tool-cards">
                <?php echo ss_tool_card('tool-sybu.php', 'SMACK YOUR BATCH UP', 'Windows / Linux', 'Shipping', 'Load a shoot, reorder it, assign categories and albums, keep the EXIF copyright, and publish the entire batch. Optional AI writes the captions and tags while the queue runs.', 'sybu-uploading.png', 'Smack Your Batch Up publishing a batch of photographs'); ?>
                <?php echo ss_tool_card('tool-gyss.php', 'GET YOUR SHIT SORTED', 'Windows / Linux', 'Shipping', 'An offline sorter for a whole blog. Repair missing titles, captions, alt text, tags and albums; reorder the feed; build carousels. Then push it back.', null); ?>
            </div>
        </div>
    </section>

    <section class="tool-group" id="get-in">
        <div class="wrap">
            <div class="tool-group-head">
                <p class="site-discovery-kicker">Get in</p>
                <h2>Bring your old sites home</h2>
                <p>Dates, captions, tags, and order survive the trip. Fifteen years of work should still read as fifteen years.</p>
            </div>
            <div class="tool-cards">
                <?php echo ss_tool_card('tool-unzucker.php', 'THE UNZUCKER', 'Windows / Linux', 'Shipping', 'Instagram export in, classic three-across grid out. Images, captions, hashtags, carousels, and original dates intact. Arrange the grid, lock the panoramas, walk away.', 'unzucker-gridsorter.png', 'The Unzucker arranging an imported Instagram grid'); ?>
                <?php echo ss_tool_card('tool-flkr-fckr.php', 'FLKR FCKR', 'Windows / Linux', 'Shipping', 'A Flickr archive without resetting its history. Photographs, titles, descriptions, tags, upload dates, views, comments, and likes all make the trip.', 'flkr-fckr.png', 'FLKR FCKR importing a Flickr archive'); ?>
                <?php echo ss_tool_card('tool-smackpress.php', 'SMACKPRESS', 'Windows / Linux', 'Closed beta', 'A WordPress photo blog into SMACKTALK, one post at a time, with a review step for each. For the blogs where every post deserves a look.', null); ?>
                <?php echo ss_tool_card('coming-soon.php', 'BLOGGER FLOGGER', 'Windows / Linux', 'Drawing board', 'An old Blogger site into SMACKTALK from a Google Takeout &mdash; posts, pages, comments, labels, photographs copied off Blogger instead of hotlinked. Spec&rsquo;d, not built.', null); ?>
            </div>
        </div>
    </section>

    <section class="tool-group" id="keep">
        <div class="wrap">
            <div class="tool-group-head">
                <p class="site-discovery-kicker">Keep</p>
                <h2>Back it up, run the fleet</h2>
                <p>One site or twenty-five. Recoverable even when the original installation is gone.</p>
            </div>
            <div class="tool-cards">
                <?php echo ss_tool_card('tool-suyb.php', 'SMACK UP YOUR BACKUP', 'Windows / Linux', 'Shipping', 'Back up one site or a fleet to local storage and cloud providers, audit what exists where, and recover from nothing. Long transfers checkpoint and resume.', 'suyb-backupinprogress-01.png', 'Smack Up Your Backup transferring a photography site backup'); ?>
                <?php echo ss_tool_card('tool-snap-hq.php', 'SNAP HQ', 'Windows', 'Closed beta', 'Local headquarters for the suite. Launch every tool from one place, discover the sites in your fleet, and share protected profiles, libraries, and prompts between the tools that need them.', 'snap-hq.png', 'SNAP HQ launcher and shared setup'); ?>
            </div>
            <p class="suite-band">One rule across the whole suite: the local store is a cache of your <em>site</em>, never a second copy of your originals. You archive your own files. The tools never quietly shadow-copy them anywhere.</p>
        </div>
    </section>

    <section class="workshop">
        <div class="wrap">
            <p class="site-discovery-kicker">Not on the shelf yet</p>
            <h2>In the workshop</h2>
            <p class="lede">Built and working on the bench, not yet packaged and released. Details on <a href="coming-soon.php">COMING UP THE REAR!</a></p>
            <div class="workshop-list">
                <a href="coming-soon.php"><strong>Take Your Shit With You</strong><span>The complete export. Ownership includes the right to leave.</span></a>
                <a href="coming-soon.php"><strong>Smack Your Mouth</strong><span>Offline comment moderation and replies for a whole fleet.</span></a>
                <a href="coming-soon.php"><strong>CRONOMETER</strong><span>Is every scheduled job on every site actually running?</span></a>
                <a href="coming-soon.php"><strong>Oh Snap!</strong><span>Design a skin visually, push it to a live site.</span></a>
            </div>
        </div>
    </section>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
