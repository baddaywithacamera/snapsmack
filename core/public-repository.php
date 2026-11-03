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

    // ─── PORTFOLIO VISIBILITY ────────────────────────────────────────────────
    //
    // ONE definition of each exclusion, shared by every surface, because a
    // listing and the count beside it MUST carry the identical predicate. When
    // they drift the count says 100, the list yields 80, and endless scroll
    // never completes -- an archive stuck part-way is the same defect as one
    // stuck on page one.
    //
    // WHY THESE TOLERATE A MISSING COLUMN. snap_schema_sync() is called from
    // exactly one place: core/updater.php:1588, inside
    // updater_run_migrations(), which is reached only from smack-update.php
    // and core/multisite-api.php. So the columns arrive when an UPDATE is RUN
    // -- not on login, and not on a SMACKBACK pass. (I told Codex otherwise
    // and he asked me to trace it; login and SMACKBACK call
    // core/installer-cleanup.php, which is a different chain entirely.) So a
    // site can be running
    // updated core files against a database that has not gained these columns
    // yet, and an unconditional `AND p.exclude_from_archive = 0` would be an
    // "Unknown column" fatal on a public page. That is not hypothetical: it is
    // recorded at core/threeacross-api.php:907 as the cause of fleet-wide
    // "Unknown column 'fedi_enabled'" 500s on sites that never ran FEDIVERSE.
    // So each helper returns an EMPTY STRING when the column is absent.
    //
    // Absent means NOT FILTERED, deliberately. Hiding nothing is a visible,
    // reversible cosmetic regression; a 500 takes the site down. But absence is
    // never silent -- visibilityFlagsPresent() reports it so an admin or health
    // page can say so out loud, and a test asserts the fallback rather than
    // leaving it to be discovered.
    //
    // NO DDL HERE. These run on unauthenticated page views. ALTER needs DDL
    // rights, implicit-commits, and has no business on a reader's request.
    // The sync path (login, SMACKBACK, update) owns adding the columns.

    /** The visibility flags, so the admin side validates against this list
     *  rather than keeping its own copy to drift. portfolio_post is NOT here:
     *  it is a designation and filters nothing. */
    public const VISIBILITY_FLAGS = [
        'exclude_from_archive',
        'exclude_from_search',
        'exclude_from_feed',
        'exclude_from_sitemap',
    ];

    /** Per-request cache of which flags the live database actually has. */
    private ?array $flagsPresent = null;

    /** complete | partial | absent | undetermined. See schemaState(). */
    private ?string $schemaState = null;

    /**
     * Measured, not assumed: which VISIBILITY_FLAGS exist on snap_posts.
     *
     * Asked by SELECTING the columns with a false WHERE, not by reading
     * INFORMATION_SCHEMA. The first version of this used INFORMATION_SCHEMA and
     * its own regression test caught it: that view is MySQL/MariaDB only, so on
     * any other engine the probe threw and silently reported every column
     * absent -- a feature that would have appeared to work and filtered
     * nothing. A SELECT ... WHERE 0 is answered by every engine, returns no
     * rows, and asks the question that actually matters, which is whether a
     * query naming these columns can be prepared at all.
     *
     * One statement in the normal case. Only a failure costs a second pass, and
     * then only to find which subset exists, so a half-applied schema still
     * filters on the flags it does have.
     */
    public function visibilityFlagsPresent(): array {
        if ($this->flagsPresent !== null) return $this->flagsPresent;

        $cols = implode(', ', array_map(
            static fn(string $f): string => "`{$f}`",
            self::VISIBILITY_FLAGS
        ));
        if ($this->columnsSelectable($cols) === true) {
            $this->schemaState = 'complete';
            return $this->flagsPresent = self::VISIBILITY_FLAGS;
        }

        $present = [];
        $undetermined = 0;
        foreach (self::VISIBILITY_FLAGS as $flag) {
            $answer = $this->columnsSelectable("`{$flag}`");
            if ($answer === true)      $present[] = $flag;
            elseif ($answer === null)  $undetermined++;
        }
        $this->schemaState = $undetermined > 0
            ? ($present ? 'partial' : 'undetermined')
            : ($present ? 'partial' : 'absent');
        return $this->flagsPresent = $present;
    }

    /**
     * Three answers, not two: TRUE selectable, FALSE genuinely unknown-column,
     * NULL the database could not tell us.
     *
     * Only an unknown-column error is evidence that a column is missing. A
     * connection failure or a revoked privilege is a different fact and gets
     * NULL, so nothing downstream can report "schema not applied" when what
     * actually happened was "the database would not answer".
     *
     * MySQL/MariaDB unknown column is SQLSTATE 42S22 (errno 1054). SQLite
     * reports HY000 with "no such column". Anything else is not a schema
     * answer.
     */
    private function columnsSelectable(string $columnList): ?bool {
        try {
            $stmt = $this->pdo->prepare("SELECT {$columnList} FROM snap_posts WHERE 0");
            $stmt->execute();
            $stmt->fetchAll();
            return true;
        } catch (Throwable $e) {
            return $this->looksLikeUnknownColumn($e) ? false : null;
        }
    }

    private function looksLikeUnknownColumn(Throwable $e): bool {
        $state = $e instanceof PDOException ? (string)$e->getCode() : '';
        if ($state === '42S22') return true;
        $msg = strtolower($e->getMessage());
        if (str_contains($msg, 'no such column')) return true;      // SQLite
        if (str_contains($msg, 'unknown column')) return true;      // MySQL text
        return false;
    }

    /**
     * True when every EXCLUSION flag is present.
     *
     * This does NOT certify that the admin save path is ready: portfolio_post
     * is a fifth column and is not in VISIBILITY_FLAGS, because it filters
     * nothing. Codex raised that in review -- a save form writing five columns
     * cannot take a four-column answer as its go-ahead. Use
     * designationAvailable() for the fifth, or portfolioSchemaReady() for both.
     */
    public function visibilityFlagsComplete(): bool {
        return $this->schemaState() === 'complete'
            && count($this->visibilityFlagsPresent()) === count(self::VISIBILITY_FLAGS);
    }

    /** Is the portfolio_post DESIGNATION column available? Asked separately
     *  because it is not an exclusion and never appears in a WHERE clause. */
    public function designationAvailable(): bool {
        return $this->columnsSelectable('`portfolio_post`') === true;
    }

    /** Everything an admin save of all five columns needs. */
    public function portfolioSchemaReady(): bool {
        return $this->visibilityFlagsComplete() && $this->designationAvailable();
    }

    /**
     * WHY THERE IS A THIRD STATE. The first version of columnsSelectable()
     * caught every Throwable and reported "absent". Codex's review caught it:
     * a connection drop, a revoked SELECT privilege or any other query failure
     * is NOT evidence that a column is missing, and silently treating it as
     * such would turn filtering off across a whole site while reporting a
     * tidy, wrong answer about why.
     *
     *   'complete'     every exclusion column answered a query
     *   'partial'      some did, some are genuinely unknown-column
     *   'absent'       all genuinely unknown-column: schema not applied yet
     *   'undetermined' the database could not answer at all
     *
     * All four behave the same way at the query boundary -- no filtering,
     * never a fatal on a reader's page -- but they must not be CONFUSED, and
     * 'undetermined' must never be read as "the feature is not installed" or
     * allow anything to claim a save succeeded.
     */
    public function schemaState(): string {
        $this->visibilityFlagsPresent();
        return $this->schemaState ?? 'undetermined';
    }

    /**
     * POST-KEYED surfaces: the listing already selects FROM snap_posts, so the
     * flag is a column on the row being listed. Returns '' when unsupported.
     *
     * $alias is the snap_posts alias in the caller's query ('' for an
     * unaliased `FROM snap_posts`).
     */
    public function postFilter(string $flag, string $alias = 'p'): string {
        if (!in_array($flag, $this->visibilityFlagsPresent(), true)) return '';
        $col = $this->qualify($alias, $flag);
        return " AND {$col} = 0";
    }

    /**
     * IMAGE-KEYED surfaces: the archive, the taxonomy counts and the images
     * half of search all select FROM snap_images. A post-level flag cannot
     * reach them except through the snap_post_images pivot.
     *
     * THE QUALIFYING-OWNER RULE, which is not the obvious one. An image can
     * belong to several posts. Hiding it because ANY owner is excluded would
     * make a photograph vanish from the archive merely because it also appears
     * on a portfolio page. And counting a DRAFT owner as a reason to keep it
     * visible would let unpublished content decide what the public sees.
     *
     * So: only a PUBLISHED, CURRENTLY VISIBLE post counts as an owner at all.
     * The image is hidden when it has at least one such owner and EVERY one of
     * them is excluded. An image with no qualifying owner is never touched by
     * this feature, which leaves orphan and legacy photographs exactly as they
     * are today.
     *
     * Both ownership routes are considered: the snap_post_images pivot and the
     * legacy nullable snap_images.post_id. Considering only the pivot would let
     * a legacy-linked image slip through.
     *
     * This is ANDed on top of the existing img_status / img_date filters. It
     * never replaces them.
     */
    public function imageFilter(string $flag, string $imageAlias = 'i'): string {
        if (!in_array($flag, $this->visibilityFlagsPresent(), true)) return '';
        $img = $imageAlias === '' ? 'snap_images' : $imageAlias;
        $owner = "(
                      EXISTS (SELECT 1 FROM snap_post_images vpi
                               WHERE vpi.image_id = {$img}.id AND vpi.post_id = vp.id)
                   OR {$img}.post_id = vp.id
                  )";
        // Hidden only if a qualifying owner exists AND none of them is visible.
        return "
               AND NOT (
                    EXISTS (
                        SELECT 1 FROM snap_posts vp
                         WHERE vp.status = 'published' AND vp.created_at <= NOW()
                           AND {$owner}
                    )
                AND NOT EXISTS (
                        SELECT 1 FROM snap_posts vp
                         WHERE vp.status = 'published' AND vp.created_at <= NOW()
                           AND vp.{$flag} = 0
                           AND {$owner}
                    )
               )";
    }

    private function qualify(string $alias, string $column): string {
        return $alias === '' ? "`{$column}`" : "{$alias}.`{$column}`";
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
            [max(1, min(100, $limit)), max(0, $offset)]
        );
    }

    /**
     * GRAMOFSMACK landing contract: one ordered tile per published post, using
     * the post's declared cover and retaining carousel/trigram presentation
     * metadata. This is the bounded CMS equivalent of THE GRID's known-good
     * landing query; returning photographs here would split carousels apart.
     */
    public function carouselPostLanding(int $limit = 5000, int $offset = 0): array {
        return $this->all(
            "SELECT p.id AS post_id,p.title,p.slug AS post_slug,p.post_type,p.trigram_id,
                    p.created_at,p.sort_order,p.post_img_size_pct,p.post_border_px,
                    p.post_border_color,p.post_bg_color,p.post_shadow,
                    i.id AS img_id,i.img_file,i.img_thumb_square,i.img_thumb_aspect,
                    i.img_width,i.img_height,i.img_orientation,i.img_slug,i.img_title,i.img_alt,
                    pi.img_size_pct,pi.img_border_px,pi.img_border_color,pi.img_bg_color,
                    pi.img_shadow,pi.img_focus_x,pi.img_focus_y,pi.img_zoom,
                    (SELECT COUNT(*) FROM snap_post_images spi
                     WHERE spi.post_id=p.id AND spi.sort_position>=0) AS image_count,
                    CASE WHEN tg.post_id_1=p.id THEN 1 WHEN tg.post_id_2=p.id THEN 2
                         WHEN tg.post_id_3=p.id THEN 3 ELSE NULL END AS trigram_slot,
                    tg.orientation AS trigram_orientation
             FROM snap_posts p
             JOIN snap_post_images pi ON pi.post_id=p.id AND pi.is_cover=1
             JOIN snap_images i ON i.id=pi.image_id
             LEFT JOIN snap_trigrams tg ON tg.id=p.trigram_id
             WHERE p.status='published' AND p.created_at<=NOW()
               AND i.img_status='published' AND i.img_date<=NOW()
             ORDER BY CASE WHEN p.sort_order>0 THEN 1 ELSE 0 END ASC,
                      p.sort_order ASC,p.id DESC
             LIMIT ? OFFSET ?",
            [max(1, min(5000, $limit)), max(0, $offset)]
        );
    }

    /**
     * Lightweight complete inventory for CMS-owned justified layouts.
     *
     * A justified row cannot be paged correctly until the CMS has measured the
     * photographs which precede it: cutting the SQL result at an arbitrary item
     * count changes row boundaries and can make the rest of a large archive
     * unreachable.  Keep this read deliberately narrow and let the controller
     * expose only one bounded page of prepared rows to the skin.
     */
    public function justifiedPhotographFeed(): array {
        return $this->all(
            "SELECT id,img_title,img_slug,img_alt,img_file,img_thumb_aspect,img_width,img_height
             FROM snap_images
             WHERE img_status='published' AND img_date <= NOW()
             ORDER BY sort_order ASC,img_date DESC,id DESC"
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
    public function gameOnPuzzlePhotographs(int $limit = 160): array {
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

    /**
     * Site-wide approved comment and like totals, for the profile stats row.
     *
     * PHOTOGRAM's mobile profile has rendered "Likes" and "Comments" with no
     * number beside them on every photoblog since the strict conversion: the
     * skin asked for like_count and comment_count, neither was in the bounded
     * response, and an absent field is an empty string. Sean's own reference
     * screenshot reads 9,975 Posts / 1 Likes / 1,909 Comments.
     *
     * Both tables are optional -- snap_likes in particular only exists where
     * the community engine has run -- so a missing table yields zero rather
     * than a fatal, exactly as the pre-conversion skin did.
     */
    public function approvedCommentCount(): int {
        try {
            return (int)$this->pdo->query(
                "SELECT COUNT(id) FROM snap_comments WHERE is_approved = 1 AND is_spam = 0"
            )->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public function likeCount(): int {
        try {
            return (int)$this->pdo->query("SELECT COUNT(id) FROM snap_likes")->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
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

    /** Published writing matches accompanying the photograph search wall. */
    public function archiveSearchPosts(string $search): array {
        $terms = preg_split('/\s+/', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (!$terms) return [];
        $scope = '';
        $params = [];
        foreach (array_slice($terms, 0, 8) as $term) {
            $scope .= ' AND (title LIKE ? OR description LIKE ? OR content LIKE ?)';
            array_push($params, '%' . $term . '%', '%' . $term . '%', '%' . $term . '%');
        }
        return $this->all(
            "SELECT id,title,slug,description FROM snap_posts
             WHERE status='published' AND created_at <= NOW(){$scope}
             ORDER BY created_at DESC,id DESC", $params
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

    /** Resolve the configured static homepage without exposing SQL to a skin. */
    public function activePageById(int $id): ?array {
        if ($id < 1) return null;
        $stmt = $this->pdo->prepare(
            'SELECT id,slug,title,content,image_asset,image_size,image_align,image_shadow,menu_order,created_at
               FROM snap_pages WHERE id = ? AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function archivePhotographs(int $limit, int $offset = 0, int $categoryId = 0, int $albumId = 0, string $search = ''): array {
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
        // The search the SMACKTALK archive never had. archive.php has filtered
        // on ?q= for a long time; this road was built without it, so every
        // SMACKTALK site answered every search with its whole library --
        // measured on baddaywithacamera.ca, where the same 6,998 photographs
        // came back for a real query and for deliberate gibberish alike.
        //
        // Same rule as archive.php: split the query into words and AND them, so
        // each word must match SOMEWHERE -- title, description, tag slug,
        // colour family, album name or category name -- in any order. A leading
        // # is a hashtag, and the tag clause already covers it once stripped.
        $search = trim($search);
        if ($search !== '') {
            $terms = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [$search];
            foreach (array_slice($terms, 0, 8) as $term) {
                $term = ltrim($term, '#');
                if ($term === '') continue;
                $like = '%' . $term . '%';
                $scope .= " AND (i.img_title LIKE ? OR i.img_description LIKE ?"
                    . " OR EXISTS (SELECT 1 FROM snap_image_tags _sit JOIN snap_tags _st ON _st.id = _sit.tag_id"
                    . " WHERE _sit.image_id = i.id AND (_st.slug LIKE ? OR _st.color_family = ?))"
                    . " OR EXISTS (SELECT 1 FROM snap_image_album_map _sam JOIN snap_albums _sa ON _sa.id = _sam.album_id"
                    . " WHERE _sam.image_id = i.id AND _sa.album_name LIKE ?)"
                    . " OR EXISTS (SELECT 1 FROM snap_image_cat_map _scm JOIN snap_categories _sca ON _sca.id = _scm.cat_id"
                    . " WHERE _scm.image_id = i.id AND _sca.cat_name LIKE ?))";
                $params[] = $like;                  // img_title
                $params[] = $like;                  // img_description
                $params[] = '%' . strtolower($term) . '%';  // tag slug
                $params[] = strtolower($term);      // colour family
                $params[] = $like;                  // album name
                $params[] = $like;                  // category name
            }
        }
        $params[] = max(1, min(5000, $limit));
        $params[] = max(0, $offset);
        return $this->all(
            // img_orientation decides which shape a thumbnail tile takes, and
            // this query never selected it. Every archive tile reached the skin
            // with no orientation, defaulted to landscape, and a portrait
            // photograph was squeezed into a 3:2 box -- all 24 tiles on
            // pixhellated.ca came back orient-landscape. COALESCE on the
            // dimensions as well, because a row whose orientation was never
            // computed still knows its own width and height.
            "SELECT i.id,i.img_title,i.img_slug,i.img_alt,i.img_file,i.img_width,i.img_height,
                    i.img_thumb_square,i.img_thumb_aspect,
                    CASE
                      WHEN COALESCE(NULLIF(i.img_orientation,0),0) <> 0 THEN i.img_orientation
                      WHEN i.img_height > i.img_width THEN 1
                      WHEN i.img_height = i.img_width AND i.img_width > 0 THEN 2
                      ELSE 0
                    END AS img_orientation
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

    public function archivePhotographCount(int $categoryId = 0, int $albumId = 0): int {
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
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(i.id) FROM snap_images i
             WHERE i.img_status='published' AND i.img_date <= NOW()
               AND NOT EXISTS (
                    SELECT 1 FROM snap_posts p
                    WHERE p.signature_image_id=i.id
                      AND p.status='published' AND p.created_at <= NOW()
               ){$scope}"
        );
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
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

    public function search(string $term, int $limit = 50, int $offset = 0): array {
        $like = '%' . $term . '%';
        $tagLike = '%' . strtolower($term) . '%';
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        return [
            'photographs' => $this->all(
                // img_width and img_height are NOT optional here. A wall tile
                // that arrives without them is given 3:2 by the renderer, so
                // every search result was cropped to landscape and portraits
                // came back on their side. Every other tile query in this file
                // already selects them; this one did not. (SS-1029, SS-1030.)
                "SELECT DISTINCT i.id,i.img_title,i.img_slug,i.img_description,i.img_alt,i.img_date,
                        i.img_file,i.img_thumb_square,i.img_thumb_aspect,i.img_width,i.img_height
                 FROM snap_images i
                 LEFT JOIN snap_image_tags it ON it.image_id=i.id
                 LEFT JOIN snap_tags t ON t.id=it.tag_id
                 WHERE i.img_status='published' AND i.img_date <= NOW()
                   AND (i.img_title LIKE ? OR i.img_description LIKE ? OR i.img_alt LIKE ? OR t.slug LIKE ?
                        OR EXISTS (SELECT 1 FROM snap_image_album_map sam JOIN snap_albums a ON a.id=sam.album_id
                                   WHERE sam.image_id=i.id AND a.album_name LIKE ?)
                        OR EXISTS (SELECT 1 FROM snap_image_cat_map scm JOIN snap_categories c ON c.id=scm.cat_id
                                   WHERE scm.image_id=i.id AND c.cat_name LIKE ?))
                 ORDER BY i.img_date DESC,i.id DESC LIMIT ? OFFSET ?",
                [$like, $like, $like, $tagLike, $like, $like, $limit, $offset]
            ),
            'posts' => $this->all(
                "SELECT id,title,slug,description,post_type,created_at,featured_image_id
                 FROM snap_posts
                 WHERE status='published' AND created_at <= NOW()
                   AND (title LIKE ? OR description LIKE ? OR content LIKE ?)
                 ORDER BY created_at DESC,id DESC LIMIT ? OFFSET ?",
                [$like, $like, $like, $limit, $offset]
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

    public function photographAlbums(int $imageId): array {
        return $this->all(
            "SELECT a.id,a.album_name,a.album_description
             FROM snap_albums a
             JOIN snap_image_album_map m ON m.album_id=a.id
             WHERE m.image_id=? ORDER BY a.album_name ASC,a.id ASC",
            [$imageId]
        );
    }

    public function photographTags(int $imageId): array {
        return $this->all(
            "SELECT t.id,t.tag,t.slug
             FROM snap_tags t
             JOIN snap_image_tags m ON m.tag_id=t.id
             WHERE m.image_id=? ORDER BY t.tag ASC,t.id ASC",
            [$imageId]
        );
    }

    /** The album an ?album=N archive view is showing: its name, how many
     *  published photographs it holds, and its view tally. The count is
     *  recomputed from the join rather than trusted to a stored column,
     *  because the header must agree with the grid underneath it. */
    public function publicAlbum(int $albumId): ?array {
        if ($albumId <= 0) return null;
        return $this->one(
            "SELECT a.id,a.album_name,a.view_count,
                    COUNT(DISTINCT i.id) AS photograph_count
             FROM snap_albums a
             LEFT JOIN snap_image_album_map m ON m.album_id=a.id
             LEFT JOIN snap_images i ON i.id=m.image_id AND i.img_status='published' AND i.img_date <= NOW()
             WHERE a.id=?
             GROUP BY a.id,a.album_name,a.view_count",
            [$albumId]
        );
    }

    /** The filmstrip along the bottom of a solo page: the most recent
     *  published photographs, newest first. GALLERIA drew this until the
     *  strict conversion; its stylesheet has carried 14 rules for
     *  .htbs-filmstrip ever since, waiting for markup nothing emitted.
     *  Query copied from the layout that used to run it, limit and order
     *  unchanged. */
    public function filmstripPhotographs(int $limit = 50): array {
        return $this->all(
            "SELECT i.id,i.img_slug,i.img_file,i.img_thumb_square,i.img_title
             FROM snap_images i
             WHERE i.img_status='published' AND i.img_date <= NOW()
             ORDER BY i.sort_order ASC,i.id DESC
             LIMIT " . max(1, min(200, $limit))
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

    /** The four sorts collections.php has offered since 0.7.79. Carried over
     *  verbatim rather than reinvented: a visitor who bookmarked ?sort=oldest
     *  must get the same order from the skin-rendered page. */
    public function publicCollections(string $sort = 'manual'): array {
        $order = match ($sort) {
            'alphabetical' => 'c.title ASC',
            'newest'       => 'c.created_at DESC',
            'oldest'       => 'c.created_at ASC',
            default        => 'c.sort_order ASC, c.id ASC',
        };
        // snap_collection_items is POLYMORPHIC -- item_type/item_id/sort_order --
        // since the collection-items migration. This query still joined
        // ci.image_id and ordered by ci.position, so every count came back 0
        // and foreverphotograph.ing showed "0 IMAGES" under collections
        // holding twenty photographs each. collections.php has carried the
        // correct shape in its own SQL since that migration; this is that
        // shape, not a second attempt at it.
        return $this->all(
            "SELECT c.id,c.title,c.slug,c.description,c.default_display,c.cover_image_id,c.sort_order,
                    (SELECT COUNT(*) FROM snap_collection_items WHERE collection_id=c.id) AS photograph_count,
                    (SELECT MAX(i.img_date)
                       FROM snap_collection_items cid
                       JOIN snap_images i ON i.id=cid.item_id AND i.img_status='published' AND i.img_date <= NOW()
                      WHERE cid.collection_id=c.id AND cid.item_type='image') AS latest_date,
                    COALESCE(
                      (SELECT i.img_thumb_aspect FROM snap_images i
                        WHERE i.id=c.cover_image_id AND i.img_status='published' AND i.img_date <= NOW() LIMIT 1),
                      (SELECT i.img_file FROM snap_images i
                        WHERE i.id=c.cover_image_id AND i.img_status='published' AND i.img_date <= NOW() LIMIT 1),
                      (SELECT COALESCE(i.img_thumb_aspect,i.img_file)
                         FROM snap_collection_items ci3
                         JOIN snap_images i ON i.id=ci3.item_id AND i.img_status='published' AND i.img_date <= NOW()
                        WHERE ci3.collection_id=c.id AND ci3.item_type='image'
                        ORDER BY ci3.sort_order ASC LIMIT 1),
                      (SELECT COALESCE(i.img_thumb_aspect,i.img_file)
                         FROM snap_collection_items ci2
                         JOIN snap_post_images pim ON pim.post_id=ci2.item_id
                         JOIN snap_images i ON i.id=pim.image_id AND i.img_status='published' AND i.img_date <= NOW()
                        WHERE ci2.collection_id=c.id AND ci2.item_type='post'
                        ORDER BY ci2.sort_order ASC,pim.sort_position ASC LIMIT 1)
                    ) AS cover_path
             FROM snap_collections c
             WHERE c.published=1
             ORDER BY " . $order
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
