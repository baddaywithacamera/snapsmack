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
    return $value instanceof SnapTrustedHtml ? (string)$value : snap_escape_html($value);
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

/** Render fixed shared chrome from bounded data; components cannot execute code. */
function snap_render_component(string $name, array $data): SnapTrustedHtml {
    $html = '';
    if ($name === 'public-page') {
        $response = is_array($data['response'] ?? null) ? $data['response'] : [];
        $site = is_array($data['site'] ?? null) ? $data['site'] : [];
        $kind = (string)($response['kind'] ?? 'not_found');
        $html = '<header class="site-header"><a class="site-title" href="' . snap_route_url('home') . '">' . snap_escape_html($site['site_name'] ?? '') . '</a>'
            . snap_render_html(snap_render_component('navigation', ['items' => $response['navigation'] ?? []])) . '</header><main class="public-content kind-' . snap_escape_attr($kind) . '">';
        if (in_array($kind, ['photo', 'post', 'page', 'collection'], true)) {
            $item = is_array($response['item'] ?? null) ? $response['item'] : [];
            $title = $item['img_title'] ?? $item['title'] ?? $item['name'] ?? '';
            $html .= '<article><h1>' . snap_escape_html($title) . '</h1>';
            $image = $item['img_file'] ?? $item['featured_image_path'] ?? '';
            if ($image !== '') $html .= snap_render_html(snap_render_component('image', ['url' => $image, 'alt' => $item['img_alt'] ?? $title]));
            $content = $item['content'] ?? $item['description'] ?? $item['img_description'] ?? '';
            $html .= '<div class="entry-content">' . snap_render_html($content) . '</div></article>';
        } elseif ($kind === 'search') {
            $html .= '<h1>Search</h1>';
            $results = is_array($response['results'] ?? null) ? $response['results'] : [];
            foreach ($results as $group) foreach ((is_array($group) ? $group : []) as $item) {
                if (!is_array($item)) continue;
                $html .= '<article><h2>' . snap_escape_html($item['img_title'] ?? $item['title'] ?? '') . '</h2></article>';
            }
        } elseif (in_array($kind, ['landing', 'archive', 'hashtag', 'albums', 'collections'], true)) {
            $html .= '<section class="public-grid">';
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
        $html .= '</main><footer class="site-footer"><p>' . snap_escape_html($site['site_name'] ?? '') . '</p></footer>';
    } elseif ($name === 'smacktalk-page') {
        $response = is_array($data['response'] ?? null) ? $data['response'] : [];
        $site = is_array($data['site'] ?? null) ? $data['site'] : [];
        $html = '<header class="site-header"><a class="site-title" href="' . snap_route_url('home') . '">'
            . snap_escape_html($site['site_name'] ?? '') . '</a>';
        if (!empty($site['tagline'])) $html .= '<p class="site-tagline">' . snap_escape_html($site['tagline']) . '</p>';
        $html .= snap_render_html(snap_render_component('navigation', ['items' => $response['navigation'] ?? []])) . '</header>';
        $html .= '<main class="post-inner reading-column">';
        if (($response['kind'] ?? '') === 'single') {
            $post = is_array($response['post'] ?? null) ? $response['post'] : [];
            $html .= '<article class="smacktalk-post"><h1>' . snap_escape_html($post['title'] ?? '') . '</h1>'
                . '<p class="post-date">' . snap_escape_html($post['created_at'] ?? '') . '</p>';
            if (!empty($post['featured_image_path'])) {
                $html .= snap_render_html(snap_render_component('image', ['url' => $post['featured_image_path'], 'alt' => $post['title'] ?? '']));
            }
            $html .= '<div class="post-content">' . snap_render_html($response['rendered_content'] ?? '') . '</div>';
            if (!empty($response['colophon'])) $html .= '<aside class="post-colophon">' . snap_escape_html($response['colophon']) . '</aside>';
            if (!empty($response['comments_enabled'])) {
                $html .= snap_render_html(snap_render_component('comments', ['items' => $response['comments'] ?? []]));
            }
            $html .= '</article>';
        } elseif (($response['kind'] ?? '') === 'feed') {
            $html .= '<section class="smacktalk-feed">';
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
        $html .= '</main><footer class="site-footer"><p>' . snap_escape_html($site['site_name'] ?? '') . '</p></footer>';
    } elseif ($name === 'navigation') {
        $html = '<nav aria-label="Primary"><ul>';
        foreach (($data['items'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $html .= '<li><a href="' . snap_escape_url($item['url'] ?? '') . '">'
                . snap_escape_html($item['label'] ?? '') . '</a></li>';
        }
        $html .= '</ul></nav>';
    } elseif ($name === 'image') {
        $html = '<img src="' . snap_escape_url($data['url'] ?? '') . '" alt="'
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
