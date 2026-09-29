<?php
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
                    img_color_mode,post_id,sort_order,is_sensitive,content_warning
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
                    img_color_mode,post_id,sort_order,is_sensitive,content_warning
             FROM snap_images
             WHERE id=? AND img_status='published' AND img_date <= NOW() LIMIT 1",
            [$id]
        );
    }

    public function postBySlug(string $slug, ?string $type = null): ?array {
        $typeSql = $type === null ? '' : ' AND post_type=?';
        $params = $type === null ? [$slug] : [$slug, $type];
        return $this->one(
            "SELECT id,title,slug,description,post_type,created_at,updated_at,allow_comments,
                    allow_download,download_url,panorama_rows,content,colophon,signature_image_id,
                    featured_image_id,show_featured_image,trigram_id,cover_pos_x,cover_pos_y,
                    cover_zoom,sort_order,user_id,is_sensitive,content_warning
             FROM snap_posts
             WHERE slug=?{$typeSql} AND status='published' AND created_at <= NOW() LIMIT 1",
            $params
        );
    }

    public function postById(int $id, ?string $type = null): ?array {
        $typeSql = $type === null ? '' : ' AND post_type=?';
        $params = $type === null ? [$id] : [$id, $type];
        return $this->one(
            "SELECT id,title,slug,description,post_type,created_at,updated_at,allow_comments,
                    allow_download,download_url,panorama_rows,content,colophon,signature_image_id,
                    featured_image_id,show_featured_image,trigram_id,cover_pos_x,cover_pos_y,
                    cover_zoom,sort_order,user_id,is_sensitive,content_warning
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
                    img_thumb_square,img_thumb_aspect,img_color_mode,post_id,sort_order
             FROM snap_images
             WHERE img_status='published' AND img_date <= NOW()
             ORDER BY CASE WHEN sort_order>0 THEN 1 ELSE 0 END ASC,sort_order ASC,id DESC
             LIMIT ? OFFSET ?",
            [max(1, min(100, $limit)), max(0, $offset)]
        );
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

    public function activePages(): array {
        return $this->all(
            "SELECT id,slug,title,content,image_asset,image_size,image_align,image_shadow,menu_order
             FROM snap_pages WHERE is_active=1 ORDER BY menu_order ASC,id ASC"
        );
    }

    public function activePageBySlug(string $slug): ?array {
        return $this->one(
            "SELECT id,slug,title,content,image_asset,image_size,image_align,image_shadow,menu_order
             FROM snap_pages WHERE slug=? AND is_active=1 LIMIT 1",
            [$slug]
        );
    }

    public function archivePhotographs(int $limit, int $offset = 0): array {
        return $this->photographLanding($limit, $offset);
    }

    public function search(string $term, int $limit = 50): array {
        $like = '%' . $term . '%';
        return [
            'photographs' => $this->all(
                "SELECT id,img_title,img_slug,img_description,img_alt,img_date,img_file,img_thumb_square,img_thumb_aspect
                 FROM snap_images
                 WHERE img_status='published' AND img_date <= NOW()
                   AND (img_title LIKE ? OR img_description LIKE ? OR img_alt LIKE ?)
                 ORDER BY img_date DESC,id DESC LIMIT ?",
                [$like, $like, $like, max(1, min(100, $limit))]
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
            "SELECT a.id,a.album_name,a.album_description,a.cover_image_id,a.featured_post_id
             FROM snap_albums a
             WHERE EXISTS (SELECT 1 FROM snap_image_album_map m JOIN snap_images i ON i.id=m.image_id
                           WHERE m.album_id=a.id AND i.img_status='published' AND i.img_date <= NOW())
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
