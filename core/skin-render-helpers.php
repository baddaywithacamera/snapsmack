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
        'app' => 'app',
        'game-scores' => 'game-on-scores.php',
    ];
    if (!array_key_exists($route, $routes)) return '';
    $allowed = array_intersect_key($parameters, array_flip(['slug', 'id', 'page', 'query', 'album', 'category']));
    foreach ($allowed as $key => $value) {
        if (!is_scalar($value)) unset($allowed[$key]);
        else $allowed[$key] = (string)$value;
    }
    $base = defined('BASE_URL') ? rtrim((string)BASE_URL, '/') . '/' : '/';
    if (($route === 'page' || $route === 'post' || $route === 'photo') && !empty($allowed['slug'])) {
        return snap_escape_url($base . rawurlencode($allowed['slug']));
    }
    if ($route === 'hashtag' && !empty($allowed['slug'])) {
        $allowed['tag'] = $allowed['slug'];
        unset($allowed['slug']);
        return snap_escape_url($base . '?' . http_build_query($allowed, '', '&', PHP_QUERY_RFC3986));
    }
    $path = $routes[$route];
    return snap_escape_url($base . $path . ($allowed ? '?' . http_build_query($allowed, '', '&', PHP_QUERY_RFC3986) : ''));
}

/**
 * Render a menu tree: list items and links, and nothing else.
 *
 * This deliberately emits NO separator. 0.7.848D added a hard-coded
 * '<span class="sep">|</span>' here to restore the pipe the legacy
 * core/header.php had always drawn, and in doing so put a separator into all
 * twelve skins that call this -- including ALFRED, TELEGRAM and TILEZ, which
 * already draw their own with `.main-menu > li + li:before { content: "/" }`.
 * Their menus then read "HOME | / THE IDEA".
 *
 * A separator between menu items is decoration, and decoration is the skin's.
 * Each skin that wants one now draws it in its own stylesheet, where it also
 * controls the character, colour, size and position -- none of which a
 * hard-coded span in here can offer. A CMS change must not alter presentation
 * in individual skins; that is what happened on 8 October and it reached
 * every site at once.
 */
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
/**
 * The photograph at the top of a static page, as an About page carries.
 *
 * Measured on 25 October 2026: five live sites had a photograph stored on
 * their About page that their skin never drew, because this markup existed
 * only inside the 'static-page' component and twelve skins render their own
 * page markup instead. The photograph was never lost -- nothing put it on the
 * page.
 *
 * It lives here so there is one copy. snap_pages stores image_asset with
 * image_size, image_align and image_shadow beside it; a page row has never
 * had img_file or featured_image_path, and a skin that reaches for those
 * finds nothing however carefully it is written.
 *
 * Returns '' when the page has no photograph, so a caller can print it
 * unconditionally.
 */
function snap_page_hero_html(array $item, string $title = ''): string {
    $hero = trim((string)($item['image_asset'] ?? ''));
    if ($hero === '') return '';
    $base = defined('BASE_URL') ? (string)BASE_URL : '/';
    $size = in_array(($item['image_size'] ?? ''), ['medium', 'small'], true)
        ? (string)$item['image_size'] : 'full';
    $align = in_array(($item['image_align'] ?? ''), ['left', 'right'], true)
        ? (string)$item['image_align'] : 'center';
    $shadow = !empty($item['image_shadow']) ? ' page-hero--shadow' : '';
    if ($title === '') $title = (string)($item['title'] ?? '');
    return '<div id="photobox" class="page-hero page-hero--' . $size
        . ' page-hero--' . $align . $shadow . '">'
        . '<div class="main-photo">'
        . '<img src="' . snap_escape_url(rtrim($base, '/') . '/' . ltrim($hero, '/'))
        . '" class="post-image" alt="' . snap_escape_attr($title) . '">'
        . '</div></div>';
}

