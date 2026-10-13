<?php defined('SNAPSMACK_SKIN_RENDER') || exit;
require_once __DIR__ . '/public-route-aliases.php'; ?>
<?php
$archiveLayout = in_array(($view['site']['archive_layout'] ?? ''), ['square', 'cropped', 'masonry', 'croppedwithcalendar'], true)
    ? (string)$view['site']['archive_layout']
    : 'square';
$archivePane = $archiveLayout === 'masonry' ? 'masonry' : 'thumbs';
?>
<!doctype html>
<html lang="<?php echo snap_escape_attr($view['site']['language']); ?>" dir="<?php echo snap_escape_attr($view['site']['direction']); ?>" data-archive-layout="<?php echo snap_escape_attr($archivePane); ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo snap_escape_html($view['response']['page_title'] ?? $view['site']['site_name']); ?></title>
<link rel="stylesheet" href="<?php echo snap_escape_url($view['site']['skin_style_url']); ?>">
<?php if (!empty($view['site']['skin_variant_url'])): ?><link rel="stylesheet" href="<?php echo snap_escape_url($view['site']['skin_variant_url']); ?>"><?php endif; ?>
<?php echo snap_render_html($view['site']['skin_custom_style'] ?? ''); ?><?php echo snap_render_html($view['site']['skin_presentation']['style'] ?? ''); ?>
</head>
<?php $kind = (string)($view['response']['kind'] ?? ''); $bodyClass = in_array($kind, ['landing', 'archive', 'hashtag', 'albums', 'collections'], true) ? 'archive-page' : (in_array($kind, ['page', 'collection'], true) ? 'static-transmission' : ''); ?>
<body class="50-shades-of-noah-grey-v2 <?php echo snap_escape_attr($bodyClass); ?>"><div id="page-wrapper"><main id="scroll-stage" class="public-content">
<header id="fsog-header" class="site-header fsog-header" data-sticky-header><div class="fsog-header-inside">
<div class="logo-area"><a href="<?php echo snap_route_url('home'); ?>"><h1 class="site-title-text"><?php echo snap_escape_html($view['site']['site_name']); ?></h1></a></div>
<?php echo snap_render_html(snap_render_component('navigation', ['items' => $view['response']['navigation'] ?? []])); ?>
</div></header>

