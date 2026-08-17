<?php
/**
 * SNAPSMACK - Buckets
 *
 * A bucket is a post's working set of Gallery photos: "these are the photos I
 * am writing this essay from". Private and editorial. Nothing here publishes on
 * its own — that is what separates a bucket from a Collection.
 *
 * It exists so the MOSAIC picker has something to narrow by. Both the longform
 * editor (where you fill the bucket) and the mosaic builder (where you spend it)
 * come through here, so there is exactly one definition of what is in a bucket.
 *
 * Bucket members are snap_images ids — the Gallery — because that is the pile
 * smack-mosaics.php's picker reads. Do not point this at snap_assets (the
 * reusable Library); the two are different tables and the mosaic engine resolves
 * saved ids against snap_images.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */


/**
 * Create snap_bucket_items if this install has not had its schema sync pass yet.
 *
 * core/schema-sync.php against the canonical schema remains authoritative; this
 * only stops the first site to load the screen from fataling on a missing table,
 * the same defensive pattern smack-mosaics.php uses for its own columns.
 */
function snap_bucket_ensure(PDO $pdo): bool {
    try {
        $pdo->query("SELECT 1 FROM snap_bucket_items LIMIT 0");
        return true;
    } catch (PDOException $e) {
        try {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS snap_bucket_items (
                    id       INT       NOT NULL AUTO_INCREMENT,
                    post_id  INT       NOT NULL,
                    image_id INT       NOT NULL,
                    position INT       NOT NULL DEFAULT 0,
                    added_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_bucket_post_image (post_id, image_id),
                    KEY idx_bucket_post_position (post_id, position)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
            return true;
        } catch (PDOException $e2) {
            return false;
        }
    }
}

/**
 * The post's bucket, as Gallery image ids in the order they were arranged.
 */
function snap_bucket_ids(PDO $pdo, int $post_id): array {
    if ($post_id <= 0) return [];
    try {
        $stmt = $pdo->prepare(
            "SELECT image_id FROM snap_bucket_items WHERE post_id = ? ORDER BY position ASC, id ASC"
        );
        $stmt->execute([$post_id]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Replace the post's bucket with exactly $image_ids, in the given order.
 *
 * Wholesale replace rather than diff: the editor always posts the complete list,
 * and a half-applied bucket after a failed diff is worse than a slower write.
 * Returns the number of photos stored.
 */
function snap_bucket_save(PDO $pdo, int $post_id, array $image_ids): int {
    if ($post_id <= 0) return 0;
    snap_bucket_ensure($pdo);

    // Ints only, no duplicates, order preserved — the UNIQUE KEY would reject a
    // repeat anyway and take the whole transaction down with it.
    $clean = [];
    foreach ($image_ids as $id) {
        $id = (int)$id;
        if ($id > 0 && !in_array($id, $clean, true)) $clean[] = $id;
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM snap_bucket_items WHERE post_id = ?")->execute([$post_id]);
        if ($clean) {
            $ins = $pdo->prepare(
                "INSERT INTO snap_bucket_items (post_id, image_id, position) VALUES (?, ?, ?)"
            );
            foreach ($clean as $pos => $id) {
                $ins->execute([$post_id, $id, $pos]);
            }
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        throw $e;
    }
    return count($clean);
}

/**
 * Which Gallery photos are already spoken for by a mosaic.
 *
 * Returns image_id => "Mosaic title (#12)" so the picker can say WHY a photo is
 * greyed out. A photo used by more than one mosaic reports the first; the point
 * is "this is already placed somewhere", not a full audit.
 *
 * Read from snap_mosaics.asset_ids, which despite the column name holds Gallery
 * image ids (see smack-mosaics.php list_assets).
 */
function snap_bucket_mosaic_usage(PDO $pdo): array {
    $used = [];
    try {
        $rows = $pdo->query("SELECT id, title, asset_ids FROM snap_mosaics")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return $used;
    }
    foreach ($rows as $m) {
        $ids = json_decode((string)$m['asset_ids'], true);
        if (!is_array($ids)) continue;
        $label = trim((string)$m['title']) ?: 'Untitled';
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id > 0 && !isset($used[$id])) {
                $used[$id] = $label . ' (#' . (int)$m['id'] . ')';
            }
        }
    }
    return $used;
}

/**
 * Longform posts that could own a bucket, newest first — for the mosaic
 * builder's scope dropdown when it was opened cold rather than from a post.
 */
function snap_bucket_posts(PDO $pdo, int $limit = 100): array {
    try {
        $stmt = $pdo->prepare(
            "SELECT p.id, p.title, p.status, COUNT(b.id) AS bucket_count
               FROM snap_posts p
               JOIN snap_bucket_items b ON b.post_id = p.id
              WHERE p.post_type = 'longform'
           GROUP BY p.id, p.title, p.status
           ORDER BY p.updated_at DESC
              LIMIT " . (int)$limit
        );
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}
// ===== SNAPSMACK EOF =====
