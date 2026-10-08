<?php
declare(strict_types=1);

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

require_once __DIR__ . '/public-repository.php';

/** Turn ambient request input into a small, typed route request. */
function snapsmack_public_parse_request(array $input): array
{
    $route = is_string($input['route'] ?? null) ? strtolower(trim($input['route'])) : 'landing';
    $allowed = ['landing', 'resolve', 'photo', 'post', 'archive', 'page', 'blogroll', 'search', 'hashtag', 'albums', 'collections', 'collection'];
    if (!in_array($route, $allowed, true)) $route = 'not_found';
    $slug = is_string($input['slug'] ?? null) ? trim($input['slug']) : '';
    if ($slug !== '' && !preg_match('/^[a-z0-9][a-z0-9_-]{0,199}$/i', $slug)) $slug = '';
    $query = is_string($input['query'] ?? null) ? trim($input['query']) : '';
    $query = function_exists('mb_substr') ? mb_substr($query, 0, 200) : substr($query, 0, 200);
    $request = [
        'route' => $route,
        'slug' => $slug,
        'id' => max(0, (int)($input['id'] ?? 0)),
        'page' => max(1, min(100000, (int)($input['page'] ?? 1))),
        'category_id' => max(0, (int)($input['category_id'] ?? 0)),
        'album_id' => max(0, (int)($input['album_id'] ?? 0)),
        'query' => $query,
    ];
    if (array_key_exists('fragment', $input)) $request['fragment'] = !empty($input['fragment']);
    return $request;
}

function snapsmack_heuristic_map(string $raw): array
{
    $map = [];
    foreach (preg_split('/\s*;\s*/', trim($raw)) ?: [] as $rule) {
        if ($rule === '' || !str_contains($rule, '=')) continue;
        [$key,$value] = array_map('trim', explode('=', $rule, 2));
        $parts = array_map('trim', explode('|', $value));
        $key = strtolower(substr(preg_replace('/[^a-zA-Z0-9:_-]/', '', $key), 0, 80));
        $code = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $parts[0] ?? ''), 0, 3));
        if ($key === '' || $code === '') continue;
        $map[$key] = ['code'=>$code,'label'=>substr($parts[1] ?? 'HEURISTIC ANALYSIS',0,48),
            'colour'=>in_array($parts[2] ?? '', ['calm','fault','blue','violet'], true) ? $parts[2] : 'calm',
            'counter'=>strtolower(substr($parts[3] ?? 'total',0,24))];
    }
    return $map;
}

function snapsmack_heuristic_items(array $items, string $raw): array
{
    $map = snapsmack_heuristic_map($raw); $total = count($items);
    foreach ($items as $index => $item) {
        $rule = $map['post:' . (int)($item['post_id'] ?? 0)] ?? $map[strtolower((string)($item['img_slug'] ?? ''))] ?? null;
        if ($rule === null) { $items[$index]['heuristic'] = []; continue; }
        $counter = $rule['counter'];
        $value = $counter === 'total' ? (string)$total : ($counter === 'ordinal' ? (string)($index + 1) : ($counter === 'post' ? (string)(int)($item['post_id'] ?? 0) : ($counter === 'none' ? '' : substr($counter,0,12))));
        $items[$index]['heuristic'] = ['code'=>$rule['code'],'label'=>$rule['label'],'colour'=>$rule['colour'],'value'=>$value,'post'=>(int)($item['post_id'] ?? 0)];
    }
    return $items;
}

function snapsmack_game_on_focus_item(array $item): array
{
    $item['img_focus_x'] = max(0, min(100, is_numeric($item['img_focus_x'] ?? null) ? (float)$item['img_focus_x'] : 50));
    $item['img_focus_y'] = max(0, min(100, is_numeric($item['img_focus_y'] ?? null) ? (float)$item['img_focus_y'] : 50));
    $item['img_zoom'] = max(100, min(500, is_numeric($item['img_zoom'] ?? null) ? (float)$item['img_zoom'] : 100));
    return $item;
}

