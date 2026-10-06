<?php
// SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
declare(strict_types=1);

/**
 * The only unauthenticated content repository. Every method is public-only,
 * uses explicit columns, and applies state and publication-time constraints at
 * the database boundary. Administrative reads belong elsewhere.
 */
final class SnapPublicRepository
{
    public function __construct(private PDO $pdo) {}

    private function all(string $sql, array $params = []): array {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function one(string $sql, array $params = []): ?array {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function photographBySlug(string $slug): ?array {
        return $this->one(
            "SELECT id,img_title,img_slug,img_description,img_alt,img_film,img_license,img_date,
                    img_file,img_download_url,img_width,img_height,img_orientation,allow_comments,
                    allow_download,download_url,img_thumb_square,img_thumb_aspect,img_display_options,
                    img_color_mode,img_exif,img_source_file,img_source_url,post_id,sort_order,is_sensitive,content_warning,
                    (SELECT pi.img_focus_x FROM snap_post_images pi WHERE pi.image_id=snap_images.id ORDER BY pi.is_cover DESC,pi.sort_position ASC LIMIT 1) AS img_focus_x,
                    (SELECT pi.img_focus_y FROM snap_post_images pi WHERE pi.image_id=snap_images.id ORDER BY pi.is_cover DESC,pi.sort_position ASC LIMIT 1) AS img_focus_y,
                    (SELECT pi.img_zoom FROM snap_post_images pi WHERE pi.image_id=snap_images.id ORDER BY pi.is_cover DESC,pi.sort_position ASC LIMIT 1) AS img_zoom
             FROM snap_images
             WHERE img_slug=? AND img_status='published' AND img_date <= NOW() LIMIT 1",
            [$slug]
        );
    }

    public function photographById(int $id): ?array {
        return $this->one(
            "SELECT id,img_title,img_slug,img_description,img_alt,img_film,img_license,img_date,
                    img_file,img_download_url,img_width,img_height,img_orientation,allow_comments,
                    allow_download,download_url,img_thumb_square,img_thumb_aspect,img_display_options,
                    img_color_mode,img_exif,img_source_file,img_source_url,post_id,sort_order,is_sensitive,content_warning,
                    (SELECT pi.img_focus_x FROM snap_post_images pi WHERE pi.image_id=snap_images.id ORDER BY pi.is_cover DESC,pi.sort_position ASC LIMIT 1) AS img_focus_x,
                    (SELECT pi.img_focus_y FROM snap_post_images pi WHERE pi.image_id=snap_images.id ORDER BY pi.is_cover DESC,pi.sort_position ASC LIMIT 1) AS img_focus_y,
                    (SELECT pi.img_zoom FROM snap_post_images pi WHERE pi.image_id=snap_images.id ORDER BY pi.is_cover DESC,pi.sort_position ASC LIMIT 1) AS img_zoom
             FROM snap_images
             WHERE id=? AND img_status='published' AND img_date <= NOW() LIMIT 1",
            [$id]
        );
    }

    /** Minimal cover lookup used by story routing on every supported schema. */
    public function photographPathById(int $id): string {
        $row = $this->one(
            "SELECT img_file FROM snap_images
             WHERE id=? AND img_status='published' AND img_date <= NOW() LIMIT 1",
            [$id]
        );
        return trim((string)($row['img_file'] ?? ''));
    }

    public function postBySlug(string $slug, ?string $type = null): ?array {
        $typeSql = $type === null ? '' : ' AND post_type=?';
        $params = $type === null ? [$slug] : [$slug, $type];
        return $this->one(
            "SELECT id,title,slug,post_type,created_at,allow_comments,content,colophon,user_id,
                    signature_image_id,featured_image_id
             FROM snap_posts
             WHERE slug=?{$typeSql} AND status='published' AND created_at <= NOW() LIMIT 1",
            $params
        );
    }

    public function postById(int $id, ?string $type = null): ?array {
        $typeSql = $type === null ? '' : ' AND post_type=?';
        $params = $type === null ? [$id] : [$id, $type];
        return $this->one(
            "SELECT id,title,slug,post_type,created_at,allow_comments,content,colophon,user_id,
                    signature_image_id,featured_image_id
             FROM snap_posts
             WHERE id=?{$typeSql} AND status='published' AND created_at <= NOW() LIMIT 1",
            $params
        );
    }

    public function photographsForPost(int $postId): array {
        return $this->all(
            "SELECT i.id,i.img_title,i.img_slug,i.img_description,i.img_alt,i.img_date,i.img_file,
                    i.img_width,i.img_height,i.img_thumb_square,i.img_thumb_aspect,i.img_display_options,
                    pi.sort_position,pi.is_cover,pi.img_size_pct,pi.img_border_px,pi.img_border_color,
                    pi.img_bg_color,pi.img_shadow,pi.img_focus_x,pi.img_focus_y,pi.img_zoom
             FROM snap_post_images pi JOIN snap_images i ON i.id=pi.image_id
             WHERE pi.post_id=? AND pi.sort_position >= 0
               AND i.img_status='published' AND i.img_date <= NOW()
             ORDER BY pi.sort_position ASC",
            [$postId]
        );
    }

    public function photographLanding(int $limit, int $offset = 0): array {
        return $this->all(
            "SELECT id,img_title,img_slug,img_description,img_alt,img_date,img_file,img_width,img_height,
                    img_thumb_square,img_thumb_aspect,img_color_mode,post_id,sort_order,
                    (SELECT pi.img_size_pct FROM snap_post_images pi WHERE pi.image_id=snap_images.id ORDER BY pi.is_cover DESC,pi.sort_position ASC LIMIT 1) AS img_size_pct,
                    (SELECT pi.img_border_px FROM snap_post_images pi WHERE pi.image_id=snap_images.id ORDER BY pi.is_cover DESC,pi.sort_position ASC LIMIT 1) AS img_border_px,
                    (SELECT pi.img_border_color FROM snap_post_images pi WHERE pi.image_id=snap_images.id ORDER BY pi.is_cover DESC,pi.sort_position ASC LIMIT 1) AS img_border_color,
                    (SELECT pi.img_bg_color FROM snap_post_images pi WHERE pi.image_id=snap_images.id ORDER BY pi.is_cover DESC,pi.sort_position ASC LIMIT 1) AS img_bg_color,
                    (SELECT pi.img_shadow FROM snap_post_images pi WHERE pi.image_id=snap_images.id ORDER BY pi.is_cover DESC,pi.sort_position ASC LIMIT 1) AS img_shadow,
                    (SELECT p.post_img_size_pct FROM snap_posts p WHERE p.id=snap_images.post_id AND p.status='published' AND p.created_at <= NOW() LIMIT 1) AS post_img_size_pct,
                    (SELECT p.post_border_px FROM snap_posts p WHERE p.id=snap_images.post_id AND p.status='published' AND p.created_at <= NOW() LIMIT 1) AS post_border_px,
                    (SELECT p.post_border_color FROM snap_posts p WHERE p.id=snap_images.post_id AND p.status='published' AND p.created_at <= NOW() LIMIT 1) AS post_border_color,
                    (SELECT p.post_bg_color FROM snap_posts p WHERE p.id=snap_images.post_id AND p.status='published' AND p.created_at <= NOW() LIMIT 1) AS post_bg_color,
                    (SELECT p.post_shadow FROM snap_posts p WHERE p.id=snap_images.post_id AND p.status='published' AND p.created_at <= NOW() LIMIT 1) AS post_shadow,
                    (SELECT COUNT(*) FROM snap_post_images pi WHERE pi.post_id=snap_images.post_id AND pi.sort_position >= 0) AS image_count
             FROM snap_images
             WHERE img_status='published' AND img_date <= NOW()
             ORDER BY CASE WHEN sort_order>0 THEN 1 ELSE 0 END ASC,sort_order ASC,id DESC
             LIMIT ? OFFSET ?",
            // The controller owns the route-specific ceiling (100 normally,
            // 5,000 for a declared progressive-reveal feed). Do not silently
            // collapse that validated full-feed request back to 100 here.
            [max(1, min(5000, $limit)), max(0, $offset)]
        );
    }

    public function randomPhotographs(int $limit): array {
        return $this->all(
            "SELECT id,img_title,img_slug,img_alt,img_file,img_width,img_height,img_thumb_square,img_thumb_aspect
             FROM snap_images
             WHERE img_status='published' AND img_date <= NOW()
             ORDER BY RAND() LIMIT ?",
            [max(1, min(400, $limit))]
        );
    }

    public function publicAssetsByIds(array $ids): array {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        if (!$ids) return [];
        $ids = array_slice($ids, 0, 50);
        $rows = $this->all(
            'SELECT id,asset_name,asset_path FROM snap_assets WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids
        );
        $byId = [];
        foreach ($rows as $row) $byId[(int)$row['id']] = $row;
        $ordered = [];
        foreach ($ids as $assetId) if (isset($byId[$assetId])) $ordered[] = $byId[$assetId];
        return $ordered;
    }

    /** CMS-owned GAME ON pool: no trigram members and no carousel cover images. */
    public function gameOnPuzzlePhotographs(int $limit = 400): array {
        return $this->all(
            "SELECT i.id,i.img_title,i.img_slug,i.img_file,i.img_thumb_square,i.img_thumb_aspect,
                    pi.img_focus_x,pi.img_focus_y,pi.img_zoom,p.id AS post_id,p.title AS post_title,
                    COALESCE(ci.img_slug,i.img_slug) AS post_img_slug
             FROM snap_posts p
             JOIN snap_post_images pi ON pi.post_id=p.id AND pi.sort_position >= 0
             JOIN snap_images i ON i.id=pi.image_id
             LEFT JOIN snap_post_images cpi ON cpi.post_id=p.id AND cpi.is_cover=1
             LEFT JOIN snap_images ci ON ci.id=cpi.image_id
             WHERE p.status='published' AND p.created_at <= NOW() AND p.trigram_id IS NULL
               AND i.img_status='published' AND i.img_date <= NOW()
               AND i.img_thumb_square IS NOT NULL AND i.img_thumb_square <> ''
               AND NOT (pi.is_cover=1 AND (SELECT COUNT(*) FROM snap_post_images spi WHERE spi.post_id=p.id AND spi.sort_position >= 0)>1)
             ORDER BY i.id DESC LIMIT ?",
            [max(1, min(1000, $limit))]
        );
    }

    public function publishedPhotographCount(): int {
        $stmt = $this->pdo->query(
            "SELECT COUNT(id) FROM snap_images WHERE img_status='published' AND img_date <= NOW()"
        );
        return (int)$stmt->fetchColumn();
    }

    public function longformLanding(int $limit, int $offset = 0): array {
        return $this->all(
            "SELECT p.id,p.title,p.slug,p.description,p.created_at,p.updated_at,p.featured_image_id,
                    i.img_file AS featured_image_path,i.img_thumb_aspect AS featured_thumb,
                    i.img_width AS featured_width,i.img_height AS featured_height
             FROM snap_posts p LEFT JOIN snap_images i ON i.id=p.featured_image_id
               AND i.img_status='published' AND i.img_date <= NOW()
             WHERE p.post_type='longform' AND p.status='published' AND p.created_at <= NOW()
             ORDER BY p.created_at DESC, p.id DESC LIMIT ? OFFSET ?",
            [max(1, min(100, $limit)), max(0, $offset)]
        );
    }

    public function publishedPostCount(?string $type = null): int {
        $typeSql = $type === null ? '' : ' AND post_type=?';
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(id) FROM snap_posts WHERE status='published' AND created_at <= NOW(){$typeSql}"
        );
        $stmt->execute($type === null ? [] : [$type]);
        return (int)$stmt->fetchColumn();
    }

    /** Public blogroll presentation data; provenance and administrative fields stay private. */
    public function blogrollPeers(): array {
        return $this->all(
            "SELECT b.peer_name,b.peer_url,b.peer_desc,c.cat_name
             FROM snap_blogroll b
             LEFT JOIN snap_blogroll_cats c ON c.id=b.cat_id
             WHERE b.peer_url IS NOT NULL AND b.peer_url<>''
             ORDER BY c.cat_name,b.peer_name"
        );
    }

    public function activePages(): array {
        return $this->all(
            "SELECT id,slug,title,content,image_asset,image_size,image_align,image_shadow,menu_order,created_at
             FROM snap_pages WHERE is_active=1 ORDER BY menu_order ASC,id ASC"
        );
    }

    public function activePageBySlug(string $slug): ?array {
        return $this->one(
            "SELECT id,slug,title,content,image_asset,image_size,image_align,image_shadow,menu_order,created_at
             FROM snap_pages WHERE slug=? AND is_active=1 LIMIT 1",
            [$slug]
        );
    }

    public function archivePhotographs(int $limit, int $offset = 0, int $categoryId = 0, int $albumId = 0): array {
        $scope = '';
        $params = [];
        if ($categoryId > 0) {
            $scope .= ' AND EXISTS (SELECT 1 FROM snap_image_cat_map cm WHERE cm.image_id=i.id AND cm.cat_id=?)';
            $params[] = $categoryId;
        }
        if ($albumId > 0) {
            $scope .= ' AND EXISTS (SELECT 1 FROM snap_image_album_map am WHERE am.image_id=i.id AND am.album_id=?)';
            $params[] = $albumId;
        }
        $params[] = max(1, min(5000, $limit));
        $params[] = max(0, $offset);
        return $this->all(
            "SELECT i.id,i.img_title,i.img_slug,i.img_alt,i.img_file,i.img_width,i.img_height,
                    i.img_thumb_square,i.img_thumb_aspect
             FROM snap_images i
             WHERE i.img_status='published' AND i.img_date <= NOW()
               AND NOT EXISTS (
                    SELECT 1 FROM snap_posts p
                    WHERE p.signature_image_id=i.id
                      AND p.status='published' AND p.created_at <= NOW()
               ){$scope}
             ORDER BY CASE WHEN i.sort_order>0 THEN 1 ELSE 0 END ASC,i.sort_order ASC,i.id DESC
             LIMIT ? OFFSET ?",
            $params
        );
    }

    public function publicCategories(): array {
        return $this->all(
            "SELECT c.id,c.cat_name,COUNT(DISTINCT i.id) AS photograph_count,
                    SUBSTRING_INDEX(GROUP_CONCAT(i.img_file ORDER BY i.id DESC SEPARATOR '\n'),'\n',1) AS cover_path
             FROM snap_categories c
             JOIN snap_image_cat_map m ON m.cat_id=c.id
             JOIN snap_images i ON i.id=m.image_id AND i.img_status='published' AND i.img_date <= NOW()
             WHERE c.show_in_archive=1
             GROUP BY c.id,c.cat_name ORDER BY c.cat_name ASC"
        );
    }

    public function search(string $term, int $limit = 50): array {
        $like = '%' . $term . '%';
        $tagLike = '%' . strtolower($term) . '%';
        return [
            'photographs' => $this->all(
                "SELECT DISTINCT i.id,i.img_title,i.img_slug,i.img_description,i.img_alt,i.img_date,
                        i.img_file,i.img_thumb_square,i.img_thumb_aspect
                 FROM snap_images i
                 LEFT JOIN snap_image_tags it ON it.image_id=i.id
                 LEFT JOIN snap_tags t ON t.id=it.tag_id
                 WHERE i.img_status='published' AND i.img_date <= NOW()
                   AND (i.img_title LIKE ? OR i.img_description LIKE ? OR i.img_alt LIKE ? OR t.slug LIKE ?
                        OR EXISTS (SELECT 1 FROM snap_image_album_map sam JOIN snap_albums a ON a.id=sam.album_id
                                   WHERE sam.image_id=i.id AND a.album_name LIKE ?)
                        OR EXISTS (SELECT 1 FROM snap_image_cat_map scm JOIN snap_categories c ON c.id=scm.cat_id
                                   WHERE scm.image_id=i.id AND c.cat_name LIKE ?))
                 ORDER BY i.img_date DESC,i.id DESC LIMIT ?",
                [$like, $like, $like, $tagLike, $like, $like, max(1, min(100, $limit))]
            ),
            'posts' => $this->all(
                "SELECT id,title,slug,description,post_type,created_at,featured_image_id
                 FROM snap_posts
                 WHERE status='published' AND created_at <= NOW()
                   AND (title LIKE ? OR description LIKE ? OR content LIKE ?)
                 ORDER BY created_at DESC,id DESC LIMIT ?",
                [$like, $like, $like, max(1, min(100, $limit))]
            ),
        ];
    }

    public function hashtagPhotographs(string $slug, int $limit, int $offset = 0): array {
        return $this->all(
            "SELECT i.id,i.img_title,i.img_slug,i.img_description,i.img_alt,i.img_date,i.img_file,
                    i.img_thumb_square,i.img_thumb_aspect,i.post_id,t.id AS tag_id,t.tag,t.slug AS tag_slug
             FROM snap_tags t JOIN snap_image_tags it ON it.tag_id=t.id
             JOIN snap_images i ON i.id=it.image_id
             WHERE t.slug=? AND i.img_status='published' AND i.img_date <= NOW()
             ORDER BY i.sort_order ASC,i.id DESC LIMIT ? OFFSET ?",
            [$slug, max(1, min(100, $limit)), max(0, $offset)]
        );
    }

    public function hashtagPhotographCount(string $slug): int {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(i.id)
             FROM snap_tags t JOIN snap_image_tags it ON it.tag_id=t.id
             JOIN snap_images i ON i.id=it.image_id
             WHERE t.slug=? AND i.img_status='published' AND i.img_date <= NOW()"
        );
        $stmt->execute([$slug]);
        return (int)$stmt->fetchColumn();
    }

