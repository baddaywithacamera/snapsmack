<?php defined('SNAPSMACK_SKIN_RENDER') || exit;
require_once __DIR__ . '/archive-pane.php'; ?>
<?php
// CHAPLIN's archive emits BOTH #browse-grid and #justified-grid with the same
// photographs. Its stylesheet already chooses between them -- style.css lines
// 1562-1565 show exactly one through html[data-archive-layout] -- but nothing
// ever set that attribute, so both were visible: the thumbnail grid, and then
// the same archive again as an unstyled 27,824px wall of full-width images
// underneath it. Measured by Codex on acolourlesslife.ca, 26 October 2026;
// setting the attribute in an isolated browser took the duplicate wall to
// height zero.
//
// Copied from core/50-shades-page-component.php:4-7, which has always done
// this and whose archive is correct. 'croppedwithcalendar' is in the list
// because it is a thumbnail layout; only masonry selects the wall.
// SUPERSEDED 2026-11-16: this derived the pane from the stored default and so
// ignored the visitor's URL and cookie, exactly as 50 SHADES did. The resolved
// answer already exists -- see core/archive-pane.php.
$archivePane = snap_archive_pane($view);
// THUMB STYLE, read the way IMPACT PRINTER reads it -- the only component that
// honoured the owner's choice. CHAPLIN asked for img_thumb_square first and
// unconditionally, so "cropped (natural aspect)" in ARCHIVE APPEARANCE changed
// nothing and every archive stayed square whatever the owner picked.
// Read the RAW archive_layout, not the normalised one. DEFAULT LAYOUT writes
// 'thumbs' or 'masonry'; THUMB STYLE writes 'square' or 'cropped', and the two
// are independent settings. The normalisation above folds any unrecognised
// value to 'square', so testing it here turned every 'thumbs' site into a
// square one and shadowed THUMB STYLE exactly as before -- measured live on
// acolourlesslife.ca at 0.7.956D, which emitted archive-layout-square while
// the owner had chosen CROPPED (NATURAL ASPECT). Only the legacy 'square' and
// 'cropped' values of archive_layout may stand in for the thumb style.
$archiveLayoutRaw  = (string)($view['site']['archive_layout'] ?? '');
$archiveThumbStyle = in_array($archiveLayoutRaw, ['square', 'cropped'], true)
    ? $archiveLayoutRaw
    : (string)($view['site']['archive_thumb_style'] ?? 'cropped');
if (!in_array($archiveThumbStyle, ['square', 'cropped'], true)) $archiveThumbStyle = 'cropped';

// ── ART DECO FRAME ORNAMENTS ────────────────────────────────────────────────
// Built once and printed wherever a framed photograph appears, because the
// four corner ornaments were being written out by hand in three places and
// the centre ornaments in none.
//
// WHAT WAS LOST. skins/chaplin/frame-deco.php emitted eight ornaments and
// gave every one of them BOTH classes: "chap-orn chap-orn-masked" plus its
// position. b61fa6aa deleted that file when CHAPLIN became a strict skin and
// the markup was rebuilt here with only the four corners and without
// chap-orn-masked. style.css still carries the rule -- .chap-orn-masked
// paints a canvas-coloured plate with a box-shadow spread, and that plate IS
// the visible gap around each ornament. Without the class there is no gap.
//
// So the frame lost three separate things at once: the gap around every
// ornament, the two centre ornaments on the top and bottom rules, and the
// two on the sides. Codex measured the live page at 1920 on 28 October:
// "continuous rectangle, tiny corner ornaments, no centre ornaments".
// Sean's reference capture, projects/snapsmack-ca/img/chaplin-landing.png,
// shows separated rules, large crosshatch corners, and a diamond centred on
// the top rule and on the bottom rule.
//
// mid_tb and mid_lr have been prepared in snapsmack_chaplin_presentation()
// the whole time and read by nothing. The owner's switches were connected to
// no markup, which is why turning them on did nothing.
$chapOrn = $view['site']['skin_presentation']['chaplin'] ?? [];