/** Format stored photograph metadata once, outside every presentation skin. */
function snapsmack_photo_technical_details(array $item): array
{
    $raw = json_decode((string)($item['img_exif'] ?? ''), true);
    if (!is_array($raw) || $raw === []) return [];
    require_once __DIR__ . '/fix-exif.php';
    $formatted = get_smack_exif($raw);
    if (!is_array($formatted)) return [];
    $labels = [
        'Camera' => $formatted['camera'] ?? '',
        'Lens' => $formatted['lens'] ?? '',
        'Aperture' => $formatted['aperture'] ?? '',
        'Shutter' => $formatted['shutter'] ?? '',
        'ISO' => $formatted['iso'] ?? '',
        'Focal Length' => $formatted['focal'] ?? '',
        'Film' => $formatted['film'] ?? '',
        'Flash' => $formatted['flash'] ?? '',
    ];
    return array_filter($labels, static fn($value): bool => $value !== '' && $value !== 'N/A' && $value !== 'f/0' && $value !== '0mm');
}

/** Complete bounded detail model shared by traditional single-photo skins. */
function snapsmack_photo_response(SnapPublicRepository $repository, array $item, array $navigation): array
{
    $previous = $repository->adjacentPhotograph((int)$item['id'], false);
    $next = $repository->adjacentPhotograph((int)$item['id'], true);
    $first = $repository->photographBoundary(false);
    $last = $repository->photographBoundary(true);
    foreach ([$previous, $next, $first, $last] as &$destination) {
        if (is_array($destination) && !empty($destination['img_slug'])) {
            $destination['url'] = snap_route_url('photo', ['slug' => $destination['img_slug']]);
        }
    }
    unset($destination);
    $item['technical'] = snapsmack_photo_technical_details($item);
    $item['albums'] = array_map(static function (array $album): array {
        $album['url'] = snap_route_url('archive', ['album' => (int)$album['id']]);
        return $album;
    }, $repository->photographAlbums((int)$item['id']));
    $item['tags'] = array_map(static function (array $tag): array {
        $tag['url'] = snap_route_url('hashtag', ['slug' => (string)$tag['slug']]);
        return $tag;
    }, $repository->photographTags((int)$item['id']));
    return [
        'status' => 200, 'kind' => 'photo', 'item' => $item,
        'comments' => $repository->approvedComments((int)$item['id'], null),
        'comments_enabled' => !empty($item['allow_comments']),
        'navigation' => $navigation, 'previous' => $previous, 'next' => $next,
        'first' => $first, 'last' => $last,
    ];
}

function snapsmack_game_on_photo_response(SnapPublicRepository $repository, array $item, array $settings, array $navigation, int $perPage, bool $fragment): array
{
    $previous = $repository->adjacentPhotograph((int)$item['id'], false);
    $next = $repository->adjacentPhotograph((int)$item['id'], true);
    $first = $repository->photographBoundary(false);
    $last = $repository->photographBoundary(true);
    foreach ([$previous, $next, $first, $last] as &$destination) {
        if (is_array($destination) && !empty($destination['img_slug'])) $destination['url'] = snap_route_url('photo', ['slug' => $destination['img_slug']]);
    }
    unset($destination);
    $response = [
        'status' => 200,
        'kind' => 'photo',
        'item' => snapsmack_game_on_focus_item($item),
        'comments' => $repository->approvedComments((int)$item['id'], null),
        'navigation' => $navigation,
        'fragment' => $fragment,
        'previous' => $previous,
        'next' => $next,
        'first' => $first,
        'last' => $last,
    ];
    if ($fragment) return $response;
    $response['items'] = snapsmack_grid_frame_items($repository->photographLanding($perPage, 0), $settings, 'game-on');
    $response['puzzle_items'] = array_map('snapsmack_game_on_focus_item', $repository->gameOnPuzzlePhotographs());
    $response['photo_count'] = $repository->publishedPhotographCount();
    $response['autoopen'] = true;
    return $response;
}