    public function adjacentPosts(int $id, string $type, bool $next): ?array {
        $op = $next ? '>' : '<';
        $direction = $next ? 'ASC' : 'DESC';
        return $this->one(
            "SELECT id,title,slug,created_at FROM snap_posts
             WHERE post_type=? AND status='published' AND created_at <= NOW() AND id {$op} ?
             ORDER BY id {$direction} LIMIT 1",
            [$type, $id]
        );
    }

    public function adjacentPhotograph(int $id, bool $next): ?array {
        $op = $next ? '>' : '<';
        $direction = $next ? 'ASC' : 'DESC';
        return $this->one(
            "SELECT id,img_slug,img_title,img_date FROM snap_images
             WHERE img_status='published' AND img_date <= NOW() AND id {$op} ?
             ORDER BY id {$direction} LIMIT 1",
            [$id]
        );
    }

    public function photographBoundary(bool $newest): ?array {
        $direction = $newest ? 'DESC' : 'ASC';
        return $this->one(
            "SELECT id,img_slug,img_title,img_date FROM snap_images
             WHERE img_status='published' AND img_date <= NOW()
             ORDER BY id {$direction} LIMIT 1"
        );
    }

    public function approvedComments(?int $imageId, ?int $postId): array {
        if ($imageId === null && $postId === null) return [];
        $column = $imageId !== null ? 'img_id' : 'post_id';
        $id = $imageId ?? $postId;
        return $this->all(
            "SELECT id,comment_author,comment_url,comment_text,comment_date,ap_source,ap_actor_url
             FROM snap_comments WHERE {$column}=? AND is_approved=1 AND is_spam=0
             ORDER BY comment_date ASC,id ASC",
            [(int)$id]
        );
    }