// THE ORNAMENT MODEL. The component layer turns this into DIV wrappers with
// each SVG inlined and recoloured -- the contract frame-deco.php had before
// b61fa6aa deleted it. Rebuilding it as <img src> is what put 734x3145
// ornaments across the owner's About text and painted the art's own #ece6d4
// instead of neutral white, so the markup shape is not a detail.
$chapCornerUrl = (string)($chapOrn['corner_asset'] ?? '');
$chapTopUrl    = (string)($chapOrn['top_asset'] ?? '');
$chapSideUrl   = (string)($chapOrn['side_asset'] ?? '');
$chapPieces = [];
if (!empty($chapOrn['corners'])) {
    foreach (['chap-orn-tl','chap-orn-tr','chap-orn-bl','chap-orn-br'] as $pos) {
        $chapPieces[] = ['position' => $pos, 'url' => $chapCornerUrl, 'constraint' => 'both'];
    }
}
if (!empty($chapOrn['mid_tb'])) {
    foreach (['chap-orn-mid-top','chap-orn-mid-bot'] as $pos) {
        $chapPieces[] = ['position' => $pos, 'url' => $chapTopUrl, 'constraint' => 'width'];
    }
}
if (!empty($chapOrn['mid_lr'])) {
    foreach (['chap-orn-mid-lft','chap-orn-mid-rgt'] as $pos) {
        $chapPieces[] = ['position' => $pos, 'url' => $chapSideUrl, 'constraint' => 'height'];
    }
}
$chapOrnModel = ['pieces' => $chapPieces];
?>
<!doctype html><html lang="<?php echo snap_escape_attr($view['site']['language']); ?>" dir="<?php echo snap_escape_attr($view['site']['direction']); ?>" data-archive-layout="<?php echo snap_escape_attr($archivePane); ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo snap_escape_html($view['response']['page_title'] ?? $view['site']['site_name']); ?></title><link rel="stylesheet" href="<?php echo snap_escape_url($view['site']['base_style_url'] ?? ''); ?>"><link rel="stylesheet" href="<?php echo snap_escape_url($view['site']['skin_style_url']); ?>"><?php if (!empty($view['site']['skin_variant_url'])): ?><link rel="stylesheet" href="<?php echo snap_escape_url($view['site']['skin_variant_url']); ?>"><?php endif; ?><?php echo snap_render_html($view['site']['skin_custom_style'] ?? ''); ?><?php echo snap_render_html($view['site']['skin_presentation']['style'] ?? ''); ?></head>
<?php // static-transmission is what CHAPLIN's own stylesheet gates its static
      // pages on -- ten rules, including the three written for #photobox.page-hero
      // and the four that let the page scroll at all. skin-view-contract.php puts
      // it on the body for page and blogroll kinds and IMPACT PRINTER and 50 SHADES
      // add it themselves; CHAPLIN hardcoded its body class and never did, so every
      // one of those rules has been dead on its About and Blogroll pages. ?><body class="chaplin-v2<?php echo ($view['response']['kind'] ?? '') === 'landing' ? ' chap-landing' : ''; ?><?php echo in_array($view['response']['kind'] ?? '', ['page', 'blogroll'], true) ? ' static-transmission' : ''; ?><?php // Eight rules in skins/chaplin/style.css hang off body.archive-page, and
      // the first two are `overflow: auto !important` on the body and the html,
      // which is the archive's scrolling. The conversion never put the class on,
      // so acolourlesslife.ca's archive could not scroll past the first screen.
      echo in_array($view['response']['kind'] ?? '', ['archive', 'hashtag', 'albums', 'collections'], true) ? ' archive-page' : ''; ?>" data-chap-card="<?php echo snap_escape_attr($view['site']['skin_presentation']['chaplin']['card_style'] ?? ''); ?>" data-chap-flicker="<?php echo snap_escape_attr($view['site']['skin_presentation']['options']['chap_flicker'] ?? ''); ?>" data-chap-scratch-frequency="<?php echo snap_escape_attr($view['site']['skin_presentation']['options']['chap_scratch_freq'] ?? ''); ?>"><canvas id="chap-film-bg" aria-hidden="true"></canvas><header id="rg-header" data-sticky-header><div class="rg-header-inside"><a class="rg-logo-link" href="<?php echo snap_route_url('home'); ?>"><span class="rg-masthead"><?php echo snap_escape_html($view['site']['site_name']); ?></span></a><nav class="rg-header-nav"><?php echo snap_render_html(snap_render_component('navigation', ['items' => $view['response']['navigation'] ?? []])); ?></nav></div></header>
