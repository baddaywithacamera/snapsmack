<?php
/**
 * SNAPSMACK - Trigram helpers
 *
 * Shared logic for the queued-publish gate that ensures all three posts in a
 * trigram group go live atomically.  Used by smack-lt-gram.php and
 * core/unzucker-api.php.  Intended for smack-post-gram.php (manual
 * publish path) — not yet wired.
 *
 * SNAPSMACK_EOF_HEADER
 *     <?php // ===== SNAPSMACK EOF =====
 * Last non-empty line of this file MUST match the line above.
 * Missing or different = truncated/corrupted. Restore before saving.
 */

/**
 * Check whether all three posts in a trigram group are ready to publish, and
 * if so promote them atomically.
 *
 * Called after a post's status has been set to 'queued' or 'published' and the
 * post belongs to a trigram group.
 *
 * Behaviour:
 *   - Counts how many of the three trigram posts are status='queued' or
 *     status='published'.
 *   - If count < 3: sets the target post to 'queued' and returns false.
 *   - If count == 3: flips all three to 'published' atomically, assigns
 *     consecutive sort_order values starting at the next row-boundary slot
 *     (next position ≡ 0 mod 3 after the current MAX(sort_order)), and
 *     returns true.
 *
 * @param PDO $pdo          Live database connection.
 * @param int $trigram_id   The snap_trigrams.id for the group.
 * @param int $post_id      The post being published (will be set to 'queued' if not ready).
 *
 * @return bool  True = all three promoted to 'published'.  False = still waiting.
 */
function trigram_check_and_publish(PDO $pdo, int $trigram_id, int $post_id): bool
{
    // Fetch the trigram row to get all three post IDs.
    $tg = $pdo->prepare("SELECT post_id_1, post_id_2, post_id_3 FROM snap_trigrams WHERE id = ? LIMIT 1");
    $tg->execute([$trigram_id]);
    $row = $tg->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        // Trigram row missing — just publish the post normally.
        $pdo->prepare("UPDATE snap_posts SET status = 'published' WHERE id = ?")->execute([$post_id]);
        return true;
    }

    $ids = [(int)$row['post_id_1'], (int)$row['post_id_2'], (int)$row['post_id_3']];

    // Count how many slots are already queued or published (i.e. user-ready).
    $ph   = implode(',', array_fill(0, 3, '?'));
    $stmt = $pdo->prepare("SELECT id, status FROM snap_posts WHERE id IN ($ph)");
    $stmt->execute($ids);
    $statuses = $stmt->fetchAll(PDO::FETCH_KEY_PAIR); // id => status

    // Mark the triggering post as queued now so it counts.
    $ready = 0;
    foreach ($ids as $pid) {
        $s = ($pid === $post_id) ? 'queued' : ($statuses[$pid] ?? '');
        if ($s === 'queued' || $s === 'published') {
            $ready++;
        }
    }

    if ($ready < 3) {
        // Not all three ready — park this post as queued.
        $pdo->prepare("UPDATE snap_posts SET status = 'queued' WHERE id = ?")->execute([$post_id]);
        return false;
    }

    // All three ready — promote atomically.
    $pdo->beginTransaction();
    try {
        // Find the next row-boundary sort_order slot.
        // sort_order is 1-indexed; row starts are 1, 4, 7, 10... (≡ 1 mod 3).
        // Find smallest n > max_so where (n-1) % 3 === 0 (column 0 of a new row).
        $max_so     = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM snap_posts WHERE status = 'published'")->fetchColumn();
        $col_offset = (1 - ($max_so % 3) + 3) % 3;
        $start      = $max_so + ($col_offset === 0 ? 3 : $col_offset);

        $upd = $pdo->prepare("UPDATE snap_posts SET status = 'published', sort_order = ? WHERE id = ?");
        foreach ($ids as $i => $pid) {
            $upd->execute([$start + $i, $pid]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        // Re-throw so callers can surface the error.
        throw $e;
    }

    return true;
}

/**
 * How many of the three trigram slots are queued or published?
 * Returns an int 0–3.  Used to build UI tooltips ("Waiting for X more").
 */
function trigram_ready_count(PDO $pdo, int $trigram_id): int
{
    $tg = $pdo->prepare("SELECT post_id_1, post_id_2, post_id_3 FROM snap_trigrams WHERE id = ? LIMIT 1");
    $tg->execute([$trigram_id]);
    $row = $tg->fetch(PDO::FETCH_ASSOC);
    if (!$row) return 0;

    $ids  = [(int)$row['post_id_1'], (int)$row['post_id_2'], (int)$row['post_id_3']];
    $ph   = implode(',', array_fill(0, 3, '?'));
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM snap_posts WHERE id IN ($ph) AND status IN ('queued','published')");
    $stmt->execute($ids);
    return (int)$stmt->fetchColumn();
}

// ===== SNAPSMACK EOF =====
