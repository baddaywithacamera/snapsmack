<?php
/**
 * SNAPSMACK - Migration 055: Collections v0.2 schema narrow + is_visible
 *
 * Collections v0.1 framed members as posts/albums/categories with live
 * resolution and no cap. v0.2 reframes as print-salon folios: hand-picked
 * individual images only, hard cap of 30, snapshot membership, per-collection
 * visibility toggle.
 *
 * This migration:
 *   1. Adds 'image' to snap_collection_items.item_type ENUM (additive first)
 *   2. Converts existing item_type='post' rows to 'image' via i.post_id
 *      mapping. Orphans get deleted.
 *   3. Deletes existing item_type='album' and item_type='category' rows.
 *      Existing collections lose their album/category members; admin needs
 *      to re-curate as individual images. Acceptable per spec — v0.1
 *      collections are barely in use.
 *   4. Narrows the ENUM to just ('image').
 *   5. Adds snap_collections.is_visible TINYINT NOT NULL DEFAULT 0. Existing
 *      collections start hidden — admin flips on after re-curating.
 *
 * Idempotent: each step checks current state before mutating.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

$migration_name = '055_collections_v0_2';

try {
    $check = $pdo->prepare("SELECT COUNT(*) FROM snap_migrations WHERE migration = ?");
    $check->execute([$migration_name]);
    if ((int)$check->fetchColumn() > 0) {
        return ['status' => 'skipped', 'message' => 'Already applied.'];
    }

    $report = [];

    // 1. Widen ENUM to include 'image' first (additive — keeps old values valid
    //    while we migrate).
    $col = $pdo->query("SHOW COLUMNS FROM snap_collection_items LIKE 'item_type'")->fetch(PDO::FETCH_ASSOC);
    if ($col && stripos($col['Type'] ?? '', "'image'") === false) {
        $pdo->exec(
            "ALTER TABLE snap_collection_items
             MODIFY COLUMN item_type
             ENUM('post','album','category','image') NOT NULL"
        );
        $report[] = "Added 'image' to item_type ENUM.";
    }

    // 2. Convert existing 'post' rows to 'image' via i.post_id mapping.
    //    snap_images.post_id links each image to its parent post.
    $convert = $pdo->exec(
        "UPDATE snap_collection_items ci
         INNER JOIN snap_images i ON i.post_id = ci.item_id
         SET ci.item_type = 'image',
             ci.item_id   = i.id
         WHERE ci.item_type = 'post'"
    );
    if ($convert > 0) {
        $report[] = "Converted {$convert} 'post' members to 'image' members.";
    }

    // 2b. Delete orphan 'post' rows (post had no image, or post no longer exists).
    $orphans = $pdo->exec("DELETE FROM snap_collection_items WHERE item_type = 'post'");
    if ($orphans > 0) {
        $report[] = "Deleted {$orphans} orphan 'post' rows (no image mapping).";
    }

    // 3. Delete album-as-member and category-as-member rows. Admin must
    //    re-curate as individual images — see spec.
    $albums = $pdo->exec("DELETE FROM snap_collection_items WHERE item_type = 'album'");
    if ($albums > 0) {
        $report[] = "Deleted {$albums} album-as-member rows (re-curate as images).";
    }
    $cats = $pdo->exec("DELETE FROM snap_collection_items WHERE item_type = 'category'");
    if ($cats > 0) {
        $report[] = "Deleted {$cats} category-as-member rows (re-curate as images).";
    }

    // 4. Narrow ENUM to just 'image'. DB-layer reject for any future bad inserts.
    $pdo->exec(
        "ALTER TABLE snap_collection_items
         MODIFY COLUMN item_type
         ENUM('image') NOT NULL DEFAULT 'image'"
    );
    $report[] = "Narrowed item_type ENUM to ('image') only.";

    // 5. Add is_visible to snap_collections. Default 0 — existing collections
    //    start hidden. Admin toggles on after re-curating per v0.2 spec.
    $col = $pdo->query("SHOW COLUMNS FROM snap_collections LIKE 'is_visible'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
        $pdo->exec(
            "ALTER TABLE snap_collections
             ADD COLUMN is_visible TINYINT NOT NULL DEFAULT 0
             AFTER sort_order"
        );
        $report[] = "Added is_visible TINYINT (default 0) to snap_collections.";
    }

    $pdo->prepare("INSERT INTO snap_migrations (migration, applied_at) VALUES (?, NOW())")
        ->execute([$migration_name]);

    $msg = empty($report)
        ? 'No changes needed (already at v0.2 shape).'
        : implode(' ', $report);

    return ['status' => 'ok', 'message' => $msg];

} catch (Throwable $e) {
    return ['status' => 'error', 'message' => 'Migration 055 failed: ' . $e->getMessage()];
}
// ===== SNAPSMACK EOF =====
