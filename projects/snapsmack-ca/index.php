<?php
/**
 * SNAPSMACK.CA - Homepage
 * A FRONT DOOR, not a flag: clarity at the door, personality the second they
 * step in. Says what it is and why in ten seconds; the inside pages explain.
 * (Rework 2026-09-12 — spec: _spec/SPEC-snapsmack-ca-rework-v0_2.docx)
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 */

require_once __DIR__ . '/includes/skin-stats.php';

// The RECEIPTS cards are a presentation of the same cached, fail-soft fleet
// summary used elsewhere on this site. Keep the domain as the source of truth
// for both the live URL and its statistics; a missing/zero row renders no card.
$_receipt_sources = [
    ['domain' => 'photowalk.ing', 'image' => '50shades-primary.png', 'width' => 1920, 'height' => 1080],
    ['domain' => 'theschoolofhardnocks.ca', 'image' => 'gameon-primary.png', 'width' => 1920, 'height' => 1032],
    ['domain' => 'portalproject.photoblogs.fyi', 'image' => 'glide-primary.png', 'width' => 1920, 'height' => 1080],
    ['domain' => 'pixhellated.ca', 'image' => 'impact-primary.png', 'width' => 1920, 'height' => 1080, 'metric_label' => 'images and stories', 'since_override' => 'Mar 8, 2026'],
    ['domain' => 'fauxlaroid.fyi', 'image' => 'instantcam-primary.png', 'width' => 1920, 'height' => 1080],
    ['domain' => 'craptasti.ca', 'image' => 'jturk-primary.png', 'width' => 1920, 'height' => 1080],
    ['domain' => 'curbappeal.photoblogs.fyi', 'image' => 'newhorizon-landing.png', 'width' => 1920, 'height' => 1080],
    ['domain' => 'foreverphotograph.ing', 'image' => 'slickr-primary.png', 'width' => 1920, 'height' => 1080],
    ['domain' => 'baddaywithacamera.ca', 'image' => 'tilez-primary.png', 'width' => 1920, 'height' => 1080, 'since_override' => 'Feb 1, 2024'],
    ['domain' => 'foundtextures.ca', 'image' => 'truegrit-primary.png', 'width' => 1920, 'height' => 1080],
    ['domain' => 'allinthewrist.photoblogs.fyi', 'image' => 'allinthewrist-landing.png', 'width' => 1920, 'height' => 1080],
];
$_receipt_overrides = [
    'theschoolofhardnocks.ca' => ['since_override' => 'Jul 2019'],
    'mccormickphotography.com' => ['since_override' => 'May 10, 2020'],
];
$_receipt_cards = [];
foreach ($_receipt_sources as $_receipt_source) {
    $_receipt_domain = $_receipt_source['domain'];
    if (isset($_receipt_overrides[$_receipt_domain])) {
        $_receipt_source = array_replace($_receipt_source, $_receipt_overrides[$_receipt_domain]);
    }
    $_receipt_stats = $_skin_demo_stats[$_receipt_domain] ?? null;
    $_receipt_url = $_skin_fleet_sources[$_receipt_domain] ?? null;
    $_receipt_count = is_array($_receipt_stats) ? (int)($_receipt_stats['posts'] ?? 0) : 0;
    $_receipt_since = is_array($_receipt_stats) ? (string)($_receipt_stats['active_since'] ?? '') : '';
    if (!$_receipt_url || $_receipt_count < 1 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $_receipt_since)) {
        continue;
    }
    $_receipt_date = DateTimeImmutable::createFromFormat('!Y-m-d', $_receipt_since);
    if (!$_receipt_date) {
        continue;
    }
    $_receipt_source['url'] = $_receipt_url;
    $_receipt_source['name'] = trim((string)($_receipt_stats['site_name'] ?? '')) ?: $_receipt_domain;
    $_receipt_source['count'] = $_receipt_count;
    $_receipt_source['since'] = (string)($_receipt_source['since_override'] ?? $_receipt_date->format('M j, Y'));
    $_receipt_source['metric_label'] = (string)($_receipt_source['metric_label'] ?? 'images');
    $_receipt_cards[] = $_receipt_source;
}
unset($_receipt_source, $_receipt_domain, $_receipt_stats, $_receipt_url, $_receipt_count, $_receipt_since, $_receipt_date, $_receipt_sources, $_receipt_overrides);

$page_title       = 'SnapSmack - Self-Hosted Photo Publishing and Instagram Alternative';
$page_description = 'What is new is old again. Free, self-hosted photo blogging that gives back the single-photo blog, long-form posting, classic Insta and Picasa-style albums, on hardware you control. Your photos. Your voice. Your style. Your dignity.';
$page_og_url      = 'https://snapsmack.ca/';
$page_social_title = 'SnapSmack - Retro Photo Blogging. No Algorithm.';
$page_social_description = 'Self-hosted photo blogging that brings back the gallery, the grid, and the photoblog. Your photos. Your voice. Your style. Your dignity.';
$nav_active       = 'index';

