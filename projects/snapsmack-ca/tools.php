<?php
/**
 * SNAPSMACK.CA - companion application catalogue
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
$page_title       = 'BOX O\' TRICKS! - SnapSmack Companion Apps';
$page_description = 'Free SnapSmack desktop applications for batch publishing, backup, Instagram migration, and Flickr migration.';
$page_og_url      = 'https://snapsmack.ca/tools.php';
$nav_active       = 'goods-tools';

$page_css = <<<'CSS'
.app-list { display: grid; gap: 72px; }
.app-entry { display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(280px, .8fr); gap: 44px; align-items: center; }
.app-entry:nth-child(even) .app-shot { order: 2; }
.app-shot { border: 1px solid var(--border); background: var(--light-grey); }
.app-shot img { width: 100%; }
.app-copy .platform { font: 700 .72rem/1.2 'Courier New', monospace; color: var(--mid-grey); text-transform: uppercase; letter-spacing: .08em; }
.app-copy h2 { color: var(--black); margin: 8px 0 18px; }
.app-copy .status { display: inline-block; padding: 5px 9px; background: var(--black); color: var(--white); font: 700 .68rem/1 Arial, sans-serif; text-transform: uppercase; }
@media (max-width: 800px) { .app-entry { grid-template-columns: 1fr; gap: 24px; } .app-entry:nth-child(even) .app-shot { order: 0; } }
CSS;

require_once __DIR__ . '/includes/header.php';
?>
<main>
    <section class="page-header">
        <div class="wrap">
            <p class="site-discovery-kicker">THE GOODS! / BOX O' TRICKS!</p>
            <h1>The heavy lifting<br><span>happens off-site.</span></h1>
            <p class="lede">Free desktop tools for jobs that are too large, too slow, or too important to entrust to one browser tab.</p>
        </div>
    </section>
    <div class="wrap" style="padding-top:32px;">
        <nav class="goods-nav" aria-label="The Goods">
            <a href="features.php"><strong>THE GOODS!</strong><span>What SnapSmack actually does.</span></a>
            <a href="skins.php"><strong>GLAD RAGS!</strong><span>Skins: different sites, same dependable engine.</span></a>
            <a class="active" href="tools.php"><strong>BOX O' TRICKS!</strong><span>Companion apps for migration, posting, and backup.</span></a>
        </nav>
    </div>
    <section>
        <div class="wrap app-list">
            <article class="app-entry">
                <div class="app-shot"><img src="img/sybu-uploading.png" alt="Smack Your Batch Up publishing a batch of photographs" width="1920" height="1032"></div>
                <div class="app-copy">
                    <p class="platform">Windows / Linux</p><span class="status">Shipping</span>
                    <h2>Smack Your Batch Up</h2>
                    <p>Load a shoot, reorder it, assign categories and albums, preserve EXIF copyright information, and publish the entire batch without living in the browser. Optional AI enrichment can prepare captions, tags, categories, and colour metadata while the queue runs.</p>
                </div>
            </article>
            <article class="app-entry">
                <div class="app-shot"><img src="img/suyb-backupinprogress-01.png" alt="Smack Up Your Backup transferring a photography site backup" width="1920" height="1032" loading="lazy"></div>
                <div class="app-copy">
                    <p class="platform">Windows / Linux</p><span class="status">Shipping</span>
                    <h2>Smack Up Your Backup</h2>
                    <p>Back up one site or a fleet to local storage and cloud providers, audit what exists in each location, and recover even when the original installation is gone. Long transfers checkpoint instead of starting from zero after an interruption.</p>
                </div>
            </article>
            <article class="app-entry">
                <div class="app-shot"><img src="img/unzucker-gridsorter.png" alt="The Unzucker arranging an imported Instagram grid" width="1920" height="1032" loading="lazy"></div>
                <div class="app-copy">
                    <p class="platform">Windows / Linux</p><span class="status">Shipping</span>
                    <h2>The Unzucker</h2>
                    <p>Move an Instagram export into SnapSmack with images, captions, hashtags, carousels, and original dates intact. Arrange the archive as a three-column grid, lock panorama groups, throttle the transfer, and walk away.</p>
                </div>
            </article>
            <article class="app-entry">
                <div class="app-shot"><img src="img/flkr-fckr.png" alt="FLKR FCKR importing a Flickr archive" width="1920" height="1032" loading="lazy"></div>
                <div class="app-copy">
                    <p class="platform">Windows / Linux</p><span class="status">Shipping</span>
                    <h2>FLKR FCKR</h2>
                    <p>Import a Flickr archive without resetting its history. Photographs, titles, descriptions, tags, upload dates, views, comments, and likes make the trip. Pair it with the Slickr skin when familiarity is part of the migration plan.</p>
                </div>
            </article>
        </div>
    </section>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
