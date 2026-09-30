<?php
declare(strict_types=1);

require_once __DIR__ . '/public-repository.php';

/** Turn ambient request input into a small, typed route request. */
function snapsmack_public_parse_request(array $input): array
{
    $route = is_string($input['route'] ?? null) ? strtolower(trim($input['route'])) : 'landing';
    $allowed = ['landing', 'resolve', 'photo', 'post', 'archive', 'page', 'search', 'hashtag', 'albums', 'collections', 'collection'];
    if (!in_array($route, $allowed, true)) $route = 'not_found';
    $slug = is_string($input['slug'] ?? null) ? trim($input['slug']) : '';
    if ($slug !== '' && !preg_match('/^[a-z0-9][a-z0-9_-]{0,199}$/i', $slug)) $slug = '';
    $query = is_string($input['query'] ?? null) ? trim($input['query']) : '';
    $query = function_exists('mb_substr') ? mb_substr($query, 0, 200) : substr($query, 0, 200);
    return [
        'route' => $route,
        'slug' => $slug,
        'id' => max(0, (int)($input['id'] ?? 0)),
        'page' => max(1, min(100000, (int)($input['page'] ?? 1))),
        'query' => $query,
    ];
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
    $perPageCap = !empty($settings['_cms_full_landing']) ? 5000 : 100;
    $perPage = max(1, min($perPageCap, (int)($settings['posts_per_page'] ?? 24)));
    $offset = ($page - 1) * $perPage;
    $navigation = $repository->activePages();

    if ($route === 'resolve') {
        $item = $slug !== '' ? $repository->photographBySlug($slug) : null;
        if ($item !== null) return ['status' => 200, 'kind' => 'photo', 'item' => $item,
            'comments' => $repository->approvedComments((int)$item['id'], null), 'navigation' => $navigation];
        $pageItem = $slug !== '' ? $repository->activePageBySlug($slug) : null;
        return $pageItem === null
            ? ['status' => 404, 'kind' => 'not_found', 'navigation' => $navigation]
            : ['status' => 200, 'kind' => 'page', 'item' => $pageItem, 'navigation' => $navigation];
    }

    if ($route === 'landing') {
        $mode = (string)($settings['site_mode'] ?? 'photoblog');
        $skin = (string)($settings['active_skin'] ?? '');
        $items = $skin === 'glide'
            ? $repository->randomPhotographs(200)
            : ($mode === 'smacktalk'
            ? $repository->longformLanding($perPage, $offset)
            : $repository->photographLanding($perPage, $offset));
        $rows = [];
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
        return ['status' => 200, 'kind' => 'landing', 'mode' => $mode, 'items' => $items,
            'rows' => $rows, 'navigation' => $navigation, 'page' => $page,
            'photo_count' => $mode === 'smacktalk' ? 0 : $repository->publishedPhotographCount()];
    }
    if ($route === 'photo') {
        $item = $slug !== '' ? $repository->photographBySlug($slug) : $repository->photographById($id);
        if ($item === null) return ['status' => 404, 'kind' => 'not_found', 'navigation' => $navigation];
        return ['status' => 200, 'kind' => 'photo', 'item' => $item,
            'comments' => $repository->approvedComments((int)$item['id'], null), 'navigation' => $navigation];
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
