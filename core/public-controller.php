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

function snapsmack_grid_frame_items(array $items, array $settings, string $skin): array
{
    $prefixes = ['the-grid'=>'tg','aurora'=>'au','sudden-impact'=>'tg','parade'=>'pa','jive-turkey'=>'jt','heuristic'=>'he','instant-camera'=>'ic','game-on'=>'go','sliders'=>'ic'];
    $prefix = $prefixes[$skin] ?? ''; if ($prefix === '') return $items;
    $level = (string)($settings[$prefix.'_customize_level'] ?? 'per_grid');
    $shadowMap = ['0'=>'none','1'=>'3px 3px 8px rgba(0,0,0,.20)','2'=>'6px 6px 18px rgba(0,0,0,.40)','3'=>'12px 12px 32px rgba(0,0,0,.60)'];
    foreach ($items as $index => $item) {
        $source = $level === 'per_image' ? 'img_' : ($level === 'per_carousel' ? 'post_' : '');
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
        $items[$index]['is_framed']=$size<100||$border>0||$shadow!=='none';
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
    $perPageCap = !empty($settings['_cms_full_landing']) ? 5000 : 100;
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
        if ($item !== null) return $skin === 'game-on'
            ? snapsmack_game_on_photo_response($repository, $item, $settings, $navigation, $perPage, $fragment)
            : ($skin === 'instant-camera'
            ? snapsmack_instant_camera_photo_response($repository, $item, $settings, $navigation, $perPage, $fragment)
            : ['status' => 200, 'kind' => 'photo', 'item' => snapsmack_game_on_focus_item($item),
                'comments' => $repository->approvedComments((int)$item['id'], null), 'navigation' => $navigation]);
        $pageItem = $slug !== '' ? $repository->activePageBySlug($slug) : null;
        return $pageItem === null
            ? ['status' => 404, 'kind' => 'not_found', 'navigation' => $navigation]
            : ['status' => 200, 'kind' => 'page', 'item' => $pageItem, 'navigation' => $navigation];
    }

    if ($route === 'landing') {
        $mode = (string)($settings['site_mode'] ?? 'photoblog');
        $items = $skin === 'glide'
            ? $repository->randomPhotographs(200)
            : ($mode === 'smacktalk'
            ? $repository->longformLanding($perPage, $offset)
            : $repository->photographLanding($perPage, $offset));
        $items = snapsmack_grid_frame_items($items, $settings, $skin);
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
        return ['status' => 200, 'kind' => 'landing', 'mode' => $mode, 'items' => $items,
            'puzzle_items' => $skin === 'game-on' ? array_map('snapsmack_game_on_focus_item', $repository->gameOnPuzzlePhotographs()) : [],
            'rows' => $rows, 'slider_items' => $sliderItems, 'navigation' => $navigation, 'page' => $page,
            'photo_count' => $mode === 'smacktalk' ? 0 : $repository->publishedPhotographCount()];
    }
    if ($route === 'photo') {
        $item = $slug !== '' ? $repository->photographBySlug($slug) : $repository->photographById($id);
        if ($item === null) return ['status' => 404, 'kind' => 'not_found', 'navigation' => $navigation];
        return $skin === 'game-on'
            ? snapsmack_game_on_photo_response($repository, $item, $settings, $navigation, $perPage, $fragment)
            : ($skin === 'instant-camera'
            ? snapsmack_instant_camera_photo_response($repository, $item, $settings, $navigation, $perPage, $fragment)
            : ['status' => 200, 'kind' => 'photo', 'item' => snapsmack_game_on_focus_item($item),
                'comments' => $repository->approvedComments((int)$item['id'], null), 'navigation' => $navigation]);
    }
    if ($route === 'post') {
        $item = $slug !== '' ? $repository->postBySlug($slug) : $repository->postById($id);
        if ($item === null) return ['status' => 404, 'kind' => 'not_found', 'navigation' => $navigation];
        return ['status' => 200, 'kind' => 'post', 'item' => $item,
            'photographs' => $repository->photographsForPost((int)$item['id']),
            'comments' => $repository->approvedComments(null, (int)$item['id']), 'navigation' => $navigation];
    }
    if ($route === 'archive') {
        $total = $repository->publishedPhotographCount();
        return ['status' => 200, 'kind' => 'archive',
            'items' => $repository->archivePhotographs($perPage, $offset),
            'navigation' => $navigation, 'page' => $page, 'per_page' => $perPage,
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
        return ['status' => 200, 'kind' => 'search', 'query' => $query,
            'results' => $query === '' ? ['photographs' => [], 'posts' => []] : $repository->search($query),
            'navigation' => $navigation];
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
    if ($route === 'albums') return ['status' => 200, 'kind' => 'albums', 'items' => $repository->publicAlbums(), 'navigation' => $navigation];
    if ($route === 'collections') return ['status' => 200, 'kind' => 'collections', 'items' => $repository->publicCollections(), 'navigation' => $navigation];
    if ($route === 'collection') {
        $collections = array_values(array_filter($repository->publicCollections(), static fn(array $row): bool => ($row['slug'] ?? '') === $slug));
        if (!$collections) return ['status' => 404, 'kind' => 'not_found', 'navigation' => $navigation];
        return ['status' => 200, 'kind' => 'collection', 'item' => $collections[0],
            'photographs' => $repository->collectionPhotographs($slug), 'navigation' => $navigation];
    }
    return ['status' => 404, 'kind' => 'not_found', 'navigation' => $navigation];
}
// ===== SNAPSMACK EOF =====
