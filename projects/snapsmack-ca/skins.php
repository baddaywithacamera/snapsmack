<?php
/**
 * SNAPSMACK.CA - production skin catalogue
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */
require_once __DIR__ . '/includes/skin-stats.php';

$page_title       = 'GLAD RAGS! - SnapSmack Skins';
$page_description = 'Production-ready SnapSmack skins shown on real photography sites.';
$page_og_url      = 'https://snapsmack.ca/skins.php';
$nav_active       = 'goods-skins';

$skins = [
    ['TILEZ', ['primary' => ['tilez-primary.png', 'Post wall'], 'secondary' => ['tilez-secondary.png', 'Longform post'], 'tertiary' => ['tilez-tertiary.png', 'Blogroll']], 'baddaywithacamera.ca', 'https://baddaywithacamera.ca', '<strong>DRAFT — for Sean to review.</strong> A white editorial portfolio for longform photography posts. Its three-column wall preserves native image proportions, with dated post titles over the photographs. Individual posts combine photographs and writing with a separate information column; the image archive and grouped blogroll provide other ways to explore.', 'SMACKTALK (3.0)', 'Editorial portfolio / longform photo wall'],
    ['GLIDE', ['primary' => ['glide-primary.png', 'Photo wall'], 'secondary' => ['glide-secondary.png', 'Lightbox'], 'tertiary' => ['glide-tertiary.png', 'About overlay']], 'portalproject.photoblogs.fyi', 'https://portalproject.photoblogs.fyi', '<strong>DRAFT — for Sean to review.</strong> An angled, full-screen wall of photographs with bands that drift in opposing directions. Drag or scroll to explore, open a photo in the flip lightbox, or bring up the archive, blogroll, and About overlays while the wall keeps moving behind them.', 'SMACKONEOUT (1.0)', 'Moving photo wall / overlay browsing'],
    ['The Grid', ['primary' => ['grid-primary.png'], 'secondary' => ['grid-secondary.png'], 'tertiary' => ['grid-tertiary.png']], 'unzucked.ca', 'https://unzucked.ca', 'The classic Instagram idea before Instagram stopped being about photographs: a disciplined three-across square grid, deep-linked modal posts, panorama triptychs, and swipeable carousels with one conversation for the whole set. Profile, background treatment, spacing, frames, and carousel presentation are configurable without disturbing the composed feed.', 'GRAMOFSMACK (2.0)', 'Three-column grid / carousel archive'],
    ['50 Shades of Noah Grey', ['primary' => ['50shades-primary.png'], 'secondary' => ['50shades-secondary.png'], 'tertiary' => ['50shades-tertiary.png']], 'photowalk.ing', 'https://photowalk.ing', 'A genuinely monochrome photography skin rather than a colourful site with saturation turned down. Three grey variants, restrained editorial type, quiet navigation, and generous solo-image presentation make room for wandering, observation, weather, and photographs that do not need an interface shouting over them.', 'SMACKONEOUT (1.0)', 'Solo photoblog / monochrome editorial'],
    ['New Horizon', ['primary' => ['newhorizon-landing.png', 'Full-screen image view'], 'secondary' => ['newhorizon-archive.png', 'Archive'], 'tertiary' => ['newhorizon-page.png', 'About page']], 'curbappeal.photoblogs.fyi', 'https://curbappeal.photoblogs.fyi', 'A clean, high-contrast photoblog built to keep the interface quiet and the photography dominant. The full-screen image view, carefully framed archive, and two-column static pages share one restrained typographic system, with dark and light variants and extensive appearance controls.', 'SMACKONEOUT (1.0)', 'Solo photoblog / restrained editorial'],
    ['Galleria', ['primary' => ['galleria-primary.png'], 'secondary' => ['galleria-secondary.png'], 'tertiary' => ['galleria-tertiary.png']], 'hekeepsdroningon.ca', 'https://hekeepsdroningon.ca', 'A gallery-first presentation with photorealistic CSS picture frames, configurable matting, bevels, and wood grain. The landing slider, framed archive, and filmstrip navigation support mixed aspect ratios without forcing every photograph into the same crop.', 'SMACKONEOUT (1.0)', 'Framed gallery / slider and filmstrip'],
    ['Impact Printer', ['primary' => ['impact-primary.png'], 'secondary' => ['impact-secondary.png'], 'tertiary' => ['impact-tertiary.png']], 'pixhellated.ca', 'https://pixhellated.ca', 'Thermal-paper photography rendered as a website: continuous-feed stock, tractor holes, ASCII borders, dithered halftones, faded ribbon ink, and a choice of green-bar ledger or plain paper. It is rough, graphic, and intentionally looks like the archive came chattering out of an old printer.', 'SMACKONEOUT (1.0)', 'Solo photoblog / continuous-feed print'],
    ['Rational Geo', ['primary' => ['rationalgeo-primary.png'], 'secondary' => ['rationalgeo-secondary.png'], 'tertiary' => ['rationalgeo-tertiary.png']], 'wateronthebrain.ca', 'https://wateronthebrain.ca', 'An homage to the world&rsquo;s best magazine: editorial serif typography, measured geometry, the unmistakable yellow accent, and light and dark variants. It treats each photograph like a feature spread while keeping the archive ordered, readable, and spacious.', 'SMACKONEOUT (1.0)', 'Editorial magazine / solo photoblog'],
    ['True Grit', ['primary' => ['truegrit-primary.png'], 'secondary' => ['truegrit-secondary.png'], 'tertiary' => ['truegrit-tertiary.png']], 'foundtextures.ca', 'https://foundtextures.ca', 'Built for photographs of the surfaces the world forgets: rust, concrete, wood, paint, wear, and entropy. Photographic wall backgrounds, adjustable overlays, archival framing, a justified grid, and the floating photo wall give texture-heavy work a physical place to live.', 'SMACKONEOUT (1.0)', 'Texture archive / justified and floating wall'],
    ['Chaplin', ['primary' => ['chaplin-primary.png'], 'secondary' => ['chaplin-secondary.png'], 'tertiary' => ['chaplin-tertiary.png']], 'acolourlesslife.ca', 'https://acolourlesslife.ca', 'Near-black canvas, black-and-white treatment, Art Deco ornament, animated film wear, and a full-screen intertitle for information and signals. The silent-film language is present everywhere, but the title cards know when to get out of the photograph&rsquo;s way.', 'SMACKONEOUT (1.0)', 'Silent-film photoblog / Art Deco'],
    ['Slickr', ['primary' => ['slickr-primary.png'], 'secondary' => ['slickr-secondary.png'], 'tertiary' => ['slickr-tertiary.png']], 'foreverphotograph.ing', 'https://foreverphotograph.ing', 'Flickr the way photographers remember it: a justified masonry archive, a strong solo view with EXIF alongside the image, and a proper albums directory. It is designed for migrated Flickr libraries and can retain an optional provenance badge without making the old landlord part of the new address.', 'SMACKONEOUT (1.0)', 'Flickr-style archive / justified masonry'],
    ['Parade', ['primary' => ['parade-primary.png'], 'secondary' => ['parade-secondary.png'], 'tertiary' => ['parade-tertiary.png']], 'allinthewrist.photoblogs.fyi', 'https://allinthewrist.photoblogs.fyi', 'An LGBT+ identity skin that flies Rainbow, Progress Pride, Trans, Bisexual, Non-Binary, Pansexual, Lesbian, Asexual, Aromantic, Genderfluid, Genderqueer, or Two-Spirit colours behind a classic three-across feed. The full-screen flag waves, coordinated borders move across the tiles, and reduced-motion visitors receive a still version.', 'GRAMOFSMACK (2.0)', 'Three-column identity grid / carousel archive'],
    ['GAME ON', ['primary' => ['gameon-primary.png'], 'secondary' => ['gameon-secondary.png'], 'tertiary' => ['gameon-tertiary.png']], 'theschoolofhardnocks.ca', 'https://theschoolofhardnocks.ca', 'Every photograph on the site is also a sliding puzzle. Behind the three-across feed, a field of 144 live fifteen-puzzles shuffles itself and quietly solves itself while you read. Click any one and it fills the screen: rebuild the photograph tile by tile, on a clock, with a move counter, your best time, and a running average that will not let you pretend the last one did not happen. Solve it and the picture snaps whole, takes a bow, and the board deals you a new one. High scores are kept on the site, per photograph, so the person who beat your time is a real person with a real name. On a blog with over 4,000 posts that is over 4,000 unique puzzles, and nobody has finished them all. Arrow keys and WASD work. Reduced-motion visitors get a still field; older machines get fewer boards automatically. Yes, the mask is a Saw reference. Yes, you want to play a game.', 'GRAMOFSMACK (2.0)', 'Three-column grid over a live puzzle field / fifteen-puzzle modal'],
    ['Instant Camera', ['primary' => ['instantcam-primary.png'], 'secondary' => ['instantcam-secondary.png'], 'tertiary' => ['instantcam-tertiary.png']], 'fauxlaroid.fyi', 'https://fauxlaroid.fyi', 'A three-across table of instant prints whose tile shape can match Polaroid, Instax Mini, Wide, Square, or a custom format. Scanned borders remain uncropped, shadows provide the physical lift, and a drifting tabletop behind the grid makes the photographs feel handled rather than tiled by an algorithm.', 'GRAMOFSMACK (2.0)', 'Instant-film grid / uncropped carousel archive'],
    ['Aurora', ['primary' => ['aurora-primary.png'], 'secondary' => ['aurora-secondary.png'], 'tertiary' => ['aurora-tertiary.png']], 'lightafterdark.ca', 'https://lightafterdark.ca', 'A dark three-across archive under a slow northern-lights field that breathes colour behind the photography. Configurable palettes, sky colour, motion, opacity, and a colour wave moving across tile borders make it luminous without turning every photograph into a nightclub poster.', 'GRAMOFSMACK (2.0)', 'Three-column night grid / animated carousel archive'],
    ['Jive Turkey', ['primary' => ['jturk-primary.png'], 'secondary' => ['jturk-secondary.png'], 'tertiary' => ['jturk-tertiary.png']], 'craptasti.ca', 'https://craptasti.ca', 'Deliberately loud seventies maximalism: kaleidoscopes, flower fields, racing ribbons, sunbursts, Bauhaus shapes, animated borders, and SURPRISE rolling a fresh combination on each visit. Under the commotion is a serious three-across carousel feed with per-image framing and enough controls to decide exactly how badly it behaves.', 'GRAMOFSMACK (2.0)', 'Three-column maximalist grid / carousel archive'],
    ['SCROLL', ['primary' => ['scroll-primary.png'], 'secondary' => ['scroll-secondary.png'], 'tertiary' => ['scroll-tertiary.png']], 'usedcarparts.photoblogs.fyi', 'https://usedcarparts.photoblogs.fyi', 'A hard typographic masthead above a four-column wall that respects every photograph&rsquo;s native proportions—portraits stand tall, panoramas stay wide, and nothing is bullied into a square. It suits visual stories assembled from details, fragments, found objects, and photographs that refuse to show the whole damn car.', 'SMACKONEOUT (1.0)', 'Native-ratio photo wall / solo photoblog'],
];

