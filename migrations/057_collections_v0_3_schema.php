<?php
/**
 * SNAPSMACK - Migration 057: Collections v0.3 schema reconciliation
 *
 * Reconciles the as-built v0.2 schema against the v0.3 spec. All changes
 * are renames or additions — no data is destroyed.
 *
 * snap_collections:
 *   name            → title          (clearer; matches spec)
 *   featured_post_id→ cover_image_id (was always storing image IDs, named wrong)
 *   is_visible      → published      (conventional; matches spec)
 *   ADD default_display ENUM('browse','slideshow') DEFAULT 'browse'
 *
 * snap_collection_items:
 *   DROP item_type     (always 'image' since 055; dead weight)
 *   item_id → image_id (explicit FK target; matches the image-first data model)
 *   sort_order→position(spec term; consistent with ordered-set semantics)
 *   ADD caption TEXT   (collection-specific caption, separate from post desc)
 *   UNIQUE KEY updated to (collection_id, image_id)
 *
 * Idempotent: each step checks current column/key state before mutating.
 */

/**
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

$migration_name = '057_collections_v0_3_schema';

try {
    $check = $pdo->prepare("SELECT COUNT(*) FROM snap_migrations WHERE migration = ?");
    $check->execute([$migration_name]);
    if ((int)$check->fetchColumn() > 0) {
        return ['status' => 'skipped', 'message' => 'Already applied.'];
    }

    $report = [];

    // ── snap_collections ─────────────────────────────────────────────────────

    // 1. name → title
    $col = $pdo->query("SHOW COLUMNS FROM snap_collections LIKE 'name'")->fetch(PDO::FETCH_ASSOC);
    if ($col) {
        $pdo->exec("ALTER TABLE snap_collections CHANGE `name` `title` VARCHAR(150) COLLATE utf8mb4_unicode_ci NOT NULL");
        $report[] = "Renamed snap_collections.name → title.";
    }

    // 2. featured_post_id → cover_image_id
    $col = $pdo->query("SHOW COLUMNS FROM snap_collections LIKE 'featured_post_id'")->fetch(PDO::FETCH_ASSOC);
    if ($col) {
        $pdo->exec("ALTER TABLE snap_collections CHANGE `featured_post_id` `cover_image_id` INT UNSIGNED DEFAULT NULL");
        $report[] = "Renamed snap_collections.featured_post_id → cover_image_id.";
    }

    // 3. is_visible → published
    $col = $pdo->query("SHOW COLUMNS FROM snap_collections LIKE 'is_visible'")->fetch(PDO::FETCH_ASSOC);
    if ($col) {
        $pdo->exec("ALTER TABLE snap_collections CHANGE `is_visible` `published` TINYINT NOT NULL DEFAULT 0");
        $report[] = "Renamed snap_collections.is_visible → published.";
    }

    // 4. ADD default_display
    $col = $pdo->query("SHOW COLUMNS FROM snap_collections LIKE 'default_display'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
        $pdo->exec(
            "ALTER TABLE snap_collections
             ADD COLUMN `default_display` ENUM('browse','slideshow')
             COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'browse'
             AFTER `description`"
        );
        $report[] = "Added snap_collections.default_display ENUM('browse','slideshow') DEFAULT 'browse'.";
    }

    // ── snap_collection_items ─────────────────────────────────────────────────

    // 5. DROP item_type (always 'image' since migration 055 — dead weight)
    $col = $pdo->query("SHOW COLUMNS FROM snap_collection_items LIKE 'item_type'")->fetch(PDO::FETCH_ASSOC);
    if ($col) {
        // Drop dependent unique key first if it still references item_type
        $keys = $pdo->query("SHOW INDEX FROM snap_collection_items WHERE Key_name = 'uq_collection_item'")->fetchAll();
        if ($keys) {
            $pdo->exec("ALTER TABLE snap_collection_items DROP KEY `uq_collection_item`");
            $report[] = "Dropped old unique key uq_collection_item.";
        }
        $pdo->exec("ALTER TABLE snap_collection_items DROP COLUMN `item_type`");
        $report[] = "Dropped snap_collection_items.item_type (was always 'image').";
    }

    // 6. item_id → image_id
    $col = $pdo->query("SHOW COLUMNS FROM snap_collection_items LIKE 'item_id'")->fetch(PDO::FETCH_ASSOC);
    if ($col) {
        $pdo->exec("ALTER TABLE snap_collection_items CHANGE `item_id` `image_id` INT UNSIGNED NOT NULL");
        $report[] = "Renamed snap_collection_items.item_id → image_id.";
    }

    // 7. sort_order → position
    $col = $pdo->query("SHOW COLUMNS FROM snap_collection_items LIKE 'sort_order'")->fetch(PDO::FETCH_ASSOC);
    if ($col) {
        $pdo->exec("ALTER TABLE snap_collection_items CHANGE `sort_order` `position` INT NOT NULL DEFAULT 0");
        $report[] = "Renamed snap_collection_items.sort_order → position.";
    }

    // 8. ADD caption
    $col = $pdo->query("SHOW COLUMNS FROM snap_collection_items LIKE 'caption'")->fetch(PDO::FETCH_ASSOC);
    if (!$col) {
        $pdo->exec(
            "ALTER TABLE snap_collection_items
             ADD COLUMN `caption` TEXT COLLATE utf8mb4_unicode_ci DEFAULT NULL
             AFTER `position`"
        );
        $report[] = "Added snap_collection_items.caption TEXT nullable.";
    }

    // 9. Restore unique key on (collection_id, image_id)
    $keys = $pdo->query("SHOW INDEX FROM snap_collection_items WHERE Key_name = 'uq_collection_image'")->fetchAll();
    if (!$keys) {
        $pdo->exec("ALTER TABLE snap_collection_items ADD UNIQUE KEY `uq_collection_image` (`collection_id`, `image_id`)");
        $report[] = "Added unique key uq_collection_image (collection_id, image_id).";
    }

    $pdo->prepare("INSERT INTO snap_migrations (migration, applied_at) VALUES (?, NOW())")
        ->execute([$migration_name]);

    $msg = empty($report)
        ? 'No changes needed (already at v0.3 shape).'
        : implode(' ', $report);

    return ['status' => 'ok', 'message' => $msg];

} catch (Throwable $e) {
    return ['status' => 'error', 'message' => 'Migration 057 failed: ' . $e->getMessage()];
}
// ===== SNAPSMACK EOF =====
