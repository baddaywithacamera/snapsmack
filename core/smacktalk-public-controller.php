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
    $page_slug = trim((string)($request['page_slug'] ?? ''));
    $requested_slug = trim((string)($request['requested_slug'] ?? ''));

    // Old controller-style links remain valid, but they are no longer the
    // public address. Send browsers and crawlers to the readable root slug.
    if ($view === 'page' && $page_slug !== '') {
        return ['handled' => true, 'redirect' => snapsmack_smacktalk_slug_url($base, $page_slug), 'redirect_status' => 301];
    }
    if ($post_slug !== '' && $post_id === 0) {
        return ['handled' => true, 'redirect' => snapsmack_smacktalk_slug_url($base, $post_slug), 'redirect_status' => 301];
    }

    if ($view === 'categories' || $view === 'albums') {
        try {
            $repo = new SnapPublicRepository($pdo);
            $groups = $view === 'categories' ? $repo->publicCategories() : $repo->publicAlbums();
        } catch (Throwable $e) { $groups = []; }
        foreach ($groups as &$group) {
            $id = (int)($group['id'] ?? 0);
            $group['label'] = (string)($view === 'categories' ? ($group['cat_name'] ?? '') : ($group['album_name'] ?? ''));
            $group['description'] = (string)($group['album_description'] ?? '');
            $group['url'] = $base . '?view=archive&' . ($view === 'categories' ? 'category' : 'album') . '=' . $id;
            $group['cover_url'] = !empty($group['cover_path']) ? $base . ltrim((string)$group['cover_path'], '/') : '';
        }
        unset($group);
        return ['handled' => true, 'kind' => 'taxonomy', 'taxonomy' => $view,
            'page_title' => strtoupper($view), 'groups' => $groups];
    }

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
            'tiles' => snapsmack_smacktalk_archive_tiles(
                $pdo, $base, max(0, (int)($request['category'] ?? 0)), max(0, (int)($request['album'] ?? 0))
            )];
    }

    // Pretty page URLs arrive as a requested path. Resolve an active CMS page
    // before treating that path as a post slug; pages and posts remain ordinary
    // canonical SnapSmack records, not skin-owned compatibility content.
    if ($requested_slug !== '' && $post_slug === '' && $post_id === 0) {
        try { $page = (new SnapPublicRepository($pdo))->activePageBySlug($requested_slug); }
        catch (Throwable $e) { $page = null; }
        if ($page) return ['handled' => true] + snapsmack_smacktalk_page($pdo, $requested_slug, $page);
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

function snapsmack_smacktalk_slug_url(string $base, string $slug): string
{
    $slug = preg_replace('/[^a-zA-Z0-9_-]/', '', trim($slug));
    return rtrim($base, '/') . '/' . rawurlencode($slug);
}

function snapsmack_smacktalk_page(PDO $pdo, string $slug, ?array $page = null): array
{
    try {
        $page = $page ?? (new SnapPublicRepository($pdo))->activePageBySlug($slug);
    } catch (Throwable $e) { $page = null; }
    if (!$page) return ['kind' => 'not_found', 'page_title' => '404 — Not Found'];

    $parser = new SnapSmack($pdo);
    $rendered = $parser->parseContent((string)($page['content'] ?? ''));
    $photo_count = preg_match_all('/<img\b/i', $rendered);
    if (preg_match_all('/\bdata-mosaic=(?:"([^"]*)"|\'([^\']*)\')/i', $rendered, $mosaics, PREG_SET_ORDER)) {
        foreach ($mosaics as $mosaic) {
            $json = html_entity_decode((string)($mosaic[1] !== '' ? $mosaic[1] : $mosaic[2]), ENT_QUOTES | ENT_HTML5);
            $items = json_decode($json, true);
            if (is_array($items)) $photo_count += count($items);
        }
    }
    $plain = preg_replace('/\[[^\]]+\]/', ' ', (string)($page['content'] ?? ''));
    $plain = html_entity_decode(strip_tags((string)$plain), ENT_QUOTES | ENT_HTML5);
    preg_match_all('/[\p{L}\p{N}]+(?:[’\'\-][\p{L}\p{N}]+)*/u', $plain, $words);
    return [
        'kind' => 'page',
        'page_title' => (string)($page['title'] ?? ''),
        'item' => $page,
        'rendered_content' => $rendered,
        'publication_date' => (string)($page['created_at'] ?? ''),
        'photo_count' => $photo_count,
        'word_count' => count($words[0]),
    ];
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

function snapsmack_smacktalk_archive_tiles(PDO $pdo, string $base, int $categoryId = 0, int $albumId = 0): array
{
    try {
        $images = (new SnapPublicRepository($pdo))->archivePhotographs(10000, 0, $categoryId, $albumId);
    } catch (Throwable $e) { return []; }

    $tiles = [];
    $canonical = [];
    foreach ($images as $image) {
        $identity = strtolower(trim((string)($image['img_title'] ?? '')));
        $identity = preg_replace('/(?:-scaled|-\d{2,5}x\d{2,5})$/i', '', $identity);
        // SMACKPRESS signatures are post chrome, never Gallery photographs.
        if (preg_match('/(?:signature|autograph|sean[-_ ]?mccormick[-_ ]?black[-_ ]?low[-_ ]?res)/i', $identity)) continue;
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
        $tile = ['full' => $base . $full, 'thumb' => $base . $thumb,
            'title' => (string)($image['img_title'] ?? ''),
            'width' => max(1, (int)($image['img_width'] ?? 3)),
            'height' => max(1, (int)($image['img_height'] ?? 2))];
        $key = $identity !== '' ? $identity : strtolower(pathinfo($full, PATHINFO_FILENAME));
        $area = $tile['width'] * $tile['height'];
        if (!isset($canonical[$key]) || $area > $canonical[$key]['area']) {
            $canonical[$key] = ['area' => $area, 'tile' => $tile, 'order' => count($canonical)];
        }
    }
    uasort($canonical, static fn(array $a, array $b): int => $a['order'] <=> $b['order']);
    foreach ($canonical as $entry) $tiles[] = $entry['tile'];
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
                $adjacent['url'] = snapsmack_smacktalk_slug_url($base, (string)$adjacent['slug']);
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
        $image = $repository->signaturePhotographForPost(
            (int)$post['id'],
            (int)($post['signature_image_id'] ?? 0)
        );
        if ($image) $signature = ['url' => $base . ltrim((string)$image['img_file'], '/'),
            'alt' => (string)($image['img_alt'] ?: $image['img_title'])];
    } catch (Throwable $e) { /* optional relationships may not exist on older installs */ }
    if ($author === '') $author = (string)($settings['site_name'] ?? '');

    $parser = new SnapSmack($pdo);
    $rendered = $parser->parseContent((string)($post['content'] ?? ''));
    // A post can own photographs through snap_images.post_id even when an old
    // or interrupted migration did not create the optional presentation pivot.
    // The landing page already honours featured_image_id; the single page must
    // show the same photograph instead of presenting a false empty post.
    if (trim(strip_tags((string)$rendered)) === '' && !preg_match('/<img\b/i', (string)$rendered)) {
        try { $owned = $repository->ownedPhotographsForPost((int)$post['id']); }
        catch (Throwable $e) { $owned = []; }
        if (!$owned && !empty($post['featured_image_path'])) {
            $owned = [[
                'img_file' => (string)$post['featured_image_path'],
                'img_alt' => (string)($post['title'] ?? ''),
                'img_title' => (string)($post['title'] ?? ''),
                'img_description' => '',
            ]];
        }
        $fallback = '';
        foreach ($owned as $image) {
            $path = trim((string)($image['img_file'] ?? ''));
            if ($path === '') continue;
            $alt = trim((string)($image['img_alt'] ?? '')) ?: trim((string)($image['img_title'] ?? ''));
            $fallback .= '<figure class="smacktalk-owned-photo"><img src="'
                . htmlspecialchars($base . ltrim($path, '/'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '" alt="' . htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
            $description = trim((string)($image['img_description'] ?? ''));
            if ($description !== '') $fallback .= '<figcaption>' . $parser->parseContent($description) . '</figcaption>';
            $fallback .= '</figure>';
        }
        if ($fallback !== '') $rendered = $fallback;
    }
    $colophon = trim(html_entity_decode(strip_tags((string)($post['colophon'] ?? '')), ENT_QUOTES | ENT_HTML5));
    // Already-imported WordPress posts may predate the explicit colophon
    // field. Recover the final equipment note in the CMS view model and keep
    // it out of the article body; skins receive only presentation-ready data.
    if ($colophon === '' && preg_match('/<p\b[^>]*>(.*?)<\/p>\s*$/is', (string)$rendered, $closing, PREG_OFFSET_CAPTURE)) {
        $candidate = trim(html_entity_decode(strip_tags((string)$closing[1][0]), ENT_QUOTES | ENT_HTML5));
        if (preg_match('/^(?:images? (?:made|taken|shot) with|camera(?:s)?\s*:|equipment\s*:|gear\s*:|shot on\b|photographed with\b)/i', $candidate)) {
            $colophon = $candidate;
            $rendered = substr((string)$rendered, 0, (int)$closing[0][1]);
        }
    }
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
        'colophon' => $colophon, 'photo_count' => $photo_count,
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
        $post['url'] = snapsmack_smacktalk_slug_url($base, (string)$post['slug']);
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