    public function publicAlbums(): array {
        return $this->all(
            "SELECT a.id,a.album_name,a.album_description,a.cover_image_id,a.featured_post_id,
                    COUNT(DISTINCT i.id) AS photograph_count,
                    COALESCE(ci.img_file,SUBSTRING_INDEX(GROUP_CONCAT(i.img_file ORDER BY i.id DESC SEPARATOR '\n'),'\n',1)) AS cover_path
             FROM snap_albums a
             JOIN snap_image_album_map m ON m.album_id=a.id
             JOIN snap_images i ON i.id=m.image_id AND i.img_status='published' AND i.img_date <= NOW()
             LEFT JOIN snap_images ci ON ci.id=COALESCE(a.cover_image_id,a.featured_post_id)
             GROUP BY a.id,a.album_name,a.album_description,a.cover_image_id,a.featured_post_id,ci.img_file
             ORDER BY a.album_name ASC,a.id ASC"
        );
    }

    public function publicCollections(): array {
        return $this->all(
            "SELECT id,title,slug,description,default_display,cover_image_id,sort_order
             FROM snap_collections WHERE published=1 ORDER BY sort_order ASC,id ASC"
        );
    }

    public function collectionPhotographs(string $slug): array {
        return $this->all(
            "SELECT i.id,i.img_title,i.img_slug,i.img_description,i.img_alt,i.img_date,i.img_file,
                    i.img_thumb_square,i.img_thumb_aspect,ci.position,ci.caption
             FROM snap_collections c JOIN snap_collection_items ci ON ci.collection_id=c.id
             JOIN snap_images i ON i.id=ci.image_id
             WHERE c.slug=? AND c.published=1 AND i.img_status='published' AND i.img_date <= NOW()
             ORDER BY ci.position ASC,ci.id ASC",
            [$slug]
        );
    }
}
// ===== SNAPSMACK EOF =====