function snapsmack_instant_camera_photo_response(SnapPublicRepository $repository, array $item, array $settings, array $navigation, int $perPage, bool $fragment): array
{
    $previous = $repository->adjacentPhotograph((int)$item['id'], false);
    $next = $repository->adjacentPhotograph((int)$item['id'], true);
    $first = $repository->photographBoundary(false);
    $last = $repository->photographBoundary(true);
    foreach ([$previous, $next, $first, $last] as &$destination) {
        if (is_array($destination) && !empty($destination['img_slug'])) $destination['url'] = snap_route_url('photo', ['slug' => $destination['img_slug']]);
    }
    unset($destination);
    $response = [
        'status' => 200,
        'kind' => 'photo',
        'item' => snapsmack_game_on_focus_item($item),
        'comments' => $repository->approvedComments((int)$item['id'], null),
        'navigation' => $navigation,
        'fragment' => $fragment,
        'previous' => $previous,
        'next' => $next,
        'first' => $first,
        'last' => $last,
    ];
    if ($fragment) return $response;
    $response['items'] = snapsmack_grid_frame_items($repository->photographLanding($perPage, 0), $settings, 'instant-camera');
    $response['photo_count'] = $repository->publishedPhotographCount();
    $response['autoopen'] = true;
    return $response;
}

function snapsmack_grid_modal_photo_response(SnapPublicRepository $repository, array $item, array $settings, array $navigation, int $perPage, bool $fragment, string $skin): array
{
    $previous = $repository->adjacentPhotograph((int)$item['id'], false);
    $next = $repository->adjacentPhotograph((int)$item['id'], true);
    $first = $repository->photographBoundary(false);
    $last = $repository->photographBoundary(true);
    foreach ([$previous, $next, $first, $last] as &$destination) {
        if (is_array($destination) && !empty($destination['img_slug'])) $destination['url'] = snap_route_url('photo', ['slug' => $destination['img_slug']]);
    }
    unset($destination);
    $response = [
        'status' => 200,
        'kind' => 'photo',
        'item' => snapsmack_game_on_focus_item($item),
        'comments' => $repository->approvedComments((int)$item['id'], null),
        'navigation' => $navigation,
        'fragment' => $fragment,
        'previous' => $previous,
        'next' => $next,
        'first' => $first,
        'last' => $last,
    ];
    $postId = (int)($item['post_id'] ?? 0);
    if ($postId > 0) {
        $post = $repository->postById($postId);
        $photographs = $repository->photographsForPost($postId);
        if ($post !== null && $photographs !== []) {
            $response['item'] = array_merge($response['item'], $post);
            $response['photographs'] = snapsmack_grid_frame_items($photographs, $settings, $skin);
            $response['comments'] = $repository->approvedComments(null, $postId);
        }
    }
    if ($fragment) return $response;
    $items = snapsmack_grid_landing_items($repository, $settings, $skin, $perPage, 0);
    if ($skin === 'heuristic') $items = snapsmack_heuristic_items($items, (string)($settings['he_infomatic_map'] ?? ''));
    $response['items'] = $items;
    $response['photo_count'] = $repository->publishedPostCount();
    $response['autoopen'] = true;
    return $response;
}

/** Preserve the original one-cover-per-post GRAM feed outside the skin. */
function snapsmack_trigram_landing_provider(array $items, ?string $publicRoot = null): array
{
    require_once __DIR__ . '/trigram.php';
    if (function_exists('trigram_align_backfill')) $items = trigram_align_backfill($items);
    $publicRoot = $publicRoot ?? dirname(__DIR__);
    $provided = [];
    $column = 0;
    foreach ($items as $item) {
        $slot = (int)($item['trigram_slot'] ?? 0);
        $orientation = (string)($item['trigram_orientation'] ?? 'h');
        if ($slot === 1 && $orientation !== 'v' && $column !== 0) {
            for ($missing = 3 - $column; $missing > 0; $missing--) {
                $provided[] = ['is_phantom' => true, 'presentation_class' => 'tg-tile tg-tile--phantom'];
                $column = ($column + 1) % 3;
            }
        }
        if ((int)($item['trigram_id'] ?? 0) > 0 && $slot > 0) {
            $labels = $orientation === 'v' ? [1=>'T',2=>'M',3=>'B'] : [1=>'L',2=>'M',3=>'R'];
            $label = $labels[$slot] ?? '';
            $relative = $label === '' ? '' : 'trigrams/trigram-' . (int)$item['trigram_id'] . '-' . $label . '.jpg';
            if ($relative !== '' && is_file(rtrim($publicRoot, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative))) {
                $item['trigram_slice_path'] = $relative;
                $item['is_trigram_slice'] = true;
            }
        }
        $provided[] = $item;
        $column = ($column + 1) % 3;
    }
    return $provided;
}

