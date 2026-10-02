<?php
/**
 * SNAPSMACK - Public SMACKTALK Controller
 *
 * Owns request interpretation, publication policy, data access and derived
 * display data. Skins receive the returned view only; they never receive PDO
 * or inspect request globals themselves.
 */

require_once __DIR__ . '/parser.php';
require_once __DIR__ . '/public-repository.php';

function snapsmack_smacktalk_request(PDO $pdo, array $settings, array $request): array
{
    $base = defined('BASE_URL') ? BASE_URL : '/';
    $view = trim((string)($request['view'] ?? ''));
    $post_slug = trim((string)($request['post_slug'] ?? ''));
    $post_id = max(0, (int)($request['post_id'] ?? 0));
    $requested_slug = trim((string)($request['requested_slug'] ?? ''));

    if ($view === 'blogroll') {
        if (($settings['blogroll_enabled'] ?? '1') !== '1') {
            return ['handled' => true, 'redirect' => $base];
        }
        return ['handled' => true, 'kind' => 'blogroll', 'page_title' => 'BLOGROLL']
            + snapsmack_smacktalk_blogroll($pdo);
    }

    if ($view === 'archive') {
        if (($settings['archive_layout'] ?? 'square') === 'none') {
            return ['handled' => true, 'redirect' => $base];
        }
        return ['handled' => true, 'kind' => 'archive', 'page_title' => 'ARCHIVE',
            'tiles' => snapsmack_smacktalk_archive_tiles($pdo, $base)];
    }

    $candidate_from_path = false;
    if ($post_slug === '' && $post_id === 0 && $requested_slug !== '') {
        $post_slug = $requested_slug;
        $candidate_from_path = true;
    }

    if ($post_slug !== '' || $post_id > 0) {
        $single = snapsmack_smacktalk_single($pdo, $settings, $base, $post_slug, $post_id);
        if ($single !== null) return ['handled' => true, 'kind' => 'single'] + $single;
        if (!$candidate_from_path || trim((string)($request['post_slug'] ?? '')) !== '' || $post_id > 0) {
            return ['handled' => true, 'kind' => 'not_found', 'page_title' => '404 — Not Found'];
        }
        return ['handled' => false];
    }

    if ($requested_slug !== '') return ['handled' => false];
    return ['handled' => true, 'kind' => 'feed']
        + snapsmack_smacktalk_feed($pdo, $settings, $base, max(1, (int)($request['page'] ?? 1)));
}

function snapsmack_smacktalk_blogroll(PDO $pdo): array
{
    try {
        $rows = (new SnapPublicRepository($pdo))->blogrollPeers();
    } catch (Throwable $e) { $rows = []; }

    $groups = [];
    $seen = [];
    foreach ($rows as $row) {
        $url = trim((string)($row['peer_url'] ?? ''));
        if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) continue;
        $key = strtolower(rtrim($url, '/'));
        if (isset($seen[$key])) continue;
        $seen[$key] = true;

        $category = trim((string)($row['cat_name'] ?? ''));
        $category = preg_replace('/^Hub:\s*/i', '', $category);
        if ($category === '' || preg_match('/^[a-z0-9.-]+\.[a-z]{2,}(?::\d+)?$/i', $category)) {
            $category = 'THE NETWORK';
        }
        if (!isset($groups[$category])) $groups[$category] = [];
        $groups[$category][] = [
            'name' => trim((string)($row['peer_name'] ?? '')) ?: $url,
            'url' => $url,
            'description' => trim((string)($row['peer_desc'] ?? '')),
        ];
    }

    $bounded = [];
    foreach ($groups as $label => $items) $bounded[] = ['label' => $label, 'items' => $items];
    return ['blogroll_groups' => $bounded];
}

function snapsmack_smacktalk_archive_tiles(PDO $pdo, string $base): array
{
    try {
        $images = (new SnapPublicRepository($pdo))->archivePhotographs(10000);
    } catch (Throwable $e) { return []; }

    $tiles = [];
    foreach ($images as $image) {
        $full = ltrim((string)($image['img_file'] ?? ''), '/');
        if ($full === '' || !is_file(dirname(__DIR__) . '/' . $full)) continue;
        $thumb = '';
        foreach ([$image['img_thumb_aspect'] ?? '', $image['img_thumb_square'] ?? '', $full] as $candidate) {
            $candidate = ltrim((string)$candidate, '/');
            if ($candidate !== '' && is_file(dirname(__DIR__) . '/' . $candidate)) {
                $thumb = $candidate;
                break;
            }
        }
        if ($thumb === '') continue;
        $tiles[] = ['full' => $base . $full, 'thumb' => $base . $thumb,
            'title' => (string)($image['img_title'] ?? ''),
            'width' => max(1, (int)($image['img_width'] ?? 3)),
            'height' => max(1, (int)($image['img_height'] ?? 2))];
    }
    return $tiles;
}

