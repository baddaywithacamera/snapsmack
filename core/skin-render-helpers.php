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
        'collections' => 'collections', 'collection' => 'collection', 'game-scores' => 'game-on-scores.php',
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
    if ($name === 'smacktalk-page') {
        $response = is_array($data['response'] ?? null) ? $data['response'] : [];
        $site = is_array($data['site'] ?? null) ? $data['site'] : [];
        $skin = (string)($site['skin_slug'] ?? '');
        $legacyFamily = in_array($skin, ['alfred','telegram','tilez'], true);
        $pageId = $skin === 'stanley' ? 'stanley-page' : ($skin === 'writing-with-impact' ? 'wwi-page' : 'page');
        $headerId = $skin === 'stanley' ? 'stanley-header' : ($skin === 'writing-with-impact' ? 'wwi-header' : 'header');
        $contentId = $skin === 'stanley' ? 'stanley-content' : ($skin === 'writing-with-impact' ? 'wwi-content' : 'content');
        $feedClass = $skin === 'stanley' ? 'stanley-posts' : ($skin === 'writing-with-impact' ? 'wwi-posts' : 'posts ss-masonry tilez-posts');
        $feedItemClass = $skin === 'stanley' ? 'stanley-post-summary' : ($skin === 'writing-with-impact' ? 'wwi-post-summary' : 'post');
        $html .= snap_render_html($site['skin_custom_style'] ?? '');
        $html .= '<div id="' . $pageId . '" class="' . snap_escape_attr($skin) . '"><header id="' . $headerId . '" class="site-header header section-inner" data-sticky-header="false"><div class="inside"><a class="site-title logo-area blog-title" href="' . snap_route_url('home') . '"><span class="site-title-text">'
            . snap_escape_html($site['site_name'] ?? '') . '</span></a>';
        if (!empty($site['tagline'])) $html .= '<p class="site-tagline">' . snap_escape_html($site['tagline']) . '</p>';
        $html .= snap_render_html(snap_render_component('navigation', ['items' => $response['navigation'] ?? []])) . '</div></header>';
        $html .= '<div id="' . $contentId . '"><main id="scroll-stage" class="content post-inner reading-column longform" role="main">';
        if (($response['kind'] ?? '') === 'single') {
            $post = is_array($response['post'] ?? null) ? $response['post'] : [];
            $html .= '<article class="smacktalk-post post-container h-entry"><div class="post-header"><h1 class="post-title p-name">' . snap_escape_html($post['title'] ?? '') . '</h1>'
                . '<p class="post-date">' . snap_escape_html($post['created_at'] ?? '') . '</p>';
            if(!empty($post['created_at']))$html.='<time class="dt-published" datetime="'.snap_escape_attr($post['created_at']).'"></time>';
            if(!empty($site['owner_name']))$html.='<span class="p-author h-card"><span class="p-name">'.snap_escape_html($site['owner_name']).'</span></span>';
            $html .= '</div><div class="post-inner">';
            if (!empty($post['featured_image_path'])) {
                $html .= snap_render_html(snap_render_component('image', ['url' => $post['featured_image_path'], 'alt' => $post['title'] ?? '']));
            }
            $html .= '<div class="post-content entry-content e-content">' . snap_render_html($response['rendered_content'] ?? '') . '</div>';
            if (!empty($response['colophon'])) $html .= '<aside class="post-colophon">' . snap_escape_html($response['colophon']) . '</aside>';
            if (!empty($response['comments_enabled'])) {
                $html .= snap_render_html(snap_render_component('comments', ['items' => $response['comments'] ?? []]));
            }
            $html .= '</div></article>';
        } elseif (($response['kind'] ?? '') === 'feed') {
            $html .= '<section class="section-inner"><div id="browse-grid" class="' . $feedClass . ' smacktalk-feed public-grid h-feed">';
            foreach (($response['posts'] ?? []) as $post) {
                if (!is_array($post)) continue;
                $html .= '<article class="' . $feedItemClass . ' smacktalk-feed-item"><a href="' . snap_escape_url($post['url'] ?? '') . '">';
                if (!empty($post['image_url'])) $html .= snap_render_html(snap_render_component('image', ['url' => $post['image_url'], 'alt' => $post['title'] ?? '']));
                $html .= '<h2>' . snap_escape_html($post['title'] ?? '') . '</h2></a></article>';
            }
            $html .= '</div></section>';
        } elseif (($response['kind'] ?? '') === 'archive') {
            $archiveClass = $skin === 'stanley' ? 'stanley-archive-grid' : ($skin === 'writing-with-impact' ? 'wwi-archive-grid' : 'alfred-archive-grid');
            $html .= '<section class="section-inner smacktalk-archive"><h1>' . snap_escape_html($response['page_title'] ?? 'Archive') . '</h1><div class="' . $archiveClass . '">';
            foreach (($response['tiles'] ?? []) as $tile) {
                if (!is_array($tile)) continue;
                $html .= '<a href="' . snap_escape_url($tile['full'] ?? '') . '">'
                    . snap_render_html(snap_render_component('image', ['url' => $tile['thumb'] ?? '', 'alt' => $tile['title'] ?? ''])) . '</a>';
            }
            $html .= '</div></section>';
        } else {
            $html .= '<section class="not-found"><h1>Not found</h1></section>';
        }
        $footerId = $skin === 'stanley' ? 'stanley-footer' : ($skin === 'writing-with-impact' ? 'wwi-skin-footer' : 'system-footer');
        $html .= '</main></div><footer id="' . $footerId . '" class="site-footer"><div id="footer" class="inside"><p id="sig-text">' . snap_escape_html($site['site_name'] ?? '') . '</p></div></footer></div>' . snap_render_html(snap_render_component('registered-assets', ['assets'=>$site['registered_assets']??[]])) . snap_render_html($site['owner_custom_code'] ?? '');
    } elseif ($name === 'navigation-tree') {
        $html = snap_render_navigation_tree(is_array($data['items'] ?? null) ? $data['items'] : []);
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
