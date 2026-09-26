<?php
/**
 * SNAPSMACK - Post model: every published photograph on a photoblog is a post.
 *
 * snap_postmodel_wrap_image() gives ONE bare image its canonical snap_posts row
 * and cover pivot, exactly as the Maintenance "CONVERT PHOTOS TO POSTS" action
 * (smack-maintenance.php, postmodel_repair, 0.7.535) does for many at once —
 * same columns, same values, same slug rule, same mode boundary. It exists so
 * the paths that still created bare photos after 0.7.539 (FLKR FCKR import,
 * SMACKTHEMUP, hub "posts/create") make posts at birth instead of leaving
 * work for the repair button (SECAUDIT 049/050 post-model follow-up,
 * 2026-09-26).
 *
 * SNAPSMACK_EOF_HEADER
 *     // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

/**
 * Wrap one bare image in a single-image post.
 *
 * Returns the post id; the existing one if the image already belongs to a post;
 * 0 when the site is not a photoblog (on GRAMOFSMACK and SMACKTALK a bare image
 * is working material, not a post — the same boundary the repair action keeps).
 * Joins the caller's transaction if one is open, otherwise runs its own.
 * Never touches img_slug (federation identity) or engagement rows.
 */
function snap_postmodel_wrap_image(PDO $pdo, int $image_id): int {
    $mode = $pdo->query("SELECT setting_val FROM snap_settings WHERE setting_key='site_mode' LIMIT 1")->fetchColumn() ?: 'photoblog';
    if ($mode !== 'photoblog') return 0;

    $own_tx = !$pdo->inTransaction();
    if ($own_tx) $pdo->beginTransaction();
    try {
        $sel = $pdo->prepare(
            "SELECT i.id, i.post_id, i.img_slug, i.img_description, i.img_status, i.img_date,
                    COALESCE(i.allow_comments, 1) AS allow_comments,
                    COALESCE(i.allow_download, 1) AS allow_download,
                    COALESCE(i.download_url, '') AS download_url,
                    COALESCE(i.sort_order, 0) AS sort_order,
                    i.fedi_published_at,
                    COALESCE(i.is_sensitive, 0) AS is_sensitive,
                    i.content_warning
               FROM snap_images i
              WHERE i.id = ?
              FOR UPDATE");
        $sel->execute([$image_id]);
        $image = $sel->fetch(PDO::FETCH_ASSOC);
        if (!$image) throw new RuntimeException('Photo ' . $image_id . ' does not exist.');

        if (!empty($image['post_id'])) {
            if ($own_tx) $pdo->commit();
            return (int)$image['post_id'];
        }
        $pivot = $pdo->prepare('SELECT post_id FROM snap_post_images WHERE image_id = ? LIMIT 1');
        $pivot->execute([$image_id]);
        $existing = (int)$pivot->fetchColumn();
        if ($existing) {
            if ($own_tx) $pdo->commit();
            return $existing;
        }

        $base_slug = trim((string)($image['img_slug'] ?? ''));
        if ($base_slug === '') $base_slug = 'post-' . $image_id;
        $slug_exists = $pdo->prepare('SELECT 1 FROM snap_posts WHERE slug = ? LIMIT 1');
        $slug = $base_slug;
        $slug_exists->execute([$slug]);
        if ($slug_exists->fetchColumn()) {
            $slug = $base_slug . '-p' . $image_id;
            $suffix = 2;
            do {
                $slug_exists->execute([$slug]);
                $taken = (bool)$slug_exists->fetchColumn();
                if ($taken) $slug = $base_slug . '-p' . $image_id . '-' . $suffix++;
            } while ($taken);
        }

        $pdo->prepare(
            "INSERT INTO snap_posts
                (title, slug, description, post_type, status, created_at,
                 allow_comments, allow_download, download_url, panorama_rows,
                 post_img_size_pct, post_border_px, post_border_color,
                 post_bg_color, post_shadow, fedi_enabled, sort_order,
                 fedi_published_at, is_sensitive, content_warning)
             VALUES
                ('', ?, ?, 'single', ?, ?, ?, ?, ?, 1,
                 100, 0, '#000000', '#ffffff', 0, 1, ?, ?, ?, ?)"
        )->execute([
            $slug,
            $image['img_description'],
            (string)$image['img_status'],
            $image['img_date'],
            (int)$image['allow_comments'],
            (int)$image['allow_download'],
            (string)$image['download_url'],
            (int)$image['sort_order'],
            $image['fedi_published_at'],
            (int)$image['is_sensitive'],
            $image['content_warning'],
        ]);
        $post_id = (int)$pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO snap_post_images
                (post_id, image_id, sort_position, is_cover,
                 img_size_pct, img_border_px, img_border_color, img_bg_color,
                 img_shadow, img_crop_mode, img_focus_x, img_focus_y, img_zoom)
             VALUES (?, ?, 0, 1, 100, 0, '#000000', '#ffffff', 0, 'fit', 50, 50, 100)"
        )->execute([$post_id, $image_id]);
        $attach = $pdo->prepare('UPDATE snap_images SET post_id = ? WHERE id = ? AND post_id IS NULL');
        $attach->execute([$post_id, $image_id]);
        if ($attach->rowCount() !== 1) {
            throw new RuntimeException('Photo ' . $image_id . ' changed while it was being wrapped.');
        }
        if ($own_tx) $pdo->commit();
        return $post_id;
    } catch (Throwable $e) {
        if ($own_tx && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

// ===== SNAPSMACK EOF =====