function snapsmack_grid_landing_items(SnapPublicRepository $repository, array $settings, string $skin, int $limit, int $offset = 0): array
{
    $gridFamily = ['the-grid','aurora','sudden-impact','parade','jive-turkey','heuristic','sliders'];
    $items = in_array($skin, $gridFamily, true)
        ? $repository->carouselPostLanding($limit, $offset)
        : $repository->photographLanding($limit, $offset);
    if (in_array($skin, $gridFamily, true)) $items = snapsmack_trigram_landing_provider($items);
    $items = snapsmack_grid_frame_items($items, $settings, $skin);
    $prefixes = ['the-grid'=>'tg','aurora'=>'au','sudden-impact'=>'tg','parade'=>'pa','jive-turkey'=>'jt','heuristic'=>'he','sliders'=>'tg'];
    $prefix = $prefixes[$skin] ?? 'tg';
    foreach ($items as &$item) {
        if (!empty($item['is_phantom'])) continue;
        $classes = $prefix . '-tile';
        if (!empty($item['is_framed'])) {
            $classes .= ' ' . $prefix . '-tile--framed';
            if (!empty($item['is_portrait'])) $classes .= ' ' . $prefix . '-tile--portrait';
        }
        $slot = (int)($item['trigram_slot'] ?? 0);
        if ($slot > 0) {
            $labels = (($item['trigram_orientation'] ?? 'h') === 'v') ? [1=>'T',2=>'M',3=>'B'] : [1=>'L',2=>'M',3=>'R'];
            $classes .= ' ' . $prefix . '-tile--trigram ' . $prefix . '-tile--trigram-' . ($labels[$slot] ?? 'M');
        }
        $item['presentation_class'] = $classes;
        $item['presentation_image'] = !empty($item['trigram_slice_path'])
            ? (string)$item['trigram_slice_path']
            : (!empty($item['is_framed'])
            ? (string)($item['img_thumb_aspect'] ?? $item['img_file'] ?? '')
            : (string)($item['img_thumb_square'] ?? $item['img_file'] ?? ''));
        $item['presentation_title'] = (string)($item['title'] ?? $item['img_title'] ?? '');
        $item['is_carousel'] = (int)($item['image_count'] ?? 0) > 1;
    }
    unset($item);
    return $items;
}

/** Pack a complete photograph inventory into bounded justified-layout pages. */
function snapsmack_justified_rows(array $items, array $settings, int $page): array
{
    $targetHeight = max(80, min(600, (int)($settings['justified_row_height'] ?? 240)));
    $canvasWidth = max(320, min(3000, (int)($settings['main_canvas_width'] ?? 1400)));
    $gap = 4;
    $rows = [];
    $row = [];
    $scaledWidth = 0.0;
    foreach ($items as $item) {
        $width = max(1, (int)($item['img_width'] ?? 400));
        $height = max(1, (int)($item['img_height'] ?? 400));
        $item['presentation_aspect'] = $width / $height;
        $item['presentation_flex'] = (int)round($item['presentation_aspect'] * 100);
        $row[] = $item;
        $scaledWidth += ($item['presentation_aspect'] * $targetHeight) + $gap;
        if ($scaledWidth - $gap >= $canvasWidth) {
            $rows[] = ['items' => $row, 'full' => true];
            $row = [];
            $scaledWidth = 0.0;
        }
    }
    if ($row) $rows[] = ['items' => $row, 'full' => false];
    $perPage = 25;
    return [
        'rows' => array_slice($rows, (max(1, $page) - 1) * $perPage, $perPage),
        'total_pages' => max(1, (int)ceil(count($rows) / $perPage)),
        'target_height' => $targetHeight,
        'gap' => $gap,
    ];
}

