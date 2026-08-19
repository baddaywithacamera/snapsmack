<?php
/**
 * SNAPSMACK — the ONE definition of "this photo is published and live".
 *
 * ARCH-01 collapse item 14 / the shared-library Sean specced. The test
 * `img_status='published' AND img_date<=<cutoff>` (published, and not scheduled
 * for the future) is currently hand-copied across ~36 files. When one copy needs
 * a fix — including a security fix — every copy has to be found and changed by
 * hand, and a missed copy quietly behaves differently. This file is the single
 * place to change it once.
 *
 * PURE DE-DUPLICATION: this returns the exact same SQL the inline copies produced,
 * so the result set must be byte-for-byte identical. The caller binds the cutoff
 * as one '?' parameter (usually date('Y-m-d H:i:s'), the PHP/DB-shared clock set
 * in core/db.php) — same value the inline copies interpolated.
 *
 * Usage:
 *   require_once __DIR__ . '/core/published-units.php';
 *   $sql = "SELECT ... FROM snap_images i WHERE " . snap_published_photo_where('i') . " ORDER BY ...";
 *   $stmt = $pdo->prepare($sql); $stmt->execute([$now_local]);
 *
 * NOTE (do not silently "fix" here): this is the FRONT-END photo filter (archive,
 * home nav). The FEDERATION outbox uses a stricter variant that also requires
 * `post_id IS NULL` (bare image) so post-backed photos federate via the posts
 * path instead — that lives in core/smackverse.php and is deliberately separate.
 * Fold that in only with the scratch-DB proof, not blind.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 */

if (!function_exists('snap_published_photo_where')) {
    /**
     * WHERE fragment: a photo that is published AND not future-scheduled.
     * @param string $alias  table alias (e.g. 'i'); '' for no alias.
     * @return string        e.g. "i.img_status = 'published' AND i.img_date <= ?"
     */
    function snap_published_photo_where(string $alias = ''): string {
        $p = $alias !== '' ? rtrim($alias, '.') . '.' : '';
        return "{$p}img_status = 'published' AND {$p}img_date <= ?";
    }
}
// ===== SNAPSMACK EOF =====
