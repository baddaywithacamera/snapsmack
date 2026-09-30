<?php
declare(strict_types=1);

require_once __DIR__ . '/trusted-html.php';

function snap_escape_html(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function snap_escape_attr(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function snap_escape_url(mixed $value): string {
    $url = trim((string)$value);
    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
    if (str_starts_with($url, '//') || ($scheme !== '' && !in_array($scheme, ['http', 'https'], true))) return '';
    return htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function snap_render_html(mixed $value): string {
    return $value instanceof SnapTrustedHtml || $value instanceof SnapOwnerCode ? (string)$value : snap_escape_html($value);
}

function snap_asset_url(string $handle): string {
    static $paths = null;
    if ($paths === null) {
        $paths = [];
        require_once __DIR__ . '/asset-registry.php';
        foreach (snapsmack_asset_registry() as $name => $entry) if (($entry['scope'] ?? '') === 'public') $paths[$name] = $entry['path'];
        $inventory = require __DIR__ . '/manifest-inventory.php';
        foreach (($inventory['scripts'] ?? []) as $name => $entry) {
            if (is_string($name) && is_array($entry) && is_string($entry['path'] ?? null)) $paths[$name] = $entry['path'];
            if (is_string($name) && is_array($entry) && is_string($entry['css'] ?? null)) $paths[$name . ':css'] = $entry['css'];
        }
    }
    $path = $paths[$handle] ?? '';
    if ($path === '' || str_contains($path, '..') || preg_match('#^(?:[a-z]+:|/)#i', $path)) return '';
    $base = defined('BASE_URL') ? rtrim((string)BASE_URL, '/') . '/' : '/';
    return snap_escape_url($base . ltrim($path, '/'));
}

function snap_route_url(string $route, array $parameters = []): string {
    $routes = [
        'home' => '', 'archive' => 'archive', 'search' => 'search', 'hashtag' => 'tag',
        'post' => 'post', 'photo' => 'photo', 'page' => 'page', 'albums' => 'albums',
        'collections' => 'collections', 'collection' => 'collection',
    ];
    if (!array_key_exists($route, $routes)) return '';
    $allowed = array_intersect_key($parameters, array_flip(['slug', 'id', 'page', 'query']));
    foreach ($allowed as $key => $value) {
        if (!is_scalar($value)) unset($allowed[$key]);
        else $allowed[$key] = (string)$value;
    }
    $base = defined('BASE_URL') ? rtrim((string)BASE_URL, '/') . '/' : '/';
    $path = $routes[$route];
    return snap_escape_url($base . $path . ($allowed ? '?' . http_build_query($allowed, '', '&', PHP_QUERY_RFC3986) : ''));
}

function snap_render_navigation_tree(array $items, int $depth = 0): string {
    $html = '';
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $children = is_array($item['children'] ?? null) && $depth < 2 ? $item['children'] : [];
        $hasChildren = $children !== [];
        $html .= '<li' . ($hasChildren ? ' class="nav-has-children"' : '') . '>';
        $label = snap_escape_html($item['label'] ?? $item['title'] ?? '');
        $url = (string)($item['url'] ?? '');
        if ($url === '') $html .= '<span>' . $label . '</span>';
        else $html .= '<a href="' . snap_escape_url($url) . '"' . (($item['target'] ?? '') === '_blank' ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . $label . '</a>';
        if ($hasChildren) $html .= '<ul class="nav-submenu">' . snap_render_navigation_tree($children, $depth + 1) . '</ul>';
        $html .= '</li>';
    }
    return $html;
}

/** Render fixed shared chrome from bounded data; components cannot execute code. */
function snap_render_component(string $name, array $data): SnapTrustedHtml {
    $html = '';
    if ($name === 'public-page' && (($data['site']['skin_slug'] ?? '') === 'instant-camera')) {
        $name = 'instant-camera-page';
    }
    if ($name === 'instant-camera-page') {
        $response = is_array($data['response'] ?? null) ? $data['response'] : [];
        $site = is_array($data['site'] ?? null) ? $data['site'] : [];
        $html .= snap_render_html($site['skin_custom_style'] ?? '');
        $presentation = is_array($site['skin_presentation'] ?? null) ? $site['skin_presentation'] : [];
        $html .= snap_render_html($presentation['style'] ?? '');
        $kind = (string)($response['kind'] ?? 'not_found');
        $registered = is_array($site['registered_assets'] ?? null) ? $site['registered_assets'] : [];
        foreach (($registered['scripts'] ?? []) as $script_url) {
            if (is_string($script_url) && str_contains($script_url, 'ss-engine-organized-mayhem.js')) {
                $html .= '<div id="organized-mayhem" class="ic-bg ic-bg-mayhem" aria-hidden="true" data-mayhem data-api-url="?ajax=mayhem" data-pan="0" data-ambient="1" data-initial-count="' . (int)($presentation['mayhem_initial_count'] ?? 120) . '" data-max-width="' . (int)($presentation['mayhem_max_width'] ?? 300) . '" data-overlap-max="' . snap_escape_attr($presentation['mayhem_overlap_max'] ?? '0.85') . '" data-drift="' . (!empty($presentation['mayhem_drift']) ? '1' : '0') . '" data-warp="' . (!empty($presentation['mayhem_warp']) ? '1' : '0') . '"></div>';
                break;
            }
        }
        $siteName = (string)($site['site_name'] ?? '');
        $html .= '<div class="ic-scrim" aria-hidden="true"></div><div class="ic-panel" aria-hidden="true"></div>'
            . '<div class="tg-content-wrap landing-feed"><header class="tg-profile">';
        $avatar = (string)($site['avatar_url'] ?? '');
        if ($avatar !== '') {
            $html .= '<div class="tg-profile-avatar"><img src="' . snap_escape_url($avatar) . '" alt=""></div>';
        } else {
            $html .= '<div class="tg-profile-avatar tg-profile-avatar-initials">' . snap_escape_html(strtoupper(substr($siteName !== '' ? $siteName : 'S', 0, 1))) . '</div>';
        }
        $html .= '<div class="tg-profile-info"><div class="tg-profile-nameline"><h1 class="tg-profile-username">' . snap_escape_html($siteName) . '</h1>';
        if (!empty($site['tagline'])) $html .= '<span class="tg-profile-tagline-sep">/</span><p class="tg-profile-tagline">' . snap_escape_html($site['tagline']) . '</p>';
        $html .= '</div><div class="tg-profile-stats"><span class="tg-profile-stat"><strong class="tg-profile-stat-num">'
            . (int)($response['photo_count'] ?? count($response['items'] ?? [])) . '</strong><span class="tg-profile-stat-label">posts</span></span></div>';
        if (!empty($site['site_description'])) $html .= '<p class="tg-profile-bio">' . nl2br(snap_escape_html($site['site_description']), false) . '</p>';
        $navigation = is_array($response['navigation'] ?? null) ? $response['navigation'] : [];
        $html .= '</div></header><nav class="tg-sticky-nav" aria-label="Primary"><div class="tg-sticky-nav-inner"><ul class="tg-sticky-nav-links">';
        $html .= $navigation !== []
            ? snap_render_navigation_tree($navigation)
            : '<li><a class="active" href="' . snap_route_url('home') . '">Home</a></li>';
        $html .= '</ul></div></nav>';
        if (in_array($kind, ['landing', 'archive', 'hashtag'], true)) {
            $html .= '<main><div id="browse-grid" class="tg-grid public-grid h-feed archive-grid">';
            foreach (($response['items'] ?? []) as $item) {
                if (!is_array($item)) continue;
                $title = (string)($item['img_title'] ?? $item['title'] ?? '');
                $url = (string)($item['url'] ?? '');
                if ($url === '' && (string)($item['img_slug'] ?? '') !== '') $url = snap_route_url('photo', ['slug' => $item['img_slug']]);
                $image = (string)($item['img_thumb_aspect'] ?? $item['img_thumb_square'] ?? $item['img_file'] ?? '');
                if ($image === '') continue;
                $html .= '<article class="tg-tile"><a href="' . snap_escape_url($url) . '" title="' . snap_escape_attr($title) . '">'
                    . snap_render_html(snap_render_component('image', ['url' => $image, 'alt' => $item['img_alt'] ?? $title]))
                    . '</a></article>';
            }
            $html .= '</div></main>';
        } elseif (in_array($kind, ['photo', 'post'], true)) {
            $item = is_array($response['item'] ?? null) ? $response['item'] : [];
            $title = (string)($item['img_title'] ?? $item['title'] ?? '');
            $image = (string)($item['img_file'] ?? $item['featured_image_path'] ?? '');
            $html .= '<main><article class="tg-post-ig"><div class="tg-post-ig-image">';
            if ($image !== '') $html .= snap_render_html(snap_render_component('image', ['url' => $image, 'alt' => $item['img_alt'] ?? $title, 'class' => 'tg-single-img']));
            $html .= '</div><div class="tg-post-ig-info"><div class="tg-post-ig-body"><h1>' . snap_escape_html($title) . '</h1><div class="tg-post-caption-block">'
                . snap_render_html($item['content'] ?? $item['description'] ?? $item['img_description'] ?? '') . '</div></div></div></article></main>';
        } else {
            $html .= '<main><section class="tg-grid-empty"><h1>Not found</h1></section></main>';
        }
        $html .= snap_render_html(snap_render_component('gram-search-dock', ['dock' => $site['search_dock'] ?? []]));
        $html .= '<footer id="system-footer" class="site-footer"><div id="footer"><p id="sig-text">' . snap_escape_html($siteName)
            . '</p></div></footer></div>' . snap_render_html(snap_render_component('registered-assets', ['assets' => $registered]))
            . '<script src="' . snap_asset_url('asset:public:ss-engine-nav-dropdown') . '" defer></script>'
            . snap_render_html($site['owner_custom_code'] ?? '');
    } elseif ($name === 'public-page') {
        $response = is_array($data['response'] ?? null) ? $data['response'] : [];
        $site = is_array($data['site'] ?? null) ? $data['site'] : [];
        $html .= snap_render_html($site['skin_custom_style'] ?? '');
        $kind = (string)($response['kind'] ?? 'not_found');
        $registered = is_array($site['registered_assets'] ?? null) ? $site['registered_assets'] : [];
        foreach (($registered['scripts'] ?? []) as $script_url) {
            if (is_string($script_url) && str_contains($script_url, 'ss-engine-organized-mayhem.js')) {
                $html .= '<div id="organized-mayhem" aria-hidden="true" data-mayhem data-api-url="?ajax=mayhem" data-pan="0" data-ambient="1" data-initial-count="120" data-max-mounted="180"></div>';
                break;
            }
        }
        $html .= '<header id="header" class="site-header" data-sticky-header><div class="inside"><a class="site-title logo-area" href="' . snap_route_url('home') . '"><span class="site-title-text">' . snap_escape_html($site['site_name'] ?? '') . '</span></a>'
            . snap_render_html(snap_render_component('navigation', ['items' => $response['navigation'] ?? []])) . '</div></header><main id="scroll-stage" class="public-content kind-' . snap_escape_attr($kind) . '">';
        if (in_array($kind, ['photo', 'post', 'page', 'collection'], true)) {
            $item = is_array($response['item'] ?? null) ? $response['item'] : [];
            $title = $item['img_title'] ?? $item['title'] ?? $item['name'] ?? '';
            $html .= '<article id="photobox" class="h-entry single-image-page"><h1 class="p-name photo-title-footer">' . snap_escape_html($title) . '</h1>';
            $date=$item['img_date']??$item['created_at']??'';if($date!=='')$html.='<time class="dt-published" datetime="'.snap_escape_attr($date).'">'.snap_escape_html($date).'</time>';
            if(!empty($site['owner_name']))$html.='<span class="p-author h-card"><span class="p-name">'.snap_escape_html($site['owner_name']).'</span></span>';
            $image = $item['img_file'] ?? $item['featured_image_path'] ?? '';
            if ($image !== '') $html .= '<div class="u-photo post-image-wrap">'.snap_render_html(snap_render_component('image', ['url' => $image, 'alt' => $item['img_alt'] ?? $title, 'class' => 'post-image'])).'</div>';
            $content = $item['content'] ?? $item['description'] ?? $item['img_description'] ?? '';
            $html .= '<div id="infobox" class="entry-content e-content static-transmission">' . snap_render_html($content) . '</div></article>';
        } elseif ($kind === 'search') {
            $html .= '<h1>Search</h1>';
            $results = is_array($response['results'] ?? null) ? $response['results'] : [];
            foreach ($results as $group) foreach ((is_array($group) ? $group : []) as $item) {
                if (!is_array($item)) continue;
                $html .= '<article><h2>' . snap_escape_html($item['img_title'] ?? $item['title'] ?? '') . '</h2></article>';
            }
        } elseif (in_array($kind, ['landing', 'archive', 'hashtag', 'albums', 'collections'], true)) {
            $html .= '<section id="browse-grid" class="public-grid h-feed archive-grid">';
            foreach (($response['items'] ?? []) as $item) {
                if (!is_array($item)) continue;
                $title = $item['img_title'] ?? $item['title'] ?? $item['name'] ?? '';
                $url = (string)($item['url'] ?? '');
                if ($url === '' && (string)($item['img_slug'] ?? '') !== '') $url = snap_route_url('photo', ['slug' => $item['img_slug']]);
                $html .= '<article><a href="' . snap_escape_url($url) . '"><h2>' . snap_escape_html($title) . '</h2></a></article>';
            }
            $html .= '</section>';
        } else {
            $html .= '<section class="not-found"><h1>Not found</h1></section>';
        }
        if (!empty($response['comments'])) $html .= snap_render_html(snap_render_component('comments', ['items' => $response['comments']]));
        $html .= '</main><footer id="system-footer" class="site-footer"><div id="footer" class="inside"><p id="sig-text">' . snap_escape_html($site['site_name'] ?? '') . '</p></div></footer>' . snap_render_html(snap_render_component('registered-assets', ['assets'=>$site['registered_assets']??[]])) . snap_render_html($site['owner_custom_code'] ?? '');
    } elseif ($name === 'smacktalk-page') {
        $response = is_array($data['response'] ?? null) ? $data['response'] : [];
        $site = is_array($data['site'] ?? null) ? $data['site'] : [];
        $html .= snap_render_html($site['skin_custom_style'] ?? '');
        $html .= '<header id="header" class="site-header" data-sticky-header><div class="inside"><a class="site-title logo-area" href="' . snap_route_url('home') . '"><span class="site-title-text">'
            . snap_escape_html($site['site_name'] ?? '') . '</span></a>';
        if (!empty($site['tagline'])) $html .= '<p class="site-tagline">' . snap_escape_html($site['tagline']) . '</p>';
        $html .= snap_render_html(snap_render_component('navigation', ['items' => $response['navigation'] ?? []])) . '</div></header>';
        $html .= '<main id="scroll-stage" class="post-inner reading-column longform">';
        if (($response['kind'] ?? '') === 'single') {
            $post = is_array($response['post'] ?? null) ? $response['post'] : [];
            $html .= '<article class="smacktalk-post h-entry"><h1 class="p-name">' . snap_escape_html($post['title'] ?? '') . '</h1>'
                . '<p class="post-date">' . snap_escape_html($post['created_at'] ?? '') . '</p>';
            if(!empty($post['created_at']))$html.='<time class="dt-published" datetime="'.snap_escape_attr($post['created_at']).'"></time>';
            if(!empty($site['owner_name']))$html.='<span class="p-author h-card"><span class="p-name">'.snap_escape_html($site['owner_name']).'</span></span>';
            if (!empty($post['featured_image_path'])) {
                $html .= snap_render_html(snap_render_component('image', ['url' => $post['featured_image_path'], 'alt' => $post['title'] ?? '']));
            }
            $html .= '<div class="post-content e-content">' . snap_render_html($response['rendered_content'] ?? '') . '</div>';
            if (!empty($response['colophon'])) $html .= '<aside class="post-colophon">' . snap_escape_html($response['colophon']) . '</aside>';
            if (!empty($response['comments_enabled'])) {
                $html .= snap_render_html(snap_render_component('comments', ['items' => $response['comments'] ?? []]));
            }
            $html .= '</article>';
        } elseif (($response['kind'] ?? '') === 'feed') {
            $html .= '<section id="browse-grid" class="smacktalk-feed public-grid h-feed">';
            foreach (($response['posts'] ?? []) as $post) {
                if (!is_array($post)) continue;
                $html .= '<article class="smacktalk-feed-item"><a href="' . snap_escape_url($post['url'] ?? '') . '">';
                if (!empty($post['image_url'])) $html .= snap_render_html(snap_render_component('image', ['url' => $post['image_url'], 'alt' => $post['title'] ?? '']));
                $html .= '<h2>' . snap_escape_html($post['title'] ?? '') . '</h2></a></article>';
            }
            $html .= '</section>';
        } elseif (($response['kind'] ?? '') === 'archive') {
            $html .= '<section class="smacktalk-archive"><h1>' . snap_escape_html($response['page_title'] ?? 'Archive') . '</h1>';
            foreach (($response['tiles'] ?? []) as $tile) {
                if (!is_array($tile)) continue;
                $html .= '<a href="' . snap_escape_url($tile['full'] ?? '') . '">'
                    . snap_render_html(snap_render_component('image', ['url' => $tile['thumb'] ?? '', 'alt' => $tile['title'] ?? ''])) . '</a>';
            }
            $html .= '</section>';
        } else {
            $html .= '<section class="not-found"><h1>Not found</h1></section>';
        }
        $html .= '</main><footer id="system-footer" class="site-footer"><div id="footer" class="inside"><p id="sig-text">' . snap_escape_html($site['site_name'] ?? '') . '</p></div></footer>' . snap_render_html(snap_render_component('registered-assets', ['assets'=>$site['registered_assets']??[]])) . snap_render_html($site['owner_custom_code'] ?? '');
    } elseif ($name === 'gram-search-dock') {
        $dock = is_array($data['dock'] ?? null) ? $data['dock'] : [];
        if (!empty($dock['enabled'])) {
            $disc = preg_match('/^#[0-9a-f]{6}$/i', (string)($dock['disc_color'] ?? '')) ? strtolower((string)$dock['disc_color']) : '#ffffff';
            $glass = preg_match('/^#[0-9a-f]{6}$/i', (string)($dock['glass_color'] ?? '')) ? strtolower((string)$dock['glass_color']) : '#262626';
            $opacity = max(0, min(100, (int)($dock['disc_opacity'] ?? 100)));
            $hex = ltrim($disc, '#');
            $rgba = sprintf('rgba(%d,%d,%d,%.2F)', hexdec(substr($hex,0,2)), hexdec(substr($hex,2,2)), hexdec(substr($hex,4,2)), $opacity / 100);
            $html = '<div class="gram-search-dock" data-gram-search-dock style="--gsd-disc-bg:' . snap_escape_attr($rgba) . ';--gsd-glass-color:' . snap_escape_attr($glass) . '">'
                . '<form class="gsd-form" method="GET" action="' . snap_route_url('home') . '" role="search">'
                . '<button type="button" class="gsd-toggle" aria-label="Search" aria-expanded="false"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></button>'
                . '<input type="search" name="q" class="gsd-input" placeholder="' . snap_escape_attr($dock['placeholder'] ?? 'Search or #tag…') . '" autocomplete="off" aria-label="Search photos or tags" tabindex="-1">'
                . '</form></div>';
        }
    } elseif ($name === 'registered-assets') {
        $assets=is_array($data['assets']??null)?$data['assets']:[];
        foreach(($assets['styles']??[]) as $url)$html.='<link rel="stylesheet" href="'.snap_escape_url($url).'">';
        foreach(($assets['scripts']??[]) as $url)$html.='<script src="'.snap_escape_url($url).'" defer></script>';
    } elseif ($name === 'navigation') {
        $html = '<nav class="navigation" aria-label="Primary"><ul class="nav-menu">';
        foreach (($data['items'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $html .= '<li><a href="' . snap_escape_url($item['url'] ?? '') . '">'
                . snap_escape_html($item['label'] ?? '') . '</a></li>';
        }
        $html .= '</ul></nav>';
    } elseif ($name === 'image') {
        $class = trim((string)($data['class'] ?? ''));
        $html = '<img' . ($class !== '' ? ' class="' . snap_escape_attr($class) . '"' : '') . ' src="' . snap_escape_url($data['url'] ?? '') . '" alt="'
            . snap_escape_attr($data['alt'] ?? '') . '" loading="lazy">';
    } elseif ($name === 'comments') {
        $html = '<ol class="snap-comments">';
        foreach (($data['items'] ?? []) as $comment) {
            if (!is_array($comment)) continue;
            $html .= '<li><strong>' . snap_escape_html($comment['author'] ?? $comment['comment_author'] ?? '') . '</strong><p>'
                . nl2br(snap_escape_html($comment['text'] ?? $comment['comment_text'] ?? ''), false) . '</p></li>';
        }
        $html .= '</ol>';
    }
    return SnapTrustedHtml::__snapsmackCmsOnly($html);
}