function snapsmack_grid_frame_items(array $items, array $settings, string $skin): array
{
    $prefixes = ['the-grid'=>'tg','aurora'=>'au','sudden-impact'=>'tg','parade'=>'pa','jive-turkey'=>'jt','heuristic'=>'he','instant-camera'=>'ic','game-on'=>'go','sliders'=>'ic'];
    $prefix = $prefixes[$skin] ?? ''; if ($prefix === '') return $items;
    $level = (string)($settings[$prefix.'_customize_level'] ?? 'per_grid');
    $shadowMap = ['0'=>'none','1'=>'3px 3px 8px rgba(0,0,0,.20)','2'=>'6px 6px 18px rgba(0,0,0,.40)','3'=>'12px 12px 32px rgba(0,0,0,.60)'];
    foreach ($items as $index => $item) {
        if (!empty($item['is_phantom'])) continue;
        $hasImageTreatment = (int)($item['img_size_pct'] ?? 100) < 100
            || (int)($item['img_border_px'] ?? 0) > 0
            || (int)($item['img_shadow'] ?? 0) > 0;
        $source = ($level === 'per_image' || $hasImageTreatment) ? 'img_' : ($level === 'per_carousel' ? 'post_' : '');
        if ($source === '') {
            $size=max(1,min(100,(int)($settings[$prefix.'_frame_size_pct']??100)));
            $border=max(0,min(100,(int)($settings[$prefix.'_frame_border_px']??0)));
            $color=(string)($settings[$prefix.'_frame_border_color']??'#000000');
            $bg=(string)($settings[$prefix.'_frame_bg_color']??'#ffffff');
            $shadow=$shadowMap[(string)($settings[$prefix.'_frame_shadow']??'0')]??'none';
        } else {
            $size=max(1,min(100,(int)($item[$source.($source==='img_'?'size_pct':'img_size_pct')]??100)));
            $border=max(0,min(100,(int)($item[$source.'border_px']??0)));
            $color=(string)($item[$source.'border_color']??'#000000');
            $bg=(string)($item[$source.'bg_color']??'#ffffff');
            $shadow=$shadowMap[(string)($item[$source.'shadow']??'0')]??'none';
        }
        if(!preg_match('/^#[0-9a-f]{6}$/i',$color))$color='#000000';
        if(!preg_match('/^#[0-9a-f]{6}$/i',$bg))$bg='#ffffff';
        $items[$index]['frame_style']="--tile-img-size:{$size}%;--tile-border-w:{$border}px;--tile-border-c:{$color};--tile-bg:{$bg};--tile-shadow:{$shadow};";
        $items[$index]['is_framed']=empty($item['is_trigram_slice'])&&($size<100||$border>0||$shadow!=='none');
        $orientation = array_key_exists('img_orientation', $item)
            ? filter_var($item['img_orientation'], FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE)
            : null;
        $items[$index]['is_portrait'] = $orientation !== null
            ? $orientation === 1
            : (int)($item['img_height']??0)>(int)($item['img_width']??0);
        if ($skin === 'aurora') {
            $items[$index]['aurora_wave_row'] = intdiv((int)$index, 3);
            $items[$index]['aurora_wave_column'] = (int)$index % 3;
        }
    }
    return $items;
}

/**
 * CMS-owned public routing and response modelling. It never emits headers or
 * markup and never reads globals; the entry point applies the returned status.
 */
