<?php
/**
 * SNAPSMACK - Public SMACKTALK Controller
 *
 * Owns request interpretation, publication policy, data access and derived
 * display data. Skins receive the returned view only; they never receive PDO
 * or inspect request globals themselves.
 */

require_once __DIR__ . '/parser.php';

function snapsmack_smacktalk_request(PDO $pdo, array $settings, array $request): array
{
    $base = defined('BASE_URL') ? BASE_URL : '/';
    $view = trim((string)($request['view'] ?? ''));
    $post_slug = trim((string)($request['post_slug'] ?? ''));
    $post_id = max(0, (int)($request['post_id'] ?? 0));
    $requested_slug = trim((string)($request['requested_slug'] ?? ''));

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

function snapsmack_smacktalk_archive_tiles(PDO $pdo, string $base): array
{
    try {
        $stmt = $pdo->query(
            "SELECT id, img_title, img_file, img_thumb_square, img_thumb_aspect
             FROM snap_images
             WHERE img_status = 'published'
               AND img_date <= NOW()
               AND id NOT IN (SELECT signature_image_id FROM snap_posts
                              WHERE signature_image_id IS NOT NULL
                                AND status = 'published' AND created_at <= NOW())
             ORDER BY sort_order ASC, id DESC"
        );
        $images = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }

    $tiles = [];
    foreach ($images as $image) {
        $full = ltrim((string)($image['img_file'] ?? ''), '/');
        if ($full === '') continue;
        $thumb = ltrim((string)($image['img_thumb_square'] ?? $image['img_thumb_aspect'] ?? ''), '/');
        if ($thumb === '') {
            $dir = trim(str_replace(basename($full), '', $full), '/');
            $thumb = ($dir !== '' ? $dir . '/' : '') . 'thumbs/t_' . basename($full);
        }
        $tiles[] = ['full' => $base . $full, 'thumb' => $base . $thumb,
            'title' => (string)($image['img_title'] ?? '')];
    }
    return $tiles;
}

function snapsmack_smacktalk_single(PDO $pdo, array $settings, string $base, string $slug, int $id): ?array
{
    try {
        if ($slug !== '') {
            $stmt = $pdo->prepare(
                "SELECT p.id,p.title,p.slug,p.content,p.colophon,p.signature_image_id,p.user_id,
                        p.allow_comments,p.created_at,p.updated_at,p.featured_image_id,
                        i.img_file AS featured_image_path FROM snap_posts p
                 LEFT JOIN snap_images i ON i.id = p.featured_image_id
                   AND i.img_status = 'published' AND i.img_date <= NOW()
                 WHERE p.slug = ? AND p.post_type = 'longform' AND p.status = 'published'
                   AND p.created_at <= NOW() LIMIT 1"
            );
            $stmt->execute([$slug]);
        } else {
            $stmt = $pdo->prepare(
                "SELECT p.id,p.title,p.slug,p.content,p.colophon,p.signature_image_id,p.user_id,
                        p.allow_comments,p.created_at,p.updated_at,p.featured_image_id,
                        i.img_file AS featured_image_path FROM snap_posts p
                 LEFT JOIN snap_images i ON i.id = p.featured_image_id
                   AND i.img_status = 'published' AND i.img_date <= NOW()
                 WHERE p.id = ? AND p.post_type = 'longform' AND p.status = 'published'
                   AND p.created_at <= NOW() LIMIT 1"
            );
            $stmt->execute([$id]);
        }
        $post = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return null; }
    if (!$post) return null;

    $previous = $next = null;
    $categories = $albums = [];
    $signature = null;
    $author = trim((string)($settings['site_author'] ?? ''));
    try {
        $stmt = $pdo->prepare("SELECT slug,title FROM snap_posts WHERE post_type='longform' AND status='published' AND created_at <= NOW() AND id < ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([(int)$post['id']]); $previous = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $stmt = $pdo->prepare("SELECT slug,title FROM snap_posts WHERE post_type='longform' AND status='published' AND created_at <= NOW() AND id > ? ORDER BY id ASC LIMIT 1");
        $stmt->execute([(int)$post['id']]); $next = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
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
            $stmt = $pdo->prepare("SELECT img_file,img_alt,img_title FROM snap_images WHERE id=? AND img_status='published' AND img_date <= NOW() LIMIT 1");
            $stmt->execute([(int)$post['signature_image_id']]);
            $image = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
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
        'categories' => $categories, 'albums' => $albums, 'author' => $author,
        'colophon' => trim((string)($post['colophon'] ?? '')), 'photo_count' => $photo_count,
        'word_count' => count($words[0]), 'comments_enabled' => !empty($post['allow_comments']),
    ];
}

function snapsmack_smacktalk_feed(PDO $pdo, array $settings, string $base, int $page): array
{
    $per_page = max(1, min(100, (int)($settings['posts_per_page'] ?? 12)));
    $offset = ($page - 1) * $per_page;
    try {
        $total = (int)$pdo->query("SELECT COUNT(*) FROM snap_posts WHERE post_type='longform' AND status='published' AND created_at <= NOW()")->fetchColumn();
        $stmt = $pdo->prepare(
            "SELECT p.id,p.title,p.slug,p.created_at,COALESCE(i.img_thumb_aspect,i.img_file) AS featured_image_path,
                    i.img_width AS featured_width,i.img_height AS featured_height
             FROM snap_posts p LEFT JOIN snap_images i ON i.id=p.featured_image_id
               AND i.img_status='published' AND i.img_date <= NOW()
             WHERE p.post_type='longform' AND p.status='published' AND p.created_at <= NOW()
             ORDER BY p.created_at DESC, p.id DESC LIMIT ? OFFSET ?"
        );
        $stmt->execute([$per_page, $offset]);
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $total = 0; $posts = []; }

    foreach ($posts as &$post) {
        $post['url'] = $base . '?post=' . rawurlencode((string)$post['slug']);
        $post['image_url'] = !empty($post['featured_image_path']) ? $base . ltrim((string)$post['featured_image_path'], '/') : '';
        $post['width'] = max(1, (int)($post['featured_width'] ?? 3));
        $post['height'] = max(1, (int)($post['featured_height'] ?? 2));
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
