<?php
// SNAPSMACK_EOF_HEADER: last non-empty line must be // ===== SNAPSMACK EOF =====
declare(strict_types=1);

require_once __DIR__ . '/public-route-aliases.php';

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
/**
 * The four prepared fields every listed photograph carries, written ONCE.
 *
 * thumb_url, thumb_style, orientation_class and photo_url were stamped inline
 * on archive items only. A SEARCH returns its rows under 'results', a map
 * keyed by group, not a flat 'items' -- so a search row had none of them, and
 * Codex had to degrade GLIDE's search tiles to a bare post link.
 *
 * Extracted rather than copied into the search path: the archive gate around
 * the original loop also prepares the archive control row, the canvas width
 * and the route aliases, none of which belong in a search response, so the
 * gate could not simply be widened. A second copy of a fallback chain is a
 * second place for it to drift -- which is the reason photo_url exists at all,
 * after the same expression had been copied into five components.
 *
 * @param array<string,mixed> $row
 * @return array<string,mixed> the row with the four fields set
 */
function snapsmack_view_row_fields(array $row, string $thumbStyle): array
{
    $square = (string)($row['img_thumb_square'] ?? '');
    $aspect = (string)($row['img_thumb_aspect'] ?? '');
    $full   = (string)($row['img_file'] ?? '');
    // Each style states its own preference and falls back to the other
    // derivative before the full-size file, so a site whose square thumbs were
    // never generated still shows a photograph instead of an empty tile.
    $row['thumb_url'] = $thumbStyle === 'square'
        ? ($square !== '' ? $square : ($aspect !== '' ? $aspect : $full))
        : ($aspect !== '' ? $aspect : ($square !== '' ? $square : $full));
    $row['thumb_style'] = $thumbStyle;
    // 1 portrait, 2 square, anything else landscape -- the same orientation
    // classes IMPACT PRINTER's component already emits, so a layout gets the
    // class rather than the integer.
    $orient = (int)($row['img_orientation'] ?? 0);
    $row['orientation_class'] = $orient === 1
        ? 'orient-portrait'
        : ($orient === 2 ? 'orient-square' : 'orient-landscape');
    // THE LINK TO THE PHOTOGRAPH, PREPARED ONCE. Every archive component in
    // core already wrote $item['url'] ?? snap_route_url('photo', ...) -- the
    // same expression copied into 50 SHADES, CHAPLIN, IMPACT PRINTER, ONYX and
    // the grid fragment.
    $slug = (string)($row['img_slug'] ?? '');
    $row['photo_url'] = (string)($row['url'] ?? '') !== ''
        ? (string)$row['url']
        : ($slug !== '' ? snap_route_url('photo', ['slug' => $slug]) : '');
    return $row;
}

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
        'comments', 'navigation', 'results', 'query', 'search_term', 'search_posts', 'slug', 'page', 'total_pages',
        'page_title', 'rendered_content', 'signature', 'previous', 'next', 'categories',
        'albums', 'categories_label', 'albums_label', 'author', 'colophon', 'published_post_count', 'photo_count', 'photo_count_display', 'comment_count', 'comment_count_display', 'like_count', 'like_count_display', 'word_count', 'comments_enabled',
        'show_titles', 'blogroll_groups', 'taxonomy', 'groups', 'puzzle_items', 'fragment', 'autoopen',
        'first', 'last', 'next_page', 'is_homepage', 'sort', 'sort_options', 'album', 'filmstrip', 'community', 'mosaic_blocks', 'about_url', 'archive_controls',
        // THE ARCHIVE BUILDS SEVEN PAGING VALUES AND THIS LIST WAS KEEPING
        // FOUR OF THEM FROM THE SKIN.
        //
        // snapsmack_public_response() returns page, per_page, total_count,
        // total_pages, has_more, previous_page and next_page. Only page,
        // total_pages and next_page were allowed through, so a strict skin
        // could render a NEXT link and the page numbers and had no way at all
        // to render a PREVIOUS one -- previous_page was computed, then dropped
        // here, one function later. GLIDE's explicit Previous/Next was blocked
        // on a value the CMS had already worked out.
        //
        // next_page was allowed because endless scroll only ever needed the
        // forward direction: it is what feeds the scroll-wall sentinel. Nothing
        // decided that backwards paging should be impossible; it just never
        // came up, so the other half was never listed.
        //
        // Adding keys to an allowlist cannot change a skin that does not read
        // them, which is why this is safe for the other twenty-nine. Proved
        // rather than asserted: the render snapshot is byte-identical across
        // 175 pages and 25 skins.
        'per_page', 'total_count', 'has_more', 'previous_page',
        // Slice 3. The same two pages as links, with the active filter already
        // in them -- category and album on an archive, the query on a search,
        // the tag on a hashtag page. A skin assembling its own query string
        // would drop the filter the moment anybody paged a filtered archive.
        'previous_url', 'next_url',
        // The resolved archive controls. archive_controls is the rendered row;
        // these three are the same answer as data, for the components that
        // assemble their own row. 50 SHADES already read archive_layouts and
        // the allowlist stripped it, so even once it was assigned the toggle
        // would still not have reached that skin.
        'archive_layouts', 'archive_calendar', 'archive_layout_active'];
    $allowedPresentation = ['site_name', 'tagline', 'site_url', 'base_url', 'language',
        'direction', 'brand_logo', 'owner_name', 'site_description', 'avatar_url', 'skin_slug',
        'skin_style_url', 'base_style_url', 'skin_variant_url', 'skin_custom_style', 'skin_presentation', 'search_dock', 'owner_custom_code', 'registered_assets',
        'favicon_url', 'og_image_url', 'footer', 'slickr_profile', 'crop_circles', 'archive_layout', 'archive_thumb_style', 'archive_grid', 'body_class'];
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
    // A SEARCH ROW GETS THE SAME FOUR FIELDS AS AN ARCHIVE TILE.
    //
    // Codex: "search results lack prepared comment metadata, unlike archive
    // items; I will show only a post link when state is unavailable." The
    // coordinator named photo_url. The same photograph deserves the same
    // prepared answers wherever it is listed, so this stamps the identical
    // four through the identical helper.
    //
    // It is a block of its own rather than an addition to the archive gate
    // below: that gate also prepares the archive control row, the canvas width
    // and the route aliases, and none of those belong in a search response.
    // "Nothing else changes" was the instruction.
    if ($kind === 'search' && is_array($response['results'] ?? null)) {
        $_searchStyle = (string)($presentation['archive_thumb_style'] ?? 'cropped');
        if (!in_array($_searchStyle, ['square', 'cropped'], true)) $_searchStyle = 'cropped';
        foreach ($response['results'] as $_sg => $_srows) {
            if (!is_array($_srows)) continue;
            foreach ($_srows as $_si => $_srow) {
                if (!is_array($_srow)) continue;
                $_srows[$_si] = snapsmack_view_row_fields($_srow, $_searchStyle);
            }
            $response['results'][$_sg] = $_srows;
        }
        unset($_searchStyle, $_sg, $_srows, $_si, $_srow);
    }
    if ($kind === 'archive' || $kind === 'hashtag') {
        // THE THUMB STYLE, RESOLVED ONCE, PER ITEM.
        //
        // Sean, on a deployed build: "cropped natural still not being passed
        // through." THUMB STYLE was reaching two skins out of thirty.
        //
        // 20 of the 30 layouts draw their own thumbs grid and NOT ONE of them
        // reads archive_thumb_style. TRUE GRIT's line 7 hardcodes both halves
        // of the answer -- class="square-grid" and img_thumb_square first --
        // so the setting could not win whatever the owner picked. CHAPLIN and
        // IMPACT PRINTER honour it only because they render through core page
        // components, where executable code is allowed.
        //
        // A strict skin may hold no executable code: the ratchet refuses a
        // function call, BASE_URL or a cast inside a layout, and it is right
        // to. Choosing between two derivatives is a conditional, so a layout
        // CANNOT honour this setting on its own. Core never prepared the
        // answer, so twenty layouts hardcoded one. The hardcoding is the
        // symptom; this absence was the fault.
        //
        // Prepared here, a layout prints thumb_url and carries no logic.
        // Stored values are 'cropped' (img_thumb_aspect, the photograph's own
        // aspect ratio) and 'square' (img_thumb_square, 1:1). 'cropped' is
        // the default in every other reader and in both save handlers, so it
        // is the default here too. SS-1043.
        $_thumbStyle = (string)($presentation['archive_thumb_style'] ?? 'cropped');
        if (!in_array($_thumbStyle, ['square', 'cropped'], true)) $_thumbStyle = 'cropped';
        if (is_array($response['items'] ?? null)) {
            foreach ($response['items'] as $_i => $_it) {
                if (!is_array($_it)) continue;
                $response['items'][$_i] = snapsmack_view_row_fields($_it, $_thumbStyle);
            }
        }
        unset($_thumbStyle, $_i, $_it);
        $_archiveBase = defined('BASE_URL') ? (string)BASE_URL : '/';
        $_archiveAliases = (array)($presentation['route_aliases'] ?? []);
        $_archiveMode = (string)($presentation['site_mode'] ?? '');
        // WHICH OPTION IS THE CONTENT WIDTH. Seven skins call it
        // main_canvas_width; the others each named their own, and there is no
        // convention to infer from -- a pattern match over the option names
        // picks up masonry_border_width and chap_line_1_width just as
        // happily. So the skin nominates it, and main_canvas_width stays the
        // default for the seven that already work.
        //
        // MEASURED in a browser at 1920 on 29 October: with no width to cap
        // it, .archive-filter-inner takes the full window and the docked T/M
        // group falls back to right:40px against the WINDOW. On CHAPLIN that
        // put the group at 1751..1880 while its photographs end at 1760 --
        // 120px of control sticking out past the archive. CHAPLIN's own width
        // control is chap_photo_max_width, and nothing told the CMS that.
        // A nominated option that is not a plausible canvas width is refused
        // rather than passed on: skins keep border widths and line widths
        // under similar names, and a 2px cap on the row would be worse than
        // no cap at all. Same bound the component applies.
        $_archiveOptions = is_array($presentation['skin_presentation']['options'] ?? null)
            ? $presentation['skin_presentation']['options'] : [];
        $_archiveWidth = '';
        // The SKIN nominates it, in its manifest, because core must not
        // learn about an individual skin -- tests/agreed-rules-gate.php
        // refused the first repair for exactly that, and was right to.
        foreach ([
            (string)($presentation['skin_canvas_width_option'] ?? ''),
            'main_canvas_width',
        ] as $_opt) {
            if ($_opt === '') continue;
            $_candidate = trim((string)($_archiveOptions[$_opt] ?? ''));
            if (preg_match('/^\d{3,5}(px)?$/', $_candidate)) { $_archiveWidth = $_candidate; break; }
        }
        $response['archive_controls'] = snap_render_component('archive-filters', [
            'content_width' => $_archiveWidth,
            // Not snap_route_url('archive'): that returns the literal /archive,
            // the unregistered path that sent six sites into a redirect loop.
            'show_all_url'  => snapsmack_legacy_archive_target($_archiveBase, $_archiveAliases, $_archiveMode),
            'query'         => (string)($response['query'] ?? ''),
            'selected'      => (array)($response['active_filters'] ?? []),
            'filters'       => (array)($presentation['crop_circles']['filters'] ?? []),
            'search'        => (array)($presentation['crop_circles']['search'] ?? []),
            // Both resolved upstream by snapsmack_archive_controls(), the one
            // resolver archive.php's own resolution was lifted into. The
            // layouts already carry the address each one switches to.
            'layouts'       => (array)($response['archive_layouts'] ?? []),
            'calendar'      => (array)($response['archive_calendar'] ?? []),
        ]);
        unset($_archiveBase, $_archiveAliases, $_archiveMode, $_archiveOptions, $_archiveWidth, $_opt, $_candidate);
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
    $hasHeadImage = !empty($view['site']['favicon_url']) || !empty($view['site']['og_image_url']);
    if ($hasHeadImage) {
        require_once __DIR__ . '/public-favicon.php';
        $favicon = snapsmack_public_favicon_link((string)($view['site']['favicon_url'] ?? ''));
        $ogImage = snap_escape_url($view['site']['og_image_url'] ?? '');
        ob_start(static function (string $html) use ($favicon, $ogImage): string {
            $headEnd = stripos($html, '</head>');
            if ($headEnd === false) return $html;
            $head = substr($html, 0, $headEnd);
            $markup = '';
            if ($favicon !== '' && !preg_match('~<link\b[^>]*\brel\s*=\s*["\x27](?:shortcut\s+)?icon["\x27]~i', $head)) {
                $markup .= $favicon;
            }
            if ($ogImage !== '' && !preg_match('~<meta\b[^>]*\bproperty\s*=\s*["\x27]og:image["\x27]~i', $head)) {
                $markup .= '<meta property="og:image" content="' . $ogImage . '">';
            }
            return substr_replace($html, $markup, $headEnd, 0);
        });
    }
    try {
        (static function (string $__snapsmack_template, array $view): void {
            include $__snapsmack_template;
        })($candidate, $view);
    } finally {
        if ($hasHeadImage) ob_end_flush();
    }
    return true;
}
// ===== SNAPSMACK EOF =====
