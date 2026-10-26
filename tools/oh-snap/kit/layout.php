<?php defined('SNAPSMACK_SKIN_RENDER') || exit; ?>
<?php /* SNAPSMACK_EOF_HEADER: presentation only; preserve the final EOF marker. */ ?>
<!doctype html>
<html lang="<?php echo snap_escape_attr($view['site']['language'] ?? 'en'); ?>" dir="<?php echo snap_escape_attr($view['site']['direction'] ?? 'ltr'); ?>">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo snap_escape_html($view['response']['page_title'] ?? $view['site']['site_name'] ?? 'Photography'); ?></title>
<link rel="stylesheet" href="<?php echo snap_escape_url($view['site']['skin_style_url'] ?? 'style.css'); ?>">
<?php echo snap_render_html($view['site']['skin_custom_style'] ?? ''); ?>
<?php echo snap_render_html($view['site']['skin_presentation']['style'] ?? ''); ?>
</head>
<body class="ohsnap-skin">
<header id="header"><div class="inside"><a class="logo-area site-title-text" data-preview-site href="<?php echo snap_route_url('home'); ?>"><?php echo snap_escape_html($view['site']['site_name'] ?? 'Photography'); ?></a><p class="tagline" data-preview-tagline><?php echo snap_escape_html($view['site']['tagline'] ?? ''); ?></p>
<?php echo snap_render_html(snap_render_component('navigation', ['items' => $view['response']['navigation'] ?? []])); ?>
</div></header>
<main id="scroll-stage">
<?php if (($view['response']['kind'] ?? '') === 'photo' || ($view['response']['kind'] ?? '') === 'post' || ($view['response']['kind'] ?? '') === 'collection'): ?>
<article class="single-post h-entry"><h1 class="photo-title-footer p-name" data-preview-title><?php echo snap_escape_html($view['response']['item']['title'] ?? $view['response']['item']['img_title'] ?? ''); ?></h1>
<div id="photobox" class="post-image-wrap">
<?php if (!empty($view['response']['item']['img_file']) || !empty($view['response']['item']['featured_image_path'])): ?><img data-preview-image class="post-image u-photo" src="<?php echo snap_escape_url($view['response']['item']['img_file'] ?? $view['response']['item']['featured_image_path']); ?>" alt="<?php echo snap_escape_attr($view['response']['item']['img_alt'] ?? $view['response']['item']['title'] ?? $view['response']['item']['img_title'] ?? ''); ?>"><?php endif; ?>
</div>
<div class="gram-strip">
<?php foreach (($view['response']['photographs'] ?? []) as $photograph): ?><figure><a href="<?php echo snap_route_url('photo', ['slug' => $photograph['img_slug'] ?? '']); ?>"><img class="post-image" src="<?php echo snap_escape_url($photograph['img_file'] ?? $photograph['url'] ?? ''); ?>" alt="<?php echo snap_escape_attr($photograph['img_alt'] ?? $photograph['img_title'] ?? ''); ?>"></a></figure><?php endforeach; ?>
</div>
<div class="description entry-content e-content" data-preview-caption><?php echo snap_render_html($view['response']['item']['content'] ?? $view['response']['item']['description'] ?? $view['response']['item']['img_description'] ?? $view['response']['rendered_content'] ?? ''); ?></div>
<nav id="infobox" aria-label="Photograph navigation">
<?php if (!empty($view['response']['previous'])): ?><a href="<?php echo snap_escape_url($view['response']['previous']['url'] ?? snap_route_url('photo', ['slug' => $view['response']['previous']['img_slug'] ?? $view['response']['previous']['slug'] ?? ''])); ?>">Previous</a><?php endif; ?>
<?php if (!empty($view['response']['next'])): ?><a href="<?php echo snap_escape_url($view['response']['next']['url'] ?? snap_route_url('photo', ['slug' => $view['response']['next']['img_slug'] ?? $view['response']['next']['slug'] ?? ''])); ?>">Next</a><?php endif; ?>
</nav>
<?php echo snap_render_html(snap_render_component('comments', ['items' => $view['response']['comments'] ?? []])); ?>
</article>
<?php elseif (($view['response']['kind'] ?? '') === 'page'): ?>
<article class="static-transmission"><h1 class="static-page-title" data-preview-title><?php echo snap_escape_html($view['response']['item']['title'] ?? $view['response']['page_title'] ?? ''); ?></h1><div class="entry-content" data-preview-caption><?php echo snap_render_html($view['response']['item']['content'] ?? $view['response']['item']['description'] ?? $view['response']['rendered_content'] ?? ''); ?></div></article>
<?php elseif (($view['response']['kind'] ?? '') === 'blogroll'): ?>
<section class="static-transmission"><h1>Blogroll</h1><?php echo snap_render_html(snap_render_component('blogroll', ['groups' => $view['response']['blogroll_groups'] ?? []])); ?></section>
<?php elseif (($view['response']['kind'] ?? '') === 'search'): ?>
<section class="search-results"><h1>Search: <?php echo snap_escape_html($view['response']['query'] ?? ''); ?></h1>
<?php foreach (($view['response']['results'] ?? []) as $group): ?><?php foreach ($group as $item): ?>
<article class="search-result"><h2><a href="<?php echo snap_escape_url($item['url'] ?? snap_route_url('photo', ['slug' => $item['img_slug'] ?? $item['slug'] ?? ''])); ?>"><?php echo snap_escape_html($item['img_title'] ?? $item['title'] ?? ''); ?></a></h2>
<?php if (!empty($item['img_thumb_aspect']) || !empty($item['img_file'])): ?><img src="<?php echo snap_escape_url($item['img_thumb_aspect'] ?? $item['img_file']); ?>" alt="<?php echo snap_escape_attr($item['img_alt'] ?? $item['img_title'] ?? ''); ?>"><?php endif; ?>
</article><?php endforeach; ?><?php endforeach; ?>
<?php if (!empty($view['response']['next_page'])): ?><a class="next-page" href="<?php echo snap_route_url('search', ['query' => $view['response']['query'] ?? '', 'page' => $view['response']['next_page']]); ?>">More results</a><?php endif; ?>
</section>
<?php elseif (($view['response']['kind'] ?? '') === 'not_found' || ($view['response']['kind'] ?? '') === 'safe_fallback'): ?>
<section class="static-transmission"><h1>Page not found</h1><p><a href="<?php echo snap_route_url('home'); ?>">Return home</a></p></section>
<?php else: ?>
<section class="archive-section"><h1 data-preview-heading><?php echo snap_escape_html($view['response']['page_title'] ?? 'Photographs'); ?></h1>
<div id="browse-grid" class="archive-grid">
<?php foreach (($view['response']['items'] ?? $view['response']['posts'] ?? []) as $item): ?>
<article class="thumb-container" data-preview-card><a class="thumb-link" data-preview-link href="<?php if (($view['response']['kind'] ?? '') === 'albums'): ?><?php echo snap_route_url('archive', ['album' => $item['id'] ?? 0]); ?><?php elseif (($view['response']['kind'] ?? '') === 'collections'): ?><?php echo snap_route_url('collection', ['slug' => $item['slug'] ?? '']); ?><?php elseif (!empty($item['post_slug']) || !empty($item['post_type'])): ?><?php echo snap_route_url('post', ['slug' => $item['post_slug'] ?? $item['slug'] ?? '']); ?><?php else: ?><?php echo snap_escape_url($item['url'] ?? snap_route_url('photo', ['slug' => $item['img_slug'] ?? $item['slug'] ?? ''])); ?><?php endif; ?>">
<?php if (!empty($item['img_thumb_aspect']) || !empty($item['img_file']) || !empty($item['featured_image_path']) || !empty($item['cover_path'])): ?><img data-preview-image src="<?php echo snap_escape_url($item['img_thumb_aspect'] ?? $item['img_file'] ?? $item['featured_image_path'] ?? $item['cover_path']); ?>" alt="<?php echo snap_escape_attr($item['img_alt'] ?? $item['title'] ?? $item['img_title'] ?? $item['album_name'] ?? ''); ?>" loading="lazy"><?php endif; ?>
<h2 data-preview-title><?php echo snap_escape_html($item['title'] ?? $item['img_title'] ?? $item['name'] ?? $item['album_name'] ?? ''); ?></h2></a><p data-preview-caption><?php echo snap_escape_html($item['excerpt'] ?? ''); ?></p></article>
<?php endforeach; ?>
</div>
<?php if (empty($view['response']['items']) && empty($view['response']['posts'])): ?><p class="empty-state">No photographs to show yet.</p><?php endif; ?>
<?php if (!empty($view['response']['next_page'])): ?><a class="next-page" href="<?php if (($view['response']['kind'] ?? '') === 'hashtag'): ?><?php echo snap_route_url('hashtag', ['slug' => $view['response']['slug'] ?? '', 'page' => $view['response']['next_page']]); ?><?php elseif (($view['response']['kind'] ?? '') === 'landing'): ?><?php echo snap_route_url('home', ['page' => $view['response']['next_page']]); ?><?php else: ?><?php echo snap_route_url('archive', ['page' => $view['response']['next_page'], 'album' => $view['response']['taxonomy']['album_id'] ?? '', 'category' => $view['response']['taxonomy']['category_id'] ?? '']); ?><?php endif; ?>">More photographs</a><?php endif; ?>
</section>
<?php endif; ?>
</main>
<footer id="system-footer"><div class="inside"><p id="sig-text">Powered by <a href="https://snapsmack.ca">SnapSmack</a></p><?php echo snap_render_html(snap_render_component('footer', ['footer' => $view['site']['footer'] ?? []])); ?></div></footer>
<?php echo snap_render_html(snap_render_component('registered-assets', ['assets' => $view['site']['registered_assets'] ?? []])); ?>
</body></html>
<?php // ===== SNAPSMACK EOF =====
