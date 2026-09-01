<?php
/**
 * SNAPSMACK.CA - companion application catalogue
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
$page_title       = 'BOX O\' TRICKS! - SnapSmack Companion Apps';
$page_description = 'Free SnapSmack desktop applications for photo editing, local tool management, batch publishing, backup, and social archive migration.';
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
.slapper-feature { padding-bottom: 72px; border-bottom: 8px solid var(--black); }
.slapper-intro { max-width: 900px; margin-bottom: 54px; }
.slapper-intro .platform { display: inline-block; margin-right: 10px; color: var(--mid-grey); font: 700 .72rem/1.2 'Courier New', monospace; text-transform: uppercase; letter-spacing: .08em; }
.slapper-intro .status { display: inline-block; padding: 5px 9px; background: var(--red); color: var(--white); font: 700 .68rem/1 Arial, sans-serif; text-transform: uppercase; }
.slapper-intro h2 { margin: 10px 0 18px; color: var(--black); font-size: clamp(2rem, 4vw, 3.2rem); }
.slapper-intro .lede { max-width: 780px; }
.slapper-story { display: grid; gap: 72px; }
.slapper-chapter { display: grid; grid-template-columns: minmax(0, 1.25fr) minmax(300px, .75fr); gap: 44px; align-items: center; }
.slapper-chapter:nth-child(even) .slapper-shot { order: 2; }
.slapper-shot { margin: 0; border: 1px solid var(--border); background: var(--black); }
.slapper-shot img { display: block; width: 100%; }
.slapper-shot figcaption { padding: 9px 12px; color: #aaa; background: var(--black); font: .68rem/1.35 'Courier New', monospace; }
.slapper-copy h3 { margin-bottom: 14px; color: var(--red); font-size: 1.35rem; }
.slapper-copy p { font-size: .93rem; line-height: 1.65; }
.slapper-copy p:last-child { margin-bottom: 0; }
@media (max-width: 800px) { .app-entry { grid-template-columns: 1fr; gap: 24px; } .app-entry:nth-child(even) .app-shot { order: 0; } }
@media (max-width: 800px) { .slapper-chapter { grid-template-columns: 1fr; gap: 24px; } .slapper-chapter:nth-child(even) .slapper-shot { order: 0; } }
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
            <article class="slapper-feature" id="snap-slapper">
                <div class="slapper-intro">
                    <p class="platform">Windows / Linux</p><span class="status">Closed beta</span>
                    <h2>SNAP SLAPPER</h2>
                    <p class="lede">A private photo library and real non-destructive editor built beside SnapSmack, not rented from somebody else&rsquo;s cloud.</p>
                    <p>SNAP SLAPPER began as the missing space between a folder full of photographs and the finished work on a SnapSmack site. It has grown into the place where the whole local workflow can happen: browse a real archive, rate and tag it, organize files and folders, develop one photograph carefully, move through a shoot quickly, save the work as an open project, and hand the finished result to the web without surrendering the originals.</p>
                    <p>It is deliberately two editors in one. <strong>Normal</strong> keeps the everyday photographic controls visible and the machinery out of the way. <strong>Advanced</strong> opens the layers, masks, curves, colour tools, geometry, retouching, filters, textures, recipes, and export controls when the photograph actually needs them. You choose the depth; the software does not punish you for knowing less or hold you back for knowing more.</p>
                </div>

                <div class="slapper-story">
                    <section class="slapper-chapter">
                        <figure class="slapper-shot">
                            <img src="img/snapslapper-library.png" alt="SNAP SLAPPER photo library showing folders, thumbnails, ratings, tags, and photograph information" width="1920" height="1032" loading="lazy">
                            <figcaption>The library: folders remain folders, with ratings, tags, albums, search, sorting, and readable thumbnails.</figcaption>
                        </figure>
                        <div class="slapper-copy">
                            <h3>Your archive is not an import hostage.</h3>
                            <p>Point SNAP SLAPPER at the folders you already use. It reads the photographs where they live instead of demanding that thousands of originals be swallowed by a proprietary catalogue before you can see them. Include subfolders when the hierarchy matters, resize both the thumbnails and folder text, search filenames, sort the shoot, and filter what is on screen.</p>
                            <p>Ratings, favourites, tags, and albums sit beside the photograph rather than in another disconnected application. The organizer can create and rename folders, move photographs, batch-rename safely, and show the important metadata without turning file management into a scavenger hunt.</p>
                            <p>The original file remains the original file. Editing is non-destructive, exports are new files, and the library is a view onto your photography rather than a deed transferring ownership to the program.</p>
                        </div>
                    </section>

                    <section class="slapper-chapter">
                        <figure class="slapper-shot">
                            <img src="img/snapslapper-editor-norm.png" alt="SNAP SLAPPER Normal editor with a photograph, simple controls, and a folder filmstrip" width="1920" height="1032" loading="lazy">
                            <figcaption>Normal mode: the controls used on most photographs, plus a filmstrip for moving through the folder.</figcaption>
                        </figure>
                        <div class="slapper-copy">
                            <h3>Normal means focused, not crippled.</h3>
                            <p>Normal mode puts brightness, contrast, highlights, shadows, temperature, saturation, vibrance, black and white, geometry, and vignette where a photographer can find them. Crop, red-eye correction, Auto, LEWKS, export, and blog-copy tools remain one click away. It is enough room to finish most photographs without staring into an aircraft cockpit.</p>
                            <p>The filmstrip follows the folder under the open photograph, loads as you scroll, and can be folded away when the image needs every available pixel. Fit and 100% views are explicit: one is for composition, the other is a real focus check at native resolution.</p>
                            <p>Normal mode intentionally leaves out layers, masks, paint machinery, and specialist controls. Simplicity here is a designed workspace, not an arbitrary set of disabled features.</p>
                        </div>
                    </section>

                    <section class="slapper-chapter">
                        <figure class="slapper-shot">
                            <img src="img/snapslapper-editor-adv.png" alt="SNAP SLAPPER Advanced editor with layers, histogram, detailed adjustment controls, and filmstrip" width="1920" height="1032" loading="lazy">
                            <figcaption>Advanced mode: layers, live histogram, detailed tonal controls, masks, geometry, retouching, and colour work.</figcaption>
                        </figure>
                        <div class="slapper-copy">
                            <h3>When the photograph needs the whole bench.</h3>
                            <p>Advanced mode exposes the complete non-destructive stack. Add adjustment, image, text, and filter layers; change opacity and blend mode; reorder or isolate the work; and apply radial, linear, luminosity, colour-range, or painted masks. The live histogram can show luminance or RGB while the photograph changes underneath it.</p>
                            <p>Light, colour, presence, effects, levels, master and per-channel curves, geometry, retouching, black-and-white colour mixing, colour mixing, split toning, glow, sharpening, filters, and textures are editable instructions rather than damage baked into the source. Perspective can be corrected vertically, horizontally, or by pulling individual corners while straight lines remain straight.</p>
                            <p>Recipes capture a sequence for reuse and batch work. Projects preserve the stack for later. PSD, TIFF, PNG, and JPEG exports provide practical exits instead of pretending one application should own the rest of your working life.</p>
                        </div>
                    </section>

                    <section class="slapper-chapter">
                        <figure class="slapper-shot">
                            <img src="img/snapslapper-editor-adv-nofilm.png" alt="SNAP SLAPPER Advanced editor with the filmstrip hidden for a larger canvas" width="1920" height="1032" loading="lazy">
                            <figcaption>The same Advanced workspace with the filmstrip closed: more canvas when browsing is finished.</figcaption>
                        </figure>
                        <div class="slapper-copy">
                            <h3>The interface gets out of the photograph&rsquo;s way.</h3>
                            <p>A serious editor needs density without becoming an obstacle course. The right rail collapses complex sections into named groups. The filmstrip opens when you are comparing a run and closes when you are concentrating on one frame. Normal and Advanced are visible modes, not secret preferences buried three dialogs deep.</p>
                            <p>Before/After makes the original available without destroying the current state. Undo, redo, reset, crop, heal, red-eye work, and keyboard shortcuts support the repetitive rhythm of actual editing. Window changes re-render a correctly sized preview so maximizing the workspace does not leave a fuzzy proxy stretched across the screen.</p>
                            <p>The editor is being dogfooded against real folders and real photographs. That matters. The awkward controls, unloaded thumbnails, duplicate windows, and assumptions that only appear after the twentieth image are being found by using the program as the primary editor—not by admiring a demo file.</p>
                        </div>
                    </section>

                    <section class="slapper-chapter">
                        <figure class="slapper-shot">
                            <img src="img/snapslapper-editor-lewks.png" alt="SNAP SLAPPER LEWKS browser previewing reusable looks on the photographer's own image" width="1920" height="1032" loading="lazy">
                            <figcaption>LEWKS preview on your photograph, with adjustable strength before anything is applied.</figcaption>
                        </figure>
                        <div class="slapper-copy">
                            <h3>Looks you can see, change, save, and leave with.</h3>
                            <p>LEWKS are reusable appearance recipes, previewed against the photograph that is actually open rather than a vendor&rsquo;s perfectly lit sample. Black-and-white treatments, corrective starting points, film and print character, landscape colour, portrait handling, and deliberately strange experiments can all be auditioned at adjustable strength.</p>
                            <p>Applying one does not flatten the photograph into a dead end. The underlying controls remain controls. Change the contrast, pull back a colour channel, alter the curve, stack another idea, or save the result as your own recipe. A useful preset should accelerate a decision, not conceal how the decision was made.</p>
                            <p>SNAP SLAPPER runs locally, arrives through SNAP HQ, and works beside a SnapSmack installation rather than inventing another subscription account or cloud library. The desktop application does the heavy image work on your computer. Your site, your archive, your edits, and your exit remain yours.</p>
                            <p><a href="wotcha.php#snap-slapper-editor"><strong>Read the WOTCHA announcement &rarr;</strong></a></p>
                        </div>
                    </section>
                </div>
            </article>

            <article class="app-entry" id="snap-hq">
                <div class="app-shot"><img src="img/snap-hq.png" alt="SNAP HQ local desktop headquarters showing the SnapSmack companion application launcher and shared setup" width="1920" height="1032" loading="lazy"></div>
                <div class="app-copy">
                    <p class="platform">Windows</p><span class="status">Closed beta</span>
                    <h2>SNAP HQ</h2>
                    <p>Your local headquarters for the SnapSmack desktop suite. Launch the tools from one place, discover the sites in your fleet, and keep shared connection profiles, protected credentials, libraries, and prompts available to the applications that need them.</p>
                </div>
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