$page_css = <<<'CSS'
.skin-catalogue { display: grid; grid-template-columns: 1fr; gap: 76px; }
.skin-entry { min-width: 0; padding-bottom: 52px; border-bottom: 1px solid var(--border); }
.skin-entry:last-child { padding-bottom: 0; border-bottom: 0; }
.skin-gallery { display: grid; grid-template-columns: 2fr 1fr; grid-template-rows: repeat(2, minmax(0, 1fr)); gap: 10px; }
.skin-entry a.shot { position: relative; display: block; min-height: 0; background: #e8e8e8; border: 1px solid var(--border); overflow: hidden; }
.skin-entry a.shot:first-child { grid-row: 1 / 3; }
.skin-entry img { width: 100%; height: 100%; aspect-ratio: 16 / 9; object-fit: cover; transition: transform .25s ease; }
.shot-label { position: absolute; left: 0; bottom: 0; padding: 5px 9px; color: var(--white); background: rgba(17,17,17,.88); font: 900 .65rem/1 Arial, sans-serif; letter-spacing: .05em; text-transform: uppercase; }
.skin-entry a.shot:hover img { transform: scale(1.015); }
.skin-entry h2 { color: var(--black); font-size: 1.35rem; margin: 18px 0 8px; }
.skin-meta { display: flex; flex-wrap: wrap; gap: 7px; margin: 0 0 14px; }
.skin-meta span { padding: 6px 9px; border: 1px solid var(--black); color: var(--black); font: 800 .66rem/1.15 'Courier New', monospace; text-transform: uppercase; }
.skin-entry p { margin-bottom: 10px; }
.skin-entry .live { display: inline-block; margin-top: 6px; font: 700 1.1rem/1.2 Arial, Helvetica, sans-serif; text-transform: uppercase; color: var(--red, #c00); }
@media (max-width: 760px) {
    .skin-gallery { grid-template-columns: 1fr; grid-template-rows: none; }
    .skin-entry a.shot:first-child { grid-row: auto; }
    .skin-entry img { height: auto; }
}
CSS;

require_once __DIR__ . '/includes/header.php';
?>
<main>
    <section class="page-header">
        <div class="wrap">
            <p class="site-discovery-kicker">GLAD RAGS!</p>
            <h1>Same engine.<br><span>Entirely different attitude.</span></h1>
            <p class="lede">These are not mockups padded with stock photographs. Every skin below is running on a real site with a real archive. <strong>All skins are free, always.</strong></p>
        </div>
    </section>
    <div class="wrap goods-nav-wrap">
        <nav class="goods-nav" aria-label="The Goods">
            <a href="features.php"><strong>THE GOODS!</strong><span>What SnapSmack actually does.</span></a>
            <a class="active" href="skins.php"><strong>GLAD RAGS!</strong><span>Skins: different sites, same dependable engine.</span></a>
            <a href="tools.php"><strong>BOX O' TRICKS!</strong><span>The free desktop suite.</span></a>
        </nav>
    </div>
    <section>
        <div class="wrap skin-catalogue">
            <?php foreach ($skins as [$name, $images, $domain, $url, $description, $mode, $style]): ?>
                <article class="skin-entry" tabindex="0" data-stats="<?php echo ss_skin_card_stats($domain, $_skin_demo_stats); ?>">
                    <div class="skin-gallery">
                        <?php foreach ($images as $slot => [$image]): ?>
                            <?php $caption = $images[$slot][1] ?? ucfirst($slot); ?>
                            <a class="shot" href="img/<?php echo htmlspecialchars($image); ?>" target="_blank" rel="noopener">
                                <img src="img/<?php echo htmlspecialchars($image); ?>" alt="<?php echo htmlspecialchars($name . ' skin — ' . $caption); ?>" width="1920" height="1080" loading="lazy">
                                <span class="shot-label"><?php echo htmlspecialchars(ucfirst($slot)); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <h2><?php echo htmlspecialchars($name); ?></h2>
                    <p class="skin-meta"><span>Install: <?php echo htmlspecialchars($mode); ?></span><span>Style: <?php echo htmlspecialchars($style); ?></span></p>
                    <p><?php echo $description; ?></p>
                    <a class="live" href="<?php echo htmlspecialchars($url); ?>" target="_blank" rel="noopener">View <?php echo htmlspecialchars($domain); ?> &rarr;</a>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
