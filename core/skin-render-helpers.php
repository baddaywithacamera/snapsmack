<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

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
        'collections' => 'collections', 'collection' => 'collection', 'blogroll' => 'blogroll',
        'game-scores' => 'game-on-scores.php',
    ];
    if (!array_key_exists($route, $routes)) return '';
    $allowed = array_intersect_key($parameters, array_flip(['slug', 'id', 'page', 'query']));
    foreach ($allowed as $key => $value) {
        if (!is_scalar($value)) unset($allowed[$key]);
        else $allowed[$key] = (string)$value;
    }
    $base = defined('BASE_URL') ? rtrim((string)BASE_URL, '/') . '/' : '/';
    if (($route === 'page' || $route === 'post' || $route === 'photo') && !empty($allowed['slug'])) {
        return snap_escape_url($base . rawurlencode($allowed['slug']));
    }
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
    if ($name === 'grid-photo-fragment') {
        $prefix = preg_replace('/[^a-z]/', '', strtolower((string)($data['prefix'] ?? 'tg')));
        if (!in_array($prefix, ['tg', 'au', 'pa', 'jt', 'he'], true)) $prefix = 'tg';
        $item = is_array($data['item'] ?? null) ? $data['item'] : [];
        $site = is_array($data['site'] ?? null) ? $data['site'] : [];
        $comments = is_array($data['comments'] ?? null) ? $data['comments'] : [];
        $siteName = snap_escape_html($site['site_name'] ?? '');
        $image = (string)($item['img_file'] ?? '');
        $html = '<article class="' . $prefix . '-post-ig"><div class="' . $prefix . '-post-ig-image">';
        if ($image !== '') {
            $html .= (string)snap_render_component('image', [
                'url' => $image,
                'alt' => $item['img_alt'] ?? $item['img_title'] ?? '',
                'class' => $prefix . '-single-img',
                'attributes' => ['data-lightbox-src' => $image],
            ]);
        }
        $html .= '</div><div class="' . $prefix . '-post-ig-info">'
            . '<div class="' . $prefix . '-post-ig-header"><button class="' . $prefix . '-back-btn" type="button" aria-label="Back to grid">&#8592;</button>';
        if (!empty($site['avatar_url'])) {
            $html .= '<img class="' . $prefix . '-post-ig-avatar" src="' . snap_escape_url($site['avatar_url']) . '" alt="">';
        } else {
            $html .= '<span class="' . $prefix . '-post-ig-avatar ' . $prefix . '-post-ig-avatar--initials">' . $siteName . '</span>';
        }
        $html .= '<span class="' . $prefix . '-post-ig-sitename">' . $siteName . '</span></div>'
            . '<div class="' . $prefix . '-post-ig-body"><div class="' . $prefix . '-post-caption-block"><p class="' . $prefix . '-post-ig-caption">'
            . '<span class="' . $prefix . '-post-ig-caption-user">' . $siteName . '</span>';
        if (!empty($item['img_title'])) $html .= ' ' . snap_escape_html($item['img_title']);
        $description = $item['content'] ?? $item['description'] ?? $item['img_description'] ?? '';
        if ((string)$description !== '') $html .= '<br>' . snap_render_html($description);
        $html .= '</p></div>';
        $exif = is_array($item['exif'] ?? null) ? $item['exif'] : [];
        $fields = ['camera'=>'Camera','lens'=>'Lens','focal'=>'Focal','film'=>'Film','iso'=>'ISO','aperture'=>'Aperture','shutter'=>'Shutter','flash'=>'Flash'];
        $rows = '';
        foreach ($fields as $key => $label) {
            if (empty($exif[$key])) continue;
            $rows .= '<div class="' . $prefix . '-exif-item" data-exif-key="' . snap_escape_attr($key) . '"><span class="' . $prefix . '-exif-label">' . snap_escape_html($label) . '</span><span class="' . $prefix . '-exif-value">' . snap_escape_html($exif[$key]) . '</span></div>';
        }
        if ($rows !== '') $html .= '<div id="' . $prefix . '-exif-panel" class="' . $prefix . '-exif-panel">' . $rows . '</div>';
        $html .= '<div class="' . $prefix . '-community-wrap">';
        if ($comments !== []) $html .= (string)snap_render_component('comments', ['items' => $comments]);
        $html .= '</div></div><div class="' . $prefix . '-post-ig-actions"><div class="' . $prefix . '-post-ig-action-icons">'
            . '<button type="button" class="' . $prefix . '-action-btn" aria-label="Comment" data-ss-action="scroll-to" data-ss-target=".' . $prefix . '-community-wrap"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></button>'
            . '<button type="button" class="' . $prefix . '-action-btn ' . $prefix . '-action-bookmark" aria-label="Save"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg></button></div>';
        if (!empty($item['img_date'])) $html .= '<p class="' . $prefix . '-post-ig-date">' . snap_escape_html($item['img_date']) . '</p>';
        $html .= '</div></div></article>';
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
        $lightboxSource = trim((string)($data['attributes']['data-lightbox-src'] ?? ''));
        $html = '<img' . ($class !== '' ? ' class="' . snap_escape_attr($class) . '"' : '') . ' src="' . snap_escape_url($data['url'] ?? '') . '" alt="'
            . snap_escape_attr($data['alt'] ?? '') . '"' . ($lightboxSource !== '' ? ' data-lightbox-src="' . snap_escape_url($lightboxSource) . '"' : '') . ' loading="lazy">';
    } elseif ($name === 'comments') {
        $html = '<ol class="snap-comments">';
        foreach (($data['items'] ?? []) as $comment) {
            if (!is_array($comment)) continue;
            $html .= '<li><strong>' . snap_escape_html($comment['author'] ?? $comment['comment_author'] ?? '') . '</strong><p>'
                . nl2br(snap_escape_html($comment['text'] ?? $comment['comment_text'] ?? ''), false) . '</p></li>';
        }
        $html .= '</ol>';
    } elseif ($name === 'footer') {
        $footer = is_array($data['footer'] ?? null) ? $data['footer'] : [];
        $parts = [];
        foreach (($footer['slots'] ?? []) as $slot) {
            if (!is_array($slot)) continue;
            switch ((string)($slot['kind'] ?? '')) {
                case 'copyright':
                    $parts[] = '&copy; ' . snap_escape_html($slot['year'] ?? '') . ' <a class="p-name u-url footer-link" href="' . snap_route_url('home') . '">' . snap_escape_html($slot['site_name'] ?? '') . '</a>';
                    break;
                case 'email':
                    $email = trim((string)($slot['email'] ?? ''));
                    if (filter_var($email, FILTER_VALIDATE_EMAIL)) $parts[] = 'EMAIL: <a class="footer-link" href="mailto:' . snap_escape_attr($email) . '">' . snap_escape_html($email) . '</a>';
                    break;
                case 'theme': $parts[] = 'THEME: ' . snap_escape_html($slot['name'] ?? ''); break;
                case 'powered':
                    $parts[] = 'POWERED BY <a class="footer-link" href="https://snapsmack.ca" target="_blank" rel="nofollow noopener">SNAPSMACK</a> ' . snap_escape_html($slot['version'] ?? '');
                    break;
                case 'link':
                    $parts[] = '<a class="footer-link" href="' . snap_escape_url($slot['url'] ?? '') . '">' . snap_escape_html($slot['label'] ?? '') . '</a>';
                    break;
                case 'text': $parts[] = snap_escape_html($slot['text'] ?? ''); break;
            }
        }
        $html = '<div class="footer-metadata-bar"><p>' . implode(' <span class="sep">|</span> ', $parts) . '</p></div>';
    }
    return SnapTrustedHtml::__snapsmackCmsOnly($html);
}
// ===== SNAPSMACK EOF =====