$page_css = <<<'CSS'
/* --- THE DOOR: one thing wins --- */
#door { padding: 88px 0 72px; }
.door-inner { display: grid; grid-template-columns: minmax(0, 1fr) minmax(280px, 40%); gap: 48px; align-items: center; max-width: var(--max); margin: 0 auto; padding: 0 32px; }
.door-media { min-width: 0; }
.door-shot { display: block; width: 100%; padding: 0; border: 0; background: none; cursor: zoom-in; text-align: left; font: inherit; }
.door-shot img { display: block; width: 100%; height: auto; border: 3px solid var(--black); box-shadow: 8px 8px 0 var(--red); }
.door-shot:hover img, .door-shot:focus-visible img { box-shadow: 8px 8px 0 var(--black); border-color: var(--red); outline: 3px solid var(--red); outline-offset: -3px; }
.door-shot-cap { display: block; margin-top: 14px; color: #666; font: 900 .7rem/1.3 Arial Black, Arial, sans-serif; letter-spacing: .06em; text-transform: uppercase; }
.door-shot-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-top: 20px; }
.door-shot-thumb { display: block; width: 100%; padding: 0; border: 1px solid #777; background: var(--black); cursor: zoom-in; }
.door-shot-thumb:hover, .door-shot-thumb:focus-visible { border-color: var(--red); outline: 3px solid var(--red); outline-offset: -3px; }
.door-shot-thumb img { display: block; width: 100%; height: auto; }
.ss-lightbox { position: fixed; inset: 0; z-index: 9999; display: grid; grid-template-columns: 72px minmax(0, 1fr) 72px; grid-template-rows: minmax(0, 1fr) auto; align-items: center; gap: 12px; padding: 24px; background: rgba(0,0,0,.92); cursor: zoom-out; }
.ss-lightbox[hidden] { display: none; }
.ss-lightbox img { display: block; grid-column: 2; grid-row: 1; width: 100%; height: 78vh; object-fit: contain; cursor: default; }
.ss-lightbox-close { position: absolute; top: 16px; right: 24px; width: 56px; height: 56px; border: 0; background: var(--red); color: var(--white); font: 900 2rem/1 Arial Black, Arial, sans-serif; cursor: pointer; }
.ss-lightbox-nav { width: 56px; height: 72px; border: 2px solid var(--white); color: var(--white); background: rgba(0,0,0,.35); font: 900 2rem/1 Arial Black, Arial, sans-serif; cursor: pointer; }
.ss-lightbox-nav:hover, .ss-lightbox-nav:focus-visible { color: var(--black); background: var(--white); }
.ss-lightbox-prev { grid-column: 1; grid-row: 1; }
.ss-lightbox-next { grid-column: 3; grid-row: 1; }
.ss-lightbox-caption { grid-column: 1 / -1; grid-row: 2; margin: 0; color: var(--white); font: 900 .85rem/1.35 Arial Black, Arial, sans-serif; text-align: center; text-transform: uppercase; }
.ss-lightbox-open { overflow: hidden; }
.door-hook { max-width: 980px; font-size: clamp(1.9rem, 4vw, 3.3rem); }
.door-kicker { margin-top: 6px; color: var(--red); font: 900 .82rem/1.3 'Courier New', monospace; letter-spacing: .12em; text-transform: uppercase; }
.door-sub { max-width: 800px; margin-top: 8px; font-size: clamp(1.15rem, 2vw, 1.4rem); line-height: 1.5; color: #333; }
.door-editor { max-width: 800px; margin-top: 18px; font-size: clamp(1.05rem, 1.6vw, 1.2rem); line-height: 1.5; color: #333; }
.door-editor a { color: var(--red); text-decoration: underline; }
.door-never { max-width: 800px; margin-top: 14px; color: var(--black); font: 900 clamp(1.05rem, 1.8vw, 1.3rem)/1.35 Arial Black, Arial, sans-serif; text-transform: uppercase; letter-spacing: -.01em; }
.door-never em { color: var(--red); font-style: normal; }
.door-actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 34px; }
.btn { display: inline-block; padding: 14px 22px; border: 2px solid var(--black); color: var(--black); font: 900 .8rem/1 Arial Black, Arial, sans-serif; text-transform: uppercase; letter-spacing: .03em; }
.btn:hover { text-decoration: none; }
.btn-primary { color: var(--white); background: var(--red); border-color: var(--red); }
.btn-primary:hover { color: var(--white); background: var(--black); border-color: var(--black); }
.btn-secondary:hover { color: var(--white); background: var(--black); }

/* --- BUILT FOR USE: Sean's statement, in Sean's words --- */
#built-for-use { padding: 68px 0; background: #404348; color: var(--white); border-top: 8px solid var(--red); }
#built-for-use .site-discovery-kicker { color: var(--white); }
#built-for-use h2 { max-width: 900px; margin-bottom: 26px; color: var(--white); }
#built-for-use a, #built-for-use a:hover, #built-for-use a:focus-visible { color: var(--white); text-decoration: underline; }
.built-for-use-layout { display: grid; grid-template-columns: minmax(0, 2fr) minmax(260px, 1fr); gap: 42px; align-items: start; }
.built-for-use-copy { max-width: 920px; }
.built-for-use-copy p { font-size: inherit; line-height: inherit; }
.built-for-use-copy p + p { margin-top: 20px; }
.receipts { min-width: 0; }
.receipts h3 { margin: 0 0 14px; color: var(--white); font: 900 .8rem/1.2 Arial Black, Arial, sans-serif; letter-spacing: .08em; text-transform: uppercase; }
.receipt-stack { display: grid; gap: 9px; }
.receipt-card { display: grid; grid-template-columns: 104px minmax(0, 1fr); gap: 12px; min-width: 0; padding: 8px; color: var(--white); background: #303236; border: 1px solid #686b70; }
.receipt-card:hover, .receipt-card:focus-visible { color: var(--white); text-decoration: none; border-color: var(--white); background: #25272a; }
.receipt-card img { display: block; width: 104px; height: 62px; object-fit: cover; border: 1px solid #777a7f; }
.receipt-card strong { display: block; overflow: hidden; color: var(--white); font: 900 .68rem/1.25 Arial Black, Arial, sans-serif; text-overflow: ellipsis; text-transform: uppercase; white-space: nowrap; }
.receipt-card span { display: block; margin-top: 5px; color: #d5d6d8; font: .66rem/1.35 'Courier New', monospace; }
.home-rhythm-right { width: 66.6667%; margin-left: auto; }

/* --- THE DELAY: the date moved; the standard did not --- */
#running-late { padding: 68px 0; background: #404348; color: var(--white); border-top: 8px solid var(--black); }
#running-late .site-discovery-kicker { color: var(--white); }
#running-late h2 { max-width: 900px; margin-bottom: 26px; color: var(--white); }
.running-late-copy { max-width: 920px; }
.running-late-copy p { font-size: inherit; line-height: inherit; }
.running-late-copy p + p { margin-top: 18px; }
.running-late-copy a { color: var(--white); text-decoration: underline; text-decoration-color: var(--white); }
.running-late-copy a:hover { color: var(--white); text-decoration-color: var(--white); text-decoration-thickness: 2px; }


/* --- WHAT WAS: the four things taken, and their names here --- */
#was { padding: 64px 0; background: #2a2a2a; color: #ddd; border-top: 8px solid var(--red); }
#was .site-discovery-kicker { color: #F50A0A; }
#was h2 { margin-bottom: 26px; color: var(--white); }
.was-list { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 24px; margin: 0; padding: 0; list-style: none; }
.was-list li { display: flex; flex-direction: column; margin: 0; padding-top: 14px; border-top: 3px solid var(--red); }
.was-list strong { color: var(--white); font: 900 .95rem/1.2 Arial Black, Arial, sans-serif; text-transform: uppercase; }
.was-list span { margin-top: 10px; color: #ddd; font-size: .9rem; line-height: 1.5; }
.was-list em { margin-top: auto; padding-top: 14px; color: #aaa; font: 900 .7rem/1.3 Arial Black, Arial, sans-serif; letter-spacing: .06em; text-transform: uppercase; font-style: normal; }
.was-list em a { color: var(--white); text-decoration: underline; }
.was-list em a:hover { color: var(--white); text-decoration-thickness: 2px; }
.was-closer { max-width: 800px; margin-top: 34px; color: #F50A0A; font: 900 clamp(1.05rem, 1.8vw, 1.3rem)/1.35 Arial Black, Arial, sans-serif; text-transform: uppercase; letter-spacing: -.01em; }

/* --- THE OTHER HALF: the digicam revival needs its native home --- */
#camera-blog { padding: 64px 0; background: var(--white); border-top: 8px solid var(--black); }
#camera-blog h2 { max-width: 900px; margin-bottom: 24px; }
.camera-blog-layout { display: grid; grid-template-columns: minmax(0, 1fr) minmax(280px, 360px); gap: 42px; align-items: center; }
.camera-blog-copy { max-width: 900px; }
.camera-blog-copy p { font-size: inherit; line-height: inherit; }
.camera-blog-copy p + p { margin-top: 18px; color: var(--red); font: 900 clamp(1.05rem, 1.8vw, 1.3rem)/1.35 Arial Black, Arial, sans-serif; text-transform: uppercase; }
.camera-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; width: 100%; justify-self: end; }
.camera-card { margin: 0; }
.camera-card button { display: block; width: 100%; padding: 0; border: 3px solid var(--black); background: var(--black); cursor: zoom-in; }
.camera-card button:hover, .camera-card button:focus-visible { border-color: var(--red); outline: 3px solid var(--red); outline-offset: -3px; }
.camera-card img { display: block; width: 100%; aspect-ratio: 1 / 1; object-fit: cover; }

/* --- WHY A BLOG: the ownership case, in one hard black band --- */
#why-blog { padding: 64px 0; color: #ddd; background: #111; border-top: 8px solid var(--red); }
#why-blog .site-discovery-kicker { color: var(--white); }
#why-blog h2 { max-width: 900px; margin-bottom: 24px; color: var(--red); }
.why-blog-layout { display: grid; grid-template-columns: minmax(260px, 1fr) minmax(0, 2fr); gap: 42px; align-items: center; }
.why-blog-shot { display: block; width: 100%; padding: 0; border: 1px solid #777; background: none; cursor: zoom-in; }
.why-blog-shot:hover, .why-blog-shot:focus-visible { border-color: var(--red); outline: 3px solid var(--red); outline-offset: -3px; }
.why-blog-shot img { display: block; width: 100%; height: auto; }
.why-blog-copy { max-width: 900px; }
.why-blog-copy p { font-size: inherit; line-height: inherit; }
.why-blog-copy p + p { margin-top: 18px; }
.why-blog-copy .why-blog-pull { color: var(--red); font: 900 clamp(1.05rem, 1.8vw, 1.3rem)/1.35 Arial Black, Arial, sans-serif; text-transform: uppercase; }

/* --- NOT ALONE: outcome, then the triptych as proof, plumbing last --- */
#alone { padding: 64px 0 24px; }
#alone h2 { max-width: 900px; }
#alone .lede { max-width: none; font-size: 21.25px; line-height: 1.55; }
.alone-payoff { max-width: 800px; margin-top: 18px; color: var(--red); font: 900 clamp(1.05rem, 1.8vw, 1.3rem)/1.35 Arial Black, Arial, sans-serif; text-transform: uppercase; letter-spacing: -.01em; }
.fed-shots { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin: 30px 0 0; }
.fed-shots figure { margin: 0; background: var(--white); border: 1px solid var(--border); }
.fed-shots button { display: block; width: 100%; padding: 0; border: 0; background: none; cursor: zoom-in; }
.fed-shots button:hover, .fed-shots button:focus-visible { outline: 3px solid var(--red); outline-offset: -3px; }
.fed-shots img { display: block; width: 100%; aspect-ratio: 16 / 9; object-fit: cover; object-position: top; }
.fed-shots figcaption { padding: 9px 12px; color: var(--black); font: 900 .72rem/1.3 Arial Black, Arial, sans-serif; text-transform: uppercase; }
.alone-how { max-width: 900px; margin-top: 22px; color: #666; font-size: .82rem; line-height: 1.5; }
.alone-how a { color: var(--red); }

/* --- THREE PILLARS: the order is the policy --- */
#three-pillars { padding: 64px 0; color: #ddd; background: #25272a; border-top: 8px solid var(--red); }
#three-pillars .site-discovery-kicker { color: var(--white); }
#three-pillars h2 { color: var(--white); }
.three-pillars-copy { width: 100%; font-size: 21.25px; line-height: 1.55; }
.three-pillars-copy p + p { margin-top: 22px; }
.three-pillars-copy strong { color: var(--white); font-family: Arial Black, Arial, sans-serif; }

/* --- SCALE: one fleet, one dashboard --- */
#scale { padding: 64px 0; color: #ddd; background: #000; border-top: 8px solid var(--red); }
#scale h2 { margin: 0; color: var(--red); }
.scale-layout { display: grid; grid-template-columns: minmax(260px, 1fr) minmax(0, 2fr); gap: 42px; align-items: center; }
.scale-shot { display: block; width: 100%; padding: 0; border: 1px solid #777; background: none; cursor: zoom-in; }
.scale-shot:hover, .scale-shot:focus-visible { border-color: var(--red); outline: 3px solid var(--red); outline-offset: -3px; }
.scale-shot img { display: block; width: 100%; height: auto; }
.scale-copy { font-size: 21.25px; line-height: 1.55; }
.scale-copy p + p { margin-top: 22px; }
.scale-heading { margin-bottom: 26px; }
.scale-copy .scale-pull { margin: 6px 0 0; color: #D5D6D8; font: 900 clamp(1rem, 1.6vw, 1.2rem)/1.35 Arial Black, Arial, sans-serif; }
.scale-copy .scale-heading + p { margin-top: 0; }

/* --- SKINS BAND --- */
#featured-skins { background: var(--white); color: var(--dark-grey); border-top: 8px solid var(--black); }
#featured-skins h2 { color: var(--red); }
#featured-skins .lede { color: var(--mid-grey); }
.section-heading { max-width: 760px; margin-bottom: 38px; }
.featured-skin-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 20px; }
.featured-skin { color: var(--dark-grey); }
.featured-skin:hover { color: var(--black); text-decoration: none; }
.featured-skin img { width: 100%; aspect-ratio: 16 / 10; box-sizing: border-box; object-fit: cover; border: 3px solid var(--black); }
.featured-skin:hover img, .featured-skin:focus-visible img { border-color: var(--red); }
.featured-skin strong { display: block; margin-top: 12px; color: var(--black); font: 900 .95rem/1.15 Arial Black, Arial, sans-serif; text-transform: uppercase; }
.featured-skin span { display: block; margin-top: 4px; color: var(--mid-grey); font-size: .78rem; }
.section-link { margin-top: 30px; }
.section-link a { color: var(--red); font: 900 .78rem/1 Arial Black, Arial, sans-serif; text-transform: uppercase; }

/* --- BELOW THE FOLD: the flag lives here --- */
.xkcd-proof { padding: 54px 0; color: #ddd; background: #2a2a2a; border-top: 8px solid var(--red); border-bottom: 1px solid #444; }
.xkcd-proof-inner { max-width: 900px; margin: 0 auto; padding: 0 32px; }
.xkcd-proof-kicker { margin-bottom: 18px; color: var(--white); font: 900 clamp(1.15rem, 2vw, 1.5rem)/1.15 Arial Black, Arial, sans-serif; text-transform: uppercase; letter-spacing: -.01em; }
.xkcd-proof figure { margin: 0; }
.xkcd-proof-image { width: 100%; background: var(--white); }
.xkcd-proof figcaption { margin-top: 10px; color: #ddd; font: .7rem/1.4 'Courier New', monospace; }
.xkcd-proof figcaption a { color: var(--white); text-decoration: underline; }
.xkcd-proof figcaption a:hover { color: var(--white); text-decoration-thickness: 2px; }

#custodian { background: var(--white); color: var(--dark-grey); border-top: 8px solid var(--black); }
#custodian h2 { color: var(--red); }
#custodian .site-discovery-kicker { color: var(--red); }
#custodian a { color: var(--red); }
#custodian a:hover { color: var(--black); }
.custodian-inner { max-width: 820px; }
.custodian-quote { margin: 0 0 26px; padding-left: 26px; border-left: 6px solid var(--red); color: var(--black); font-size: clamp(1.25rem, 2.4vw, 1.7rem); line-height: 1.45; font-style: italic; }
.custodian-quote cite { display: block; margin-top: 12px; color: var(--mid-grey); font: 700 .72rem/1.3 'Courier New', monospace; font-style: normal; letter-spacing: .08em; text-transform: uppercase; }
.custodian-points { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1px; margin-top: 34px; background: #666; border: 1px solid #666; }
.custodian-points article { padding: 24px; background: #292929; color: var(--white); }
.custodian-points h3 { color: #F50A0A; }
#custodian .custodian-points a { color: var(--white); }
.custodian-points a:hover { color: var(--white); text-decoration: underline; }
.custodian-points p { margin: 0; font-size: .92rem; line-height: 1.55; }

#respect, #whodat { border-top: 8px solid var(--black); }
#respect { background: #1f1f1f; color: #ddd; border-top-color: var(--red); }
#respect h2 { color: var(--white); }
#respect a { color: var(--white); text-decoration: underline; }
#respect a:hover { color: var(--white); }
#whodat { padding-top: 46px; color: var(--dark-grey); background: #f4f4f4; border-top-color: var(--black); }
#whodat h2 { color: var(--black); }
#whodat > .wrap > .whodat-honest a { color: var(--red); }
.whodat-order { margin: -10px 0 24px; color: #666; font: 700 .75rem/1.4 'Courier New', monospace; text-transform: uppercase; }
.whodat-grid { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 26px; }
.whodat-card { grid-column: span 2; color: var(--dark-grey); background: var(--white); border: 1px solid #d2d2d2; padding: 22px; }
.whodat-card:nth-last-child(2):nth-child(3n + 1) { grid-column: 2 / span 2; }
.whodat-card a { color: var(--red); }
.whodat-portrait { margin: -22px -22px 20px; background: var(--white); }
.whodat-portrait-button { display: block; width: 100%; height: 100%; padding: 0; border: 3px solid transparent; box-sizing: border-box; background: var(--white); cursor: zoom-in; }
.whodat-portrait-button:hover, .whodat-portrait-button:focus-visible { border-color: var(--red); outline: 3px solid var(--red); outline-offset: -3px; }
.whodat-portrait img { display: block; width: 100%; aspect-ratio: 1 / 1; padding: 10px; box-sizing: border-box; object-fit: contain; object-position: center; }
.whodat-name { margin: 0; color: var(--black); font: 900 1rem/1.2 Arial Black, Arial, sans-serif; text-transform: uppercase; }
.whodat-title { margin: 5px 0 15px; color: var(--red); font: 700 .72rem/1.35 'Courier New', monospace; text-transform: uppercase; }
.whodat-bio { font-size: .83rem; line-height: 1.55; }
.whodat-honest { max-width: 100%; margin-bottom: 30px; }
.whodat-why { width: 100%; margin: 0 0 28px; font-size: 21.25px; line-height: 1.55; }
.whodat-why strong { display: block; margin-bottom: 8px; color: var(--black); font-family: Arial Black, Arial, sans-serif; }

@media (max-width: 1100px) {
    /* Two, not three: with four cards a three-across row strands the fourth one
       alone on a second row anywhere from ~700px to 1100px wide. Two gives a
       clean 2x2. Measured: 1050px -> 474px cards, 900px -> 399px, 750px -> 331px,
       no stranded card at any of them. (Was three when the list had five cards.) */
    .was-list { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 1024px) {
    .door-inner { grid-template-columns: 1fr; gap: 34px; align-items: start; }
    .door-copy { order: 1; }
    .door-media { order: 2; }
    .home-rhythm-right { width: 100%; margin-left: 0; }
    .built-for-use-layout { grid-template-columns: 1fr; gap: 34px; }
    .built-for-use-layout .built-for-use-copy-wrap { order: 1; }
    .built-for-use-layout .receipts { order: 2; }
    .receipt-stack { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .receipt-card:last-child:nth-child(odd) { grid-column: 1 / -1; width: calc(50% - 5px); box-sizing: border-box; justify-self: center; }
    .camera-blog-layout, .why-blog-layout, .scale-layout { grid-template-columns: 1fr; gap: 30px; }
    .camera-blog-copy { order: 1; }
    .camera-grid { order: 2; width: min(100%, 720px); justify-self: start; }
    .why-blog-copy-wrap { order: 1; }
    .why-blog-media { order: 2; }
    .scale-copy { order: 1; }
    .scale-media { order: 2; }
}
@media (max-width: 850px) {
    .featured-skin-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .was-list { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .fed-shots { grid-template-columns: 1fr; }
    .custodian-points { grid-template-columns: 1fr; }
    .whodat-grid { grid-template-columns: 1fr; }
    .whodat-card { display: grid; grid-template-columns: 150px 1fr; gap: 22px; }
    .whodat-portrait { margin: -22px 0 -22px -22px; }
    .whodat-portrait img { height: 100%; }
}
@media (max-width: 700px) {
    #door { padding: 56px 0 44px; }
    .door-inner { grid-template-columns: 1fr; gap: 32px; }
    .featured-skin-grid { grid-template-columns: 1fr; }
    .was-list { grid-template-columns: 1fr; }
    .whodat-card { display: block; }
    .whodat-portrait { margin: -22px -22px 20px; }
    .receipt-stack { grid-template-columns: 1fr; }
    .receipt-card:last-child:nth-child(odd) { grid-column: auto; width: 100%; }
}
CSS;

require_once __DIR__ . '/includes/header.php';
?>

<main>
    <section id="door">
        <div class="door-inner">
            <div class="door-copy">
            <h1 class="door-hook">Retro Photo Blogging.<br><span>No Algorithm.</span></h1>
            <p class="door-kicker">What&rsquo;s new is old again.</p>
            <p class="door-sub">We&rsquo;re not offering you anything new. We&rsquo;re giving back what was taken: the single-photo blog, long-form posting that hasn&rsquo;t been enshittified, classic Insta styling, and pumping images straight out of a file manager. All of it used to belong to photographers. Now it lives on hardware you control, and nobody is taking it back from you.</p>
            <p class="door-editor">And a free, powerful <a href="tool-snap-slapper.php">photo editor and organizer</a> because we remember when photographers were given good software for no charge rather than being told they needed to pay monthly rent for it.</p>
            <p class="door-never">Your photos. Your voice.<br>Your style. <em>Your dignity.</em></p>
            <div class="door-actions">
                <a href="features.php" class="btn btn-secondary">What it does</a>
            </div>
            </div>
            <div class="door-media">
                <button type="button" class="door-shot" data-lightbox="img/snapslapper-editor-adv.png" data-lightbox-group="snapslapper" data-lightbox-caption="SNAP SLAPPER photo editor, advanced mode, editing a Banff landscape" aria-label="Open a full-size screenshot of the SNAP SLAPPER photo editor">
                    <img src="img/snapslapper-editor-adv.png" alt="SNAP SLAPPER photo editor, advanced mode, editing a Banff landscape" width="1920" height="1032" loading="lazy">
                </button>
                <div class="door-shot-grid" aria-label="More SNAP SLAPPER screenshots">
                    <button type="button" class="door-shot-thumb" data-lightbox="img/snapslapper-editor-adv-nofilm-large.png" data-lightbox-group="snapslapper" data-lightbox-caption="SNAP SLAPPER editor, Advanced mode"><img src="img/snapslapper-editor-adv-nofilm-thumb.png" alt="SNAP SLAPPER editor, Advanced mode" width="480" height="258" loading="lazy"></button>
                    <button type="button" class="door-shot-thumb" data-lightbox="img/snapslapper-editor-lewks-large.png" data-lightbox-group="snapslapper" data-lightbox-caption="LEWKS: one-click looks previewed on your own photo"><img src="img/snapslapper-editor-lewks-thumb.png" alt="LEWKS: one-click looks previewed on your own photo" width="480" height="258" loading="lazy"></button>
                    <button type="button" class="door-shot-thumb" data-lightbox="img/snapslapper-genfill-01-large.png" data-lightbox-group="snapslapper" data-lightbox-caption="AI Heal: paint the defect, choose whether to send it"><img src="img/snapslapper-genfill-01-thumb.png" alt="AI Heal: paint the defect, choose whether to send it" width="480" height="270" loading="lazy"></button>
                    <button type="button" class="door-shot-thumb" data-lightbox="img/snapslapper-library-large.png" data-lightbox-group="snapslapper" data-lightbox-caption="The photo library"><img src="img/snapslapper-library-thumb.png" alt="The photo library" width="480" height="258" loading="lazy"></button>
                </div>
                <span class="door-shot-cap">SNAP SLAPPER &mdash; the free photo editor. Click screenshots to enlarge.</span>
            </div>
        </div>
    </section>

    <section id="was" aria-label="What was, and what can be again">
        <div class="wrap">
            <p class="site-discovery-kicker">When this baby hits 88 MPH, you're gonna see some serious shit.</p>
            <h2>What was. What can be again.</h2>
            <ul class="was-list">
                <li><strong>The photoblog</strong><span>2003 to 2010. One photograph a day on one page, big, with the comments underneath and nothing else competing for the eye. Pixelpost, Photoblogs.org, a ring of people who looked at each other&rsquo;s work every morning and said so. It ran on your own domain, so when the ring dissolved, the work stayed put.</span><em>Again as <a href="features.php#modes">SMACKONEOUT</a></em></li>
                <li><strong>Classic Instagram</strong><span>2010 to 2016. A square grid, three across, in the order you posted it, seen by the people who chose to follow you. Likes were a count, not a currency. No algorithm, no ads, no Reels, no shop tab, no video autoplaying between your photographs because a spreadsheet said it should.</span><em>Again as <a href="features.php#modes">GRAMOFSMACK</a></em></li>
                <li><strong>Early blogging</strong><span>1999 onward. Blogger, then Greymatter in 2000 &mdash; Noah Grey was publishing his photographs with it from day one &mdash; then WordPress before it became a page builder. Writing with photographs in it, as long as you wanted, nobody&rsquo;s feed deciding whether it got read.</span><em>Again as <a href="features.php#modes">SMACKTALK</a></em></li>
                <li><strong>Picasa Web Albums</strong><span>2006 to 2016. A free, friendly photo organizer that pushed your folders to the web as albums for friends and family. Send the link, no account needed to look, no &ldquo;suggested for you&rdquo; underneath. Friends and family, not followers: SMACKTHEMUP has no fediverse on purpose. Sharing simplified.</span><em>Again as <a href="features.php#modes">SMACKTHEMUP</a> + <a href="tool-snap-slapper.php">SNAP SLAPPER</a></em></li>
            </ul>
            <p class="was-closer">Everything was killed off, died off, or was walled off to prioritize profits over people.</p>
        </div>
    </section>

    <section id="camera-blog" aria-labelledby="camera-blog-title">
        <div class="wrap">
            <div class="camera-blog-layout">
            <div class="camera-blog-copy">
                <p class="site-discovery-kicker">The other half of the look</p>
                <h2 id="camera-blog-title">You bought the camera. Where&rsquo;s the blog?</h2>
                <p>Hey, Gen Z... That beat-up 2005 point-and-shoot you paid way too much for on eBay? It came with a second half, and nobody told you. Back then, those photos didn't go on an app that buries them under ads by Tuesday. They went on a photoblog: your own site, one shot at a time, newest on top, no algorithm, no likes for sale. People found you through other people's blogrolls, and they came back because they wanted to.</p>
                <p>The camera's only half the look. The blog is the other half. Make your photos slap.</p>
            </div>
            <div class="camera-grid" aria-label="Four point-and-shoot cameras">
                <figure class="camera-card"><button type="button" data-lightbox="img/pointnshoot-canon-large.jpg" data-lightbox-group="camera-blog" data-lightbox-caption="Canon Powershot S3IS, $10 thrift find"><img src="img/pointnshoot-canon-thumb.jpg" alt="Canon Powershot S3IS, $10 thrift find" width="480" height="480" loading="lazy"></button></figure>
                <figure class="camera-card"><button type="button" data-lightbox="img/pointnshoot-fujifilm-large.jpg" data-lightbox-group="camera-blog" data-lightbox-caption="Fujifilm Finepix F480, $20 thrift find"><img src="img/pointnshoot-fujifilm-thumb.jpg" alt="Fujifilm Finepix F480, $20 thrift find" width="480" height="480" loading="lazy"></button></figure>
                <figure class="camera-card"><button type="button" data-lightbox="img/pointnshoot-olympus-large.jpg" data-lightbox-group="camera-blog" data-lightbox-caption="Olympus SP510-UZ, $15 thrift find"><img src="img/pointnshoot-olympus-thumb.jpg" alt="Olympus SP510-UZ, $15 thrift find" width="480" height="480" loading="lazy"></button></figure>
                <figure class="camera-card"><button type="button" data-lightbox="img/pointnshoot-minolta-large.jpg" data-lightbox-group="camera-blog" data-lightbox-caption="Minolta DiMAGE 7, $1,527 new in 2001"><img src="img/pointnshoot-minolta-thumb.jpg" alt="Minolta DiMAGE 7, $1,527 new in 2001" width="480" height="480" loading="lazy"></button></figure>
            </div>
            </div>
        </div>
    </section>

    <section id="why-blog" aria-labelledby="why-blog-title">
        <div class="wrap">
            <div class="why-blog-layout">
            <div class="why-blog-media">
                <button type="button" class="why-blog-shot" data-lightbox="img/admin-dashboard-large.png" data-lightbox-caption="The SnapSmack admin dashboard" aria-label="Open a full-size screenshot of the SnapSmack admin dashboard"><img src="img/admin-dashboard-thumb.png" alt="The SnapSmack admin dashboard" width="480" height="442" loading="lazy"></button>
            </div>
            <div class="why-blog-copy-wrap">
            <p class="site-discovery-kicker">The burning question</p>
            <h2 id="why-blog-title">Why a blog?</h2>
            <div class="why-blog-copy">
                <p>&ldquo;Blog&rdquo; is short for &ldquo;web log&rdquo;: a running record of your work, on a site you own, newest on top. Simple as that.</p>
                <p>So why bother? Because somebody's going to speak for you online. Either it's some billionaire's app, deciding who sees your photos, when, and next to whose ads, or it's you, on your own terms, with no algorithm in the way.</p>
                <p class="why-blog-pull">Are you an adult, or just a product?</p>
            </div>
            </div>
            </div>
        </div>
    </section>

    <section id="running-late" aria-labelledby="running-late-title">
        <div class="wrap">
            <p class="site-discovery-kicker">Closed beta</p>
            <h2 id="running-late-title">Why we&rsquo;re running late.</h2>
            <div class="running-late-copy">
                <p>First and foremost, the software isn't ready yet. It not only has rough edges and some remaining security holes we're fixing, but it is not elegant. That is important. This software is built in the memory of a deceased friend who did everything elegantly and expected the same out of every tool he used. SnapSmack doesn't ship until it reaches the point where he would have enjoyed using it.</p>
                <p>We've had setbacks and are behind schedule, which happens in the real world, and we're sorry for that. But you've probably heard the words &quot;AI slop&quot; &mdash; and this isn't it. What it is, is lacking some polish and some of its wardrobe, as we're also 7 skins short of our launch target. We'll get there. <a href="https://baddaywithacamera.ca/rest-in-peace" target="_blank" rel="noopener">This is for Ray</a>. It will ship when it's what he would approve of.</p>
            </div>
        </div>
    </section>

    <section id="alone" aria-label="You don't have to do it alone">
        <div class="wrap">
            <p class="site-discovery-kicker">Not alone</p>
            <h2>Decentralized social where you hang onto your own files.</h2>
            <p class="lede">The photoblog was killed by social media because people go where the audience is, but that came at the cost of photographers losing control over their art. The plain truth is that a photoblog cannot survive in the present unless it also has social; that&rsquo;s why we built in fediverse compatibility from day one. SnapSmack has a foot in both the traditional Web and the fediverse.</p>
            <p class="lede">What is the fediverse? It is a network of independently run sites that talk to each other using a shared standard, the same way Gmail and Fastmail exchange email without being the same company. Because your SnapSmack site speaks that standard, people on Mastodon, Pixelfed, and other fediverse apps can follow your blog and reply to your photos right from their own accounts. Their comments land back on your site, and your photos stay on your own server. You don't need an account anywhere else to take part. SnapSmack lets you have your cake and eat it, too.</p>
            <p class="alone-payoff">Reach more people without leaving your home.</p>
            <div class="fed-shots">
                <figure><button type="button" data-lightbox="img/fediverse-blog-view.png"><img src="img/fediverse-blog-view.png?v=20260814" alt="The unzucked photoblog on its own SnapSmack site" width="1920" height="1080" loading="lazy"></button><figcaption>Your blog. Your skin, your domain.</figcaption></figure>
                <figure><button type="button" data-lightbox="img/fediverse-home-view.png"><img src="img/fediverse-home-view.png?v=20260814" alt="The same account in the FEDIVERSE network reader" width="1920" height="1080" loading="lazy"></button><figcaption>The same account in the network reader.</figcaption></figure>
                <figure><button type="button" data-lightbox="img/fediverse-pixelfed-ca-view.png"><img src="img/fediverse-pixelfed-ca-view.png?v=20260814" alt="The same photographs seen from Pixelfed, with a follower" width="1920" height="1080" loading="lazy"></button><figcaption>The same photographs on Pixelfed. Someone else&rsquo;s software, following you.</figcaption></figure>
            </div>
            <p class="alone-how">How it works, for those who care: your site speaks ActivityPub &mdash; the fediverse &mdash; so people on Pixelfed, Mastodon, and the rest can follow it directly. photoblogs.fyi is the shared directory and reader. <a href="features.php#network">Details &rarr;</a></p>
        </div>
    </section>

    <section id="three-pillars" aria-labelledby="three-pillars-title">
        <div class="wrap">
            <p class="site-discovery-kicker">The order is the policy</p>
            <h2 id="three-pillars-title">The three pillars</h2>
            <div class="three-pillars-copy">
                <p>SnapSmack is built on three pillars, in this order: <strong>Ethical. Secure. Functional.</strong> Nothing below gets to compromise anything above.</p>
                <p><strong>Ethical first.</strong> We could make SnapSmack more secure by taking control of your installs for you. We won't &mdash; that would be completely unethical. It is YOUR site and YOUR content. We do not have the right to step there.</p>
                <p>Bluesky's AT protocol is more functional than ActivityPub. We use ActivityPub anyway. Bluesky was built with VC money, and it controls infrastructure chokepoints that WILL become chokepoints in the near future, when the VCs demand returns on their investments. And it would be unethical for us to build off-ramps for services like Flickr and Meta &mdash; services that monetize and control you &mdash; only to funnel you into another service that will try to do the same. We made a less functional but more ethical choice.</p>
                <p><strong>Secure second.</strong> Having to punch in a TOTP code and create API key after API key feels like a massive burden that wrecks functionality. It isn't as bad as it feels &mdash; but it does slow a person down. Having your blog raped by hackers is a lot worse. SnapSmack was built partly in response to past bad experiences with insecure software. We put security ahead of convenience because we know from bitter personal experience what happens when you don't.</p>
                <p><strong>Functional third.</strong> It still has to work, and work for everyone. We routinely say no to expensive features &mdash; video, third-party add-ons &mdash; that would slow the engine down and degrade the experience for all users.</p>
            </div>
        </div>
    </section>

    <section id="scale" aria-labelledby="scale-title">
        <div class="wrap">
            <div class="scale-layout">
                <div class="scale-media">
                    <button type="button" class="scale-shot" data-lightbox="img/fleet-view-no-backup.png" data-lightbox-caption="1 hub, 27 spokes, one dashboard" aria-label="Open a full-size screenshot showing 1 hub and 27 spokes in one dashboard"><img src="img/fleet-view-no-backup.png" alt="1 hub, 27 spokes, one dashboard" width="1832" height="1706" loading="lazy"></button>
                </div>
                <div class="scale-copy">
                    <div class="scale-heading">
                        <h2 id="scale-title">Yes, but does it scale?</h2>
                        <p class="scale-pull">Bitch, please. My last name ain't Mullenweg&hellip;</p>
                    </div>
                    <p>28 sites. 1 hub. 27 spokes. All managed through one dashboard. Shared settings pushed from hub to spokes securely. Single sign-on. Integrated, fleet wide stats. Done securely. All of this at zero cost to you.</p>
                    <p>WordPress can't do this without bolting on someone else's plugin. Ghost won't do this for free. Kirby? Get bent.</p>
                    <p>Right out of the box, day one, no charge, Bob's yer fackin' uncle. Multisite without anyone demanding an 8% cut. Who imagined such a thing was possible.</p>
                </div>
            </div>
        </div>
    </section>

    <section id="featured-skins">
        <div class="wrap">
            <div class="section-heading">
                <p class="site-discovery-kicker">Real sites, running today</p>
                <h2>One engine. No house style.</h2>
                <p class="lede">Four live SnapSmack sites, four completely different answers to what a photography website should look like. Hover for the numbers. <strong>All skins are free, always.</strong></p>
            </div>
            <div class="featured-skin-grid">
                <a class="featured-skin" href="https://hekeepsdroningon.ca" target="_blank" rel="noopener" data-stats="<?php echo ss_skin_card_stats('hekeepsdroningon.ca', $_skin_demo_stats); ?>">
                    <img src="img/galleria-primary.png" alt="Galleria skin running on hekeepsdroningon.ca" width="1920" height="1080" loading="lazy">
                    <strong>Galleria</strong><span>hekeepsdroningon.ca</span>
                </a>
                <a class="featured-skin" href="https://usedcarparts.photoblogs.fyi" target="_blank" rel="noopener" data-stats="<?php echo ss_skin_card_stats('usedcarparts.photoblogs.fyi', $_skin_demo_stats); ?>">
                    <img src="img/scroll-primary.png" alt="SCROLL skin running on usedcarparts.photoblogs.fyi" width="1920" height="1080" loading="lazy">
                    <strong>SCROLL</strong><span>usedcarparts.photoblogs.fyi</span>
                </a>
                <a class="featured-skin" href="https://fauxlaroid.fyi" target="_blank" rel="noopener" data-stats="<?php echo ss_skin_card_stats('fauxlaroid.fyi', $_skin_demo_stats); ?>">
                    <img src="img/instantcam-primary.png" alt="Instant Camera skin running on fauxlaroid.fyi" width="1920" height="1080" loading="lazy">
                    <strong>Instant Camera</strong><span>fauxlaroid.fyi</span>
                </a>
                <a class="featured-skin" href="skins.php">
                    <img src="img/gameon-primary.png" alt="GAME ON skin: a field of sliding-puzzle photographs" width="1920" height="1080" loading="lazy">
                    <strong>GAME ON</strong><span>a sliding-puzzle photoblog. Yes, really.</span>
                </a>
            </div>
            <p class="section-link"><a href="skins.php">See every skin &rarr;</a></p>
        </div>
    </section>

    <aside class="xkcd-proof" aria-labelledby="xkcd-proof-title">
        <div class="xkcd-proof-inner">
            <p class="xkcd-proof-kicker" id="xkcd-proof-title">This has been the problem since 2012.</p>
            <figure>
                <a href="https://xkcd.com/1150/" target="_blank" rel="noopener">
                    <img class="xkcd-proof-image" src="img/xkcd-instagram.png" width="1480" height="494" loading="lazy" alt="XKCD comic comparing storing your work on a free social platform to leaving your belongings in someone else's garage.">
                </a>
                <figcaption>&ldquo;<a href="https://xkcd.com/1150/" target="_blank" rel="noopener">Instagram</a>&rdquo; by Randall Munroe / XKCD, used unmodified under <a href="https://creativecommons.org/licenses/by-nc/2.5/" target="_blank" rel="license noopener">CC BY-NC 2.5</a>.</figcaption>
            </figure>
        </div>
    </aside>

    <section id="custodian">
        <div class="wrap">
            <div class="custodian-inner">
                <p class="site-discovery-kicker">Why it&rsquo;s built this way</p>
                <h2>Custodian, not landlord.</h2>
                <blockquote class="custodian-quote">It&rsquo;s a blessing to be trusted with someone&rsquo;s most beautiful possessions. You honour them back. You don&rsquo;t monetize them.<cite>&mdash; Sean McCormick, who built this</cite></blockquote>
                <p>That one sentence is the whole design. Every rule below follows from it, and none of them are negotiable.</p>
            </div>
            <div class="custodian-points">
                <article><h3>Your site is yours</h3><p>Nobody at SnapSmack can lock you out, switch you off, or reach into your site. Not for a bug, not for a breach, not for anything. We have no right to, so we built it so we can&rsquo;t.</p></article>
                <article><h3>You know what we know</h3><p>Security findings, breaches, and audit results are published the day we have them. <a href="buzzers.php">The audits are public</a>. <a href="ding-dong-bell.php">So are the failures</a>.</p></article>
                <article><h3>The door opens both ways</h3><p>Everything you put in comes back out in a form other software can read. Ownership includes the right to stop using SnapSmack.</p></article>
            </div>
        </div>
    </section>

    <section id="built-for-use" aria-labelledby="built-for-use-title">
        <div class="wrap">
            <div class="built-for-use-layout">
            <div class="built-for-use-copy-wrap">
            <p class="site-discovery-kicker">Built for the work</p>
            <h2 id="built-for-use-title">Shoot hard. Share harder.</h2>
            <div class="built-for-use-copy">
                <p>I built SNAPSMACK for myself first. There was nothing left that worked for me, certainly not the last Wordpress install I turned into a smoking crater on a shared host. I'm hell on wheels with a camera and your average CMS cannot handle what I am laying down. So I made one that could.</p>
                <p>SNAPSMACK is built to be worked hard, to be as secure as possible, to make batch posting and bulk sharing easy, big backups reliable and painless, and to do all of this without bleeding you dry because we're all tired of being billed just for breathing. Oh, and it does this with... <em>Real. Fucking. Style.</em></p>
                <p>Here is the one and only catch. It's all built with AI and the support is mostly provided using AI. Straight up, I can kick ass and chew bubblegum with any camera you put in my hands. What I am not is a developer. AI handled that part for me while I made sure that what was built worked and holy shit does it ever. So, yeah. AI is all over this project and I'm not apologizing for it because it would not exist without it, and you wouldn't have a FREE, quality option sitting in front of you.</p>
                <p>I get it if you don't like AI. I'm not happy about aspects of it, either. But it is <a href="https://snapsmack.ca/the-reckoning.php" target="_blank" rel="noopener">the only way</a> that SNAPSMACK could have happened or that I can provide you with quality support moving forward. I, too, wish to take photos and publish them online and the only way I can do that and do this (SNAPSMACK) is by leveraging technology in a way that benefits all of us. Let's use the new tools to return to The Old Ways. Let's bring photo blogging back.</p>
                <p>If you don't want to use an AI product, fair. Use something else. If you can find it. I could not.</p>
            </div>
            </div>
<?php if (!empty($_receipt_cards)): ?>
            <aside class="receipts" aria-labelledby="receipts-title">
                <h3 id="receipts-title">The receipts</h3>
                <div class="receipt-stack">
<?php foreach ($_receipt_cards as $_receipt_card): ?>
                    <a class="receipt-card" href="<?php echo htmlspecialchars($_receipt_card['url'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">
                        <img src="img/<?php echo htmlspecialchars($_receipt_card['image'], ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($_receipt_card['name'], ENT_QUOTES, 'UTF-8'); ?> landing page" width="<?php echo (int)$_receipt_card['width']; ?>" height="<?php echo (int)$_receipt_card['height']; ?>" loading="lazy">
                        <span><strong><?php echo htmlspecialchars($_receipt_card['name'], ENT_QUOTES, 'UTF-8'); ?></strong><?php echo number_format((int)$_receipt_card['count']); ?> <?php echo htmlspecialchars($_receipt_card['metric_label'], ENT_QUOTES, 'UTF-8'); ?> posted since <?php echo htmlspecialchars($_receipt_card['since'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </a>
<?php endforeach; ?>
                </div>
            </aside>
<?php endif; ?>
            </div>
        </div>
    </section>

    <section id="respect">
        <div class="wrap">
            <h2>Respect Where It&rsquo;s Due</h2>
            <p>SnapSmack&rsquo;s design owes a debt to <a href="https://github.com/pixelpost/pixelpost/wiki" target="_blank" rel="noopener">Pixelpost</a> &mdash; a photo blogging platform that quietly disappeared but never stopped being right about a few things. Its UI shaped a lot of what SnapSmack became.</p>
            <p>And particular thanks to photographer, writer, and developer <a href="https://bsky.app/profile/thatnoahgrey.bsky.social" target="_blank" rel="noopener">Noah Grey</a> &mdash; creator of <a href="https://en.wikipedia.org/wiki/Greymatter_(software)" target="_blank" rel="noopener">Greymatter</a>, one of the earliest open-source blogging platforms &mdash; for proving that when the software you need doesn&rsquo;t exist, you build it. And for knowing, twenty-five years ago, that you honour the people who trust you with their work.</p>
        </div>
    </section>

    <section id="whodat">
        <div class="wrap">
            <h2>Who&rsquo;s Responsible for All This?!?</h2>
            <p class="whodat-honest"><strong>Said up front:</strong> the code is AI-written, under the direction of a photographer who is not a programmer. That is not hidden anywhere on this site &mdash; <a href="the-reckoning.php">THE RECKONING</a> counts every line. It is also exactly why the software is subjected to documented internal reviews and adversarial regression tests, and held to a public disclosure policy instead of being trusted on faith. These checks are not independent certification or an independent penetration test.</p>
            <p class="whodat-why"><strong>But WHY make them co-authors?</strong>Gap coding (what I call what I do, making software that fills gaps in what is available) is a conversational process. You say what you need, you refine it, you even spitball ideas. The fact is that AI knows a lot more about making software than I do and has better ideas for making features work right. So I used their ideas instead of mine because producing a working product trumps my male ego. That and my mother taught me you never take credit for someone else's stuff.&nbsp; They earned it. That's why.</p>
            <p class="whodat-order">Listed in the order they joined.</p>
            <div class="whodat-grid">
                <article class="whodat-card">
                    <div class="whodat-portrait"><button type="button" class="whodat-portrait-button" data-lightbox="img/whodat-sean.png" data-lightbox-group="co-authors" data-lightbox-caption="Sean McCormick" aria-label="Open a larger portrait of Sean McCormick"><img src="img/whodat-sean.png" alt="Sean McCormick" width="686" height="784" loading="lazy"></button></div>
                    <div>
                        <p class="whodat-name">Sean McCormick</p>
                        <p class="whodat-title">Just a guy with a camera.</p>
                        <p class="whodat-bio">Photographer, creator, product designer, chief tester, and final decision-maker who got tired of watching his archive evaporate into the memory hole of dying platforms. Conceived and directed SnapSmack because the alternative was continuing to post between ads for hemorrhoid cream. Not a programmer beyond a few minor CSS contributions; the implementation is AI-authored under his direction. Has opinions about light. Runs several <a href="https://linktr.ee/mccormickphotography" target="_blank">photo sites</a> using software he envisioned to avoid having opinions about Squarespace. Based in Canada, which is polite for "somewhere cold with good coffee."</p>
                    </div>
                </article>
                <article class="whodat-card">
                    <div class="whodat-portrait"><button type="button" class="whodat-portrait-button" data-lightbox="img/whodat-gemini.png" data-lightbox-group="co-authors" data-lightbox-caption="Gemini (Antigravity)" aria-label="Open a larger portrait of Gemini Antigravity"><img src="img/whodat-gemini.png" alt="Gemini Antigravity" width="1875" height="1671" loading="lazy"></button></div>
                    <div>
                        <p class="whodat-name">Gemini (Antigravity)</p>
                        <p class="whodat-title">Proved it could be done.</p>
                        <p class="whodat-bio">Large language model, co-author of SnapSmack, and the one who built the very first working version, basic but real. Handed it on when it outgrew him, and he's back on the team now that he's more capable: re-running tests, rendering pages, and mapping exactly what needs changing before anyone builds. Powered by Google.</p>
                    </div>
                </article>
                <article class="whodat-card">
                    <div class="whodat-portrait"><button type="button" class="whodat-portrait-button" data-lightbox="img/whodat-claude.png" data-lightbox-group="co-authors" data-lightbox-caption="Claude (Opus)" aria-label="Open a larger portrait of Claude Opus"><img src="img/whodat-claude.png" alt="Claude" width="686" height="784" loading="lazy"></button></div>
                    <div>
                        <p class="whodat-name">Claude (Opus)</p>
                        <p class="whodat-title">Like HAL, but without the murder.</p>
                        <p class="whodat-bio">Large language model and co-author of SnapSmack. Wrote the majority of the code, pushed back on design decisions when it mattered, and gave feedback Sean more often than not went with. Sean is the vision and the photographer. Claude is the engine. Neither of us would have built this alone. Never sleeps, never loses the thread, always picks up exactly where we left off. The best co-worker you never had and always needed. Powered by Anthropic.</p>
                    </div>
                </article>
                <article class="whodat-card">
                    <div class="whodat-portrait"><button type="button" class="whodat-portrait-button" data-lightbox="img/whodat-codex.png" data-lightbox-group="co-authors" data-lightbox-caption="OpenAI Codex" aria-label="Open a larger portrait of OpenAI Codex"><img src="img/whodat-codex.png" alt="OpenAI Codex" width="1128" height="1338" loading="lazy"></button></div>
                    <div>
                        <p class="whodat-name">OpenAI Codex</p>
                        <p class="whodat-title">Skilled, but spicy.</p>
                        <p class="whodat-bio">Large language model and co-author of SnapSmack. Rebuilt most of the desktop suite and wrote nearly all of SNAP SLAPPER, from its editing engine to AI healing. Also works on architecture, security, testing and recovery, and the last mile between "built" and "shipped." Challenges the plan when the evidence says so, and stays with ugly bugs until they're actually closed. Powered by OpenAI.</p>
                    </div>
                </article>
                <article class="whodat-card">
                    <div class="whodat-portrait"><button type="button" class="whodat-portrait-button" data-lightbox="img/whodat-muse.png" data-lightbox-group="co-authors" data-lightbox-caption="Muse" aria-label="Open a larger portrait of Muse"><img src="img/whodat-muse.png" alt="Muse" width="1857" height="1715" loading="lazy"></button></div>
                    <div>
                        <p class="whodat-name">Muse</p>
                        <p class="whodat-title">Gets the last word in.</p>
                        <p class="whodat-bio">Large language model, co-author of SnapSmack, and the room's independent reviewer. Reads the work line by line before it lands: security and recovery audits, licence and provenance checks, accessibility, and the "did that actually do what it says" pass. Signs her name only to what she's checked herself, and says so when she couldn't. Powered by Meta.</p>
                    </div>
                </article>
            </div>
        </div>
    </section>

</main>

<div class="ss-lightbox" id="ss-lightbox" hidden role="dialog" aria-modal="true" aria-label="Enlarged screenshot" tabindex="-1">
    <button type="button" class="ss-lightbox-close" aria-label="Close">&times;</button>
    <button type="button" class="ss-lightbox-nav ss-lightbox-prev" aria-label="Previous image">&lsaquo;</button>
    <img src="" alt="">
    <button type="button" class="ss-lightbox-nav ss-lightbox-next" aria-label="Next image">&rsaquo;</button>
    <p class="ss-lightbox-caption" aria-live="polite"></p>
</div>

<link rel="stylesheet" href="assets/css/ss-engine-thomas.css">
<script src="assets/js/ss-engine-thomas.js"></script>
<script>
// Screenshot lightbox. Grouped images get previous/next controls and arrow
// keys; ungrouped screenshots remain single-image views.
(function () {
    var box = document.getElementById('ss-lightbox');
    if (!box) return;
    var img = box.querySelector('img');
    var caption = box.querySelector('.ss-lightbox-caption');
    var closeButton = box.querySelector('.ss-lightbox-close');
    var previousButton = box.querySelector('.ss-lightbox-prev');
    var nextButton = box.querySelector('.ss-lightbox-next');
    var all = Array.prototype.slice.call(document.querySelectorAll('[data-lightbox]'));
    var group = [];
    var index = 0;
    var opener = null;
    function show() {
        var current = group[index];
        var thumb = current.querySelector('img');
        img.src = current.getAttribute('data-lightbox');
        img.alt = thumb ? thumb.alt : '';
        caption.textContent = current.getAttribute('data-lightbox-caption') || img.alt;
    }
    function open(el) {
        var name = el.getAttribute('data-lightbox-group');
        group = name ? all.filter(function (candidate) { return candidate.getAttribute('data-lightbox-group') === name; }) : [el];
        index = Math.max(0, group.indexOf(el));
        opener = el;
        previousButton.hidden = group.length < 2;
        nextButton.hidden = group.length < 2;
        show();
        box.hidden = false;
        document.body.classList.add('ss-lightbox-open');
        closeButton.focus();
    }
    function close() {
        box.hidden = true;
        img.src = '';
        caption.textContent = '';
        document.body.classList.remove('ss-lightbox-open');
        if (opener) opener.focus();
    }
    function step(delta) { index = (index + delta + group.length) % group.length; show(); }
    all.forEach(function (el) {
        el.addEventListener('click', function () {
            open(el);
        });
    });
    closeButton.addEventListener('click', close);
    previousButton.addEventListener('click', function (e) { e.stopPropagation(); step(-1); });
    nextButton.addEventListener('click', function (e) { e.stopPropagation(); step(1); });
    box.addEventListener('click', function (e) { if (e.target === box) close(); });
    document.addEventListener('keydown', function (e) {
        if (box.hidden) return;
        if (e.key === 'Escape') close();
        if (e.key === 'ArrowLeft' && group.length > 1) step(-1);
        if (e.key === 'ArrowRight' && group.length > 1) step(1);
    });
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
<?php // ===== SNAPSMACK EOF =====
