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
.tool-feature { padding-bottom: 68px; border-bottom: 8px solid var(--black); }
.tool-feature + .tool-feature { padding-top: 68px; }
.tool-feature-head { max-width: 900px; margin-bottom: 42px; }
.tool-feature-head .platform { color: var(--mid-grey); font: 700 .72rem/1.2 'Courier New', monospace; text-transform: uppercase; letter-spacing: .08em; }
.tool-feature-head .status { display: inline-block; margin-left: 10px; padding: 5px 9px; background: var(--red); color: var(--white); font: 700 .68rem/1 Arial, sans-serif; text-transform: uppercase; }
.tool-feature-head h2 { margin: 10px 0 18px; color: var(--black); font-size: clamp(2rem, 4vw, 3.2rem); }
.tool-story { display: grid; gap: 64px; }
.tool-chapter { display: grid; grid-template-columns: minmax(0, 1.25fr) minmax(300px, .75fr); gap: 42px; align-items: center; }
.tool-chapter:nth-child(even) .tool-shot { order: 2; }
.tool-shot { margin: 0; border: 1px solid var(--border); background: var(--black); }
.tool-shot img { display: block; width: 100%; }
.tool-shot figcaption { padding: 9px 12px; color: #aaa; background: var(--black); font: .68rem/1.35 'Courier New', monospace; }
.tool-copy h3 { margin-bottom: 14px; color: var(--red); font-size: 1.35rem; }
.tool-copy p { font-size: .93rem; line-height: 1.65; }
@media (max-width: 800px) { .app-entry { grid-template-columns: 1fr; gap: 24px; } .app-entry:nth-child(even) .app-shot { order: 0; } }
@media (max-width: 800px) { .tool-chapter { grid-template-columns: 1fr; gap: 24px; } .tool-chapter:nth-child(even) .tool-shot { order: 0; } }
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
            <article class="tool-feature" id="snap-slapper">
                <div class="tool-feature-head">
                    <p class="platform">Windows / Linux <span class="status">Closed beta</span></p>
                    <h2>SNAP SLAPPER</h2>
                    <p class="lede">A private photo manager and real non-destructive editor built beside SnapSmack, not rented from somebody else&rsquo;s cloud.</p>
                    <p>SNAP SLAPPER is the missing space between a folder full of photographs and the finished work on your site. Browse a real archive, rate and tag it, organize files and folders, develop one photograph carefully, move through a shoot quickly, save editable work, and prepare the finished result for the web without surrendering the originals.</p>
                </div>
                <div class="tool-story">
                    <section class="tool-chapter">
                        <figure class="tool-shot"><img src="img/snapslapper-library.png" alt="SNAP SLAPPER photo library with folders, thumbnails, ratings, tags, and photograph information" width="1920" height="1032"><figcaption>The library: ordinary folders remain ordinary folders.</figcaption></figure>
                        <div class="tool-copy"><h3>Your archive is not an import hostage.</h3><p>Point it at the folders you already use. Search, sort, include subfolders, resize thumbnails and folder text, create and rename folders, move photographs, batch rename safely, and keep ratings, favourites, tags, and albums beside the work.</p><p>SNAP SLAPPER is a view onto your photography, not a deed transferring ownership to a catalogue. Originals stay where you put them; edits and exports become separate files.</p></div>
                    </section>
                    <section class="tool-chapter">
                        <figure class="tool-shot"><img src="img/snapslapper-editor-norm.png" alt="SNAP SLAPPER Normal editor with simple photographic controls and filmstrip" width="1920" height="1032" loading="lazy"><figcaption>Normal mode: the controls used on most photographs.</figcaption></figure>
                        <div class="tool-copy"><h3>Normal means focused, not crippled.</h3><p>Brightness, contrast, highlights, shadows, temperature, saturation, vibrance, black and white, geometry, vignette, crop, red-eye, Auto, LEWKS, export, and Blog Copy are visible without an aircraft cockpit of specialist machinery.</p><p>The folder filmstrip follows the open photograph, loads as you scroll, and folds away when the image needs every pixel. Fit is for composition; 100% is a real focus check.</p></div>
                    </section>
                    <section class="tool-chapter">
                        <figure class="tool-shot"><img src="img/snapslapper-editor-adv.png" alt="SNAP SLAPPER Advanced editor with layers, histogram, masks, and detailed controls" width="1920" height="1032" loading="lazy"><figcaption>Advanced mode opens the complete non-destructive bench.</figcaption></figure>
                        <div class="tool-copy"><h3>When the photograph needs the whole bench.</h3><p>Add adjustment, image, text, and filter layers; change opacity and blend mode; reorder the work; and apply radial, linear, luminosity, colour-range, or painted masks. Levels, curves, colour mixing, split toning, retouching, geometry, perspective, filters, textures, sharpening, and a live histogram stay editable.</p><p>Recipes carry a sequence into batch work. Projects preserve the stack. PSD and TIFF provide working exits; JPEG and PNG provide finished ones.</p></div>
                    </section>
                    <section class="tool-chapter">
                        <figure class="tool-shot"><img src="img/snapslapper-editor-adv-nofilm.png" alt="SNAP SLAPPER Advanced editor with filmstrip hidden" width="1920" height="1032" loading="lazy"><figcaption>Close the filmstrip when one photograph needs the room.</figcaption></figure>
                        <div class="tool-copy"><h3>The interface gets out of the photograph&rsquo;s way.</h3><p>Normal and Advanced are obvious modes rather than secret preferences. The filmstrip opens for comparisons and closes for concentration. Before/After, undo, redo, reset, crop, healing, keyboard shortcuts, remembered window state, and correctly sized previews support the rhythm of actual editing.</p><p>It is being dogfooded against real folders and real photographs. The freezes, fuzzy proxies, stalled thumbnails, duplicate windows, and controls hidden in the wrong place are being found by using it as a primary editor.</p></div>
                    </section>
                    <section class="tool-chapter">
                        <figure class="tool-shot"><img src="img/snapslapper-editor-lewks.png" alt="SNAP SLAPPER LEWKS browser previewing reusable looks on the open photograph" width="1920" height="1032" loading="lazy"><figcaption>LEWKS preview on your photograph at adjustable strength.</figcaption></figure>
                        <div class="tool-copy"><h3>Looks you can see, change, save, and leave with.</h3><p>LEWKS are reusable appearance recipes previewed against the photograph that is actually open. Applying one does not flatten the work into a dead end: the underlying controls remain controls, and the result can be changed, stacked, or saved as your own recipe.</p><p>SNAP SLAPPER runs locally, arrives through THE HUB, and works beside your SnapSmack installation. No cloud library, no subscription account, and no originals uploaded for somebody else&rsquo;s machine-learning appetizer. <a href="wotcha.php#snap-slapper-editor"><strong>Read the WOTCHA announcement &rarr;</strong></a></p></div>
                    </section>
                </div>
            </article>

            <article class="tool-feature" id="the-hub">
                <div class="tool-feature-head"><p class="platform">Windows <span class="status">Shipping</span></p><h2>THE HUB</h2><p class="lede">One front door for the desktop fleet. Set the blogs, credentials, profiles, prompts, and shared services once; every companion tool sees the same system.</p></div>
                <div class="tool-story"><section class="tool-chapter"><figure class="tool-shot"><img src="img/the-hub.png" alt="THE HUB showing the SnapSmack desktop fleet and shared blog profiles" width="1920" height="1032" loading="lazy"><figcaption>Launch the fleet and manage its shared connections from one place.</figcaption></figure><div class="tool-copy"><h3>One door. Set the fleet up once.</h3><p>THE HUB launches SNAP SLAPPER, Smack Your Batch Up, Get Your Shit Sorted, Cold Snap, Smack Up Your Backup, OH SNAP, Smack Your Mouth, Shots Fired, and Cronometer. More importantly, it gives those applications one shared understanding of your sites instead of making you configure the same blog nine times.</p><p>Discover a multisite fleet, store its blog profiles, test the HUB and Gemini keys, share Google Drive backup settings, and synchronize prompts across the sites that use them. SNAP SLAPPER can consume those same profiles for blog-aware preparation and publishing; SUYB can protect the work; the rest of the tools inherit the connection rather than inventing another credentials drawer.</p><p>THE HUB is deliberately plain. It is infrastructure, not a dashboard trying to become your hobby.</p></div></section></div>
            </article>

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
