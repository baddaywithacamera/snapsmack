<?php
declare(strict_types=1);

const SNAPSMACK_SKIN_VIEW_MODEL_VERSION = 1;

/** Resolve one labelled destination before the bounded view reaches a skin. */
function snapsmack_skin_navigation_url(array $items, string $wantedLabel): string
{
    $wantedLabel = strtolower(trim($wantedLabel));
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $label = strtolower(trim((string)($item['label'] ?? $item['title'] ?? '')));
        if ($label === $wantedLabel && !empty($item['url'])) return (string)$item['url'];
        $children = is_array($item['children'] ?? null) ? $item['children'] : [];
        $found = snapsmack_skin_navigation_url($children, $wantedLabel);
        if ($found !== '') return $found;
    }
    return '';
}

/** Convert a controller response into the only data a strict skin receives. */
function snapsmack_build_skin_view(array $response, array $presentation = []): array
{
    $kind = (string)($response['kind'] ?? '');
    $item = is_array($response['item'] ?? null) ? $response['item'] : [];
    $bodyClasses = ['skin-' . preg_replace('/[^a-z0-9_-]/i', '-', (string)($presentation['skin_slug'] ?? 'default'))];
    if ($kind === 'page' || $kind === 'blogroll') $bodyClasses[] = 'static-transmission';
    if (!empty($response['is_homepage'])) $bodyClasses[] = 'homepage-static';
    if ($kind === 'page' && !empty($item['slug'])) {
        $bodyClasses[] = 'page-slug-' . preg_replace('/[^a-z0-9_-]/i', '-', (string)$item['slug']);
    }
    // Skin stylesheets key archive-only rules off this -- IMPACT PRINTER's
    // site footer is hidden except under .archive-page, and nothing was
    // setting it, so the archive rendered a footer zero pixels tall.
    if ($kind === 'archive' || $kind === 'hashtag') $bodyClasses[] = 'archive-page';
    if ($kind === 'blogroll') $bodyClasses[] = 'is-blogroll';
    if ($kind === 'photo') $bodyClasses[] = 'is-photo-page';
    $presentation['body_class'] = implode(' ', $bodyClasses);
    // Skins display this bounded value; they do not search or interpret the
    // CMS navigation tree themselves.
    if (empty($response['about_url'])) {
        $response['about_url'] = snapsmack_skin_navigation_url(
            is_array($response['navigation'] ?? null) ? $response['navigation'] : [],
            'about'
        );
    }
    // MOSAIC is an audited CMS compositor.  Skins receive only display-ready
    // block data; they never assemble JSON or run layout logic themselves.
    $response['mosaic_blocks'] = [];
    foreach (array_chunk(is_array($response['items'] ?? null) ? $response['items'] : [], 6) as $block) {
        $tiles = [];
        foreach ($block as $tile) {
            if (!is_array($tile)) continue;
            $tiles[] = [
                'src' => (string)($tile['img_thumb_aspect'] ?? $tile['img_file'] ?? ''),
                'full' => (string)($tile['img_file'] ?? $tile['img_thumb_aspect'] ?? ''),
                // Repository rows carry img_slug, while several ordinary skin
                // components can synthesize their own link from it. MOSAIC is
                // JSON-driven and cannot do that after the strict view boundary,
                // so give it the same solo-page fallback here.
                'href' => (string)($tile['url'] ?? (!empty($tile['img_slug'])
                    ? snap_route_url('photo', ['slug' => (string)$tile['img_slug']])
                    : '')),
                'alt' => (string)($tile['img_alt'] ?? $tile['img_title'] ?? ''),
                'id' => (int)($tile['id'] ?? $tile['img_id'] ?? 0),
                'width' => max(1, (int)($tile['presentation_width'] ?? $tile['img_width'] ?? 1)),
                'height' => max(1, (int)($tile['presentation_height'] ?? $tile['img_height'] ?? 1)),
                'focusX' => (float)($tile['img_focus_x'] ?? 50),
                'focusY' => (float)($tile['img_focus_y'] ?? 50),
            ];
        }
        $response['mosaic_blocks'][] = [
            'json' => json_encode($tiles, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
        ];
    }
    $allowedResponse = ['status', 'kind', 'mode', 'item', 'items', 'post', 'posts', 'tiles', 'rows', 'slider_items', 'photographs',
        'comments', 'navigation', 'results', 'query', 'slug', 'page', 'total_pages',
        'page_title', 'rendered_content', 'signature', 'previous', 'next', 'categories',
        'albums', 'categories_label', 'albums_label', 'author', 'colophon', 'published_post_count', 'photo_count', 'photo_count_display', 'comment_count', 'comment_count_display', 'like_count', 'like_count_display', 'word_count', 'comments_enabled',
        'show_titles', 'blogroll_groups', 'taxonomy', 'groups', 'puzzle_items', 'fragment', 'autoopen',
        'first', 'last', 'next_page', 'is_homepage', 'sort', 'sort_options', 'album', 'filmstrip', 'community', 'mosaic_blocks', 'about_url', 'archive_controls'];
    $allowedPresentation = ['site_name', 'tagline', 'site_url', 'base_url', 'language',
        'direction', 'brand_logo', 'owner_name', 'site_description', 'avatar_url', 'skin_slug',
        'skin_style_url', 'base_style_url', 'skin_variant_url', 'skin_custom_style', 'skin_presentation', 'search_dock', 'owner_custom_code', 'registered_assets',
        'favicon_url', 'footer', 'slickr_profile', 'crop_circles', 'archive_layout', 'archive_thumb_style', 'archive_grid', 'body_class'];
    // THE ARCHIVE'S OWN CONTROL ROW -- SHOW ALL, the filter panel, the search
    // field and the layout toggles.
    //
    // It used to live inside archive.php. When strict skins stopped rendering
    // through that file the whole row vanished from their archives: no select
    // and no input on the page, while the switch engine stayed loaded with
    // nothing to bind to. core/skin-render-helpers.php rebuilt it as the
    // 'archive-filters' component "to give it back to every skin at once",
    // and then exactly one skin -- 50 SHADES -- was ever wired to it.
    //
    // It is built HERE, not in a layout, because a strict skin may hold no
    // executable code: the security ratchet blocks a function call, BASE_URL
    // and a cast inside a layout, and it is right to. Measured on Sean's
    // foundtextures.ca, where the row has been missing since the conversion.
    // A skin that wants the row prints this one value and carries no logic;
    // a skin that does not print it is completely unchanged.
    if ($kind === 'archive' || $kind === 'hashtag') {
        $response['archive_controls'] = snap_render_component('archive-filters', [
            'content_width' => (string)($presentation['skin_presentation']['options']['main_canvas_width'] ?? ''),
            // Not snap_route_url('archive'): that returns the literal /archive,
            // the unregistered path that sent six sites into a redirect loop.
            'show_all_url'  => snapsmack_legacy_archive_target(
                defined('BASE_URL') ? (string)BASE_URL : '/',
                (array)($presentation['route_aliases'] ?? []),
                (string)($presentation['site_mode'] ?? '')
            ),
            'query'         => (string)($response['query'] ?? ''),
            'selected'      => (array)($response['active_filters'] ?? []),
            'filters'       => (array)($presentation['crop_circles']['filters'] ?? []),
            'search'        => (array)($presentation['crop_circles']['search'] ?? []),
            'layouts'       => (array)($response['archive_layouts'] ?? []),
        ]);
    }

    // Hand the CMS-rendered community block to the comments component. It
    // travels outside the view because every skin already renders that
    // component in the right place, which makes this a CMS-only change: no
    // skin edit, no manifest bump, no per-site skin update.
    snap_community_block(
        ($response['community'] ?? null) instanceof SnapTrustedHtml ? $response['community'] : null,
        true
    );
    return [
        'model' => 'snapsmack.public',
        'version' => SNAPSMACK_SKIN_VIEW_MODEL_VERSION,
        'response' => array_intersect_key($response, array_flip($allowedResponse)),
        'site' => array_intersect_key($presentation, array_flip($allowedPresentation)),
    ];
}

function snapsmack_validate_skin_view(array $view, ?string &$reason = null): bool
{
    $reason = null;
    if (($view['model'] ?? null) !== 'snapsmack.public'
        || ($view['version'] ?? null) !== SNAPSMACK_SKIN_VIEW_MODEL_VERSION
        || !is_array($view['response'] ?? null)
        || !is_array($view['site'] ?? null)) {
        $reason = 'unsupported or malformed view-model envelope';
        return false;
    }
    $walk = static function ($value) use (&$walk): bool {
        if (is_null($value) || is_scalar($value) || $value instanceof SnapTrustedHtml || $value instanceof SnapOwnerCode) return true;
        if (!is_array($value)) return false;
        foreach ($value as $key => $child) {
            if (!is_int($key) && !is_string($key)) return false;
            if (!$walk($child)) return false;
        }
        return true;
    };
    if (!$walk($view)) {
        $reason = 'view model contains an object, resource, or callable value';
        return false;
    }
    return true;
}

function snapsmack_safe_fallback_view(int $status = 500): array
{
    return snapsmack_build_skin_view(['status' => $status, 'kind' => 'safe_fallback'], []);
}

/**
 * Render a schema-v2 template in a deliberately tiny local scope. The policy
 * grammar rejects access to the internal path variable; only $view is usable.
 */
function snapsmack_render_strict_skin_template(string $skinDir, string $template, array $view): bool
{
    $reason = null;
    if (!snapsmack_validate_skin_view($view, $reason)) return false;
    if (!preg_match('/^[a-zA-Z0-9._-]+\.php$/', $template)) return false;
    $root = realpath($skinDir);
    $candidate = $root === false ? false : realpath($root . DIRECTORY_SEPARATOR . $template);
    if ($root === false || $candidate === false || is_link($candidate)
        || !str_starts_with(str_replace('\\', '/', $candidate), rtrim(str_replace('\\', '/', $root), '/') . '/')) {
        return false;
    }
    // The guard is deliberately established at the audited CMS include point.
    // Strict-controller requests do not load the legacy skin-view bootstrap,
    // so relying on that bootstrap leaves every guarded schema-v2 layout blank.
    if (!defined('SNAPSMACK_SKIN_RENDER')) define('SNAPSMACK_SKIN_RENDER', true);
    (static function (string $__snapsmack_template, array $view): void {
        include $__snapsmack_template;
    })($candidate, $view);
    return true;
}
