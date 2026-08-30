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
    ['The Grid', ['grid-landing.png', 'grid-solo.png', 'grid-page.png'], 'unzucked.ca', 'https://unzucked.ca', 'The classic Instagram idea before Instagram stopped being about photographs: a disciplined three-across square grid, deep-linked modal posts, panorama triptychs, and swipeable carousels with one conversation for the whole set. Profile, background treatment, spacing, frames, and carousel presentation are configurable without disturbing the composed feed.', 'GRAMOFSMACK (2.0)', 'Three-column grid / carousel archive'],
    ['50 Shades of Noah Grey', ['50shades-landing.png', '50shades-archive.png', '50shades-page.png'], 'photowalk.ing', 'https://photowalk.ing', 'A genuinely monochrome photography skin rather than a colourful site with saturation turned down. Three grey variants, restrained editorial type, quiet navigation, and generous solo-image presentation make room for wandering, observation, weather, and photographs that do not need an interface shouting over them.', 'SMACKONEOUT (1.0)', 'Solo photoblog / monochrome editorial'],
    ['Galleria', ['galleria-landing.png', 'galleria-archive.png', 'galleria-page.png'], 'hekeepsdroningon.ca', 'https://hekeepsdroningon.ca', 'A gallery-first presentation with photorealistic CSS picture frames, configurable matting, bevels, and wood grain. The landing slider, framed archive, and filmstrip navigation support mixed aspect ratios without forcing every photograph into the same crop.', 'SMACKONEOUT (1.0)', 'Framed gallery / slider and filmstrip'],
    ['Impact Printer', ['impact-landing.png', 'impact-archive.png', 'impact-page.png'], 'pixhellated.ca', 'https://pixhellated.ca', 'Thermal-paper photography rendered as a website: continuous-feed stock, tractor holes, ASCII borders, dithered halftones, faded ribbon ink, and a choice of green-bar ledger or plain paper. It is rough, graphic, and intentionally looks like the archive came chattering out of an old printer.', 'SMACKONEOUT (1.0)', 'Solo photoblog / continuous-feed print'],
    ['Rational Geo', ['rationalgeo-landing.png', 'rationalgeo-archive.png', 'rationalgeo-page.png'], 'wateronthebrain.ca', 'https://wateronthebrain.ca', 'An homage to the world&rsquo;s best magazine: editorial serif typography, measured geometry, the unmistakable yellow accent, and light and dark variants. It treats each photograph like a feature spread while keeping the archive ordered, readable, and spacious.', 'SMACKONEOUT (1.0)', 'Editorial magazine / solo photoblog'],
    ['True Grit', ['truegrit-landing.png', 'truegrit-archive.png', 'truegrit-page.png'], 'foundtextures.ca', 'https://foundtextures.ca', 'Built for photographs of the surfaces the world forgets: rust, concrete, wood, paint, wear, and entropy. Photographic wall backgrounds, adjustable overlays, archival framing, a justified grid, and the floating photo wall give texture-heavy work a physical place to live.', 'SMACKONEOUT (1.0)', 'Texture archive / justified and floating wall'],
    ['Chaplin', ['chaplin-landing.png', 'chaplin-archive.png', 'chaplin-page.png'], 'acolourlesslife.ca', 'https://acolourlesslife.ca', 'Near-black canvas, black-and-white treatment, Art Deco ornament, animated film wear, and a full-screen intertitle for information and signals. The silent-film language is present everywhere, but the title cards know when to get out of the photograph&rsquo;s way.', 'SMACKONEOUT (1.0)', 'Silent-film photoblog / Art Deco'],
    ['Slickr', ['slickr-landing.png', 'slickr-archive.png', 'slickr-page.png'], 'foreverphotograph.ing', 'https://foreverphotograph.ing', 'Flickr the way photographers remember it: a justified masonry archive, a strong solo view with EXIF alongside the image, and a proper albums directory. It is designed for migrated Flickr libraries and can retain an optional provenance badge without making the old landlord part of the new address.', 'SMACKONEOUT (1.0)', 'Flickr-style archive / justified masonry'],
    ['Parade', ['parade-landing.png', 'parade-archive.png', 'parade-page.png'], 'theschoolofhardnocks.ca', 'https://theschoolofhardnocks.ca', 'An LGBT+ identity skin that flies Rainbow, Progress Pride, Trans, Bisexual, Non-Binary, Pansexual, Lesbian, Asexual, Aromantic, Genderfluid, Genderqueer, or Two-Spirit colours behind a classic three-across feed. The full-screen flag waves, coordinated borders move across the tiles, and reduced-motion visitors receive a still version.', 'GRAMOFSMACK (2.0)', 'Three-column identity grid / carousel archive'],
    ['Instant Camera', ['instantcam-landing.png', 'instantcam-archive.png', 'instantcam-page.png'], 'fauxlaroid.fyi', 'https://fauxlaroid.fyi', 'A three-across table of instant prints whose tile shape can match Polaroid, Instax Mini, Wide, Square, or a custom format. Scanned borders remain uncropped, shadows provide the physical lift, and a drifting tabletop behind the grid makes the photographs feel handled rather than tiled by an algorithm.', 'GRAMOFSMACK (2.0)', 'Instant-film grid / uncropped carousel archive'],
    ['Aurora', ['aurora-landing.png', 'aurora-archive.png', 'aurora-page.png'], 'lightafterdark.ca', 'https://lightafterdark.ca', 'A dark three-across archive under a slow northern-lights field that breathes colour behind the photography. Configurable palettes, sky colour, motion, opacity, and a colour wave moving across tile borders make it luminous without turning every photograph into a nightclub poster.', 'GRAMOFSMACK (2.0)', 'Three-column night grid / animated carousel archive'],
    ['Jive Turkey', ['jturk-landing.png', 'jturk-archive.png', 'jturk-page.png'], 'craptasti.ca', 'https://craptasti.ca', 'Deliberately loud seventies maximalism: kaleidoscopes, flower fields, racing ribbons, sunbursts, Bauhaus shapes, animated borders, and SURPRISE rolling a fresh combination on each visit. Under the commotion is a serious three-across carousel feed with per-image framing and enough controls to decide exactly how badly it behaves.', 'GRAMOFSMACK (2.0)', 'Three-column maximalist grid / carousel archive'],
    ['SCROLL', ['scroll-landing.png', 'scroll-archive.png', 'scroll-page.png'], 'usedcarparts.photoblogs.fyi', 'https://usedcarparts.photoblogs.fyi', 'A hard typographic masthead above a four-column wall that respects every photograph&rsquo;s native proportions—portraits stand tall, panoramas stay wide, and nothing is bullied into a square. It suits visual stories assembled from details, fragments, found objects, and photographs that refuse to show the whole damn car.', 'SMACKONEOUT (1.0)', 'Native-ratio photo wall / solo photoblog'],
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
.skin-entry .live { font: 700 .76rem/1.2 'Courier New', monospace; text-transform: uppercase; }
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
            <p class="site-discovery-kicker">THE GOODS! / GLAD RAGS!</p>
            <h1>Same engine.<br><span>Entirely different attitude.</span></h1>
            <p class="lede">These are not mockups padded with stock photographs. Every skin below is running on a real site with a real archive.</p>
        </div>
    </section>
    <div class="wrap" style="padding-top:32px;">
        <nav class="goods-nav" aria-label="The Goods">
            <a href="features.php"><strong>THE GOODS!</strong><span>What SnapSmack actually does.</span></a>
            <a class="active" href="skins.php"><strong>GLAD RAGS!</strong><span>Skins: different sites, same dependable engine.</span></a>
            <a href="tools.php"><strong>BOX O' TRICKS!</strong><span>Companion apps for migration, posting, and backup.</span></a>
        </nav>
    </div>
    <section>
        <div class="wrap skin-catalogue">
            <?php foreach ($skins as [$name, $images, $domain, $url, $description, $mode, $style]): ?>
                <article class="skin-entry" tabindex="0" data-stats="<?php echo ss_skin_card_stats($domain, $_skin_demo_stats); ?>">
                    <div class="skin-gallery">
                        <?php foreach ($images as $index => $image): ?>
                            <?php $view = $index === 0 ? 'Landing' : ($index === 1 ? 'Archive / Solo' : 'Page'); ?>
                            <a class="shot" href="img/<?php echo htmlspecialchars($image); ?>" target="_blank" rel="noopener">
                                <img src="img/<?php echo htmlspecialchars($image); ?>" alt="<?php echo htmlspecialchars($name . ' skin ' . strtolower($view) . ' view'); ?>" width="1920" height="1080" loading="lazy">
                                <span class="shot-label"><?php echo htmlspecialchars($view); ?></span>
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