<main id="scroll-stage" class="public-content<?php echo ($view['response']['kind'] ?? '') === 'landing' ? ' chap-landing' : ''; ?><?php echo in_array($view['response']['kind'] ?? '', ['photo','post','collection'], true) ? ' rg-single' : ''; ?>">
<?php if (($view['response']['kind'] ?? '') === 'landing'): $hero = $view['response']['items'][0] ?? null; ?>
<?php if (is_array($hero)): ?><div id="rg-photobox"><div class="rg-photo-wrap"><a class="chap-landing-link" href="<?php echo snap_escape_url($hero['url'] ?? snap_route_url('photo', ['slug' => $hero['img_slug'] ?? ''])); ?>" title="<?php echo snap_escape_attr($hero['img_title'] ?? ''); ?>"><div class="chap-img-frame"><?php echo snap_render_html(snap_render_component('frame-ornaments', ['model' => $chapOrnModel])); ?><img class="rg-image post-image chap-photo" src="<?php echo snap_escape_url($hero['img_file'] ?? $hero['img_thumb_aspect'] ?? ''); ?>" alt="<?php echo snap_escape_attr($hero['img_alt'] ?? $hero['img_title'] ?? ''); ?>" loading="eager"><div class="chap-frame-lines" aria-hidden="true"><div class="chap-line chap-line-top"></div><div class="chap-line chap-line-bot"></div><div class="chap-line chap-line-lft"></div><div class="chap-line chap-line-rgt"></div></div></div></a></div></div><?php if (($view['site']['skin_presentation']['chaplin']['title_position'] ?? '') === 'below_photo'): ?><div class="chap-intertitle"><div class="chap-intertitle-rule"></div><div class="chap-intertitle-title"><?php echo snap_escape_html($hero['img_title'] ?? ''); ?></div><?php if (!empty($hero['img_date'])): ?><div class="chap-intertitle-date"><?php echo snap_escape_html($hero['img_date']); ?></div><?php endif; ?><div class="chap-intertitle-rule"></div></div><?php endif; ?><div id="infobox"><?php echo snap_render_html(snap_render_component('photo-navigation', ['item' => $hero ?? [],'previous' => $view['response']['previous'] ?? [],'first' => $view['response']['first'] ?? [],'last' => $view['response']['last'] ?? [],'next' => $view['response']['next'] ?? [],'comments' => $view['response']['comments'] ?? [],'comments_enabled' => $view['response']['comments_enabled'] ?? true,])); ?></div><?php else: ?><div id="rg-photobox" class="chap-photobox-empty"><p class="chap-no-photos">The projector is dark.</p></div><?php endif; ?>
<?php elseif (($view['response']['kind'] ?? '') === 'page'): ?><?php $chapPage = is_array($view['response']['item'] ?? null) ? $view['response']['item'] : []; $chapHero = trim((string)($chapPage['image_asset'] ?? '')); $chapTitle = (string)($chapPage['title'] ?? $view['response']['page_title'] ?? ''); ?><div class="static-content"><h1 class="static-page-title"><?php echo snap_escape_html($chapTitle); ?></h1><?php if ($chapHero !== ''): ?><div id="photobox" class="page-hero page-hero--<?php echo snap_escape_attr(in_array(($chapPage['image_size'] ?? ''), ['medium','small'], true) ? $chapPage['image_size'] : 'full'); ?> page-hero--<?php echo snap_escape_attr(in_array(($chapPage['image_align'] ?? ''), ['left','right'], true) ? $chapPage['image_align'] : 'center'); ?><?php if (!empty($chapPage['image_shadow'])): ?> page-hero--shadow<?php endif; ?>"><div class="main-photo"><div class="chap-img-frame"><?php echo snap_render_html(snap_render_component('frame-ornaments', ['model' => $chapOrnModel])); ?><img class="rg-image post-image chap-photo" src="<?php echo snap_escape_url(rtrim(defined('BASE_URL') ? (string)BASE_URL : '/', '/') . '/' . ltrim($chapHero, '/')); ?>" alt="<?php echo snap_escape_attr($chapTitle); ?>"><div class="chap-frame-lines" aria-hidden="true"><div class="chap-line chap-line-top"></div><div class="chap-line chap-line-bot"></div><div class="chap-line chap-line-lft"></div><div class="chap-line chap-line-rgt"></div></div></div></div></div><?php endif; ?><div class="description entry-content e-content"><?php echo snap_render_html($view['response']['item']['content'] ?? $view['response']['item']['description'] ?? $view['response']['rendered_content'] ?? ''); ?></div></div><?php elseif (($view['response']['kind'] ?? '') === 'photo' || ($view['response']['kind'] ?? '') === 'post' || ($view['response']['kind'] ?? '') === 'collection'): ?>
<article class="h-entry single-image-page">
<?php if (($view['site']['skin_presentation']['chaplin']['title_position'] ?? '') === 'above_photo'): ?><h1 class="p-name photo-title-header"><?php echo snap_escape_html($view['response']['item']['img_title'] ?? $view['response']['item']['title'] ?? $view['response']['item']['name'] ?? ''); ?></h1><?php endif; ?>
<?php if (!empty($view['response']['item']['img_file']) || !empty($view['response']['item']['featured_image_path'])): ?><div id="rg-photobox"><div class="rg-photo-wrap"><div class="u-photo chap-img-frame">
<?php echo snap_render_html(snap_render_component('frame-ornaments', ['model' => $chapOrnModel])); ?>
<?php echo snap_render_html(snap_render_component('image', ['url' => $view['response']['item']['img_file'] ?? $view['response']['item']['featured_image_path'], 'alt' => $view['response']['item']['img_alt'] ?? $view['response']['item']['img_title'] ?? '', 'class' => 'post-image'])); ?><div class="chap-frame-lines" aria-hidden="true"><div class="chap-line chap-line-top"></div><div class="chap-line chap-line-bot"></div><div class="chap-line chap-line-lft"></div><div class="chap-line chap-line-rgt"></div></div></div></div></div><?php endif; ?>
<?php if (($view['site']['skin_presentation']['chaplin']['title_position'] ?? '') !== 'above_photo'): ?><div class="chap-intertitle"><div class="chap-intertitle-rule"></div><h1 class="chap-intertitle-title p-name"><?php echo snap_escape_html($view['response']['item']['img_title'] ?? $view['response']['item']['title'] ?? $view['response']['item']['name'] ?? ''); ?></h1><?php if (!empty($view['response']['item']['img_date']) || !empty($view['response']['item']['created_at'])): ?><div class="chap-intertitle-date"><time class="dt-published" datetime="<?php echo snap_escape_attr($view['response']['item']['img_date'] ?? $view['response']['item']['created_at']); ?>"><?php echo snap_escape_html($view['response']['item']['date_display'] ?? $view['response']['item']['img_date'] ?? $view['response']['item']['created_at']); ?></time></div><?php endif; ?><div class="chap-intertitle-rule"></div></div><?php endif; ?>
<?php if (!empty($view['site']['owner_name'])): ?><span class="p-author h-card"><span class="p-name"><?php echo snap_escape_html($view['site']['owner_name']); ?></span></span><?php endif; ?>
<?php if (($view['site']['skin_presentation']['chaplin']['show_description'] ?? false)): ?><div class="chap-description entry-content e-content static-transmission"><?php echo snap_render_html($view['response']['item']['content'] ?? $view['response']['item']['description'] ?? $view['response']['item']['img_description'] ?? ''); ?></div><?php endif; ?></article><div id="infobox"><?php echo snap_render_html(snap_render_component('photo-navigation', ['item' => $view['response']['item'] ?? [],'previous' => $view['response']['previous'] ?? [],'first' => $view['response']['first'] ?? [],'last' => $view['response']['last'] ?? [],'next' => $view['response']['next'] ?? [],'comments' => $view['response']['comments'] ?? [],'comments_enabled' => $view['response']['comments_enabled'] ?? true,])); ?></div>
<?php elseif (($view['response']['kind'] ?? '') === 'blogroll'): ?><section class="blogroll-page"><h1 class="blogroll-page-title"><?php echo snap_escape_html($view['response']['page_title'] ?? 'Blogroll'); ?></h1><?php echo snap_render_html(snap_render_component('blogroll', ['groups' => $view['response']['blogroll_groups'] ?? []])); ?></section>
<?php elseif (($view['response']['kind'] ?? '') === 'search'): ?><?php echo snap_render_html(snap_render_component('search-results', ['results' => $view['response']['results'] ?? [], 'query' => $view['response']['query'] ?? '', 'page_title' => $view['response']['page_title'] ?? ''])); ?><?php elseif (($view['response']['kind'] ?? '') === 'archive' || ($view['response']['kind'] ?? '') === 'hashtag' || ($view['response']['kind'] ?? '') === 'albums' || ($view['response']['kind'] ?? '') === 'collections'): ?><?php // The archive's control row -- SHOW ALL, FILTER, search, T/M and the
      // calendar button -- built in core and printed here. CHAPLIN is one of
      // the five skins whose manifest already requires the toggle engine, so
      // it has had page-archive.css all along and simply never printed the
      // markup the stylesheet was written for. Empty on albums and
      // collections, which the CMS does not build a row for. ?><?php echo snap_render_html($view['response']['archive_controls'] ?? ''); ?><?php
      // ── SS-1569: THE v0.7.188D ARCHIVE, MOVED HERE RATHER THAN REWRITTEN ──
      //
      // What was lost, and it is not a detail. 188D's masonry built JUSTIFIED
      // ROWS: it packed images into rows by aspect ratio against a reference
      // width, marked the last row short, and published --last-row-ar-sum so
      // the stylesheet could size it. The replacement emitted a FLAT list of
      // anchors into .ss-scroll-wall with no rows at all, which is why this
      // file's own older comment records "an unstyled 27,824px wall of
      // full-width images". The row packer below is 188D's, line for line.
      //
      // The thumbnail half lost its selectors: .chap-archive-grid,
      // .chap-archive-item, .chap-thumb and .chap-archive-title became
      // .fsog-*, so 188D's stylesheet -- which draws the double-rule frame as
      // a 3px border plus two box-shadow rings on .chap-thumb -- matched
      // nothing. Those four names are back, because Codex is restoring that
      // CSS verbatim and asked for them by name.
      //
      // DELIBERATELY NOT BROUGHT BACK: .chap-img-frame and .chap-frame-lines.
      // 188D's archive markup has neither; they arrived with the replacement.
      // 188D draws the frame in CSS on .chap-thumb, and emitting divs its
      // stylesheet never mentions would leave unstyled boxes inside every
      // thumbnail.
      //
      // (a) THE VIEW MODEL. 188D read $images, $settings and BASE_URL from
      //     archive.php's scope. Every read below comes from $view instead.
      //     No query, no BASE_URL, no cast -- the strict rules still hold.
      // (b) BOUNDED. 188D looped the whole unbounded $images. This loops the
      //     page the CMS prepared, and the existing sentinel below keeps
      //     endless scroll working. The row packer therefore packs one page at
      //     a time, which is what it did per request anyway.
      // (c) THE INLINE SCRIPT IS NOT MOVED, AND THIS IS THE ONE PLACE I HAVE
      //     NOT FOLLOWED THE INSTRUCTION LITERALLY. 188D ended with a script
      //     that showed one grid and hid the other by setting element.style
      //     .display. That job is now done by CSS keyed on
      //     html[data-archive-layout], which ss-engine-archive-toggle.js sets
      //     (it dispatches smackarchive:layoutchange and never touches either
      //     grid's display itself). Moving the script in would set inline
      //     display on both grids and override the stylesheet, so the toggle
      //     would stop responding to CSS and start fighting it. Reported
      //     rather than done.
      //
      // Aspect bounds, row height, gap and reference width: 188D's values and
      // 188D's settings keys, read through the view model.
      $chapRatioMin = 2 / 3;
      $chapRatioMax = 3 / 2;
      $chapItems    = is_array($view['response']['items'] ?? null) ? $view['response']['items'] : [];
      $chapPresent  = is_array($view['site']['skin_presentation']['chaplin'] ?? null)
          ? $view['site']['skin_presentation']['chaplin'] : [];
      $chapRowH     = (int)($chapPresent['justified_row_height'] ?? 280);
      if ($chapRowH <= 0) { $chapRowH = 280; }
      $chapGap      = 4;
      $chapRefW     = (int)($chapPresent['archive_max_width'] ?? 1400);
      if ($chapRefW <= 0) { $chapRefW = 1400; }

      // 188D's row packer, unchanged in arithmetic.
      $chapRows = [];
      $chapRow = [];
      $chapRowW = 0;
      foreach ($chapItems as $chapImg) {
          $iw = (int)($chapImg['img_width'] ?? 400);
          $ih = (int)($chapImg['img_height'] ?? 400);
          if ($ih <= 0) { $ih = 400; }
          if ($iw <= 0) { $iw = 400; }
          $chapImg['_aspect'] = $iw / $ih;
          $scaledW = round($chapImg['_aspect'] * $chapRowH);
          $chapRow[] = $chapImg;
          $chapRowW += $scaledW + $chapGap;
          if ($chapRowW - $chapGap >= $chapRefW) {
              $chapRows[] = ['images' => $chapRow, 'full' => true];
              $chapRow = [];
              $chapRowW = 0;
          }
      }
      if ($chapRow !== []) { $chapRows[] = ['images' => $chapRow, 'full' => false]; }

      // The last FULL row's aspect sum, which the stylesheet uses to size the
      // short final row. 188D's fallback when there is no full row is kept.
      $chapLastFullArSum = 0;
      for ($i = count($chapRows) - 1; $i >= 0; $i--) {
          if ($chapRows[$i]['full']) {
              foreach ($chapRows[$i]['images'] as $chapRowImg) {
                  $chapLastFullArSum += $chapRowImg['_aspect'];
              }
              break;
          }
      }
      if ($chapLastFullArSum <= 0) { $chapLastFullArSum = $chapRefW / $chapRowH; }
      ?><section id="browse-grid" class="chap-archive-grid archive-grid h-feed <?php echo snap_escape_attr($archiveThumbStyle); ?>-grid archive-layout-<?php echo snap_escape_attr($archiveThumbStyle); ?> archive-cols-<?php echo (int)($view['site']['archive_grid']['cols'] ?? 4); ?> archive-thumbs-<?php echo snap_escape_attr((string)($view['site']['archive_grid']['step'] ?? 'm')); ?>"><?php if ($chapItems !== []): ?><?php foreach ($chapItems as $item): ?><?php
          // 188D preferred the aspect thumbnail and fell back to the full file.
          // The square crop is honoured when the owner asked for a square
          // archive, which is the current control and not an invention.
          $chapThumb = $archiveThumbStyle === 'square'
              ? ($item['img_thumb_square'] ?? $item['img_thumb_aspect'] ?? $item['img_file'] ?? '')
              : ($item['img_thumb_aspect'] ?? $item['img_thumb_square'] ?? $item['img_file'] ?? '');
          $chapW = (int)($item['img_width'] ?? 0);
          $chapH = (int)($item['img_height'] ?? 0);
          // 188D clamped to 2:3..3:2 and fell back to 1 with no dimensions.
          $chapRatio = ($chapW > 0 && $chapH > 0)
              ? max($chapRatioMin, min($chapRatioMax, $chapW / $chapH)) : 1;
          if ($archiveThumbStyle === 'square') { $chapRatio = 1.0; }
          ?><a href="<?php echo snap_escape_url($item['url'] ?? snap_route_url('photo', ['slug' => $item['img_slug'] ?? ''])); ?>" class="chap-archive-item" title="<?php echo snap_escape_attr($item['img_title'] ?? ''); ?>"><div class="chap-thumb<?php echo $chapRatio < 1 ? ' chap-thumb-portrait' : ''; ?>" style="aspect-ratio: <?php echo snap_escape_attr((string)round($chapRatio, 4)); ?>;"><?php if ($chapThumb !== ''): ?><?php echo snap_render_html(snap_render_component('image', ['url' => $chapThumb, 'alt' => $item['img_alt'] ?? $item['img_title'] ?? ''])); ?><?php endif; ?></div><div class="chap-archive-title"><?php echo snap_escape_html($item['img_title'] ?? $item['title'] ?? $item['name'] ?? ''); ?></div></a><?php endforeach; ?><?php else: ?><div class="chap-archive-empty"><p>The projector is dark. No frames to show.</p></div><?php endif; ?></section><section id="justified-grid" style="--justified-gap: <?php echo (int)$chapGap; ?>px; --justified-row-height: <?php echo (int)$chapRowH; ?>px; --last-row-ar-sum: <?php echo snap_escape_attr((string)round($chapLastFullArSum, 4)); ?>;"><?php if ($chapRows !== []): ?><?php foreach ($chapRows as $chapRowData): ?><div class="justified-row<?php echo $chapRowData['full'] ? '' : ' justified-row-last'; ?>"><?php foreach ($chapRowData['images'] as $item): ?><?php $chapFull = (string)($item['img_thumb_aspect'] ?? $item['img_file'] ?? ''); ?><a href="<?php echo snap_escape_url($item['url'] ?? snap_route_url('photo', ['slug' => $item['img_slug'] ?? ''])); ?>" class="justified-item" title="<?php echo snap_escape_attr($item['img_title'] ?? ''); ?>" style="flex-grow: <?php echo (int)round($item['_aspect'] * 100); ?>; flex-basis: 0; aspect-ratio: <?php echo snap_escape_attr((string)round($item['_aspect'], 4)); ?>;"><?php if ($chapFull !== ''): ?><?php echo snap_render_html(snap_render_component('image', ['url' => $chapFull, 'alt' => $item['img_alt'] ?? $item['img_title'] ?? ''])); ?><?php endif; ?></a><?php endforeach; ?></div><?php endforeach; ?><?php else: ?><div class="chap-archive-empty"><p>The projector is dark. No frames to show.</p></div><?php endif; ?></section><?php if (!empty($view['response']['next_page'])): ?><div class="scroll-wall-sentinel" data-next="<?php echo snap_escape_attr($view['response']['next_page']); ?>" data-has-more="1" aria-hidden="true"></div><?php endif; ?>
<?php else: ?><section class="not-found"><h1>Not found</h1></section><?php endif; ?>
<?php if ((!empty($view['response']['comments']) || !empty($view['response']['community']))): ?><?php echo snap_render_html(snap_render_component('comments', ['items' => $view['response']['comments']])); ?><?php endif; ?></main>
<footer id="system-footer" class="site-footer<?php if (!empty($view['site']['footer']['lowercase'])): ?> footer-lowercase<?php endif; ?>"><div id="footer" class="inside"><?php echo snap_render_html(snap_render_component('footer', ['footer' => $view['site']['footer'] ?? []])); ?></div></footer><?php echo snap_render_html($view['before_assets'] ?? ''); ?><?php echo snap_render_html(snap_render_component('registered-assets', ['assets' => $view['site']['registered_assets'] ?? []])); ?><?php echo snap_render_html($view['site']['owner_custom_code'] ?? ''); ?>
</body></html>
