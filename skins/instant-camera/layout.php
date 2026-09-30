<?php defined('SNAPSMACK_SKIN_RENDER') || exit; ?>
<!doctype html>
<html lang="<?php echo snap_escape_attr($view['site']['language']); ?>" dir="<?php echo snap_escape_attr($view['site']['direction']); ?>">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo snap_escape_html($view['response']['page_title'] ?? $view['site']['site_name']); ?></title>
<link rel="stylesheet" href="<?php echo snap_escape_url($view['site']['skin_style_url']); ?>">
<?php echo snap_render_html($view['site']['skin_custom_style'] ?? ''); ?>
<?php echo snap_render_html($view['site']['skin_presentation']['style'] ?? ''); ?>
</head>
<body class="instant-camera-v2">
<?php if (($view['site']['skin_presentation']['background']['mode'] ?? '') === 'mayhem'): ?>
<div id="organized-mayhem" class="ic-bg ic-bg-mayhem" aria-hidden="true" data-mayhem data-api-url="?ajax=mayhem" data-pan="0" data-ambient="1" data-initial-count="<?php echo snap_escape_attr($view['site']['skin_presentation']['background']['initial_count']); ?>" data-max-width="<?php echo snap_escape_attr($view['site']['skin_presentation']['background']['max_width']); ?>" data-overlap-max="<?php echo snap_escape_attr($view['site']['skin_presentation']['background']['overlap_max']); ?>" data-drift="<?php echo snap_escape_attr($view['site']['skin_presentation']['background']['drift']); ?>" data-warp="<?php echo snap_escape_attr($view['site']['skin_presentation']['background']['warp']); ?>"></div>
<?php elseif (($view['site']['skin_presentation']['background']['mode'] ?? '') === 'racetrack'): ?>
<div class="ic-bg ic-bg-racetrack" aria-hidden="true" data-racetrack data-cycle="<?php echo snap_escape_attr($view['site']['skin_presentation']['background']['cycle']); ?>" data-speed="<?php echo snap_escape_attr($view['site']['skin_presentation']['background']['racetrack_speed']); ?>" data-count="<?php echo snap_escape_attr($view['site']['skin_presentation']['background']['racetrack_count']); ?>" data-size="<?php echo snap_escape_attr($view['site']['skin_presentation']['background']['racetrack_size']); ?>" data-opacity="<?php echo snap_escape_attr($view['site']['skin_presentation']['background']['racetrack_opacity']); ?>"></div>
<?php elseif (($view['site']['skin_presentation']['background']['mode'] ?? '') === 'rainfall'): ?>
<div class="ic-bg ic-bg-rainfall" aria-hidden="true" data-rainfall data-density="<?php echo snap_escape_attr($view['site']['skin_presentation']['background']['rainfall_density']); ?>" data-speed="<?php echo snap_escape_attr($view['site']['skin_presentation']['background']['rainfall_speed']); ?>" data-angle="<?php echo snap_escape_attr($view['site']['skin_presentation']['background']['rainfall_angle']); ?>" data-thickness="<?php echo snap_escape_attr($view['site']['skin_presentation']['background']['rainfall_thickness']); ?>" data-color="<?php echo snap_escape_attr($view['site']['skin_presentation']['background']['rainfall_color']); ?>" data-opacity="<?php echo snap_escape_attr($view['site']['skin_presentation']['background']['rainfall_opacity']); ?>"></div>
<?php elseif (($view['site']['skin_presentation']['background']['mode'] ?? '') === 'static'): ?>
<div class="ic-bg ic-bg-static" aria-hidden="true"></div>
<?php endif; ?>
<div class="ic-scrim" aria-hidden="true"></div><div class="ic-panel" aria-hidden="true"></div>
<div class="tg-content-wrap landing-feed">
<?php if (($view['site']['skin_presentation']['options']['ic_profile_header'] ?? '') === '1'): ?>
<header class="tg-profile">
<?php if (!empty($view['site']['avatar_url'])): ?><div class="tg-profile-avatar"><img src="<?php echo snap_escape_url($view['site']['avatar_url']); ?>" alt=""></div><?php else: ?><div class="tg-profile-avatar tg-profile-avatar-initials"><?php echo snap_escape_html($view['site']['site_name']); ?></div><?php endif; ?>
<div class="tg-profile-info"><div class="tg-profile-nameline"><h1 class="tg-profile-username"><?php echo snap_escape_html($view['site']['site_name']); ?></h1>
<?php if (($view['site']['skin_presentation']['options']['ic_show_tagline'] ?? '') === '1' && !empty($view['site']['tagline'])): ?><span class="tg-profile-tagline-sep">/</span><p class="tg-profile-tagline"><?php echo snap_escape_html($view['site']['tagline']); ?></p><?php endif; ?>
</div><div class="tg-profile-stats"><span class="tg-profile-stat"><strong class="tg-profile-stat-num"><?php echo snap_escape_html($view['response']['photo_count'] ?? ''); ?></strong><span class="tg-profile-stat-label">posts</span></span></div>
<?php if (!empty($view['site']['site_description'])): ?><p class="tg-profile-bio"><?php echo snap_escape_html($view['site']['site_description']); ?></p><?php endif; ?>
</div></header>
<?php endif; ?>
<nav class="tg-sticky-nav" aria-label="Primary"><div class="tg-sticky-nav-inner"><ul class="tg-sticky-nav-links">
<?php if (!empty($view['response']['navigation'])): ?><?php echo snap_render_html(snap_render_component('navigation-tree', ['items' => $view['response']['navigation']])); ?><?php else: ?><li><a class="active" href="<?php echo snap_route_url('home'); ?>">Home</a></li><?php endif; ?>
</ul></div></nav>
<?php if (($view['response']['kind'] ?? '') === 'landing' || ($view['response']['kind'] ?? '') === 'archive' || ($view['response']['kind'] ?? '') === 'hashtag'): ?>
<main><div id="browse-grid" class="tg-grid public-grid h-feed archive-grid">
<?php foreach (($view['response']['items'] ?? []) as $item): ?>
<?php if (!empty($item['img_thumb_aspect']) || !empty($item['img_thumb_square']) || !empty($item['img_file'])): ?>
<article class="tg-tile<?php if (!empty($item['is_framed'])): ?> tg-tile--framed<?php endif; ?>" style="<?php echo snap_escape_attr($item['frame_style'] ?? ''); ?>"><a href="<?php echo snap_escape_url($item['url'] ?? snap_route_url('photo', ['slug' => $item['img_slug'] ?? ''])); ?>" title="<?php echo snap_escape_attr($item['img_title'] ?? $item['title'] ?? ''); ?>"><?php echo snap_render_html(snap_render_component('image', ['url' => $item['img_thumb_aspect'] ?? $item['img_thumb_square'] ?? $item['img_file'], 'alt' => $item['img_alt'] ?? $item['img_title'] ?? ''])); ?></a></article>
<?php endif; ?>
<?php endforeach; ?>
</div></main>
<?php elseif (($view['response']['kind'] ?? '') === 'photo' || ($view['response']['kind'] ?? '') === 'post'): ?>
<main><article class="tg-post-ig"><div class="tg-post-ig-image">
<?php if (!empty($view['response']['item']['img_file']) || !empty($view['response']['item']['featured_image_path'])): ?><?php echo snap_render_html(snap_render_component('image', ['url' => $view['response']['item']['img_file'] ?? $view['response']['item']['featured_image_path'], 'alt' => $view['response']['item']['img_alt'] ?? $view['response']['item']['img_title'] ?? '', 'class' => 'tg-single-img'])); ?><?php endif; ?>
</div><div class="tg-post-ig-info"><div class="tg-post-ig-body"><h1><?php echo snap_escape_html($view['response']['item']['img_title'] ?? $view['response']['item']['title'] ?? ''); ?></h1><div class="tg-post-caption-block"><?php echo snap_render_html($view['response']['item']['content'] ?? $view['response']['item']['description'] ?? $view['response']['item']['img_description'] ?? ''); ?></div></div></div></article></main>
<?php elseif (($view['response']['kind'] ?? '') === 'page'): ?>
<main><article class="tg-static-page"><h1 class="tg-static-title"><?php echo snap_escape_html($view['response']['item']['title'] ?? ''); ?></h1><div class="tg-static-content"><div class="tg-static-body description"><?php echo snap_render_html($view['response']['item']['content'] ?? ''); ?></div></div></article></main>
<?php else: ?><main><section class="tg-grid-empty"><h1>Not found</h1></section></main><?php endif; ?>
<?php echo snap_render_html(snap_render_component('gram-search-dock', ['dock' => $view['site']['search_dock'] ?? []])); ?>
<footer id="system-footer" class="site-footer"><div id="footer"><p id="sig-text"><?php echo snap_escape_html($view['site']['site_name']); ?></p></div></footer>
</div>
<div id="tg-modal-overlay" class="tg-modal-overlay" hidden data-grid-url="<?php echo snap_route_url('home'); ?>"><div class="tg-modal-backdrop"></div><div id="tg-modal-frame" class="tg-modal-frame"></div></div><div id="tg-lightbox" class="tg-lightbox" hidden><button type="button" class="tg-lightbox-close" aria-label="Close">&times;</button><img class="tg-lightbox-img" src="" alt=""></div>
<?php echo snap_render_html(snap_render_component('registered-assets', ['assets' => $view['site']['registered_assets'] ?? []])); ?>
<?php echo snap_render_html($view['site']['owner_custom_code'] ?? ''); ?>
</body></html>