function snapsmack_smacktalk_single(PDO $pdo, array $settings, string $base, string $slug, int $id): ?array
{
    try {
        $repository = new SnapPublicRepository($pdo);
        $post = $slug !== '' ? $repository->postBySlug($slug, 'longform') : $repository->postById($id, 'longform');
        if ($post && !empty($post['featured_image_id'])) {
            $post['featured_image_path'] = $repository->photographPathById((int)$post['featured_image_id']);
        }
    } catch (Throwable $e) { return null; }
    if (!$post) return null;

    $previous = $next = null;
    $categories = $albums = [];
    $signature = null;
    $author = trim((string)($settings['site_author'] ?? ''));
    try {
        $previous = $repository->adjacentPosts((int)$post['id'], 'longform', false);
        $next = $repository->adjacentPosts((int)$post['id'], 'longform', true);
        foreach (['previous' => &$previous, 'next' => &$next] as &$adjacent) {
            if (is_array($adjacent) && !empty($adjacent['slug'])) {
                $adjacent['url'] = $base . '?post=' . rawurlencode((string)$adjacent['slug']);
            }
        }
        unset($adjacent);
        $stmt = $pdo->prepare("SELECT c.cat_name FROM snap_post_cat_map m JOIN snap_categories c ON c.id=m.cat_id WHERE m.post_id=? ORDER BY c.cat_name");
        $stmt->execute([(int)$post['id']]); $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $stmt = $pdo->prepare("SELECT a.album_name FROM snap_post_album_map m JOIN snap_albums a ON a.id=m.album_id WHERE m.post_id=? ORDER BY a.album_name");
        $stmt->execute([(int)$post['id']]); $albums = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($post['user_id'])) {
            $stmt = $pdo->prepare('SELECT username FROM snap_users WHERE id=? LIMIT 1');
            $stmt->execute([(int)$post['user_id']]);
            $author = trim((string)($stmt->fetchColumn() ?: $author));
        }
        if (!empty($post['signature_image_id'])) {
            $image = $repository->photographById((int)$post['signature_image_id']);
            if ($image) $signature = ['url' => $base . ltrim((string)$image['img_file'], '/'),
                'alt' => (string)($image['img_alt'] ?: $image['img_title'])];
        }
    } catch (Throwable $e) { /* optional relationships may not exist on older installs */ }
    if ($author === '') $author = (string)($settings['site_name'] ?? '');

    $parser = new SnapSmack($pdo);
    $rendered = $parser->parseContent((string)($post['content'] ?? ''));
    $photo_count = preg_match_all('/<img\b/i', $rendered);
    if (preg_match_all('/\bdata-mosaic=(?:"([^"]*)"|\'([^\']*)\')/i', $rendered, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $json = html_entity_decode((string)($match[1] !== '' ? $match[1] : $match[2]), ENT_QUOTES | ENT_HTML5);
            $items = json_decode($json, true);
            if (is_array($items)) $photo_count += count($items);
        }
    }
    $plain = preg_replace('/\[[^\]]+\]/', ' ', (string)($post['content'] ?? ''));
    $plain = html_entity_decode(strip_tags((string)$plain), ENT_QUOTES | ENT_HTML5);
    preg_match_all('/[\p{L}\p{N}]+(?:[’\'\-][\p{L}\p{N}]+)*/u', $plain, $words);

    return [
        'page_title' => (string)$post['title'], 'post' => $post, 'rendered_content' => $rendered,
        'signature' => $signature, 'previous' => $previous, 'next' => $next,
        'categories' => $categories, 'albums' => $albums,
        'categories_label' => implode(', ', $categories), 'albums_label' => implode(', ', $albums), 'author' => $author,
        'colophon' => trim((string)($post['colophon'] ?? '')), 'photo_count' => $photo_count,
        'word_count' => count($words[0]), 'comments_enabled' => !empty($post['allow_comments']),
        'comments' => $repository->approvedComments(null, (int)$post['id']),
    ];
}

function snapsmack_smacktalk_feed(PDO $pdo, array $settings, string $base, int $page): array
{
    $per_page = max(1, min(100, (int)($settings['posts_per_page'] ?? 12)));
    $offset = ($page - 1) * $per_page;
    try {
        $repository = new SnapPublicRepository($pdo);
        $total = $repository->publishedPostCount('longform');
        $posts = $repository->longformLanding($per_page, $offset);
    } catch (Throwable $e) { $total = 0; $posts = []; }

    foreach ($posts as &$post) {
        $post['url'] = $base . '?post=' . rawurlencode((string)$post['slug']);
        $post['image_url'] = !empty($post['featured_image_path']) ? $base . ltrim((string)$post['featured_image_path'], '/') : '';
        $post['width'] = max(1, (int)($post['featured_width'] ?? 3));
        $post['height'] = max(1, (int)($post['featured_height'] ?? 2));
        $post['created_label'] = !empty($post['created_at']) ? date('M j, Y', strtotime((string)$post['created_at'])) : '';
        if ($post['image_url'] !== '') {
            $path = dirname(__DIR__) . '/' . ltrim((string)$post['featured_image_path'], '/');
            $size = @getimagesize($path);
            if (is_array($size) && ($size[0] ?? 0) > 0 && ($size[1] ?? 0) > 0) {
                $post['width'] = (int)$size[0]; $post['height'] = (int)$size[1];
            }
        }
    }
    unset($post);
    return ['posts' => $posts, 'page' => $page, 'total_pages' => (int)ceil($total / $per_page),
        'show_titles' => ($settings['show_post_titles'] ?? '0') === '1'];
}

// ===== SNAPSMACK EOF =====