function snap_render_component(string $name, array $data): SnapTrustedHtml {
    $html = '';
    if (in_array($name, ['50-shades-page', 'chaplin-page', 'galleria-page', 'impact-printer-page', 'onyx-page', 'rational-geo-page'], true)) {
        $componentFiles = [
            '50-shades-page' => '50-shades-page-component.php',
            'chaplin-page' => 'chaplin-page-component.php',
            'galleria-page' => 'galleria-page-component.php',
            'impact-printer-page' => 'impact-printer-page-component.php',
            'onyx-page' => 'onyx-page-component.php',
            'rational-geo-page' => 'rational-geo-page-component.php',
        ];
        $view = $data;
        ob_start();
        require __DIR__ . '/' . $componentFiles[$name];
        $html = (string)ob_get_clean();
    } elseif ($name === 'grid-photo-fragment') {
        $prefix = preg_replace('/[^a-z]/', '', strtolower((string)($data['prefix'] ?? 'tg')));
        if (!in_array($prefix, ['tg', 'au', 'pa', 'jt', 'he'], true)) $prefix = 'tg';
        $item = is_array($data['item'] ?? null) ? $data['item'] : [];
        $site = is_array($data['site'] ?? null) ? $data['site'] : [];
        $comments = is_array($data['comments'] ?? null) ? $data['comments'] : [];
        $photographs = is_array($data['photographs'] ?? null) ? $data['photographs'] : [];
        $siteName = snap_escape_html($site['site_name'] ?? '');
        $image = (string)($item['img_file'] ?? '');
        $html = '<article class="' . $prefix . '-post-ig"><div class="' . $prefix . '-post-ig-image">';
        if (count($photographs) > 1) {
            $html .= '<div class="' . $prefix . '-carousel-wrap"><div id="' . $prefix . '-carousel" class="ss-slider" data-slider-mode="carousel"><div class="slider-track">';
            foreach ($photographs as $photograph) {
                if (!is_array($photograph) || empty($photograph['img_file'])) continue;
                $classes = 'slider-slide' . (!empty($photograph['is_framed']) ? ' ' . $prefix . '-slide--framed' : '');
                $html .= '<div class="' . $classes . '" data-image-id="' . snap_escape_attr($photograph['id'] ?? '') . '" style="' . snap_escape_attr($photograph['frame_style'] ?? '') . '">'
                    . (string)snap_render_component('image', [
                        'url' => $photograph['img_file'],
                        'alt' => $photograph['img_alt'] ?? $photograph['img_title'] ?? '',
                        'attributes' => ['data-lightbox-src' => $photograph['img_file']],
                    ]) . '</div>';
            }
            $html .= '</div></div><div class="ss-slider-dots" data-slider-indicators>';
            foreach ($photographs as $index => $photograph) {
                $html .= '<button type="button" class="ss-slider-dot' . ($index === 0 ? ' is-active' : '')
                    . '" data-slide-index="' . snap_escape_attr($index) . '" aria-label="'
                    . snap_escape_attr($photograph['img_title'] ?? 'Photograph') . '"></button>';
            }
            $html .= '</div></div>';
        } elseif ($image !== '') {
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
        // The community block is the whole feature, so an empty comment list
        // is no longer a reason to render nothing -- the form lives in it.
        if (snap_community_block() !== null || $comments !== []) {
            $html .= (string)snap_render_component('comments', ['items' => $comments]);
        }
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
    } elseif ($name === 'photogram-grid-cell') {
        $item = is_array($data['item'] ?? null) ? $data['item'] : [];
        $url = (string)($item['url'] ?? '');
        if ($url === '' && !empty($item['img_slug'])) $url = snap_route_url('photo', ['slug' => $item['img_slug']]);
        $image = (string)($item['presentation_image'] ?? $item['img_thumb_aspect'] ?? $item['img_thumb_square'] ?? $item['img_file'] ?? '');
        $title = (string)($item['presentation_title'] ?? $item['img_title'] ?? $item['title'] ?? '');
        $classes = 'pg-grid-cell';
        if (!empty($item['is_phantom'])) $classes .= ' pg-grid-cell--phantom';
        if ((int)($item['trigram_id'] ?? 0) > 0) $classes .= ' pg-grid-cell--trigram';
        if (!empty($item['is_phantom'])) {
            $html = '<span class="' . $classes . '" aria-hidden="true"></span>';
        } else {
            $html = '<a href="' . snap_escape_url($url) . '" class="' . $classes . '" title="' . snap_escape_attr($title) . '" aria-label="' . snap_escape_attr($title) . '">';
            if ($image !== '') $html .= (string)snap_render_component('image', ['url' => $image, 'alt' => $item['img_alt'] ?? $title]);
            $count = (int)($item['image_count'] ?? 0);
            if ($count > 1) $html .= '<span class="pg-carousel-badge" aria-label="' . $count . ' photos">&#x29C9;</span>';
            $html .= '</a>';
        }
    } elseif ($name === 'wall-tile') {
        // One photograph in a wall. The page renderer and the endless-scroll
        // JSON endpoint both build their tiles here, so a tile fetched on
        // scroll is identical to one rendered in the page -- the two cannot
        // drift apart, and a fix to either reaches both.
        $item = is_array($data['item'] ?? null) ? $data['item'] : [];
        $thumb = (string)($item['img_thumb_aspect'] ?? '') !== ''
            ? (string)$item['img_thumb_aspect'] : (string)($item['img_file'] ?? '');
        $href = (string)($item['url'] ?? '');
        if ($href === '') $href = snap_route_url('photo', ['slug' => $item['img_slug'] ?? '']);
        $html = '<a class="ss-masonry-item" href="' . snap_escape_url($href) . '" title="'
            . snap_escape_attr($item['img_title'] ?? '') . '">';
        if ($thumb !== '') {
            $html .= (string)snap_render_component('image', [
                'url' => $thumb,
                'alt' => $item['img_alt'] ?? $item['img_title'] ?? '',
                'attributes' => [
                    'data-w' => max(1, (int)($item['img_width'] ?? 3)),
                    'data-h' => max(1, (int)($item['img_height'] ?? 2)),
                ],
            ]);
        }
        $html .= '</a>';
    } elseif ($name === 'archive-filters') {
        // The archive's own control row: SHOW ALL, the FILTER panel, the
        // search field and the layout toggles. It lived inside archive.php,
        // so when strict skins stopped rendering through that file the whole
        // row disappeared from their archives -- no select and no input on
        // the page at all, while the switch engine stayed loaded with nothing
        // to bind to. Rendering it here, from the bounded filter data the
        // view model already prepares, gives it back to every skin at once
        // rather than to one. It carries no navigation: a skin's header and
        // menus are its own.
        $groups = is_array($data['filters'] ?? null) ? $data['filters'] : [];
        $search = is_array($data['search'] ?? null) ? $data['search'] : [];
        $layouts = is_array($data['layouts'] ?? null) ? $data['layouts'] : [];
        $selected = array_map('strval', (array)($data['selected'] ?? []));
        $count = count($selected);
        $showAll = (string)($data['show_all_url'] ?? '');
        $query = (string)($data['query'] ?? '');
        // The content cap is the site's own, so it arrives as bounded data and
        // becomes a custom property on the row. Without it the docked controls
        // measure from the window and escape the content column.
        // Skin width controls store a bare number of pixels; accept either.
        $width = trim((string)($data['content_width'] ?? ''));
        if (preg_match('/^\d{3,5}$/', $width)) $width .= 'px';
        $width = preg_match('/^\d{3,5}px$/', $width) ? $width : '';
        $html = '<div id="infobox" class="archive-filter-row"'
            . ($width !== '' ? ' style="--archive-content-width:' . snap_escape_attr($width) . '"' : '')
            . '><div class="archive-filter-inner"><div class="nav-links"><div class="center">';
        $html .= '<a href="' . snap_escape_url($showAll) . '" class="'
            . ($count === 0 && $query === '' ? 'active' : 'inactive') . '">[ SHOW ALL ]</a>';
        if ($groups) {
            $html .= '<span class="sep">/</span>'
                . '<div class="filter-group saf-wrap">'
                . '<button id="smack-archive-filter-btn" class="saf-btn'
                . ($count > 0 ? ' saf-btn--active' : '')
                . '" aria-expanded="false" aria-controls="smack-archive-filter-panel" type="button">'
                . '<span class="saf-btn-label">'
                . ($count > 0 ? snap_escape_html($count . ' SELECTED') : 'FILTER')
                . '</span><span class="saf-btn-arrow">▾</span></button>'
                . '<div id="smack-archive-filter-panel" class="saf-panel" role="dialog" aria-label="Filter photos">'
                . '<input type="text" id="smack-archive-filter-search" class="saf-search"'
                . ' placeholder="SEARCH FILTERS…" autocomplete="off" spellcheck="false">';
            foreach ($groups as $group) {
                if (!is_array($group) || empty($group['items']) || !is_array($group['items'])) continue;
                $type = preg_replace('/[^a-z]/', '', strtolower((string)($group['type'] ?? '')));
                if ($type === '') continue;
                $html .= '<div class="saf-group"><div class="saf-group-header">'
                    . snap_escape_html(strtoupper((string)($group['label'] ?? ''))) . '</div>';
                foreach ($group['items'] as $item) {
                    if (!is_array($item)) continue;
                    $value = (string)($item['id'] ?? '');
                    if ($value === '') continue;
                    // data-type is how ss-engine-archive-filter.js knows whether a
                    // ticked box is a category, an album, a collection or an author.
                    // Without it every selection was dropped and applying a filter
                    // navigated to a bare archive, clearing what you had just ticked.
                    $html .= '<label class="saf-item"><input type="checkbox" class="saf-checkbox" data-type="'
                        . snap_escape_attr($type) . '" name="'
                        . snap_escape_attr($type) . '[]" value="' . snap_escape_attr($value) . '"'
                        . (in_array($type . ':' . $value, $selected, true) ? ' checked' : '')
                        . '><span class="saf-label">'
                        . snap_escape_html(strtoupper((string)($item['label'] ?? ''))) . '</span></label>';
                }
                $html .= '</div>';
            }
            $html .= '</div></div>';
        }
        if (!empty($search['action'])) {
            $html .= '<span class="sep">|</span><div class="filter-group">'
                . '<form method="GET" action="' . snap_escape_url($search['action']) . '" class="archive-search-form" role="search">'
                . '<input type="search" name="q" class="archive-search-input" autocomplete="off"'
                . ' aria-label="Search photographs"'
                . ' placeholder="' . snap_escape_attr($search['placeholder'] ?? 'Search or #tag…') . '"'
                . ' value="' . snap_escape_attr($query) . '"></form></div>';
        }
        $html .= '</div></div>';
        if ($layouts) {
            // Docked inside this row, which is why the row is the positioning
            // context: the shipped fallback is right:40px against whatever is
            // positioned above it, and it relied on a runtime JS correction
            // that a skin not loading the toggle engine never receives.
            $html .= '<div class="archive-controls archive-controls--docked" role="group" aria-label="Archive controls">'
                . '<div class="archive-layout-toggle" role="group" aria-label="Layout">';
            foreach ($layouts as $layout) {
                if (!is_array($layout) || empty($layout['url'])) continue;
                $html .= '<a href="' . snap_escape_url($layout['url']) . '" class="alt-btn'
                    . (!empty($layout['active']) ? ' alt-btn--active' : '') . '"'
                    . (!empty($layout['active']) ? ' aria-current="true"' : '')
                    . ' title="' . snap_escape_attr($layout['label'] ?? '') . '">'
                    . snap_escape_html($layout['label'] ?? '') . '</a>';
            }
            $html .= '</div></div>';
        }
        $html .= '</div></div>';
    } elseif ($name === 'document-head') {
        // The one <head>. Twenty skins carried a byte-identical copy of this
        // block and six variants existed across the thirty, so a bug in it --
        // a wrong escape, a missing meta, a stylesheet needing a cache-buster
        // -- was six fixes and thirty skin releases. Skins that need more in
        // their head pass it as 'extra'; nothing else varied between the
        // copies.
        $site = is_array($data['site'] ?? null) ? $data['site'] : [];
        $title = (string)($data['title'] ?? '');
        if ($title === '') $title = (string)($site['site_name'] ?? '');
        $html = '<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . snap_escape_html($title) . '</title>'
            . '<link rel="stylesheet" href="' . snap_escape_url((string)($site['base_style_url'] ?? '')) . '">'
            . '<link rel="stylesheet" href="' . snap_escape_url((string)($site['skin_style_url'] ?? '')) . '">';
        // Owner and skin styles are already trusted, constructed CMS values.
        $html .= snap_render_html($site['skin_custom_style'] ?? '');
        $html .= snap_render_html($site['skin_presentation']['style'] ?? '');
        // A skin that needs more in its head declares it as BOUNDED DATA --
        // urls and name/content pairs the CMS turns into markup here. Raw
        // markup is not an option: snapsmack_trusted_html() is a content
        // sanitiser and strips <link> entirely, so a skin passing a font
        // preconnect as html would have silently got nothing.
        foreach ((array)($data['preconnect'] ?? []) as $url) {
            $safe = snap_escape_url($url);
            if ($safe !== '') $html .= '<link rel="preconnect" href="' . $safe . '" crossorigin>';
        }
        foreach ((array)($data['stylesheets'] ?? []) as $url) {
            $safe = snap_escape_url($url);
            if ($safe !== '') $html .= '<link rel="stylesheet" href="' . $safe . '">';
        }
        foreach ((array)($data['meta'] ?? []) as $name => $content) {
            if (!is_string($name) || $name === '') continue;
            $html .= '<meta name="' . snap_escape_attr($name) . '" content="'
                . snap_escape_attr($content) . '">';
        }
        $html .= '</head>';
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
    } elseif ($name === 'crop-circles') {
        $model = is_array($data['model'] ?? null) ? $data['model'] : [];
        $sprite = (string)($model['icon_sprite_url'] ?? '');
        $icon = static fn(string $name): string => '<svg viewBox="0 0 24 24" aria-hidden="true"><use href="' . snap_escape_url($sprite) . '#' . snap_escape_attr($name) . '"></use></svg>';
        $navItems = is_array($model['navigation'] ?? null) ? $model['navigation'] : [];
        $html = '<nav class="scroll-sticky-nav ss-grid-sticky-nav ' . (!empty($data['solo']) ? 'ss-grid-nav-always-visible scroll-solo-nav' : 'ss-grid-nav-inline-then-sticky') . '" aria-label="Site navigation"' . (empty($data['solo']) ? ' data-grid-nav-observer="scroll-profile"' : '') . '>';
        $html .= '<div class="ss-grid-nav-identity"><a class="ss-grid-nav-name" href="' . snap_escape_url($model['home_url'] ?? snap_route_url('home')) . '">' . snap_escape_html($model['identity'] ?? '') . '</a></div><div class="ss-grid-nav-links">';
        foreach ($navItems as $item) {
            if (!is_array($item) || empty($item['url'])) continue;
            $label = (string)($item['label'] ?? '');
            $probe = strtolower($label);
            $glyph = str_contains($probe, 'home') ? 'home' : (str_contains($probe, 'about') ? 'about' : (str_contains($probe, 'blogroll') ? 'blogroll' : 'link'));
            $html .= '<a class="ss-grid-nav-link" href="' . snap_escape_url($item['url']) . '" title="' . snap_escape_attr($label) . '">' . $icon($glyph) . '<span class="ss-grid-nav-label">' . snap_escape_html($label) . '</span></a>';
        }
        $search = is_array($model['search'] ?? null) ? $model['search'] : [];
        $html .= '<details class="scroll-nav-search"><summary class="ss-grid-nav-link" title="Search">' . $icon('search') . '<span class="ss-grid-nav-label">Search</span></summary><form class="scroll-nav-search-panel" method="get" action="' . snap_escape_url($search['action'] ?? '') . '"><label class="ss-grid-nav-label" for="crop-circles-search">Search photographs</label><input id="crop-circles-search" type="search" name="q" placeholder="' . snap_escape_attr($search['placeholder'] ?? 'Search or #tag…') . '" autocomplete="off"><button type="submit">GO</button></form></details>';
        $groups = is_array($model['filters'] ?? null) ? $model['filters'] : [];
        if ($groups) {
            $html .= '<div class="saf-wrap scroll-nav-filter"><button id="smack-archive-filter-btn" type="button" class="ss-grid-nav-link" aria-expanded="false" aria-controls="smack-archive-filter-panel" title="Filter">' . $icon('filter') . '<span class="ss-grid-nav-label saf-btn-label">Filter</span></button><div id="smack-archive-filter-panel" class="saf-panel" role="dialog" aria-label="Filter photographs"><input type="text" id="smack-archive-filter-search" class="saf-search" placeholder="SEARCH FILTERS…" autocomplete="off" spellcheck="false">';
            foreach ($groups as $group) {
                if (!is_array($group)) continue;
                $html .= '<div class="saf-group"><div class="saf-group-header">' . snap_escape_html(strtoupper((string)($group['label'] ?? ''))) . '</div>';
                foreach (($group['items'] ?? []) as $item) {
                    if (!is_array($item)) continue;
                    $html .= '<label class="saf-item"><input type="checkbox" class="saf-checkbox" data-type="' . snap_escape_attr($group['type'] ?? '') . '" value="' . snap_escape_attr($item['id'] ?? '') . '"><span class="saf-label">' . snap_escape_html(strtoupper((string)($item['label'] ?? ''))) . '</span></label>';
                }
                $html .= '</div>';
            }
            $html .= '</div></div>';
        }
        $appearance = is_array($model['appearance'] ?? null) ? $model['appearance'] : [];
        $dockStyle = '--dock-bg:' . snap_escape_attr($appearance['background'] ?? 'transparent')
            . ';--dock-bg-hover:' . snap_escape_attr($appearance['background_hover'] ?? 'rgba(255,255,255,.95)')
            . ';--dock-border:' . snap_escape_attr($appearance['border'] ?? 'rgba(26,26,26,.3)')
            . ';--dock-border-hover:' . snap_escape_attr($appearance['border_hover'] ?? 'rgba(26,26,26,.7)')
            . ';--dock-icon:' . snap_escape_attr($appearance['icon'] ?? '#1a1a1a')
            . ';--dock-idle-opacity:' . snap_escape_attr($appearance['opacity'] ?? 1);
        $html .= '</div><div class="ss-grid-nav-actions"><div class="social-dock social-dock-inline" data-inline="true" style="' . $dockStyle . '">';
        foreach (($model['social'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $glyph = preg_replace('/[^a-z0-9]/', '', strtolower((string)($item['icon'] ?? 'link')));
            $html .= '<a href="' . snap_escape_url($item['url'] ?? '') . '" target="_blank" rel="me noopener" title="' . snap_escape_attr($item['label'] ?? '') . '" class="dock-link u-url">' . $icon($glyph) . '</a>';
        }
        $html .= '</div></div></nav>';
    } elseif ($name === 'grid-nav-links') {
        // SCROLL and the other wall skins style a row of round icon buttons
        // (.ss-grid-nav-link + svg). The pre-migration component that emitted
        // them reads globals and was never wired into the strict component
        // system, so the strict templates fell back to the generic list
        // component and the icon row rendered as a bulleted <ul>.
        // The markup is fixed here; the skin supplies only bounded link data.
        $icons = [
            'home'      => '<path d="M3 11.5 12 4l9 7.5v8a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z" fill="none" stroke="currentColor" stroke-width="1.8"/>',
            'archive'   => '<path d="M4 7h16v13H4zM3 4h18v3H3zm6 7h6" fill="none" stroke="currentColor" stroke-width="1.8"/>',
            'people'    => '<circle cx="9" cy="8" r="3" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M3 20c0-4 2-6 6-6s6 2 6 6M16 5a3 3 0 0 1 0 6m1 3c2.7.3 4 2.3 4 6" fill="none" stroke="currentColor" stroke-width="1.8"/>',
        ];
        $fallback = '<circle cx="12" cy="12" r="7" fill="none" stroke="currentColor" stroke-width="1.8"/>';
        $html = '<div class="ss-grid-nav-links">';
        foreach (($data['items'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $label = (string)($item['label'] ?? '');
            $icon = strtolower((string)($item['icon'] ?? ''));
            if ($icon === '') {
                // Derive the icon from the label so an ordinary menu still
                // gets sensible glyphs without the owner configuring anything.
                $probe = strtolower($label);
                if (str_contains($probe, 'home')) $icon = 'home';
                elseif (str_contains($probe, 'blogroll') || str_contains($probe, 'friend')) $icon = 'people';
                elseif (str_contains($probe, 'archive') || str_contains($probe, 'album')) $icon = 'archive';
            }
            $html .= '<a class="ss-grid-nav-link" href="' . snap_escape_url($item['url'] ?? '')
                . '" title="' . snap_escape_attr($label) . '">'
                . '<svg viewBox="0 0 24 24" aria-hidden="true">'
                . ($icons[$icon] ?? $fallback) . '</svg>'
                . '<span class="ss-grid-nav-label">' . snap_escape_html($label) . '</span></a>';
        }
        $html .= '</div>';
    } elseif ($name === 'photo-navigation') {
        $comments = is_array($data['comments'] ?? null) ? $data['comments'] : [];
        $enabled = !array_key_exists('comments_enabled', $data) || !empty($data['comments_enabled']);
        $item = is_array($data['item'] ?? null) ? $data['item'] : [];
        $current = (string)($item['url'] ?? ($item['img_slug'] ?? ''));
        $destination = static fn(mixed $value): array => is_array($value) ? $value : [];
        // rel="prev"/"next" is standard HTML, and it is also how the hotkey
        // engine finds where the arrow keys should go. It used to read a
        // window.SNAP_DATA global that nothing has set since the pre-CMS
        // code, so arrow and spacebar navigation has been dead on every skin
        // for a long time. A link the page already renders cannot go stale
        // the way a parallel global does.
        $link = static function (array $target, string $label, string $title, string $current, string $rel = ''): string {
            $url = (string)($target['url'] ?? '');
            $slug = (string)($target['img_slug'] ?? '');
            if ($url === '' || ($current !== '' && ($url === $current || $slug === $current))) {
                return '<span class="dim">' . snap_escape_html($label) . '</span>';
            }
            return '<a href="' . snap_escape_url($url) . '"'
                . ($rel !== '' ? ' rel="' . snap_escape_attr($rel) . '"' : '')
                . ' title="' . snap_escape_attr($title) . '">' . snap_escape_html($label) . '</a>';
        };
        $html = '<div class="nav-links"><span class="left">'
            . $link($destination($data['previous'] ?? []), '« PREV', 'Previous Entry', $current, 'prev')
            . '<span class="sep">|</span>'
            . $link($destination($data['first'] ?? []), 'FIRST', 'Jump to First Entry', $current)
            . '</span><span class="sep">|</span><span class="center"><a href="#" id="show-details">INFO</a>';
        if ($enabled) $html .= '<span class="sep">|</span><a href="#" id="show-comments">COMMENTS (' . count($comments) . ')</a>';
        $html .= '</span><span class="sep">|</span><span class="right">'
            . $link($destination($data['last'] ?? []), 'LAST', 'Jump to Latest Entry', $current)
            . '<span class="sep">|</span>'
            . $link($destination($data['next'] ?? []), 'NEXT »', 'Next Entry', $current, 'next')
            . '</span></div>';
    } elseif ($name === 'image') {
        $class = trim((string)($data['class'] ?? ''));
        $lightboxSource = trim((string)($data['attributes']['data-lightbox-src'] ?? ''));
        $width = filter_var($data['attributes']['data-w'] ?? null, FILTER_VALIDATE_INT);
        $height = filter_var($data['attributes']['data-h'] ?? null, FILTER_VALIDATE_INT);
        $html = '<img' . ($class !== '' ? ' class="' . snap_escape_attr($class) . '"' : '') . ' src="' . snap_escape_url($data['url'] ?? '') . '" alt="'
            . snap_escape_attr($data['alt'] ?? '') . '"' . ($lightboxSource !== '' ? ' data-lightbox-src="' . snap_escape_url($lightboxSource) . '"' : '')
            . ($width !== false && $width > 0 ? ' data-w="' . $width . '"' : '')
            . ($height !== false && $height > 0 ? ' data-h="' . $height . '"' : '')
            . ' loading="lazy">';
    } elseif ($name === 'comments') {
        // The real comment system -- thread, form, guest fields, likes,
        // reactions, moderation state, fediverse replies -- is
        // core/community-component.php, rendered on the CMS side of the
        // boundary and read here from snap_community_block().
        //
        // It is read rather than passed as $data on purpose. Every skin
        // already calls this component where its own stylesheet puts
        // comments, so reading it here restores the feature in all 26 of
        // them with no change to any skin -- and therefore no manifest bump,
        // no repackaging, and no per-site skin update. A CMS deploy is the
        // whole delivery. The rails matter (OPAUDIT 025): a fix that needed
        // 26 skin packages installed would have reached almost nobody.
        //
        // The list below is the fallback when there is no community block:
        // comments switched off for the site or the post, or the community
        // migration not yet run. Author and text, which is all it ever was.
        $community = snap_community_block();
        if ($community !== null) return $community;
        $html = '<ol class="snap-comments">';
        foreach (($data['items'] ?? []) as $comment) {
            if (!is_array($comment)) continue;
            $html .= '<li><strong>' . snap_escape_html($comment['author'] ?? $comment['comment_author'] ?? '') . '</strong><p>'
                . nl2br(snap_escape_html($comment['text'] ?? $comment['comment_text'] ?? ''), false) . '</p></li>';
        }
        $html .= '</ol>';
    } elseif ($name === 'archive-thumb') {
        // One thumbnail in a thumbs-view archive. It exists because a skin may
        // not compute: the aspect ratio, the clamp and the portrait test are
        // arithmetic, and the strict boundary keeps arithmetic in the CMS.
        // ONYX's layout was rejected by the security gate for doing exactly
        // that, which is the gate working. The class prefix is the skin's
        // (fsog, pfd), bounded to letters and dashes.
        $item = is_array($data['item'] ?? null) ? $data['item'] : [];
        $prefix = preg_match('/^[a-z][a-z0-9-]{0,15}$/', (string)($data['prefix'] ?? ''))
            ? (string)$data['prefix'] : 'archive';
        $url = snap_escape_url($item['url'] ?? snap_route_url('photo', ['slug' => $item['img_slug'] ?? '']));
        $title = (string)($item['img_title'] ?? $item['title'] ?? $item['name'] ?? '');
        $thumb = (string)($item['img_thumb_aspect'] ?? $item['img_file'] ?? '');
        $w = max(1, (int)($item['img_width'] ?? 1));
        $h = max(1, (int)($item['img_height'] ?? 1));
        $ratio = max(2 / 3, min(3 / 2, $w / $h));
        $html = '<a class="' . $prefix . '-archive-item" href="' . $url
            . '" title="' . snap_escape_attr($title) . '">';
        if ($thumb !== '') {
            $html .= '<div class="' . $prefix . '-thumb' . ($ratio < 1 ? ' ' . $prefix . '-thumb-portrait' : '')
                . '" style="aspect-ratio:' . snap_escape_attr((string)round($ratio, 4)) . '">'
                . (string)snap_render_component('image', [
                    'url' => $thumb, 'alt' => $item['img_alt'] ?? $title])
                . '</div>';
        }
        $html .= '<div class="' . $prefix . '-archive-title">' . snap_escape_html($title) . '</div></a>';
    } elseif ($name === 'static-page') {
        // The markup page.php emitted before this moved into the CMS, tag for
        // tag and class for class. Moving code out of the skins was supposed
        // to change WHERE it lives, not what it produces; my first version
        // invented its own wrapper and classes and every difference cost Sean
        // something real:
        //
        //   static-transmission belongs on <body>. I put it on this element,
        //   and IMPACT PRINTER styles it as a fixed-height scrolling panel --
        //   so the About page got a scrollbar down the middle of the page.
        //   snap_body_class() gives it back to the body where it belongs.
        //
        //   page-hero--full|medium|small and --left|right|center and
        //   --shadow are what float the photograph beside the text. I emitted
        //   a bare "page-hero", so the columns collapsed into one. The runtime
        //   has bounded these three fields all along; I simply never read them.
        //
        //   <div class="description">, not a <main>, and no e-content: the
        //   skins' rules are written against the original.
        $item = is_array($data['item'] ?? null) ? $data['item'] : [];
        $title = (string)($item['title'] ?? $data['page_title'] ?? '');
        $body = $item['content'] ?? $item['description'] ?? $data['rendered_content'] ?? '';
        $hero = trim((string)($item['image_asset'] ?? ''));
        $base = defined('BASE_URL') ? (string)BASE_URL : '/';

        $html = '<div class="static-content">'
            . '<h1 class="static-page-title">' . snap_escape_html($title) . '</h1>';

        $html .= snap_page_hero_html($item, $title);

        $rendered = snap_render_html($body);
        if (trim(strip_tags($rendered)) === '' && !str_contains($rendered, '<img')) {
            $rendered = "<p class='dim'>No content signal found for this sector.</p>";
        }
        $html .= '<div class="description">' . $rendered . '</div></div>';
    } elseif ($name === 'page-hero') {
        // The About page photograph, on its own, for skins that render their
        // own static-page markup instead of calling 'static-page'.
        //
        // Four skins -- AURORA, JIVE TURKEY, INSTANT CAMERA, PARADE -- print a
        // title and a body in their own layout.php and dropped the photograph
        // entirely. They cannot call snap_page_hero_html() themselves: the skin
        // template policy allows eight functions and that is not one of them.
        // They also cannot build the URL, because BASE_URL and string
        // concatenation are both outside the policy, and image_asset is stored
        // relative ('media_assets/about-hero.jpg') -- printed raw it resolves
        // against the current page URL and 404s.
        //
        // So the hero gets a component of its own. One call, allowlisted,
        // and the size/align/shadow bounding stays in the one function that
        // has always done it.
        $item = is_array($data['item'] ?? null) ? $data['item'] : [];
        $html = snap_page_hero_html($item, (string)($data['title'] ?? ''));
    } elseif ($name === 'blogroll') {
        // The markup blogroll.php has always emitted, class for class. The
        // skins already style .blogroll-canvas, .blogroll-category-block,
        // .blogroll-category-heading, .blogroll-grid, .blogroll-peer,
        // -peer-name, -peer-desc and -peer-url, and five manifest controls
        // target those names -- Column Layout, Content Max Width, Column
        // Gutter, Show Peer Description, Show Peer URL. Inventing different
        // names would have left every one of those controls doing nothing,
        // which is exactly how the About page lost its photograph frame.
        // blogroll.php wrapped all of this in <div class="blogroll-canvas">
        // with the heading inside it. I left the canvas out and let each skin
        // supply its own wrapper, so on IMPACT PRINTER the peers ran the full
        // width of the window while the masthead and the menu stayed inside
        // their column. The canvas is what holds it to the content width.
        $groups = is_array($data['groups'] ?? null) ? $data['groups'] : [];
        // The canvas only. blogroll.php also put the <h1> inside it, but
        // twenty-six skins render their own heading before calling this, and
        // emitting one here would give every one of them two. The canvas is
        // the part that was missing and the part that holds the width.
        $html = '<div class="blogroll-canvas">';
        foreach ($groups as $group) {
            if (!is_array($group)) continue;
            $items = is_array($group['items'] ?? null) ? $group['items'] : [];
            $peers = '';
            foreach ($items as $peer) {
                if (!is_array($peer)) continue;
                $url = snap_escape_url($peer['url'] ?? '');
                if ($url === '') continue;
                $peers .= '<div class="blogroll-peer"><div class="blogroll-peer-name">'
                    . '<a href="' . $url . '" target="_blank" rel="noopener noreferrer">'
                    . snap_escape_html($peer['name'] ?? $peer['url'] ?? '') . '</a></div>';
                // Always emitted, empty or not: blogroll.php did, and the
                // skins' spacing is measured from it being there.
                $peers .= '<p class="blogroll-peer-desc">'
                    . snap_escape_html(trim((string)($peer['description'] ?? ''))) . '</p>';
                $peers .= '<a class="blogroll-peer-url dim" href="' . $url
                    . '" target="_blank" rel="noopener noreferrer">'
                    . snap_escape_html($peer['url'] ?? '') . '</a></div>';
            }
            if ($peers === '') continue;
            $label = trim((string)($group['label'] ?? ''));
            $html .= '<div class="blogroll-category-block">';
            if ($label !== '') {
                // blogroll.php uppercased this in PHP. I thought that was
                // presentation leaking into the CMS and stopped doing it --
                // but the job here is to emit what the skins were written
                // against, not to improve it. Restored.
                $html .= '<h2 class="blogroll-category-heading">'
                    . snap_escape_html(strtoupper($label)) . '</h2>';
            }
            $html .= '<div class="blogroll-grid">' . $peers . '</div></div>';
            $rendered = true;
        }
        // blogroll.php's empty state, and the canvas has to be closed.
        if (empty($rendered)) {
            $html .= '<p class="dim">The network is currently offline. No peers found.</p>';
        }
        $html .= '</div>';
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
        // The hidden h-card core/footer.php published. It is how a fediverse or
        // IndieWeb reader learns whose site this is, and every strict skin lost
        // it when the footer stopped being rendered by that file.
        $card = '';
        $name = trim((string)($footer['site_name'] ?? ''));
        if ($name !== '') {
            $card = '<span class="h-card snapsmack-indieweb-properties" hidden aria-hidden="true">'
                . '<data class="p-name" value="' . snap_escape_attr($name) . '"></data>'
                . '<a class="u-url" href="' . snap_escape_url($footer['site_url'] ?? '') . '"></a>';
            $note = trim((string)($footer['site_note'] ?? ''));
            if ($note !== '') $card .= '<data class="p-note" value="' . snap_escape_attr($note) . '"></data>';
            $card .= '</span>';
        }
        $html = $card . '<div class="footer-metadata-bar"><p>' . implode(' <span class="sep">|</span> ', $parts) . '</p></div>';
    }
    return SnapTrustedHtml::__snapsmackCmsOnly($html);
}
// ===== SNAPSMACK EOF =====
