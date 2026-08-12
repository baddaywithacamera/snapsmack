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
    ['The Grid', ['grid-landing.png', 'grid-solo.png', 'grid-page.png'], 'unzucked.ca', 'https://unzucked.ca', 'A disciplined three-column gram grid with punctuation rows and carousel posts.'],
    ['50 Shades of Noah Grey', ['50shades-landing.png', '50shades-archive.png', '50shades-page.png'], 'photowalk.ing', 'https://photowalk.ing', 'A quiet monochrome presentation built for wandering, observation, and grey weather.'],
    ['Galleria', ['galleria-landing.png', 'galleria-archive.png', 'galleria-page.png'], 'hekeepsdroningon.ca', 'https://hekeepsdroningon.ca', 'A gallery-first skin with generous images and minimal interference.'],
    ['Impact Printer', ['impact-landing.png', 'impact-archive.png', 'impact-page.png'], 'pixhellated.ca', 'https://pixhellated.ca', 'Hard type, rough edges, and the visual manners of printed matter.'],
    ['Rational Geo', ['rationalgeo-landing.png', 'rationalgeo-archive.png', 'rationalgeo-page.png'], 'wateronthebrain.ca', 'https://wateronthebrain.ca', 'Measured geometry for photographs that benefit from order and breathing room.'],
    ['True Grit', ['truegrit-landing.png', 'truegrit-archive.png', 'truegrit-page.png'], 'foundtextures.ca', 'https://foundtextures.ca', 'Texture-forward presentation for surfaces, details, and weathered things.'],
    ['Chaplin', ['chaplin-landing.png', 'chaplin-archive.png', 'chaplin-page.png'], 'acolourlesslife.ca', 'https://acolourlesslife.ca', 'Silent-film typography and monochrome restraint without the title cards taking over.'],
    ['Slickr', ['slickr-landing.png', 'slickr-archive.png', 'slickr-page.png'], 'foreverphotograph.ing', 'https://foreverphotograph.ing', 'A familiar home for a Flickr archive after the archive becomes yours again.'],
    ['Parade', ['parade-landing.png', 'parade-archive.png', 'parade-page.png'], 'theschoolofhardnocks.ca', 'https://theschoolofhardnocks.ca', 'An LGBT+ identity skin that flies your choice of Rainbow, Progress Pride, Trans, Bisexual, Non-Binary, Pansexual, Lesbian, Asexual, Aromantic, Genderfluid, Genderqueer, or Two-Spirit flag behind a classic three-across grid. The full-screen flag waves, the tile borders carry its colours, and reduced-motion visitors receive a still version.'],
    ['Instant Camera', ['instantcam-landing.png', 'instantcam-archive.png', 'instantcam-page.png'], 'fauxlaroid.fyi', 'https://fauxlaroid.fyi', 'Faux-Polaroid grams with all the charm and none of the chemical disposal.'],
    ['Aurora', ['aurora-landing.png', 'aurora-archive.png', 'aurora-page.png'], 'lightafterdark.ca', 'https://lightafterdark.ca', 'A dark, luminous skin for night photography and available light.'],
    ['Jive Turkey', ['jturk-landing.png', 'jturk-archive.png', 'jturk-page.png'], 'craptasti.ca', 'https://craptasti.ca', 'Loud, playful, and unwilling to behave like a tasteful portfolio.'],
    ['SCROLL', ['scroll-landing.png', 'scroll-archive.png', 'scroll-page.png'], 'usedcarparts.photoblogs.fyi', 'https://usedcarparts.photoblogs.fyi', 'A typographic masthead and native-ratio photo wall for stories found in fragments.'],
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
            <?php foreach ($skins as [$name, $images, $domain, $url, $description]): ?>
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
                    <p><?php echo htmlspecialchars($description); ?></p>
                    <a class="live" href="<?php echo htmlspecialchars($url); ?>" target="_blank" rel="noopener">View <?php echo htmlspecialchars($domain); ?> &rarr;</a>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</main>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