<?php if (in_array(($view['response']['kind'] ?? ''), ['photo', 'post', 'collection'], true)): ?>
<?php $item = is_array($view['response']['item'] ?? null) ? $view['response']['item'] : []; ?>
<article id="fsog-photobox" class="h-entry single-image-page"><div class="fsog-photo-wrap">
<?php if (!empty($item['img_file']) || !empty($item['featured_image_path'])): ?><img src="<?php echo snap_escape_url($item['img_file'] ?? $item['featured_image_path']); ?>" alt="<?php echo snap_escape_attr($item['img_alt'] ?? $item['img_title'] ?? ''); ?>" class="fsog-image post-image u-photo" id="main-image"><?php endif; ?>
</div></article>
<div id="infobox"><?php echo snap_render_html(snap_render_component('photo-navigation', [
    'item' => $item, 'previous' => $view['response']['previous'] ?? [], 'first' => $view['response']['first'] ?? [],
    'last' => $view['response']['last'] ?? [], 'next' => $view['response']['next'] ?? [],
    'comments' => $view['response']['comments'] ?? [], 'comments_enabled' => $view['response']['comments_enabled'] ?? true,
])); ?></div>
<div id="footer"><div id="pane-info" class="footer-pane">
<h2 class="p-name photo-title-footer"><?php echo snap_escape_html($item['img_title'] ?? $item['title'] ?? $item['name'] ?? ''); ?></h2>
<div class="description entry-content e-content"><?php echo snap_render_html($item['content'] ?? $item['description'] ?? $item['img_description'] ?? ''); ?></div>
<?php if (!empty($item['tags'])): ?><div class="fsog-tags"><?php foreach ($item['tags'] as $tag): ?><a class="fsog-tag" href="<?php echo snap_escape_url($tag['url'] ?? snap_route_url('hashtag', ['slug' => $tag['slug'] ?? ''])); ?>">#<?php echo snap_escape_html($tag['slug'] ?? $tag['name'] ?? ''); ?></a><?php endforeach; ?></div><?php endif; ?>
<?php $technical = is_array($item['technical'] ?? null) ? $item['technical'] : (is_array($item['exif'] ?? null) ? $item['exif'] : []); ?>
<?php if ($technical): ?><div class="meta"><div class="meta-header">TECHNICAL SPECIFICATIONS</div><table class="exif-table"><?php foreach ($technical as $label => $value): ?><?php if ((string)$value !== '' && (string)$value !== 'N/A'): ?><tr><td class="exif-label"><?php echo snap_escape_html($label); ?></td><td class="exif-value"><?php echo snap_escape_html($value); ?></td></tr><?php endif; ?><?php endforeach; ?></table></div><?php endif; ?>
</div><div id="pane-comments" class="footer-pane"><?php echo snap_render_html(snap_render_component('comments', ['items' => $view['response']['comments'] ?? []])); ?></div></div>
<?php elseif (($view['response']['kind'] ?? '') === 'page'): ?>
<?php
// A static page is not a photograph. It used to render through the skin's
// own skin-page.php, deleted on 2026-09-28, after which pages fell into the
// photograph branch and came out with photo navigation and an empty
// technical-specifications slot. This is that file's structure: the skin
// still styles .static-content, .static-page-title, .description and
// #photobox.page-hero, and until now emitted none of them.
$pageItem = is_array($view['response']['item'] ?? null) ? $view['response']['item'] : [];
$pageHero = trim((string)($pageItem['image_asset'] ?? ''));
$pageBase = defined('BASE_URL') ? rtrim((string)BASE_URL, '/') . '/' : '/';
?>
<div class="static-content">
<h1 class="static-page-title"><?php echo snap_escape_html($pageItem['title'] ?? $view['response']['page_title'] ?? ''); ?></h1>
<?php if ($pageHero !== ''): ?><div id="photobox" class="page-hero"><div class="main-photo"><img src="<?php echo snap_escape_url($pageBase . ltrim($pageHero, '/')); ?>" class="post-image" alt="<?php echo snap_escape_attr($pageItem['title'] ?? ''); ?>"></div></div><?php endif; ?>
<div class="description entry-content e-content"><?php echo snap_render_html($pageItem['content'] ?? $pageItem['description'] ?? ''); ?></div>
</div>
<?php elseif (($view['response']['kind'] ?? '') === 'search'): ?>
<section class="fsog-search-page search-results"><h1 class="static-page-title"><?php echo snap_escape_html($view['response']['page_title'] ?? 'Search'); ?></h1><?php foreach (($view['response']['results'] ?? []) as $group): ?><?php foreach ($group as $result): ?><article><h2><?php echo snap_escape_html($result['img_title'] ?? $result['title'] ?? ''); ?></h2></article><?php endforeach; ?><?php endforeach; ?></section>
<?php else: ?>
<?php
// The archive's control row, below the header and above the grids, exactly
// where this skin has always carried it. It is a shared component: it brings
// no navigation of its own, so the header and menus above are untouched.
echo snap_render_html(snap_render_component('archive-filters', [
    'content_width' => (string)($view['site']['skin_presentation']['options']['archive_content_width'] ?? ''),
    // Not snap_route_url('archive'): that returns the literal /archive, which
    // is exactly the unregistered path that sent six sites into a redirect
    // loop. The guarded helper returns the site's real archive slug, or
    // ?view=archive, which the strict controller always renders.
    'show_all_url'  => snapsmack_legacy_archive_target(
        defined('BASE_URL') ? (string)BASE_URL : '/',
        (array)($view['route_aliases'] ?? $view['site']['route_aliases'] ?? []),
        (string)($view['site']['site_mode'] ?? '')
    ),
    'query'         => (string)($view['response']['query'] ?? ''),
    'selected'      => (array)($view['response']['active_filters'] ?? []),
    'filters'       => (array)($view['site']['crop_circles']['filters'] ?? []),
    'search'        => (array)($view['site']['crop_circles']['search'] ?? []),
    'layouts'       => (array)($view['response']['archive_layouts'] ?? []),
]));
?>
<section id="browse-grid" class="fsog-archive-grid archive-grid h-feed"<?php if ($archivePane !== 'thumbs'): ?> style="display:none"<?php endif; ?>><?php foreach (($view['response']['items'] ?? []) as $archiveItem): ?><?php $archiveWidth = max(1, (int)($archiveItem['img_width'] ?? 1)); $archiveHeight = max(1, (int)($archiveItem['img_height'] ?? 1)); $archiveRatio = max(2 / 3, min(3 / 2, $archiveWidth / $archiveHeight)); $archiveThumb = !empty($archiveItem['img_thumb_aspect']) ? $archiveItem['img_thumb_aspect'] : ($archiveItem['img_file'] ?? ''); ?><a class="fsog-archive-item" href="<?php echo snap_escape_url($archiveItem['url'] ?? snap_route_url('photo', ['slug' => $archiveItem['img_slug'] ?? ''])); ?>" title="<?php echo snap_escape_attr($archiveItem['img_title'] ?? ''); ?>"><?php if ($archiveThumb !== ''): ?><div class="fsog-thumb<?php if ($archiveRatio < 1): ?> fsog-thumb-portrait<?php endif; ?>" style="aspect-ratio:<?php echo snap_escape_attr(round($archiveRatio, 4)); ?>"><?php echo snap_render_html(snap_render_component('image', ['url' => $archiveThumb, 'alt' => $archiveItem['img_alt'] ?? $archiveItem['img_title'] ?? ''])); ?></div><?php endif; ?><div class="fsog-archive-title"><?php echo snap_escape_html($archiveItem['img_title'] ?? $archiveItem['title'] ?? $archiveItem['name'] ?? ''); ?></div></a><?php endforeach; ?></section>
<section id="justified-grid" class="<?php echo snap_escape_attr(($view['site']['skin_presentation']['options']['masonry_layout'] ?? '') === 'columns' ? 'ss-masonry' : (($view['site']['skin_presentation']['options']['masonry_layout'] ?? '') === 'square' ? 'ss-square-wall' : 'ss-scroll-wall')); ?>" data-masonry-layout="<?php echo snap_escape_attr($view['site']['skin_presentation']['options']['masonry_layout'] ?? ''); ?>"<?php if ($archivePane === 'thumbs'): ?> style="display:none"<?php endif; ?>><?php foreach (($view['response']['items'] ?? []) as $archiveItem): ?><?php $archiveWidth = max(1, (int)($archiveItem['img_width'] ?? 3)); $archiveHeight = max(1, (int)($archiveItem['img_height'] ?? 2)); $archiveThumb = !empty($archiveItem['img_thumb_aspect']) ? $archiveItem['img_thumb_aspect'] : ($archiveItem['img_file'] ?? ''); ?><a class="ss-masonry-item" href="<?php echo snap_escape_url($archiveItem['url'] ?? snap_route_url('photo', ['slug' => $archiveItem['img_slug'] ?? ''])); ?>" title="<?php echo snap_escape_attr($archiveItem['img_title'] ?? ''); ?>"><?php if ($archiveThumb !== ''): ?><?php echo snap_render_html(snap_render_component('image', ['url' => $archiveThumb, 'alt' => $archiveItem['img_alt'] ?? $archiveItem['img_title'] ?? '', 'attributes' => ['data-w' => $archiveWidth, 'data-h' => $archiveHeight]])); ?><?php endif; ?></a><?php endforeach; ?></section>
<?php endif; ?>
<footer id="system-footer" class="site-footer fsog-footer"><div class="inside"><p id="sig-text"><?php echo snap_escape_html($view['site']['site_name']); ?></p></div></footer>
</main></div>
<?php echo snap_render_html(snap_render_component('registered-assets', ['assets' => $view['site']['registered_assets'] ?? []])); ?><?php echo snap_render_html($view['site']['owner_custom_code'] ?? ''); ?>
</body></html>