function snapsmack_public_controller(SnapPublicRepository $repository, array $request, array $settings = []): array
{
    $route = (string)($request['route'] ?? 'not_found');
    $slug = (string)($request['slug'] ?? '');
    $id = max(0, (int)($request['id'] ?? 0));
    $page = max(1, (int)($request['page'] ?? 1));
    $fragment = !empty($request['fragment']);
    $perPageCap = 100;
    $skin = (string)($settings['active_skin'] ?? '');
    $pageSizeKey = ['onyx'=>'onyx_wall_page_size', 'scroll'=>'scroll_page_size',
        'show-n-tell'=>'htbs_grid_per_page'][$skin] ?? 'posts_per_page';
    $perPage = max(1, min($perPageCap, (int)($settings[$pageSizeKey] ?? 24)));
    $offset = ($page - 1) * $perPage;
    $navigation = [['label' => 'Home', 'url' => snap_route_url('home')]];
    foreach ($repository->activePages() as $pageItem) {
        $navigation[] = [
            'label' => (string)($pageItem['title'] ?? ''),
            'url' => snap_route_url('page', ['slug' => (string)($pageItem['slug'] ?? '')]),
        ];
    }

    if ($route === 'resolve') {
        $item = $slug !== '' ? $repository->photographBySlug($slug) : null;
        $gridModalSkins = ['the-grid', 'aurora', 'sudden-impact', 'parade', 'jive-turkey', 'heuristic', 'sliders'];
        if ($item !== null) return $skin === 'game-on'
            ? snapsmack_game_on_photo_response($repository, $item, $settings, $navigation, $perPage, $fragment)
            : ($skin === 'instant-camera'
            ? snapsmack_instant_camera_photo_response($repository, $item, $settings, $navigation, $perPage, $fragment)
            : (in_array($skin, $gridModalSkins, true)
            ? snapsmack_grid_modal_photo_response($repository, $item, $settings, $navigation, $perPage, $fragment, $skin)
            : snapsmack_photo_response($repository, $item, $navigation)));
        $pageItem = $slug !== '' ? $repository->activePageBySlug($slug) : null;
        return $pageItem === null
            ? ['status' => 404, 'kind' => 'not_found', 'navigation' => $navigation]
            : ['status' => 200, 'kind' => 'page', 'item' => $pageItem, 'navigation' => $navigation];
    }

    if ($route === 'landing') {
        $mode = (string)($settings['site_mode'] ?? 'photoblog');
        $items = $skin === 'glide'
            ? $repository->randomPhotographs(200)
            : ($skin === 'slickr'
            ? $repository->justifiedPhotographFeed()
            : ($mode === 'smacktalk'
            ? $repository->longformLanding($perPage, $offset)
            : snapsmack_grid_landing_items($repository, $settings, $skin, $perPage, $offset)));
        if ($skin === 'glide' || $skin === 'slickr' || $mode === 'smacktalk') {
            $items = snapsmack_grid_frame_items($items, $settings, $skin);
        }
        $rows = [];
        if ($skin === 'heuristic') $items = snapsmack_heuristic_items($items, (string)($settings['he_infomatic_map'] ?? ''));
        if ($skin === 'glide') {
            $rows = array_fill(0, 9, []);
            foreach ($items as $index => $item) {
                $width = max(1, (int)($item['img_width'] ?? 3));
                $height = max(1, (int)($item['img_height'] ?? 2));
                $item['presentation_aspect'] = $width . '/' . $height;
                $items[$index] = $item;
                $rows[$index % 9][] = $item;
            }
        }
        $justifiedRows = [];
        if ($skin === 'slickr') {
            $justifiedRows = snapsmack_justified_rows($items, $settings, $page);
        }
        $sliderItems = [];
        if ($skin === 'show-n-tell' && (($settings['htbs_slider_enabled'] ?? '1') === '1')) {
            $assetIds = json_decode((string)($settings['htbs_slider_assets'] ?? '[]'), true);
            $sliderMax = max(1, min(30, (int)($settings['htbs_slider_max'] ?? 10)));
            $assets = $repository->publicAssetsByIds(is_array($assetIds) ? array_slice($assetIds, 0, $sliderMax) : []);
            $imageOverlay = (($settings['htbs_overlay_source'] ?? 'global') === 'image');
            foreach ($assets as $asset) {
                $sliderItems[] = [
                    'img_file' => (string)($asset['asset_path'] ?? ''),
                    'img_alt' => (string)($asset['asset_name'] ?? ''),
                    'overlay_name' => $imageOverlay ? (string)($asset['asset_name'] ?? '') : (string)($settings['htbs_overlay_name'] ?? ''),
                    'overlay_tagline' => $imageOverlay ? '' : (string)($settings['htbs_overlay_tagline'] ?? ''),
                ];
            }
        }
        $totalCount = $mode === 'smacktalk' ? 0 : ($mode === 'carousel'
            ? $repository->publishedPostCount() : $repository->publishedPhotographCount());
        // A full batch may have a successor; a short batch is definitively the
        // end. This follows the actual filtered landing query instead of a
        // broader site-wide count that can include posts without usable covers.
        $nextLandingPage = count($items) === $perPage ? $page + 1 : 0;
        return ['status' => 200, 'kind' => 'landing', 'mode' => $mode, 'items' => $items,
            'puzzle_items' => $skin === 'game-on' ? array_map('snapsmack_game_on_focus_item', $repository->gameOnPuzzlePhotographs()) : [],
            'rows' => $skin === 'slickr' ? ($justifiedRows['rows'] ?? []) : $rows,
            'total_pages' => $skin === 'slickr' ? ($justifiedRows['total_pages'] ?? 1) : 1,
            'next_page' => $skin === 'slickr'
                ? ($page < ($justifiedRows['total_pages'] ?? 1) ? $page + 1 : 0)
                : $nextLandingPage,
            'slider_items' => $sliderItems, 'navigation' => $navigation, 'page' => $page,
            'photo_count' => $totalCount];
    }
    if ($route === 'photo') {
        $item = $slug !== '' ? $repository->photographBySlug($slug) : $repository->photographById($id);
        if ($item === null) return ['status' => 404, 'kind' => 'not_found', 'navigation' => $navigation];
        $gridModalSkins = ['the-grid', 'aurora', 'sudden-impact', 'parade', 'jive-turkey', 'heuristic', 'sliders'];
        return $skin === 'game-on'
            ? snapsmack_game_on_photo_response($repository, $item, $settings, $navigation, $perPage, $fragment)
            : ($skin === 'instant-camera'
            ? snapsmack_instant_camera_photo_response($repository, $item, $settings, $navigation, $perPage, $fragment)
            : (in_array($skin, $gridModalSkins, true)
            ? snapsmack_grid_modal_photo_response($repository, $item, $settings, $navigation, $perPage, $fragment, $skin)
            : ['status' => 200, 'kind' => 'photo', 'item' => snapsmack_game_on_focus_item($item),
                'comments' => $repository->approvedComments((int)$item['id'], null), 'navigation' => $navigation]));
    }
    if ($route === 'post') {
        $item = $slug !== '' ? $repository->postBySlug($slug) : $repository->postById($id);
        if ($item === null) return ['status' => 404, 'kind' => 'not_found', 'navigation' => $navigation];
        return ['status' => 200, 'kind' => 'post', 'item' => $item,
            'photographs' => $repository->photographsForPost((int)$item['id']),
            'comments' => $repository->approvedComments(null, (int)$item['id']), 'navigation' => $navigation];
    }
    if ($route === 'archive') {
        $categoryId = max(0, (int)($request['category_id'] ?? 0));
        $albumId = max(0, (int)($request['album_id'] ?? 0));
        $total = $repository->archivePhotographCount($categoryId, $albumId);
        return ['status' => 200, 'kind' => 'archive',
            'items' => $repository->archivePhotographs($perPage, $offset, $categoryId, $albumId),
            'navigation' => $navigation, 'page' => $page, 'per_page' => $perPage,
            'taxonomy' => ['category_id' => $categoryId, 'album_id' => $albumId],
            'total_count' => $total, 'total_pages' => (int)ceil($total / $perPage),
            'has_more' => ($offset + $perPage) < $total,
            'previous_page' => $page > 1 ? $page - 1 : null,
            'next_page' => ($offset + $perPage) < $total ? $page + 1 : null];
    }
    if ($route === 'page') {
        $item = $slug !== '' ? $repository->activePageBySlug($slug) : null;
        return $item === null
            ? ['status' => 404, 'kind' => 'not_found', 'navigation' => $navigation]
            : ['status' => 200, 'kind' => 'page', 'item' => $item, 'navigation' => $navigation];
    }
    if ($route === 'blogroll') {
        if (($settings['blogroll_enabled'] ?? '1') !== '1') {
            return ['status' => 404, 'kind' => 'not_found', 'navigation' => $navigation];
        }
        $groups = [];
        $seen = [];
        foreach ($repository->blogrollPeers() as $row) {
            $url = trim((string)($row['peer_url'] ?? ''));
            if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) continue;
            $key = strtolower(rtrim($url, '/'));
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $category = preg_replace('/^Hub:\s*/i', '', trim((string)($row['cat_name'] ?? '')));
            if ($category === '' || preg_match('/^[a-z0-9.-]+\.[a-z]{2,}(?::\d+)?$/i', $category)) $category = 'THE NETWORK';
            $groups[$category][] = [
                'name' => trim((string)($row['peer_name'] ?? '')) ?: $url,
                'url' => $url,
                'description' => trim((string)($row['peer_desc'] ?? '')),
            ];
        }
        $bounded = [];
        foreach ($groups as $label => $items) $bounded[] = ['label' => $label, 'items' => $items];
        return ['status' => 200, 'kind' => 'blogroll', 'page_title' => 'BLOGROLL',
            'blogroll_groups' => $bounded, 'navigation' => $navigation];
    }
    if ($route === 'search') {
        $query = trim((string)($request['query'] ?? ''));
        $results = $query === '' ? ['photographs' => [], 'posts' => []] : $repository->search($query);
        $searchRows = $skin === 'slickr'
            ? snapsmack_justified_rows($results['photographs'] ?? [], $settings, 1)['rows']
            : [];
        return ['status' => 200, 'kind' => 'search', 'query' => $query,
            'results' => $results, 'rows' => $searchRows, 'navigation' => $navigation];
    }
    if ($route === 'hashtag') {
        $total = $slug === '' ? 0 : $repository->hashtagPhotographCount($slug);
        return ['status' => 200, 'kind' => 'hashtag', 'slug' => $slug,
            'items' => $slug === '' ? [] : $repository->hashtagPhotographs($slug, $perPage, $offset),
            'navigation' => $navigation, 'page' => $page, 'per_page' => $perPage,
            'total_count' => $total, 'total_pages' => (int)ceil($total / $perPage),
            'has_more' => ($offset + $perPage) < $total,
            'previous_page' => $page > 1 ? $page - 1 : null,
            'next_page' => ($offset + $perPage) < $total ? $page + 1 : null];
    }
    if ($route === 'albums') {
        $items = $repository->publicAlbums();
        if ($skin === 'slickr') {
            foreach ($items as &$item) {
                $count = max(0, (int)($item['photograph_count'] ?? 0));
                $item['presentation_id'] = max(0, (int)($item['id'] ?? 0));
                $item['presentation_count'] = number_format($count) . ($count === 1 ? ' photo' : ' photos');
            }
            unset($item);
        }
        return ['status' => 200, 'kind' => 'albums', 'items' => $items, 'navigation' => $navigation];
    }
    if ($route === 'collections') {
        $items = $repository->publicCollections();
        if ($skin === 'slickr') {
            foreach ($items as &$item) {
                $count = max(0, (int)($item['photograph_count'] ?? 0));
                $item['presentation_count'] = number_format($count) . ($count === 1 ? ' image' : ' images');
                $timestamp = strtotime((string)($item['latest_date'] ?? ''));
                $item['presentation_posted'] = $timestamp === false ? '' : 'Posted ' . date('j F Y', $timestamp);
            }
            unset($item);
        }
        return ['status' => 200, 'kind' => 'collections', 'items' => $items, 'navigation' => $navigation];
    }
    if ($route === 'collection') {
        $collections = array_values(array_filter($repository->publicCollections(), static fn(array $row): bool => ($row['slug'] ?? '') === $slug));
        if (!$collections) return ['status' => 404, 'kind' => 'not_found', 'navigation' => $navigation];
        return ['status' => 200, 'kind' => 'collection', 'item' => $collections[0],
            'photographs' => $repository->collectionPhotographs($slug), 'navigation' => $navigation];
    }
    return ['status' => 404, 'kind' => 'not_found', 'navigation' => $navigation];
}
// ===== SNAPSMACK EOF =====
